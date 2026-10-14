<?php
/**
 * Deshacer o rehacer un grupo grande fuera de la petición.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\History;

use MagicLinking\Content\PostWriter;
use MagicLinking\Core\Installer;
use MagicLinking\Core\Module;
use MagicLinking\Jobs\JobRepository;
use MagicLinking\Jobs\Jobs;
use Throwable;
use WP_Post;

/**
 * Un grupo de más de {@see self::sync_limit()} cambios no se deshace dentro de la petición REST (cada cambio
 * toma el candado de su entrada, verifica, guarda y reindexa): se encola como un proceso `undo` en
 * `magiclinking_jobs`, que Action Scheduler ejecuta por tandas con presupuesto de tiempo (regla 10).
 *
 * El proceso lleva un cursor (el último cambio tratado), así que se reanuda donde se quedó, y cada cambio
 * pasa por el mismo {@see Undo} o {@see Redo} que en la petición. Hay un único proceso de este tipo a la vez
 * por sitio (docs/03 §5). Se ejecuta con los permisos de quien lo pidió, no con los del cron.
 *
 * Lo que no se puede deshacer solo (entrada editada después, abierta en el editor…) queda anotado en
 * `params.issues` (hasta {@see self::MAX_ISSUES}) para que la pantalla lo muestre con su enlace al editor.
 */
final class BatchJob implements Module {

	public const TYPE = 'undo';

	public const HOOK = 'magiclinking_run_undo';

	/**
	 * Cambios a partir de los cuales el grupo se procesa en segundo plano (filtro `magiclinking_undo_sync_limit`).
	 */
	public const SYNC_LIMIT = 25;

	/**
	 * Segundos de trabajo por ejecución de Action Scheduler.
	 */
	public const TIME_BUDGET = 15;

	/**
	 * Incidencias que se guardan con el proceso (el resto solo se cuenta).
	 */
	public const MAX_ISSUES = 100;

	/**
	 * Cambios de una entrada que se tratan bajo un candado y con una reindexación (procesos de una entrada).
	 */
	public const POST_CHUNK = 10;

	/**
	 * Constructor.
	 *
	 * @param Reader           $reader Lectura del historial.
	 * @param Undo             $undo   Deshacer.
	 * @param Redo             $redo   Rehacer.
	 * @param JobRepository    $jobs   Procesos.
	 * @param ChangeRepository $changes Historial.
	 */
	public function __construct(
		private Reader $reader,
		private Undo $undo,
		private Redo $redo,
		private JobRepository $jobs,
		private ChangeRepository $changes
	) {
	}

	/**
	 * Engancha el manejador del proceso.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
		add_action( Jobs::HOOK_NIGHTLY, array( $this, 'recover' ), 30 );
	}

	/**
	 * Minutos sin avanzar a partir de los cuales un proceso sin acción pendiente se da por muerto.
	 */
	public const STALL_MINUTES = 10;

	/**
	 * Cambios por encima de los cuales se va a segundo plano.
	 */
	public static function sync_limit(): int {
		/**
		 * Cambios de un grupo a partir de los cuales deshacer o rehacer se hace en segundo plano.
		 *
		 * @param int $limit Número de cambios (25 por defecto).
		 */
		return max( 1, (int) apply_filters( 'magiclinking_undo_sync_limit', self::SYNC_LIMIT ) );
	}

	/**
	 * Lanza el proceso de un grupo. Quien llama ya ha comprobado los permisos sobre todas sus entradas.
	 *
	 * @param string $batch_id Lote.
	 * @param bool   $redo     true = rehacer los deshechos; false = deshacer los activos.
	 * @param int    $user_id  Quien lo pide: con sus permisos se hace todo.
	 * @param bool   $enqueue  Programar la primera ejecución en Action Scheduler.
	 *
	 * @return array{id: int, type: string, status: string, total: int, done: int, params: array<string, mixed>, error: string, created_by: int, created_at: string, updated_at: string}|null Null si no hay nada que tratar.
	 */
	public function start( string $batch_id, bool $redo, int $user_id, bool $enqueue = true ): ?array {
		$total = count( $this->reader->pending( $batch_id, $redo ) );
		if ( 0 === $total ) {
			return null;
		}

		$id = $this->jobs->create(
			self::TYPE,
			$total,
			array(
				'batch_id' => $batch_id,
				'mode'     => $redo ? 'redo' : 'undo',
				'user_id'  => $user_id,
				'cursor'   => 0,
				'counts'   => array(),
				'issues'   => array(),
				'undone'   => 0,
			),
			$user_id
		);

		if ( $enqueue ) {
			as_enqueue_async_action( self::HOOK, array( $id ), Installer::ACTION_GROUP, true );
		}

		return $this->jobs->get( $id );
	}

