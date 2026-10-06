<?php
/**
 * Procesos en segundo plano con Action Scheduler.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Jobs;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\Graph\GraphIndexer;
use MagicLinking\Graph\IndexOutcome;
use MagicLinking\Index\LexicalIndexer;
use Throwable;
use WP_Post;

/**
 * Indexado del grafo y del índice léxico: inicial por lotes, incremental al guardar y recálculo nocturno.
 *
 * El proceso `index` hace las dos cosas en la misma pasada: por cada corte de entradas, el grafo y
 * el índice léxico (F1-04). Si aún no hay índice léxico (o se pide reconstruirlo), el proceso tiene
 * dos fases, porque el peso de un término depende de su df y de la longitud media de todo el
 * corpus: `scan` (grafo, df y longitudes) y `weigh` (pesos y postings con los df ya definitivos);
 * si ya lo hay, una sola fase en la que solo se reindexan las entradas cuya huella ha cambiado.
 * El recálculo nocturno reconstruye el índice léxico cuando el corpus ha cambiado más de un 5 %.
 *
 * - El indexado inicial es un proceso (`index`) con estado en magiclinking_jobs; solo hay uno
 *   activo por sitio. Se puede pausar, reanudar y cancelar. Cada ejecución trabaja por
 *   presupuesto de tiempo (unos 20 s, o un máximo de entradas), en cortes cuyo tamaño se
 *   adapta al ritmo; el cursor se guarda al terminar cada corte, así que un proceso
 *   interrumpido se reanuda donde se quedó. Al acabar la ejecución se programa la siguiente.
 * - Al guardar una entrada se recalcula si cambió su contenido. En sitios de más de 500
 *   entradas se encola en vez de hacerse en la petición.
 * - Cada noche se vuelven a comprobar las entradas con enlaces rotos (un destino pudo publicarse
 *   o borrarse sin que cambiara el contenido del origen) y se limpia lo que ya no cuenta.
 *
 * El trabajo no depende de WP-Cron: Action Scheduler corre también con un cron del sistema o con
 * `wp action-scheduler run`, y `wp magic-linking index` lo hace todo en la propia orden.
 */
final class Jobs implements Module {

	public const TYPE_INDEX = 'index';

	/**
	 * Fases del proceso: recorrido con grafo y conteo (df, longitudes) y, al construir el índice léxico, pesado.
	 */
	public const PHASE_SCAN  = 'scan';
	public const PHASE_WEIGH = 'weigh';

	public const HOOK_BATCH   = 'magiclinking_run_batch';
	public const HOOK_POSTS   = 'magiclinking_index_posts';
	public const HOOK_NIGHTLY = 'magiclinking_nightly';

	/**
	 * Entradas por corte al empezar (después se adapta, ver Pace).
	 */
	public const DEFAULT_BATCH = 50;

	/**
	 * Segundos de trabajo por ejecución de Action Scheduler (filtro `magiclinking_batch_time_budget`).
	 */
	public const TIME_BUDGET = 20;

	/**
	 * Máximo de entradas por ejecución (filtro `magiclinking_batch_max_entries`).
	 */
	public const MAX_ENTRIES = 5000;

	/**
	 * Segundos que debería tardar un corte; el tamaño del corte se ajusta a ello.
	 */
	public const SLICE_SECONDS = 1.5;

	/**
	 * Memoria en uso (bytes) a partir de la cual la ejecución termina y deja el resto para la siguiente,
	 * salvo que ya se usara más al empezar: entonces se permite ese uso más MEMORY_MARGIN.
	 */
	public const MEMORY_CEILING = 40 * MB_IN_BYTES;

	/**
	 * Crecimiento de memoria (bytes) que se tolera durante una ejecución cuando ya se partía del techo.
	 */
	public const MEMORY_MARGIN = 8 * MB_IN_BYTES;

	/**
	 * Por encima de este número de entradas indexadas, guardar una entrada encola el trabajo.
	 */
	public const INLINE_LIMIT = 500;

	/**
	 * Repositorio de procesos.
	 *
	 * @var JobRepository
	 */
	private JobRepository $jobs;

