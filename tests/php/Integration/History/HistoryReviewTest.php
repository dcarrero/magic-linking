<?php
/**
 * Revisión de código del PR #5: cursor, procesos atascados, permisos, rehacer en clásico y purga.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\History;

use MagicLinking\Cli\Command;
use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\History\BatchId;
use MagicLinking\History\BatchJob;
use MagicLinking\History\ChangeRepository;
use MagicLinking\History\Reader;
use MagicLinking\History\Redo;
use MagicLinking\History\Retention;
use MagicLinking\History\Undo;
use MagicLinking\History\UndoResult;
use MagicLinking\Jobs\JobRepository;
use RuntimeException;
use WP_CLI;
use WP_REST_Request;
use WP_REST_Response;

final class HistoryReviewTest extends HistoryTestCase {

	public function set_up(): void {
		parent::set_up();

		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function request( string $method, string $route, array $params = array(), ?int $user = null ): WP_REST_Response {
		wp_set_current_user( $user ?? $this->admin );
		$request = new WP_REST_Request( $method, '/magic-linking/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function jobs(): JobRepository {
		return Plugin::container()->get( JobRepository::class );
	}

	private function batches(): BatchJob {
		return Plugin::container()->get( BatchJob::class );
	}

	/**
	 * Envejece la última actualización de un proceso.
	 *
	 * @param int $job_id  Proceso.
	 * @param int $seconds Segundos hacia atrás.
	 */
	private function stale( int $job_id, int $seconds = 3600 ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'magiclinking_jobs', array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ), array( 'id' => $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	// 1. Cursor por id con huecos rehechos.

	public function test_a_redone_link_does_not_make_the_background_job_skip_links(): void {
		$batch = $this->batch( 3 );
		Plugin::container()->get( Undo::class )->revert( $batch['changes'][0] );
		$this->assertSame( UndoResult::REDONE, Plugin::container()->get( Redo::class )->redo( $batch['changes'][0] )->status );

		$pending = Plugin::container()->get( Reader::class )->pending( $batch['batch'], false );
		$sorted  = $pending;
		rsort( $sorted );
		$this->assertSame( $sorted, $pending, 'Deshacer va por id, del más nuevo al más antiguo, sea cual sea la posición del hueco.' );

		$job = $this->batches()->start( $batch['batch'], false, $this->admin, false );
		$this->assertNotNull( $job );
		$steps = 0;
		while ( $this->batches()->step( $job['id'], 0.0 ) ) {
			++$steps;
		}

		$this->assertSame( 2, $steps, 'Una tanda por cambio con el presupuesto agotado.' );
		$done = $this->jobs()->get( $job['id'] );
		$this->assertSame( 3, $done['params']['counts']['restored'] );
		foreach ( $batch['posts'] as $post ) {
			$this->assertStringNotContainsString( '<a href=', $this->stored( $post ) );
		}
	}

	public function test_redoing_in_the_background_goes_by_ascending_id_too(): void {
		$batch = $this->batch( 3 );
		$undo  = Plugin::container()->get( Undo::class );
		$redo  = Plugin::container()->get( Redo::class );
		$undo->revert_batch( $batch['batch'] );
		$redo->redo( $batch['changes'][1] );
		$undo->revert( (int) Plugin::container()->get( Reader::class )->changes_of( $batch['batch'], 1, 10 )['items'][1]['id'] );

		$job = $this->batches()->start( $batch['batch'], true, $this->admin, false );
		while ( $this->batches()->step( $job['id'], 0.0 ) ) {
			continue;
		}

		foreach ( $batch['posts'] as $post ) {
			$this->assertStringContainsString( '<a href=', $this->stored( $post ) );
		}
	}

	// 2. Procesos atascados.

	public function test_a_dead_process_is_requeued_and_no_longer_blocks(): void {
		$batch = $this->batch( 2 );
		$job   = $this->batches()->start( $batch['batch'], false, $this->admin, false );
		$this->assertFalse( as_has_scheduled_action( BatchJob::HOOK, array( $job['id'] ), Installer::ACTION_GROUP ) );

		$this->batches()->recover();
		$this->assertFalse( as_has_scheduled_action( BatchJob::HOOK, array( $job['id'] ), Installer::ACTION_GROUP ), 'Reciente: aún no se toca.' );

		$this->stale( $job['id'] );
		$this->batches()->recover();

		$this->assertTrue( as_has_scheduled_action( BatchJob::HOOK, array( $job['id'] ), Installer::ACTION_GROUP ) );
		$fresh = strtotime( $this->jobs()->get( $job['id'] )['updated_at'] . ' UTC' );
		$this->assertGreaterThan( time() - 60, $fresh );
		$this->batches()->run( $job['id'] );
		$this->assertSame( 'done', $this->jobs()->get( $job['id'] )['status'] );
		$this->assertNull( $this->batches()->active() );
	}

	public function test_a_stale_process_with_a_pending_action_is_left_alone(): void {
		$batch = $this->batch( 2 );
		$job   = $this->batches()->start( $batch['batch'], false, $this->admin, true );
		$this->stale( $job['id'] );

		$this->batches()->recover();

		$this->assertLessThan( time() - 600, strtotime( $this->jobs()->get( $job['id'] )['updated_at'] . ' UTC' ), 'Está esperando al cron, no está muerto.' );
	}

	public function test_a_paused_process_is_cancelled_and_the_nightly_task_recovers_the_stuck_ones(): void {
		$batch = $this->batch( 2 );
		$job   = $this->batches()->start( $batch['batch'], false, $this->admin, false );
		$this->jobs()->set_status( $job['id'], JobRepository::PAUSED );

		$this->assertNull( $this->batches()->active(), 'Un proceso en pausa ya no bloquea.' );
		$this->assertSame( 'cancelled', $this->jobs()->get( $job['id'] )['status'] );

		$other = $this->batch( 2 );
		$job2  = $this->batches()->start( $other['batch'], false, $this->admin, false );
		$this->stale( $job2['id'] );
		do_action( \MagicLinking\Jobs\Jobs::HOOK_NIGHTLY );
		$this->assertTrue( as_has_scheduled_action( BatchJob::HOOK, array( $job2['id'] ), Installer::ACTION_GROUP ) );
	}

	public function test_resume_and_cancel_have_their_own_routes_and_the_generic_ones_refuse_other_types(): void {
		$batch  = $this->batch( 2 );
		$job    = $this->batches()->start( $batch['batch'], false, $this->admin, false );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		foreach ( array( 'pause', 'resume', 'cancel' ) as $action ) {
			$this->assertSame( 409, $this->request( 'POST', "/jobs/{$job['id']}/{$action}" )->get_status(), 'Las rutas genéricas son del análisis.' );
		}
		$this->assertSame( 'queued', $this->jobs()->get( $job['id'] )['status'] );

		$this->assertSame( 403, $this->request( 'POST', "/history/jobs/{$job['id']}/cancel", array(), $editor )->get_status(), 'Solo quien lo lanzó o un administrador.' );
		$this->assertSame( 401, $this->request( 'POST', "/history/jobs/{$job['id']}/cancel", array(), 0 )->get_status() );

		$resume = $this->request( 'POST', "/history/jobs/{$job['id']}/resume" );
		$this->assertSame( 200, $resume->get_status() );
		$this->assertTrue( as_has_scheduled_action( BatchJob::HOOK, array( $job['id'] ), Installer::ACTION_GROUP ) );

		$cancel = $this->request( 'POST', "/history/jobs/{$job['id']}/cancel" );
		$this->assertSame( 200, $cancel->get_status() );
		$this->assertSame( 'cancelled', $cancel->get_data()['job']['status'] );
		$this->assertFalse( as_has_scheduled_action( BatchJob::HOOK, array( $job['id'] ), Installer::ACTION_GROUP ) );
		$this->assertSame( 409, $this->request( 'POST', "/history/jobs/{$job['id']}/cancel" )->get_status(), 'Ya terminó.' );

		// Cancelado, el grupo vuelve a poder deshacerse a mano.
		$this->assertSame( 200, $this->request( 'POST', '/undo', array( 'batch_id' => $batch['batch'] ) )->get_status() );
	}

	// 3. Quién puede seguir un proceso.

	public function test_whoever_can_see_the_group_can_follow_its_progress(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$batch  = $this->batch( 2 );
		$job    = $this->batches()->start( $batch['batch'], false, $this->admin, false );

		$this->assertSame( 200, $this->request( 'GET', '/history/jobs/' . $job['id'], array(), $editor )->get_status(), 'El listado se lo enseña, así que puede leerlo.' );
		$this->assertFalse( $this->request( 'GET', '/history/jobs/' . $job['id'], array(), $editor )->get_data()['job']['can_control'] );
		$this->assertSame( 403, $this->request( 'GET', '/history/jobs/' . $job['id'], array(), $author )->get_status(), 'Quien no ve el grupo, no ve el proceso.' );
		$this->assertTrue( $this->request( 'GET', '/history/jobs/' . $job['id'] )->get_data()['job']['can_control'] );
	}

	// 4. Lote con proceso activo.

	public function test_a_batch_with_a_background_process_cannot_be_changed_by_hand(): void {
		$batch = $this->batch( 2 );
		$this->batches()->start( $batch['batch'], false, $this->admin, false );

		$this->assertSame( 409, $this->request( 'POST', '/undo', array( 'change_id' => $batch['changes'][0] ) )->get_status() );
		$this->assertSame( 409, $this->request( 'POST', '/redo', array( 'change_id' => $batch['changes'][0] ) )->get_status() );
		$this->assertSame( 409, $this->request( 'POST', '/undo', array( 'batch_id' => $batch['batch'] ) )->get_status() );
		$this->assertSame( 409, $this->request( 'POST', '/redo', array( 'batch_id' => $batch['batch'] ) )->get_status() );
		foreach ( $batch['posts'] as $post ) {
			$this->assertStringContainsString( '<a href=', $this->stored( $post ) );
		}

		// Otro lote no se ve afectado.
		$other = $this->link();
		$this->assertSame( 200, $this->request( 'POST', '/undo', array( 'change_id' => $other['change'] ) )->get_status() );
	}

	public function test_the_command_line_respects_the_background_process_too(): void {
		$batch = $this->batch( 2 );
		$this->batches()->start( $batch['batch'], false, $this->admin, false );
		$c = Plugin::container();
		WP_CLI::reset();
		$command = new Command( $c->get( \MagicLinking\Jobs\Jobs::class ), $c->get( \MagicLinking\Graph\ReportRepository::class ), $c->get( \MagicLinking\Graph\BrokenRepository::class ), $c->get( Reader::class ), $c->get( Undo::class ), $c->get( Retention::class ), $c->get( ChangeRepository::class ), $c->get( Redo::class ), $c->get( BatchJob::class ) );

		try {
			$command->history( array( 'undo', $batch['batch'] ), array() );
			$this->fail( 'Debía negarse.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'processed in the background', $e->getMessage() );
		}
		$this->assertStringContainsString( '<a href=', $this->stored( $batch['posts'][0] ) );

		// 10. Cursor de la lista.
		$more = $this->batch( 3, BatchId::generate( 4_000_000_000_000 ) );
		WP_CLI::reset();
		$command->history( array( 'list' ), array( 'per-page' => 1 ) );
		$this->assertStringContainsString( $more['batch'], WP_CLI::$lines[0] );
		$this->assertStringContainsString( '--before=' . $more['batch'], end( WP_CLI::$lines ) );
		WP_CLI::reset();
		$command->history( array( 'list' ), array( 'before' => $more['batch'] ) );
		$this->assertStringContainsString( $batch['batch'], WP_CLI::$lines[0] );
		$this->assertStringNotContainsString( '--before=', end( WP_CLI::$lines ) );
	}

	// 5. Rehacer localiza por contenido.

	public function test_redo_in_the_classic_editor_survives_an_edit_before_the_paragraph(): void {
		$post   = $this->post( array( 'post_content' => "Introducción.\n\nCompra aire acondicionado ya.\n\nFinal." ) );
		$linked = $this->link( $post );
		$with   = $this->stored( $post );
		$this->assertStringStartsNotWith( '<!--', $with );
		Plugin::container()->get( Undo::class )->revert( $linked['change'] );

		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => wp_slash( "Un texto nuevo y bastante más largo al principio.\n\n" . $this->stored( $post ) ),
			)
		);
		$result = Plugin::container()->get( Redo::class )->redo( $linked['change'] );

		$this->assertSame( UndoResult::REDONE, $result->status );
		$this->assertSame( "Un texto nuevo y bastante más largo al principio.\n\n" . $with, $this->stored( $post ) );
	}

	public function test_redo_survives_a_block_inserted_before_and_refuses_when_the_paragraph_repeats_elsewhere(): void {
		$linked = $this->link();
		$with   = $this->stored( $linked['post'] );
		Plugin::container()->get( Undo::class )->revert( $linked['change'] );

		wp_update_post(
			array(
				'ID'           => $linked['post'],
				'post_content' => wp_slash( $this->p( 'Primero.' ) . "\n\n" . $this->stored( $linked['post'] ) ),
			)
		);
		$this->assertSame( UndoResult::REDONE, Plugin::container()->get( Redo::class )->redo( $linked['change'] )->status );
		$this->assertSame( $this->p( 'Primero.' ) . "\n\n" . $with, $this->stored( $linked['post'] ) );

		// El mismo párrafo dos veces y desplazado: no se sabe cuál es, no se toca.
		$twin = $this->link( $this->post() );
		Plugin::container()->get( Undo::class )->revert( $twin['change'] );
		$original = $this->p( 'Compra aire acondicionado ya.' );
		$doubled  = $this->p( 'Antes.' ) . "\n\n" . $original . "\n\n" . $original;
		wp_update_post(
			array(
				'ID'           => $twin['post'],
				'post_content' => wp_slash( $doubled ),
			)
		);
		$this->assertSame( UndoResult::MANUAL, Plugin::container()->get( Redo::class )->redo( $twin['change'] )->status );
		$this->assertSame( $doubled, $this->stored( $twin['post'] ) );
	}

	// 6. La purga cuenta el último deshacer.

	public function test_an_undo_that_wrote_nothing_still_counts_as_recent_activity(): void {
		global $wpdb;
		$batch = $this->batch( 1 );
		$this->age( $batch['batch'], 120 );
		$wpdb->update( $wpdb->prefix . 'magiclinking_changes', array( 'undone_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'batch_id' => $batch['batch'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$retention = Plugin::container()->get( Retention::class );
		$this->assertNull( $retention->start( 0, false ), 'Deshecho ayer: no ha caducado.' );
		$this->assertSame( 1, $this->rows( $batch['batch'] ) );

		$wpdb->update( $wpdb->prefix . 'magiclinking_changes', array( 'undone_at' => gmdate( 'Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS ) ), array( 'batch_id' => $batch['batch'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$job = $retention->start( 0, false );
		$this->assertNotNull( $job );
		$retention->run_to_completion( $job['id'] );
		$this->assertSame( 0, $this->rows( $batch['batch'] ) );
	}

	// 8 y 9. Una sola identidad de hueco y una lectura por lote y petición.

	public function test_the_light_read_of_a_batch_is_made_once_until_something_is_written(): void {
		global $wpdb;
		$batch   = $this->batch( 2 );
		$changes = Plugin::container()->get( ChangeRepository::class );

		$changes->inserts_of( array( $batch['batch'] ) );
		$before = $wpdb->num_queries;
		$again  = $changes->inserts_of( array( $batch['batch'] ) );
		$this->assertSame( $before, $wpdb->num_queries, 'La segunda lectura sale de la memoria de la petición.' );
		$this->assertCount( 2, $again[ $batch['batch'] ] );

		Plugin::container()->get( Undo::class )->revert( $batch['changes'][0] );
		$fresh = $changes->inserts_of( array( $batch['batch'] ) );
		$this->assertNotNull( $fresh[ $batch['batch'] ][0]['undone_at'], 'Tras escribir se vuelve a leer.' );
	}

	public function test_a_request_reads_the_batch_a_bounded_number_of_times(): void {
		global $wpdb;
		$batch = $this->batch( 3 );
		$this->request( 'GET', '/history' ); // calienta cachés de usuarios y entradas.

		$wpdb->queries = array();
		$before        = $wpdb->num_queries;
		$this->request( 'POST', '/undo', array( 'batch_id' => $batch['batch'] ) );
		$queries = $wpdb->num_queries - $before;

		$this->assertLessThan( 140, $queries, 'Un deshacer de 3 enlaces no multiplica las lecturas del lote.' );
	}
}