	/**
	 * Lanza el proceso de varios cambios de **una** entrada («Deshacer todos» por encima del límite síncrono, F1-36).
	 * Guarda la lista de cambios tal como se confirmó, así que lo que se trata coincide con lo contado. Quien llama ya
	 * ha comprobado el permiso sobre la entrada.
	 *
	 * @param int   $post_id Entrada.
	 * @param array $ids     Cambios de esa entrada, en el orden en que se tratan.
	 * @param bool  $redo    true = rehacer; false = deshacer.
	 * @param int   $user_id Quien lo pide.
	 * @param bool  $enqueue Programar la primera ejecución.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @return array{id: int, type: string, status: string, total: int, done: int, params: array<string, mixed>, error: string, created_by: int, created_at: string, updated_at: string}|null Null si no hay nada que tratar.
	 */
	public function start_for_post( int $post_id, array $ids, bool $redo, int $user_id, bool $enqueue = true ): ?array {
		if ( array() === $ids ) {
			return null;
		}

		$id = $this->jobs->create(
			self::TYPE,
			count( $ids ),
			array(
				'batch_id' => '',
				'post_id'  => $post_id,
				'ids'      => $ids,
				'mode'     => $redo ? 'redo' : 'undo',
				'user_id'  => $user_id,
				'cursor'   => 0,
				'counts'   => array(),
				'issues'   => array(),
				'undone'   => 0,
			),
			$user_id
		);

		if ( $enqueue ) {
			as_enqueue_async_action( self::HOOK, array( $id ), Installer::ACTION_GROUP, true );
		}

		return $this->jobs->get( $id );
	}

	/**
	 * Si una entrada tiene ahora mismo un proceso en segundo plano propio.
	 *
	 * @param int $post_id Entrada.
	 */
	public function is_busy_post( int $post_id ): bool {
		$active = $this->active();

		return null !== $active && (int) ( $active['params']['post_id'] ?? 0 ) === $post_id;
	}

	/**
	 * Proceso sin terminar de este tipo (antes de devolverlo se recupera el que se haya quedado atascado).
	 *
	 * @return array{id: int, type: string, status: string, total: int, done: int, params: array<string, mixed>, error: string, created_by: int, created_at: string, updated_at: string}|null
	 */
	public function active(): ?array {
		$this->recover();

		return $this->jobs->active( self::TYPE );
	}

	/**
	 * Si un grupo tiene ahora mismo un proceso en segundo plano (no se puede deshacer ni rehacer a mano).
	 *
	 * @param string $batch_id Lote.
	 */
	public function is_busy( string $batch_id ): bool {
		$active = $this->active();
		if ( null === $active ) {
			return false;
		}
		if ( ( $active['params']['batch_id'] ?? '' ) === $batch_id ) {
			return true;
		}

		// Un proceso de una entrada también ocupa los lotes que la tocan.
		$post_id = (int) ( $active['params']['post_id'] ?? 0 );

		return $post_id > 0 && in_array( $post_id, $this->reader->posts_of_batch( $batch_id ), true );
	}

	/**
	 * Si un cambio no se puede deshacer ni rehacer a mano ahora: su lote o su entrada tienen un proceso en segundo plano.
	 *
	 * @param array $change Fila del historial (basta `post_id` y `batch_id`).
	 *
	 * @phpstan-param array{post_id: int, batch_id: string} $change
	 */
	public function is_busy_for_change( array $change ): bool {
		return $this->is_busy_for_post( $change['post_id'], array( $change['batch_id'] ) );
	}