	/**
	 * Indexador del grafo.
	 *
	 * @var GraphIndexer
	 */
	private GraphIndexer $indexer;

	/**
	 * Ajustes.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Indexador léxico.
	 *
	 * @var LexicalIndexer
	 */
	private LexicalIndexer $lexical;

	/**
	 * Constructor.
	 *
	 * @param JobRepository  $jobs     Repositorio de procesos.
	 * @param GraphIndexer   $indexer  Indexador del grafo.
	 * @param Settings       $settings Ajustes.
	 * @param LexicalIndexer $lexical  Indexador léxico.
	 */
	public function __construct( JobRepository $jobs, GraphIndexer $indexer, Settings $settings, LexicalIndexer $lexical ) {
		$this->jobs     = $jobs;
		$this->indexer  = $indexer;
		$this->settings = $settings;
		$this->lexical  = $lexical;
	}

	/**
	 * Engancha los manejadores. No hace consultas: solo declara hooks.
	 */
	public function register(): void {
		add_action( self::HOOK_BATCH, array( $this, 'run_batch' ), 10, 1 );
		add_action( self::HOOK_POSTS, array( $this, 'run_posts' ), 10, 2 );
		add_action( self::HOOK_NIGHTLY, array( $this, 'run_nightly' ) );
		add_action( 'wp_after_insert_post', array( $this, 'on_post_saved' ), 20, 4 );
		add_action( 'deleted_post', array( $this, 'on_post_deleted' ), 10, 1 );
	}

	/**
	 * Repositorio de procesos.
	 */
	public function repository(): JobRepository {
		return $this->jobs;
	}

	/**
	 * Indexador del grafo.
	 */
	public function indexer(): GraphIndexer {
		return $this->indexer;
	}

	/**
	 * Indexador léxico.
	 */
	public function lexical(): LexicalIndexer {
		return $this->lexical;
	}

	// ------------------------------------------------------------------ Proceso de indexado.

	/**
	 * Lanza el indexado de todo el sitio, o devuelve el que ya está en marcha.
	 *
	 * @param int  $user_id Quien lo lanza (0 = sistema o WP-CLI).
	 * @param bool $enqueue Programar el primer lote en Action Scheduler (false para ejecutarlo a mano, como hace WP-CLI).
	 * @param bool $force   Volver a analizar todas aunque su contenido no haya cambiado (los destinos pueden haber cambiado). Con false (el «Analizar cambios» normal) se saltan las que tienen el mismo hash y el mismo tipo.
	 * @param bool $graph   Analizar también el grafo de enlaces (false para recalcular solo el índice léxico).
	 * @param bool $rebuild Reconstruir el índice léxico entero (df incluido) aunque ya exista; sin esto solo se reconstruye si aún no hay (o si una construcción anterior quedó a medias).
	 * @param bool $system  Proceso del sistema (el recálculo nocturno): cede el sitio a quien lo pida a mano y no cuenta como su último proceso.
	 *
	 * @return array{id: int, type: string, status: string, total: int, done: int, params: array<string, mixed>, error: string, created_by: int, created_at: string, updated_at: string}
	 */
	public function start_index( int $user_id = 0, bool $enqueue = true, bool $force = false, bool $graph = true, bool $rebuild = false, bool $system = false ): array {
		$active = $this->jobs->active( self::TYPE_INDEX );
		if ( null !== $active ) {
			// Una petición manual no se queda enganchada al recálculo nocturno (solo léxico, sin grafo ni «forzar»):
			// se retira y se lanza el proceso pedido, que reconstruye si la construcción quedó a medias.
			if ( $system || empty( $active['params']['system'] ) || ! $this->cancel( $active['id'] ) ) {
				return $active;
			}
		}

		// Construir = dos fases (ver la descripción de la clase). `done` y `total` cuentan entradas del recorrido;
		// el pesado de la segunda fase se anota aparte (`params.weighed`).
		$build = $rebuild || ! $this->lexical->is_built();
		$total = $this->indexer->repository()->count_eligible( $this->settings->post_types() );
		$id    = $this->jobs->create(
			self::TYPE_INDEX,
			$total,
			array(
				'last_id' => 0,
				'batch'   => self::DEFAULT_BATCH,
				'force'   => $force,
				'graph'   => $graph,
				'build'   => $build,
				'phase'   => self::PHASE_SCAN,
				'system'  => $system,
			),
			$user_id
		);

		if ( $build ) {
			$this->lexical->begin_build( $id );
		}

		if ( $enqueue ) {
			$this->enqueue_batch( $id );
		}
		$this->ensure_schedules();

		return (array) $this->jobs->get( $id );
	}

