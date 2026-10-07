<?php
/**
 * Conservación del historial y purga en el trabajo nocturno (docs/03 §4.6 y §5).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\History;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\Core\Settings;
use MagicLinking\History\BatchId;
use MagicLinking\History\ChangeRepository;
use MagicLinking\History\Retention;
use MagicLinking\History\Undo;
use MagicLinking\Jobs\JobRepository;
use MagicLinking\Jobs\Jobs;

final class RetentionTest extends HistoryTestCase {

	private Retention $retention;

	public function set_up(): void {
		parent::set_up();

		$this->retention = Plugin::container()->get( Retention::class );
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );
	}

	private function settings(): Settings {
		return Plugin::container()->get( Settings::class );
	}

	public function test_the_default_is_90_days_and_only_the_offered_values_are_accepted(): void {
		$this->assertSame( 90, $this->settings()->history_retention_days() );

		foreach ( array( 30, 365, 0, 90 ) as $days ) {
			$this->settings()->update( array( Settings::RETENTION_SETTING => $days ) );
			$this->assertSame( $days, $this->settings()->history_retention_days() );
		}

		$this->settings()->update( array( Settings::RETENTION_SETTING => 30 ) );
		foreach ( array( 7, -1, 'abc', 91, null ) as $invalid ) {
			$this->settings()->update( array( Settings::RETENTION_SETTING => $invalid ) );
			$this->assertSame( 30, $this->settings()->history_retention_days(), 'Un valor no ofrecido no cambia el ajuste.' );
		}

		$this->settings()->update( array( Settings::RETENTION_SETTING => '365' ) );
		$this->assertSame( 365, $this->settings()->history_retention_days(), 'También se aceptan las cadenas numéricas del formulario.' );
	}

	public function test_it_stays_inside_the_single_autoload_option(): void {
		$this->settings()->update( array( Settings::RETENTION_SETTING => 365 ) );

		$stored = get_option( 'magiclinking_settings' );
		$this->assertSame( 365, $stored[ Settings::RETENTION_SETTING ] );
		$this->assertFalse( get_option( 'magiclinking_history_retention_days' ), 'Sin opción propia (regla 8).' );
	}

	public function test_it_deletes_whole_old_groups_and_keeps_the_recent_ones(): void {
		$old    = $this->batch( 2 );
		$recent = $this->batch( 2 );
		$this->age( $old['batch'], 100 );
		$this->age( $recent['batch'], 10 );

		$job = $this->retention->start( 0, false );
		$this->assertNotNull( $job );
		$this->assertSame( 2, $job['total'] );

		$this->retention->run_to_completion( $job['id'] );

		$this->assertSame( 0, $this->rows( $old['batch'] ) );
		$this->assertSame( 2, $this->rows( $recent['batch'] ) );
		$done = Plugin::container()->get( JobRepository::class )->get( $job['id'] );
		$this->assertSame( JobRepository::DONE, $done['status'] );
		$this->assertSame( 2, $done['done'] );
	}

	public function test_a_group_with_recent_activity_is_kept_whole(): void {
		$batch = $this->batch( 2 );
		$this->age( $batch['batch'], 200 );

		// Se deshace uno hoy: el grupo tiene actividad reciente y no caduca.
		Plugin::container()->get( Undo::class )->revert( $batch['changes'][0] );
		$this->assertSame( 3, $this->rows( $batch['batch'] ) );

		$this->assertNull( $this->retention->start( 0, false ), 'No hay nada caducado.' );
		$this->assertSame( 3, $this->rows( $batch['batch'] ) );
	}

	public function test_no_expiry_deletes_nothing(): void {
		$batch = $this->batch( 1 );
		$this->age( $batch['batch'], 5000 );
		$this->settings()->update( array( Settings::RETENTION_SETTING => 0 ) );

		$this->assertNull( $this->retention->cutoff() );
		$this->assertNull( $this->retention->start( 0, false ) );
		$this->assertSame( 1, $this->rows( $batch['batch'] ) );
	}

	public function test_choosing_a_shorter_period_expires_more(): void {
		$batch = $this->batch( 1 );
		$this->age( $batch['batch'], 60 );

		$this->assertNull( $this->retention->start( 0, false ), '60 días caben en los 90 por defecto.' );

		$this->settings()->update( array( Settings::RETENTION_SETTING => 30 ) );
		$job = $this->retention->start( 0, false );
		$this->assertNotNull( $job );
		$this->retention->run_to_completion( $job['id'] );
		$this->assertSame( 0, $this->rows( $batch['batch'] ) );
	}

	public function test_there_is_no_cap_on_the_number_of_actions_and_it_resumes_in_slices(): void {
		global $wpdb;
		$changes = Plugin::container()->get( ChangeRepository::class );
		$groups  = Retention::GROUPS_PER_QUERY * 2 + 30;

		for ( $i = 0; $i < $groups; $i++ ) {
			$changes->record( BatchId::generate( 1_600_000_000_000 + $i ), 1, ChangeRepository::INSERT, '0', 'a', 'b', sha1( (string) $i ), 0 );
		}
		$table = Schema::table( $wpdb->prefix, 'changes' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s', $table, gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->settings()->update( array( Settings::RETENTION_SETTING => 365 ) );

		$job = $this->retention->start( 0, false );
		$this->assertNotNull( $job );
		$this->assertSame( $groups, $job['total'] );

		// Con presupuesto cero cada ejecución borra un solo lote de grupos y deja el resto para la siguiente.
		$runs = 0;
		while ( $this->retention->step( $job['id'], 0.0 ) ) {
			++$runs;
			$partial = Plugin::container()->get( JobRepository::class )->get( $job['id'] );
			$this->assertSame( JobRepository::RUNNING, $partial['status'] );
			$this->assertSame( min( $groups, $runs * Retention::GROUPS_PER_QUERY ), $partial['done'] );
		}

		$this->assertGreaterThanOrEqual( 2, $runs );
		$this->assertSame( 0, $changes->count() );
		$this->assertSame( JobRepository::DONE, Plugin::container()->get( JobRepository::class )->get( $job['id'] )['status'] );
	}

	public function test_the_nightly_task_starts_the_purge_and_the_action_scheduler_handler_runs_it(): void {
		$batch = $this->batch( 1 );
		$this->age( $batch['batch'], 120 );

		do_action( Jobs::HOOK_NIGHTLY );

		$active = Plugin::container()->get( JobRepository::class )->active( Retention::TYPE );
		$this->assertNotNull( $active, 'El trabajo nocturno lanza la purga.' );
		$this->assertTrue( as_has_scheduled_action( Retention::HOOK, array( $active['id'] ), Installer::ACTION_GROUP ) );
		$this->assertTrue( $active['params']['system'] );

		// Dos noches seguidas sin ejecutarse no duplican el proceso.
		do_action( Jobs::HOOK_NIGHTLY );
		$this->assertSame( $active['id'], Plugin::container()->get( JobRepository::class )->active( Retention::TYPE )['id'] );

		$this->retention->run( $active['id'] );
		$this->assertSame( 0, $this->rows( $batch['batch'] ) );
		$this->assertNull( Plugin::container()->get( JobRepository::class )->active( Retention::TYPE ) );
	}

	public function test_a_nightly_task_with_nothing_expired_leaves_no_job(): void {
		$this->batch( 1 );

		do_action( Jobs::HOOK_NIGHTLY );

		$this->assertNull( Plugin::container()->get( JobRepository::class )->latest( Retention::TYPE ) );
	}
}
