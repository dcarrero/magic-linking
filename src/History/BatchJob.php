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
	 * Constructor.
	 *
	 * @param Reader        $reader Lectura del historial.
	 * @param Undo          $undo   Deshacer.
	 * @param Redo          $redo   Rehacer.
	 * @param JobRepository $jobs   Procesos.
	 */
	public function __construct(
		private Reader $reader,
		private Undo $undo,
		private Redo $redo,
		private JobRepository $jobs
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

		return null !== $active && ( $active['params']['batch_id'] ?? '' ) === $batch_id;
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

		$params  = $job['params'];
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
				if ( in_array( $result->status, array( UndoResult::RESTORED, UndoResult::LINK_REMOVED, UndoResult::GONE ), true ) ) {
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
}