	/**
	 * Manejador de Action Scheduler: un lote y, si queda trabajo, programa el siguiente.
	 *
	 * @param int $job_id ID del proceso.
	 */
	public function run_batch( int $job_id ): void {
		if ( $this->step( $job_id ) ) {
			// Sin modo único: la acción que se está ejecutando sigue «en curso» y bloquearía la siguiente.
			$this->enqueue_batch( $job_id, false );
		}
	}

	/**
	 * Procesa un proceso de indexado durante un presupuesto de tiempo.
	 *
	 * Trabaja en cortes hasta agotar el tiempo o el máximo de entradas, y guarda el cursor tras
	 * cada corte. No hace nada si el proceso está pausado, cancelado o terminado.
	 *
	 * @param int        $job_id      ID del proceso.
	 * @param float|null $budget      Segundos de trabajo (por defecto TIME_BUDGET, filtrable).
	 * @param int|null   $max_entries Máximo de entradas (por defecto MAX_ENTRIES, filtrable).
	 *
	 * @return bool Si queda trabajo (hay que programar otra ejecución).
	 */
	public function step( int $job_id, ?float $budget = null, ?int $max_entries = null ): bool {
		$job = $this->jobs->get( $job_id );

		if ( null === $job || self::TYPE_INDEX !== $job['type'] || ! in_array( $job['status'], array( JobRepository::QUEUED, JobRepository::RUNNING ), true ) ) {
			return false;
		}

		$this->jobs->set_status( $job_id, JobRepository::RUNNING );

		/**
		 * Segundos de trabajo por ejecución del indexado en segundo plano.
		 *
		 * @param float $seconds Segundos.
		 */
		$budget = $budget ?? max( 1.0, (float) apply_filters( 'magiclinking_batch_time_budget', (float) self::TIME_BUDGET ) );

		/**
		 * Máximo de entradas por ejecución del indexado en segundo plano.
		 *
		 * @param int $entries Entradas.
		 */
		$max_entries = $max_entries ?? max( 1, (int) apply_filters( 'magiclinking_batch_max_entries', self::MAX_ENTRIES ) );

		$params  = $job['params'];
		$last_id = (int) ( $params['last_id'] ?? 0 );
		$size    = max( Pace::MIN_SLICE, min( Pace::MAX_SLICE, (int) ( $params['batch'] ?? self::DEFAULT_BATCH ) ) );
		$force   = ! empty( $params['force'] );
		$graph   = false !== ( $params['graph'] ?? true );
		$build   = ! empty( $params['build'] );
		$system  = ! empty( $params['system'] );
		$phase   = self::PHASE_WEIGH === ( $params['phase'] ?? '' ) ? self::PHASE_WEIGH : self::PHASE_SCAN;
		$weighed = (int) ( $params['weighed'] ?? 0 );
		$samples = is_array( $params['samples'] ?? null ) ? $params['samples'] : array();
		$changed = (int) ( $params['changed'] ?? 0 );
		$same    = (int) ( $params['same'] ?? 0 );
		$done    = $job['done'];
		$run     = 0;
		$types   = $this->settings->post_types();
		$repo    = $this->indexer->repository();
		$started = microtime( true );
		$stop_at = $started + $budget;

		// Tope de memoria de la ejecución: el mayor entre el techo fijo y lo ya usado más un margen.
		$memory_cap = max( self::MEMORY_CEILING, memory_get_usage( true ) + self::MEMORY_MARGIN );

		if ( array() === $samples ) {
			$samples = Pace::push_sample( array(), time(), $done );
		}

		try {
			$this->indexer->flush();

			do {
				// Un proceso cancelado (p. ej. el nocturno, sustituido por una petición manual) deja de trabajar ya:
				// seguiría sumando df encima de la construcción nueva.
				if ( $run > 0 && ! $this->is_running( $job_id ) ) {
					return false;
				}

				$slice_started = microtime( true );
				$want          = min( $size, $max_entries - $run );
				$ids           = $repo->eligible_ids( $types, $last_id, $want );
				$counts        = $this->index_slice( $job_id, $ids, $phase, $build, $graph, $force, $stop_at );
				$handled       = min( count( $ids ), $counts['processed'] );
				$changed      += $counts['indexed'];
				$same         += $counts['unchanged'];
				$exhausted     = count( $ids ) < $want;

				if ( $handled > 0 ) {
					$last_id = (int) $ids[ $handled - 1 ];
				}
				if ( self::PHASE_WEIGH === $phase ) {
					$weighed += $handled;
				} else {
					$done += $handled;
				}
				$run += $handled;
				wp_cache_flush_runtime();

				if ( $exhausted && count( $ids ) === $handled ) {
					if ( $build && self::PHASE_SCAN === $phase ) {
						// Fin del conteo: df definitivos; se descartan los términos que no unen entradas y lo que ya no cuenta.
						$this->lexical->end_scan();
						$repo->purge_out_of_scope( $types );
						$phase   = self::PHASE_WEIGH;
						$last_id = 0;
						$weighed = 0;
						$this->jobs->set_progress( $job_id, $done, max( $job['total'], $done ), $this->progress_params( $last_id, $size, $force, $samples, $changed, $same, $graph, $build, $phase, $weighed, $system ) );

						return true;
					}

					$this->finish( $job_id, $done, $job['total'], $types, $force, $changed, $same, $graph, $build, $system );
					return false;
				}

				$size = Pace::next_slice( $size, microtime( true ) - $slice_started, self::SLICE_SECONDS );
				$this->jobs->set_progress( $job_id, $done, max( $job['total'], $done ), $this->progress_params( $last_id, $size, $force, $samples, $changed, $same, $graph, $build, $phase, $weighed, $system ) );
			} while ( microtime( true ) < $stop_at && $run < $max_entries && memory_get_usage( true ) < $memory_cap );

			$samples = Pace::push_sample( $samples, time(), $done );
			$this->jobs->set_progress( $job_id, $done, max( $job['total'], $done ), $this->progress_params( $last_id, $size, $force, $samples, $changed, $same, $graph, $build, $phase, $weighed, $system ) );

			return true;
		} catch ( Throwable $e ) {
			$this->jobs->set_status( $job_id, JobRepository::FAILED, $e->getMessage() );

			return false;
		}//end try
	}

