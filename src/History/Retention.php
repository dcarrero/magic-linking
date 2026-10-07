<?php
/**
 * Conservación del historial: purga de los grupos caducados.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\History;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\Jobs\JobRepository;
use MagicLinking\Jobs\Jobs;

/**
 * Borra de `magiclinking_changes` los grupos cuya última actividad es anterior a la conservación que eligió
 * el usuario (30, 90, 365 días o sin caducidad; docs/03 §4.6). No hay tope por número de acciones: solo
 * caduca lo antiguo, y un grupo se borra entero o no se toca.
 *
 * Cuelga del trabajo nocturno (`Jobs::HOOK_NIGHTLY`, docs/03 §5). Trabaja como cualquier proceso largo
 * (regla 10): un proceso `purge` en `magiclinking_jobs`, por lotes de grupos con Action Scheduler y con
 * presupuesto de tiempo por ejecución. No guarda cursor porque no lo necesita: cada vuelta borra lo que sigue
 * caducado, así que una interrupción se reanuda sola. Hay una orden equivalente en WP-CLI.
 */
final class Retention implements Module {

	public const TYPE = 'purge';

	public const HOOK = 'magiclinking_purge_history';

	/**
	 * Grupos que se borran por consulta.
	 */
	public const GROUPS_PER_QUERY = 100;

	/**
	 * Segundos de trabajo por ejecución de Action Scheduler.
	 */
	public const TIME_BUDGET = 15;

	/**
	 * Constructor.
	 *
	 * @param ChangeRepository $changes  Historial.
	 * @param JobRepository    $jobs     Procesos.
	 * @param Settings         $settings Ajustes.
	 */
	public function __construct(
		private ChangeRepository $changes,
		private JobRepository $jobs,
		private Settings $settings
	) {
	}

	/**
	 * Engancha el trabajo nocturno y el manejador del proceso.
	 */
	public function register(): void {
		add_action( Jobs::HOOK_NIGHTLY, array( $this, 'on_nightly' ), 20 );
		add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Fecha límite de la conservación actual.
	 *
	 * @return string|null `Y-m-d H:i:s` en UTC, o null si el historial no caduca.
	 */
	public function cutoff(): ?string {
		$days = $this->settings->history_retention_days();

		return $days > 0 ? gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) : null;
	}

	/**
	 * Trabajo nocturno: lanza la purga si hay algo caducado.
	 */
	public function on_nightly(): void {
		$this->start( 0, true );
	}

	/**
	 * Lanza la purga, o devuelve la que está en marcha.
	 *
	 * @param int  $user_id Quien la lanza (0 = sistema).
	 * @param bool $enqueue Programar la primera ejecución en Action Scheduler (false para hacerla a mano, como WP-CLI).
	 *
	 * @return array{id: int, type: string, status: string, total: int, done: int, params: array<string, mixed>, error: string, created_by: int, created_at: string, updated_at: string}|null Null si el historial no caduca o no hay nada caducado.
	 */
	public function start( int $user_id = 0, bool $enqueue = true ): ?array {
		$cutoff = $this->cutoff();
		if ( null === $cutoff ) {
			return null;
		}

		$active = $this->jobs->active( self::TYPE );
		if ( null !== $active ) {
			// Una purga que se quedó sin ejecutarse (sin cron durante días) se vuelve a programar.
			if ( $enqueue ) {
				$this->enqueue();
			}

			return $active;
		}

		$total = $this->changes->count_expired( $cutoff );
		if ( 0 === $total ) {
			return null;
		}

		$id = $this->jobs->create( self::TYPE, $total, array( 'system' => 0 === $user_id ), $user_id );
		if ( $enqueue ) {
			$this->enqueue();
		}

		return $this->jobs->get( $id );
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
	 * Borra durante un presupuesto de tiempo.
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

		$budget ??= (float) self::TIME_BUDGET;
		$stop_at = microtime( true ) + $budget;
		$done    = $job['done'];
		$total   = $job['total'];
		$cutoff  = $this->cutoff();
		$deleted = 0;

		while ( null !== $cutoff ) {
			$deleted = $this->changes->purge( $cutoff, self::GROUPS_PER_QUERY );
			$done   += $deleted;
			$total   = max( $total, $done );
			$this->jobs->set_progress( $job_id, $done, $total, $job['params'] );

			if ( 0 === $deleted || microtime( true ) >= $stop_at ) {
				break;
			}
		}

		if ( null === $cutoff || 0 === $deleted ) {
			$this->jobs->set_status( $job_id, JobRepository::DONE );

			return false;
		}

		return true;
	}

	/**
	 * Hace toda la purga en la misma llamada (WP-CLI).
	 *
	 * @param int           $job_id   ID del proceso.
	 * @param callable|null $progress Se llama con las filas borradas hasta ahora.
	 */
	public function run_to_completion( int $job_id, ?callable $progress = null ): void {
		while ( $this->step( $job_id, 3600.0 ) ) {
			$job = $this->jobs->get( $job_id );
			if ( null !== $progress && null !== $job ) {
				$progress( $job['done'] );
			}
		}
	}

	/**
	 * Programa una ejecución, una sola a la vez.
	 */
	private function enqueue(): void {
		as_enqueue_async_action( self::HOOK, array( $this->jobs->active( self::TYPE )['id'] ?? 0 ), Installer::ACTION_GROUP, true );
	}
}