	/**
	 * Si una entrada no se puede tocar a mano ahora: tiene un proceso propio, o lo tiene alguno de los lotes dados.
	 *
	 * @param int   $post_id   Entrada.
	 * @param array $batch_ids Lotes de los cambios que se van a tratar.
	 *
	 * @phpstan-param list<string> $batch_ids
	 */
	public function is_busy_for_post( int $post_id, array $batch_ids = array() ): bool {
		$active = $this->active();
		if ( null === $active ) {
			return false;
		}
		if ( (int) ( $active['params']['post_id'] ?? 0 ) === $post_id ) {
			return true;
		}

		$batch = (string) ( $active['params']['batch_id'] ?? '' );

		return '' !== $batch && in_array( $batch, $batch_ids, true );
	}

	/**
	 * Recupera un proceso atascado para que no bloquee para siempre los deshacer grandes: uno en pausa (este
	 * proceso no se pausa) se cancela, y uno en cola o en marcha que lleva {@see self::STALL_MINUTES} minutos sin
	 * avanzar y sin acción pendiente en Action Scheduler (murió, o se borró la acción) se vuelve a programar.
	 */
	public function recover(): void {
		$job = $this->jobs->active( self::TYPE );
		if ( null === $job ) {
			return;
		}

		if ( JobRepository::PAUSED === $job['status'] ) {
			$this->cancel( $job['id'] );
			return;
		}

		$updated = strtotime( $job['updated_at'] . ' UTC' );
		if ( false === $updated || ( time() - $updated ) <= self::STALL_MINUTES * MINUTE_IN_SECONDS ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK, array( $job['id'] ), Installer::ACTION_GROUP ) ) {
			$this->resume( $job['id'] );
		}
	}

	/**
	 * Vuelve a programar un proceso sin terminar (continúa por el cursor).
	 *
	 * @param int $job_id Proceso.
	 *
	 * @return bool False si no es de este tipo o ya terminó.
	 */
	public function resume( int $job_id ): bool {
		$job = $this->jobs->get( $job_id );
		if ( null === $job || self::TYPE !== $job['type'] || ! in_array( $job['status'], JobRepository::ACTIVE, true ) ) {
			return false;
		}

		$this->jobs->set_status( $job_id, JobRepository::QUEUED );
		as_enqueue_async_action( self::HOOK, array( $job_id ), Installer::ACTION_GROUP, true );

		return true;
	}

	/**
	 * Cancela un proceso sin terminar: lo ya tratado se queda tratado y el grupo vuelve a poder deshacerse a mano.
	 *
	 * @param int $job_id Proceso.
	 *
	 * @return bool False si no es de este tipo o ya terminó.
	 */
	public function cancel( int $job_id ): bool {
		$job = $this->jobs->get( $job_id );
		if ( null === $job || self::TYPE !== $job['type'] || ! in_array( $job['status'], JobRepository::ACTIVE, true ) ) {
			return false;
		}

		as_unschedule_all_actions( self::HOOK, array( $job_id ), Installer::ACTION_GROUP );
		$this->jobs->set_status( $job_id, JobRepository::CANCELLED );

		return true;
	}

	/**
	 * Manejador de Action Scheduler.
	 *
	 * @param int $job_id Proceso.
	 */
	public function run( int $job_id ): void {
		if ( $this->step( $job_id ) ) {
			as_enqueue_async_action( self::HOOK, array( $job_id ), Installer::ACTION_GROUP, false );
		}
	}

	/**
	 * Trata cambios durante un presupuesto de tiempo.
	 *
	 * @param int        $job_id ID del proceso.
	 * @param float|null $budget Segundos de trabajo (por defecto TIME_BUDGET).
	 *
	 * @return bool Si queda trabajo (hay que programar otra ejecución).
	 */
	public function step( int $job_id, ?float $budget = null ): bool {
		$job = $this->jobs->get( $job_id );
		if ( null === $job || self::TYPE !== $job['type'] || ! in_array( $job['status'], array( JobRepository::QUEUED, JobRepository::RUNNING ), true ) ) {
			return false;
		}

		$this->jobs->set_status( $job_id, JobRepository::RUNNING );

		$params = $job['params'];
		if ( (int) ( $params['post_id'] ?? 0 ) > 0 ) {
			return $this->step_post( $job, $budget );
		}

		$batch   = (string) ( $params['batch_id'] ?? '' );
		$redo    = 'redo' === ( $params['mode'] ?? '' );
		$user_id = (int) ( $params['user_id'] ?? 0 );
		$cursor  = (int) ( $params['cursor'] ?? 0 );
		$counts  = is_array( $params['counts'] ?? null ) ? $params['counts'] : array();
		$issues  = is_array( $params['issues'] ?? null ) ? $params['issues'] : array();
		$undone  = (int) ( $params['undone'] ?? 0 );
		$done    = $job['done'];
		$stop_at = microtime( true ) + ( $budget ?? (float) self::TIME_BUDGET );
		$last    = null;

		// Los cambios que quedan: deshacer va del último al primero (cursor descendente), rehacer del primero al último.
		$pending = array_values(
			array_filter(
				$this->reader->pending( $batch, $redo ),
				static fn( int $id ): bool => $redo ? $id > $cursor : ( 0 === $cursor || $id < $cursor )
			)
		);

		// Con los permisos de quien lo pidió (kses, capacidades); se restablece el usuario al terminar.
		$previous = get_current_user_id();
		wp_set_current_user( $user_id );

		try {
			foreach ( $pending as $change_id ) {
				try {
					$result = $redo ? $this->redo->redo( $change_id, $user_id ) : $this->undo->revert( $change_id, $user_id );
				} catch ( Throwable $e ) {
					// Un fallo inesperado de WordPress o de otro complemento no detiene el resto del grupo.
					PostWriter::log( sprintf( 'Fallo al tratar el cambio %d en segundo plano: %s', $change_id, $e->getMessage() ) );
					$result = new UndoResult( $change_id, 0, UndoResult::FAILED, __( 'WordPress could not save the post; nothing was changed.', 'magic-linking' ) );
				}

				$counts[ $result->status ] = (int) ( $counts[ $result->status ] ?? 0 ) + 1;
				if ( in_array( $result->status, array( UndoResult::RESTORED, UndoResult::LINK_REMOVED, UndoResult::FIX_REVERTED, UndoResult::GONE ), true ) ) {
					++$undone;
				}
				if ( in_array( $result->status, array( UndoResult::MANUAL, UndoResult::FAILED ), true ) ) {
					if ( count( $issues ) < self::MAX_ISSUES ) {
						$issues[] = array(
							'change_id'  => $result->change_id,
							'post_id'    => $result->post_id,
							'post_title' => get_post( $result->post_id ) instanceof WP_Post ? get_the_title( $result->post_id ) : null,
							'status'     => $result->status,
							'message'    => $result->message,
							'edit_url'   => $result->edit_url,
						);
					}
				}

				++$done;
				$cursor = $change_id;
				$last   = $change_id;

				if ( microtime( true ) >= $stop_at ) {
					break;
				}
			}//end foreach
		} finally {
			wp_set_current_user( $previous );
		}//end try

		$params['cursor'] = $cursor;
		$params['counts'] = $counts;
		$params['issues'] = $issues;
		$params['undone'] = $undone;

		$finished = null === $last || end( $pending ) === $last;
		$this->jobs->set_progress( $job_id, $done, max( $job['total'], $done ), $params );

		if ( $finished ) {
			$this->jobs->set_status( $job_id, JobRepository::DONE );
			if ( ! $redo && $undone > 0 ) {
				// Mismo aviso que al deshacer un lote en la petición: una sola vez, con el total.
				do_action( 'magiclinking_batch_undone', $batch, $undone );
			}
		}

		return ! $finished;
	}

	/**
	 * Trata los cambios de un proceso de **una** entrada, en tandas de {@see self::POST_CHUNK} bajo un único candado y
	 * con una sola reindexación por tanda.
	 *
	 * @param array<string, mixed> $job    Proceso.
	 * @param float|null           $budget Segundos de trabajo.
	 *
	 * @return bool Si queda trabajo.
	 */
	private function step_post( array $job, ?float $budget ): bool {
		$params  = $job['params'];
		$post_id = (int) $params['post_id'];
		$redo    = 'redo' === ( $params['mode'] ?? '' );
		$user_id = (int) ( $params['user_id'] ?? 0 );
		$cursor  = (int) ( $params['cursor'] ?? 0 );
		$counts  = is_array( $params['counts'] ?? null ) ? $params['counts'] : array();
		$issues  = is_array( $params['issues'] ?? null ) ? $params['issues'] : array();
		$undone  = (int) ( $params['undone'] ?? 0 );
		$batches = is_array( $params['batches'] ?? null ) ? $params['batches'] : array();
		$done    = (int) $job['done'];
		$stop_at = microtime( true ) + ( $budget ?? (float) self::TIME_BUDGET );
		$ids     = array_map( 'intval', is_array( $params['ids'] ?? null ) ? $params['ids'] : array() );
		$pending = array_values( array_filter( $ids, static fn( int $id ): bool => $redo ? $id > $cursor : ( 0 === $cursor || $id < $cursor ) ) );

		$previous = get_current_user_id();
		wp_set_current_user( $user_id );

		try {
			foreach ( array_chunk( $pending, self::POST_CHUNK ) as $chunk ) {
				$light = $redo ? array() : $this->changes->light_of( $chunk );
				try {
					// revert_post y redo_post ya devuelven un resultado por cambio, también cuando algo salta a medias.
					$results = $redo ? $this->redo->redo_post( $post_id, $chunk, $user_id ) : $this->undo->revert_post( $post_id, $chunk, $user_id, false );
				} catch ( Throwable $e ) {
					PostWriter::log( sprintf( 'Fallo al tratar cambios de la entrada %d en segundo plano: %s', $post_id, $e->getMessage() ) );
					$results = array_map( static fn( int $id ): UndoResult => new UndoResult( $id, $post_id, UndoResult::FAILED, __( 'WordPress could not save the post; nothing was changed.', 'magic-linking' ) ), $chunk );
				}

				foreach ( $results as $result ) {
					$counts[ $result->status ] = (int) ( $counts[ $result->status ] ?? 0 ) + 1;
					if ( in_array( $result->status, array( UndoResult::RESTORED, UndoResult::LINK_REMOVED, UndoResult::FIX_REVERTED, UndoResult::GONE ), true ) ) {
						++$undone;
						$batch             = $light[ $result->change_id ]['batch_id'] ?? '';
						$batches[ $batch ] = ( $batches[ $batch ] ?? 0 ) + 1;
					}
					if ( in_array( $result->status, array( UndoResult::MANUAL, UndoResult::FAILED ), true ) && count( $issues ) < self::MAX_ISSUES ) {
						$issues[] = array(
							'change_id'  => $result->change_id,
							'post_id'    => $result->post_id,
							'post_title' => get_post( $result->post_id ) instanceof WP_Post ? get_the_title( $result->post_id ) : null,
							'status'     => $result->status,
							'message'    => $result->message,
							'edit_url'   => $result->edit_url,
						);
					}
					++$done;
				}

				$cursor = (int) end( $chunk );

				if ( microtime( true ) >= $stop_at ) {
					break;
				}
			}//end foreach
		} finally {
			wp_set_current_user( $previous );
		}//end try

		$params['cursor']  = $cursor;
		$params['counts']  = $counts;
		$params['issues']  = $issues;
		$params['undone']  = $undone;
		$params['batches'] = $batches;

		$finished = array() === $pending || (int) end( $pending ) === $cursor;
		$this->jobs->set_progress( $job['id'], $done, max( (int) $job['total'], $done ), $params );
		if ( $finished ) {
			$this->jobs->set_status( $job['id'], JobRepository::DONE );
			if ( ! $redo ) {
				// Una sola vez por lote afectado y con el total, como al deshacer un lote en la petición.
				foreach ( $batches as $batch => $count ) {
					if ( '' !== (string) $batch && $count > 0 ) {
						do_action( 'magiclinking_batch_undone', (string) $batch, (int) $count );
					}
				}
			}
		}

		return ! $finished;
	}
}