	/**
	 * Si el proceso sigue en cola o en marcha (no se ha cancelado ni pausado desde que empezó la ejecución).
	 *
	 * @param int $job_id ID.
	 */
	private function is_running( int $job_id ): bool {
		$job = $this->jobs->get( $job_id );

		return null !== $job && in_array( $job['status'], array( JobRepository::QUEUED, JobRepository::RUNNING ), true );
	}

	/**
	 * Parámetros que se guardan con el avance.
	 *
	 * @param int                               $last_id Cursor.
	 * @param int                               $size    Tamaño del siguiente corte.
	 * @param bool                              $force   Volver a analizar aunque no haya cambiado.
	 * @param array<int, array{0: int, 1: int}> $samples Muestras de ritmo.
	 * @param int                               $changed Entradas nuevas o modificadas analizadas hasta ahora.
	 * @param int                               $same    Entradas saltadas por no haber cambiado.
	 * @param bool                              $graph   Si se analiza el grafo.
	 * @param bool                              $build   Si se construye el índice léxico (dos fases).
	 * @param string                            $phase   Fase en curso.
	 * @param int                               $weighed Entradas pesadas en la segunda fase.
	 * @param bool                              $system  Si es un proceso del sistema (recálculo nocturno).
	 *
	 * @return array<string, mixed>
	 */
	private function progress_params( int $last_id, int $size, bool $force, array $samples, int $changed, int $same, bool $graph, bool $build, string $phase, int $weighed, bool $system ): array {
		return array(
			'last_id' => $last_id,
			'batch'   => $size,
			'force'   => $force,
			'samples' => $samples,
			'changed' => $changed,
			'same'    => $same,
			'graph'   => $graph,
			'build'   => $build,
			'phase'   => $phase,
			'weighed' => $weighed,
			'system'  => $system,
		);
	}

	/**
	 * Procesa un corte de entradas: grafo e índice léxico (o solo el pesado, en la segunda fase).
	 *
	 * El índice léxico lleva su propia huella (no depende del orden respecto al grafo) y solo trata las
	 * entradas que el grafo ha llegado a procesar dentro del plazo.
	 *
	 * @param int             $job_id   Proceso.
	 * @param array<int, int> $ids      IDs del corte.
	 * @param string          $phase    Fase.
	 * @param bool            $build    Si se construye el índice léxico.
	 * @param bool            $graph    Si se analiza el grafo.
	 * @param bool            $force    Volver a analizar el grafo aunque no haya cambiado.
	 * @param float           $deadline Instante a partir del cual no se empieza otra entrada.
	 *
	 * @return array{indexed: int, unchanged: int, processed: int}
	 */
	private function index_slice( int $job_id, array $ids, string $phase, bool $build, bool $graph, bool $force, float $deadline ): array {
		if ( self::PHASE_WEIGH === $phase ) {
			$this->lexical->weigh( $ids, fn(): bool => $this->is_running( $job_id ) );

			return array(
				'indexed'   => 0,
				'unchanged' => 0,
				'processed' => count( $ids ),
			);
		}

		$counts  = $graph
			? $this->indexer->index_many( $ids, $force, $deadline )
			: array(
				'indexed'   => 0,
				'unchanged' => 0,
				'processed' => count( $ids ),
			);
		$handled = array_slice( $ids, 0, $counts['processed'] );

		if ( $build ) {
			$this->lexical->scan( $handled, fn(): bool => $this->is_running( $job_id ) );
		} else {
			$lexical = $this->lexical->index_changed( $handled );
			if ( ! $graph ) {
				$counts['indexed']   = $lexical['indexed'];
				$counts['unchanged'] = $lexical['unchanged'];
			}
		}

		return array(
			'indexed'   => $counts['indexed'],
			'unchanged' => $counts['unchanged'],
			'processed' => $counts['processed'],
		);
	}

	/**
	 * Ejecuta un proceso hasta el final en la propia petición (WP-CLI).
	 *
	 * @param int           $job_id   ID del proceso.
	 * @param callable|null $progress Función (hechas, previstas) tras cada tanda.
	 */
	public function run_to_completion( int $job_id, ?callable $progress = null ): void {
		do {
			$more = $this->step( $job_id, 2.0, self::MAX_ENTRIES );
			$job  = $this->jobs->get( $job_id );

			if ( null !== $progress && null !== $job ) {
				$progress( $job['done'], $job['total'] );
			}
		} while ( $more );
	}

	/**
	 * Cierra el proceso: limpia lo que ya no cuenta y recalcula los entrantes de todas.
	 *
	 * @param int                $job_id ID.
	 * @param int                $done   Entradas procesadas.
	 * @param int                $total  Entradas previstas.
	 * @param array<int, string> $types  Tipos analizados.
	 * @param bool               $force   Si fue un análisis forzado.
	 * @param int                $changed Entradas nuevas o modificadas.
	 * @param int                $same    Entradas saltadas por no haber cambiado.
	 * @param bool               $graph   Si se analizó el grafo.
	 * @param bool               $build   Si se construyó el índice léxico (se anota el recálculo de df).
	 * @param bool               $system  Si es un proceso del sistema (recálculo nocturno).
	 */
	private function finish( int $job_id, int $done, int $total, array $types, bool $force, int $changed, int $same, bool $graph, bool $build, bool $system ): void {
		if ( ! $this->is_running( $job_id ) ) {
			return;
		}

		$repo = $this->indexer->repository();
		$repo->purge_out_of_scope( $types );
		$repo->refresh_inbound();

		if ( $build ) {
			$this->lexical->record_build( $this->lexical->repository()->count_lexical(), $job_id );
		}

		$this->jobs->set_progress(
			$job_id,
			$done,
			max( $total, $done ),
			array(
				'last_id' => 0,
				'force'   => $force,
				'changed' => $changed,
				'same'    => $same,
				'graph'   => $graph,
				'build'   => $build,
				'system'  => $system,
			)
		);
		$this->jobs->set_status( $job_id, JobRepository::DONE );
	}

	/**
	 * Pausa un proceso. El lote programado no hace nada mientras esté pausado.
	 *
	 * @param int $job_id ID.
	 */
	public function pause( int $job_id ): bool {
		$job = $this->jobs->get( $job_id );
		if ( null === $job || ! in_array( $job['status'], array( JobRepository::QUEUED, JobRepository::RUNNING ), true ) ) {
			return false;
		}

		$this->jobs->set_status( $job_id, JobRepository::PAUSED );

		return true;
	}

	/**
	 * Reanuda un proceso pausado o parado a medias (sin avance desde hace más de 10 minutos).
	 *
	 * @param int  $job_id  ID.
	 * @param bool $enqueue Programar el siguiente lote.
	 */
	public function resume( int $job_id, bool $enqueue = true ): bool {
		$job = $this->jobs->get( $job_id );
		if ( null === $job || ! in_array( $job['status'], JobRepository::ACTIVE, true ) ) {
			return false;
		}

		if ( JobRepository::PAUSED !== $job['status'] && ! $this->is_stalled( $job ) ) {
			return false;
		}

		// La pausa no cuenta para el ritmo: se empieza a medir de nuevo.
		$params = $job['params'];
		unset( $params['samples'] );
		$this->jobs->set_progress( $job_id, $job['done'], $job['total'], $params );

		$this->jobs->set_status( $job_id, JobRepository::QUEUED );
		if ( $enqueue ) {
			$this->enqueue_batch( $job_id );
		}

		return true;
	}

	/**
	 * Cancela un proceso y retira sus lotes pendientes. Lo indexado hasta entonces se conserva.
	 *
	 * @param int $job_id ID.
	 */
	public function cancel( int $job_id ): bool {
		$job = $this->jobs->get( $job_id );
		if ( null === $job || ! in_array( $job['status'], JobRepository::ACTIVE, true ) ) {
			return false;
		}

		$this->jobs->set_status( $job_id, JobRepository::CANCELLED );
		as_unschedule_all_actions( self::HOOK_BATCH, array( $job_id ), Installer::ACTION_GROUP );

		return true;
	}

	/**
	 * Si un proceso «en marcha» lleva más de 10 minutos sin avanzar (el lote se perdió).
	 *
	 * @param array{status: string, updated_at: string} $job Proceso.
	 */
	public function is_stalled( array $job ): bool {
		if ( JobRepository::RUNNING !== $job['status'] && JobRepository::QUEUED !== $job['status'] ) {
			return false;
		}

		$updated = strtotime( $job['updated_at'] . ' UTC' );

		return false !== $updated && ( time() - $updated ) > 10 * MINUTE_IN_SECONDS;
	}

	/**
	 * Programa el siguiente lote de un proceso.
	 *
	 * @param int  $job_id ID.
	 * @param bool $unique Si no debe programarse cuando ya hay uno pendiente o en curso.
	 */
	private function enqueue_batch( int $job_id, bool $unique = true ): void {
		as_enqueue_async_action( self::HOOK_BATCH, array( $job_id ), Installer::ACTION_GROUP, $unique );
	}

	// ------------------------------------------------------------------ Indexado incremental.

	/**
	 * Al guardar una entrada: la vuelve a analizar si cambió y actualiza las que dependen de ella.
	 *
	 * @param int          $post_id     ID.
	 * @param WP_Post      $post        Entrada guardada.
	 * @param bool         $update      Si es una actualización.
	 * @param WP_Post|null $post_before Entrada antes del cambio.
	 */
	public function on_post_saved( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before = null ): void {
		unset( $update );

		if ( ! $this->is_ready() || wp_is_post_revision( $post_id ) || ! $this->is_tracked_type( $post->post_type, $post_before ) ) {
			return;
		}

		$this->indexer->flush();
		$this->index_or_queue( array( $post_id ), false );

		$changed_state = null === $post_before
			|| $post_before->post_status !== $post->post_status
			|| $post_before->post_name !== $post->post_name
			|| $post_before->post_type !== $post->post_type;

		if ( $changed_state ) {
			$dependents = $this->indexer->dependents_of( $post_id );
			if ( array() !== $dependents ) {
				$this->index_or_queue( $dependents, true );
			}
		}
	}

	/**
	 * Al borrar una entrada para siempre: sale del índice y se revisan las que la enlazaban.
	 *
	 * @param int $post_id ID.
	 */
	public function on_post_deleted( int $post_id ): void {
		if ( ! $this->is_ready() ) {
			return;
		}

		$repo = $this->indexer->repository();

		if ( null === $repo->doc( $post_id ) && array() === $repo->sources_linking_to( $post_id ) ) {
			return;
		}

		$dependents = $repo->sources_linking_to( $post_id );
		$affected   = $repo->delete_doc( $post_id );
		if ( array() !== $affected ) {
			$repo->refresh_inbound( $affected );
		}

		$this->indexer->flush();
		if ( array() !== $dependents ) {
			$this->index_or_queue( $dependents, true );
		}
	}

	/**
	 * Si las tablas del plugin ya están instaladas (no lo están entre una actualización y la
	 * primera visita al administrador, ni en un sitio de una red que aún no se ha activado).
	 */
	private function is_ready(): bool {
		return (int) get_option( Installer::DB_VERSION_OPTION, 0 ) >= 1;
	}

	/**
	 * Manejador de Action Scheduler para un grupo de entradas.
	 *
	 * @param array<int, int> $post_ids IDs.
	 * @param bool            $force    Volver a analizar aunque el contenido no haya cambiado.
	 */
	public function run_posts( array $post_ids, bool $force = false ): void {
		$this->indexer->flush();
		$this->index_posts( array_map( 'intval', $post_ids ), $force );
	}

	/**
	 * Indexa una entrada ahora (WP-CLI): grafo, índice léxico si ya existe y recuento de entrantes.
	 *
	 * @param int  $post_id ID.
	 * @param bool $force   Ver GraphIndexer::index_post().
	 */
	public function index_one( int $post_id, bool $force ): IndexOutcome {
		$outcome = $this->indexer->index_and_refresh( $post_id, $force );

		if ( $this->lexical->is_built() ) {
			$this->lexical->index_changed( array( $post_id ) );
		}

		return $outcome;
	}

	/**
	 * Indexa unas entradas: el grafo y, si ya hay índice léxico, el léxico (que salta las que no han cambiado).
	 * Sin índice léxico (o con la construcción a medias) no se hace nada: el proceso lo construye entero,
	 * con df exactos, y pesarlas ahora con df a cero dejaría postings vacías.
	 *
	 * @param array<int, int> $ids   IDs.
	 * @param bool            $force Ver GraphIndexer::index_post().
	 */
	private function index_posts( array $ids, bool $force ): void {
		$this->indexer->index_many( $ids, $force );

		if ( $this->lexical->is_built() ) {
			$this->lexical->index_changed( $ids );
		}
	}

	/**
	 * Recálculo nocturno: limpia lo que ya no cuenta y vuelve a comprobar las entradas con enlaces rotos.
	 */
	public function run_nightly(): void {
		$repo = $this->indexer->repository();
		if ( 0 === $repo->count_docs() ) {
			return;
		}

		$repo->purge_out_of_scope( $this->settings->post_types() );

		foreach ( array_chunk( $repo->sources_with_broken_links(), 25 ) as $chunk ) {
			as_enqueue_async_action( self::HOOK_POSTS, array( $chunk, true ), Installer::ACTION_GROUP );
		}

		// Entradas cuyo índice léxico quedó por detrás del grafo (el léxico falló tras guardar el grafo).
		if ( $this->lexical->is_built() ) {
			$behind = $this->lexical->repository()->lexical_behind( 500 );
			foreach ( array_chunk( $behind, 25 ) as $chunk ) {
				as_enqueue_async_action( self::HOOK_POSTS, array( $chunk, false ), Installer::ACTION_GROUP );
			}
		}

		// df se mantiene a mano al indexar una a una; con más de un 5 % de cambios (o una construcción a medias) se recuentan (solo el índice léxico).
		if ( $this->lexical->is_stale() ) {
			$this->start_index( 0, true, false, false, true, true );
		}
	}

	/**
	 * Programa el trabajo nocturno si no lo está ya.
	 */
	public function ensure_schedules(): void {
		if ( ! as_has_scheduled_action( self::HOOK_NIGHTLY, null, Installer::ACTION_GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_NIGHTLY, array(), Installer::ACTION_GROUP );
		}
	}

	/**
	 * Indexa ahora si el sitio es pequeño y son pocas; si no, lo encola.
	 *
	 * @param array<int, int> $ids   IDs.
	 * @param bool            $force Ver GraphIndexer::index_post().
	 */
	private function index_or_queue( array $ids, bool $force ): void {
		$inline = count( $ids ) <= 20 && $this->indexer->repository()->count_docs() <= self::INLINE_LIMIT;

		if ( $inline ) {
			try {
				$this->index_posts( $ids, $force );
			} catch ( Throwable $e ) {
				// Guardar una entrada no puede fallar por el índice. Si falla el léxico, su huella propia no se
				// anota y la entrada sigue desfasada: se reintenta en el siguiente guardado o en el recálculo nocturno.
				unset( $e );
			}
			return;
		}

		foreach ( array_chunk( $ids, 25 ) as $chunk ) {
			as_enqueue_async_action( self::HOOK_POSTS, array( $chunk, $force ), Installer::ACTION_GROUP, true );
		}
	}

	/**
	 * Si el tipo de una entrada es de los que se analizan (ahora, o antes del cambio).
	 *
	 * @param string       $post_type   Tipo actual.
	 * @param WP_Post|null $post_before Entrada antes del cambio.
	 */
	private function is_tracked_type( string $post_type, ?WP_Post $post_before ): bool {
		$types = $this->settings->post_types();

		return in_array( $post_type, $types, true )
			|| ( null !== $post_before && in_array( $post_before->post_type, $types, true ) );
	}

	// ------------------------------------------------------------------ Estado.

	/**
	 * Estado del índice, para la pantalla y WP-CLI.
	 *
	 * @return array{indexed: int, lexical: int, eligible: int, pending: int, job: array<string, mixed>|null, stalled: bool, last_job: array<string, mixed>|null, last_done: array<string, mixed>|null, eta: int|null}
	 */
	public function status(): array {
		$repo     = $this->indexer->repository();
		$indexed  = $repo->count_docs();
		$eligible = $repo->count_eligible( $this->settings->post_types() );
		$active   = $this->jobs->active( self::TYPE_INDEX );
		$stalled  = null !== $active && $this->is_stalled( $active );
		$eta      = null;

		if ( null !== $active && ! $stalled && JobRepository::PAUSED !== $active['status'] ) {
			$samples = is_array( $active['params']['samples'] ?? null ) ? $active['params']['samples'] : array();
			$eta     = Pace::eta( max( 0, $active['total'] - $active['done'] ), $samples );
		}

		return array(
			'indexed'   => $indexed,
			'lexical'   => $this->lexical->repository()->count_lexical(),
			'eligible'  => $eligible,
			'pending'   => max( 0, $eligible - $indexed ),
			'job'       => $active,
			'stalled'   => $stalled,
			'last_job'  => $this->jobs->latest( self::TYPE_INDEX, true ),
			'last_done' => $this->jobs->latest_done( self::TYPE_INDEX, true ),
			'eta'       => $eta,
		);
	}
}
