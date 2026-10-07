<?php
/**
 * API REST del historial: listar, deshacer, rehacer, permisos y deshacer en segundo plano.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\History;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\History\BatchId;
use MagicLinking\History\BatchJob;
use MagicLinking\Jobs\JobRepository;
use WP_REST_Request;
use WP_REST_Response;

final class HistoryRestTest extends HistoryTestCase {

	private const BASE = '/magic-linking/v1';

	private int $editor = 0;

	private int $author = 0;

	private int $subscriber = 0;

	public function set_up(): void {
		parent::set_up();

		$this->editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->author     = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'magiclinking_undo_sync_limit' );
		parent::tear_down();
	}

	private function request( string $method, string $route, array $params = array(), ?int $user = null ): WP_REST_Response {
		wp_set_current_user( $user ?? $this->admin );
		$request = new WP_REST_Request( $method, self::BASE . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function jobs(): JobRepository {
		return Plugin::container()->get( JobRepository::class );
	}

	// ------------------------------------------------------------------ Permisos.

	public function test_every_route_needs_a_login_and_the_edit_posts_capability(): void {
		$batch  = $this->batch( 1 );
		$routes = array(
			array( 'GET', '/history', array() ),
			array( 'GET', '/history/' . $batch['batch'], array() ),
			array( 'GET', '/history/' . $batch['batch'] . '/changes', array() ),
			array( 'GET', '/history/jobs/1', array() ),
			array( 'POST', '/undo', array( 'batch_id' => $batch['batch'] ) ),
			array( 'POST', '/redo', array( 'change_id' => $batch['changes'][0] ) ),
		);

		foreach ( $routes as [ $method, $route, $params ] ) {
			$anonymous = $this->request( $method, $route, $params, 0 );
			$this->assertSame( 401, $anonymous->get_status(), "Sin sesión: {$method} {$route}" );

			$subscriber = $this->request( $method, $route, $params, $this->subscriber );
			$this->assertSame( 403, $subscriber->get_status(), "Suscriptor: {$method} {$route}" );
		}

		$this->assertSame( 'Origen', substr( (string) get_the_title( $batch['posts'][0] ), 0, 6 ) );
		$this->assertStringContainsString( '<a href=', $this->stored( $batch['posts'][0] ), 'Nada se ha tocado.' );
	}

	public function test_an_author_cannot_see_or_undo_other_peoples_entries(): void {
		$mine   = $this->link( $this->post( array( 'post_author' => $this->author ) ) );
		$theirs = $this->link( $this->post( array( 'post_author' => $this->admin ) ) );

		// Ve y deshace lo suyo.
		$list = $this->request( 'GET', '/history', array(), $this->author );
		$this->assertSame( array( $mine['batch'] ), array_column( $list->get_data()['items'], 'batch_id' ) );

		// Lo ajeno: ni lo ve ni lo deshace, ni por cambio ni por grupo.
		$this->assertSame( 404, $this->request( 'GET', '/history/' . $theirs['batch'], array(), $this->author )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/history/' . $theirs['batch'] . '/changes', array(), $this->author )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/undo', array( 'batch_id' => $theirs['batch'] ), $this->author )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/undo', array( 'change_id' => $theirs['change'] ), $this->author )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/redo', array( 'change_id' => $theirs['change'] ), $this->author )->get_status() );
		$this->assertStringContainsString( '<a href=', $this->stored( $theirs['post'] ), 'El contenido ajeno sigue como estaba.' );

		$own = $this->request( 'POST', '/undo', array( 'batch_id' => $mine['batch'] ), $this->author );
		$this->assertSame( 200, $own->get_status() );
		$this->assertSame( 'restored', $own->get_data()['results'][0]['status'] );
	}

	public function test_a_batch_with_an_entry_the_user_cannot_edit_is_refused_as_a_whole(): void {
		$batch = BatchId::generate();
		$mine  = $this->link( $this->post( array( 'post_author' => $this->author ) ), $batch );
		$other = $this->link( $this->post( array( 'post_author' => $this->admin ) ), $batch );

		$response = $this->request( 'POST', '/undo', array( 'batch_id' => $batch ), $this->author );

		$this->assertSame( 404, $response->get_status(), 'El grupo no es visible para quien no puede editarlo entero.' );
		$this->assertStringContainsString( '<a href=', $this->stored( $mine['post'] ) );
		$this->assertStringContainsString( '<a href=', $this->stored( $other['post'] ) );

		// Quien sí puede con todo, lo deshace entero.
		$all = $this->request( 'POST', '/undo', array( 'batch_id' => $batch ), $this->editor );
		$this->assertSame( 200, $all->get_status() );
		$this->assertCount( 2, $all->get_data()['results'] );
	}

	public function test_malformed_and_ambiguous_requests_are_rejected_before_touching_anything(): void {
		$linked = $this->link();

		$this->assertSame( 400, $this->request( 'POST', '/undo', array() )->get_status(), 'Falta qué deshacer.' );
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/undo',
				array(
					'batch_id'  => $linked['batch'],
					'change_id' => $linked['change'],
				)
			)->get_status(),
			'Un grupo o un cambio, no los dos.'
		);
		$this->assertSame( 400, $this->request( 'POST', '/undo', array( 'batch_id' => "x'; DROP TABLE wp_posts;--" ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/undo', array( 'change_id' => 0 ) )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/undo', array( 'change_id' => 999999 ) )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/undo', array( 'batch_id' => BatchId::generate() ) )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/history/jobs/999999' )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/history', array( 'before' => 'nope' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/history', array( 'per_page' => 500 ) )->get_status() );

		$this->assertStringContainsString( '<a href=', $this->stored( $linked['post'] ) );
	}

	// ------------------------------------------------------------------ Listar.

	public function test_the_list_describes_each_group_and_pages_with_a_cursor(): void {
		$first  = $this->batch( 2 );
		$second = $this->batch( 1, BatchId::generate( 4_102_444_800_000 ) );

		$all = $this->request( 'GET', '/history' )->get_data();
		$this->assertSame( array( $second['batch'], $first['batch'] ), array_column( $all['items'], 'batch_id' ) );
		$this->assertNull( $all['next'] );
		$this->assertSame( 90, $all['retention_days'] );

		$group = $all['items'][1];
		$this->assertSame( 2, $group['links'] );
		$this->assertSame( 2, $group['active'] );
		$this->assertSame( 0, $group['undone'] );
		$this->assertSame( 2, $group['posts'] );
		$this->assertSame( $this->admin, $group['user_id'] );
		$this->assertNotSame( '', $group['user_name'] );
		$this->assertCount( 2, $group['titles'] );
		$this->assertNull( $group['job'] );

		$page = $this->request( 'GET', '/history', array( 'per_page' => 1 ) )->get_data();
		$this->assertCount( 1, $page['items'] );
		$this->assertSame( $second['batch'], $page['next'] );

		$older = $this->request(
			'GET',
			'/history',
			array(
				'per_page' => 1,
				'before'   => $page['next'],
			)
		)->get_data();
		$this->assertSame( $first['batch'], $older['items'][0]['batch_id'] );
		$this->assertNull( $older['next'] );
	}

	public function test_the_empty_history_is_an_empty_list(): void {
		$data = $this->request( 'GET', '/history' )->get_data();

		$this->assertSame( array(), $data['items'] );
		$this->assertNull( $data['next'] );
	}

	public function test_the_links_of_a_group_come_paged_and_without_html(): void {
		$batch = $this->batch( 3 );

		$data = $this->request(
			'GET',
			'/history/' . $batch['batch'] . '/changes',
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		)->get_data();

		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 2, $data['total_pages'] );
		$this->assertCount( 1, $data['items'] );
		$item = $data['items'][0];
		$this->assertSame( 'aire acondicionado', $item['anchor'] );
		$this->assertSame( 'active', $item['state'] );
		$this->assertStringContainsString( 'post=' . $item['post_id'], (string) $item['edit_url'] );
		$this->assertArrayNotHasKey( 'after_html', $item );
	}

	// ------------------------------------------------------------------ Deshacer y rehacer.

	public function test_undo_and_redo_a_change_then_a_whole_batch(): void {
		$batch = $this->batch( 2 );
		$with  = array_map( fn( int $post ): string => $this->stored( $post ), $batch['posts'] );

		$undo = $this->request( 'POST', '/undo', array( 'change_id' => $batch['changes'][0] ) );
		$this->assertSame( 200, $undo->get_status() );
		$data = $undo->get_data();
		$this->assertSame( 'restored', $data['results'][0]['status'] );
		$this->assertSame( $batch['posts'][0], $data['results'][0]['post_id'] );
		$this->assertSame( 1, $data['group']['active'] );
		$this->assertSame( 1, $data['group']['undone'] );
		$this->assertNull( $data['job'] );
		$this->assertSame( $this->p( 'Compra aire acondicionado ya.' ), $this->stored( $batch['posts'][0] ) );

		// Deshacer lo ya deshecho no escribe nada.
		$twice = $this->request( 'POST', '/undo', array( 'change_id' => $batch['changes'][0] ) )->get_data();
		$this->assertSame( 'already_undone', $twice['results'][0]['status'] );

		$redo = $this->request( 'POST', '/redo', array( 'change_id' => $batch['changes'][0] ) )->get_data();
		$this->assertSame( 'redone', $redo['results'][0]['status'] );
		$this->assertSame( $with[0], $this->stored( $batch['posts'][0] ) );
		$this->assertSame( 2, $redo['group']['active'] );
		$this->assertSame( 2, $redo['group']['links'] );

		$all = $this->request( 'POST', '/undo', array( 'batch_id' => $batch['batch'] ) )->get_data();
		$this->assertSame( array( 'restored', 'restored' ), array_column( $all['results'], 'status' ) );
		$this->assertSame( 0, $all['group']['active'] );

		$back = $this->request( 'POST', '/redo', array( 'batch_id' => $batch['batch'] ) )->get_data();
		$this->assertSame( array( 'redone', 'redone' ), array_column( $back['results'], 'status' ) );
		$this->assertSame( $with, array_map( fn( int $post ): string => $this->stored( $post ), $batch['posts'] ) );
	}

	public function test_a_post_edited_afterwards_is_reported_with_a_link_to_the_editor(): void {
		$batch = $this->batch( 1 );
		preg_match( '#<a href="[^"]+">aire acondicionado</a>#', $this->stored( $batch['posts'][0] ), $link );
		wp_update_post(
			array(
				'ID'           => $batch['posts'][0],
				'post_content' => wp_slash( $this->stored( $batch['posts'][0] ) . "\n\n" . $this->p( $link[0] ) ),
			)
		);

		$data = $this->request( 'POST', '/undo', array( 'batch_id' => $batch['batch'] ) )->get_data();

		$this->assertSame( 'manual', $data['results'][0]['status'] );
		$this->assertStringContainsString( 'post=' . $batch['posts'][0], (string) $data['results'][0]['edit_url'] );
		$this->assertSame( 1, $data['group']['active'], 'Sigue sin deshacer.' );
	}

	// ------------------------------------------------------------------ Segundo plano.

	public function test_a_large_group_is_undone_in_the_background_with_progress(): void {
		add_filter( 'magiclinking_undo_sync_limit', static fn(): int => 2 );
		$batch = $this->batch( 5 );

		$response = $this->request( 'POST', '/undo', array( 'batch_id' => $batch['batch'] ) );

		$this->assertSame( 202, $response->get_status() );
		$job = $response->get_data()['job'];
		$this->assertSame( 'undo', $job['mode'] );
		$this->assertSame( 5, $job['total'] );
		$this->assertSame( 0, $job['done'] );
		$this->assertSame( array(), $response->get_data()['results'] );
		$this->assertTrue( as_has_scheduled_action( BatchJob::HOOK, array( $job['id'] ), Installer::ACTION_GROUP ) );
		foreach ( $batch['posts'] as $post ) {
			$this->assertStringContainsString( '<a href=', $this->stored( $post ), 'Todavía no se ha tocado nada.' );
		}

		// Otro deshacer grande a la vez: uno solo por sitio.
		$other = $this->batch( 3 );
		$busy  = $this->request( 'POST', '/undo', array( 'batch_id' => $other['batch'] ) );
		$this->assertSame( 409, $busy->get_status() );
		$this->assertSame( $job['id'], $busy->get_data()['data']['job']['id'] );

		// La lista del historial muestra el proceso en su grupo.
		$list = $this->request( 'GET', '/history' )->get_data()['items'];
		$mine = array_values( array_filter( $list, static fn( array $g ): bool => $g['batch_id'] === $batch['batch'] ) )[0];
		$this->assertSame( $job['id'], $mine['job']['id'] );

		// Lo ve quien lo lanzó y quien puede ver el grupo (un editor), pero no quien no lo ve.
		$this->assertSame( 200, $this->request( 'GET', '/history/jobs/' . $job['id'] )->get_status() );
		$this->assertSame( 200, $this->request( 'GET', '/history/jobs/' . $job['id'], array(), $this->editor )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/history/jobs/' . $job['id'], array(), $this->author )->get_status() );

		// Action Scheduler lo ejecuta con presupuesto agotado: una tanda cada vez, sin perder el sitio.
		wp_set_current_user( $this->subscriber );
		$batches = Plugin::container()->get( BatchJob::class );
		$calls   = 0;
		$seen    = array();
		while ( $batches->step( $job['id'], 0.0 ) ) {
			++$calls;
			$seen[] = $this->jobs()->get( $job['id'] )['done'];
		}
		$this->assertSame( $this->subscriber, get_current_user_id(), 'El proceso devuelve el usuario que había.' );
		wp_set_current_user( $this->admin );

		$this->assertSame( array( 1, 2, 3, 4 ), $seen, 'Cada tanda avanza y deja el cursor para la siguiente.' );
		$final = $this->request( 'GET', '/history/jobs/' . $job['id'] )->get_data()['job'];
		$this->assertSame( 'done', $final['status'] );
		$this->assertSame( 5, $final['done'] );
		$this->assertSame( 100, $final['percent'] );
		$this->assertSame( 5, $final['counts']->restored );
		foreach ( $batch['posts'] as $post ) {
			$this->assertSame( $this->p( 'Compra aire acondicionado ya.' ), $this->stored( $post ) );
		}
		$this->assertSame( 0, $this->request( 'GET', '/history/' . $batch['batch'] )->get_data()['group']['active'] );
	}

	public function test_the_background_process_lists_what_it_could_not_undo_and_fires_the_hook_once(): void {
		add_filter( 'magiclinking_undo_sync_limit', static fn(): int => 1 );
		$batch = $this->batch( 3 );
		preg_match( '#<a href="[^"]+">aire acondicionado</a>#', $this->stored( $batch['posts'][1] ), $link );
		$twice = $this->stored( $batch['posts'][1] ) . "\n\n" . $this->p( $link[0] );
		wp_update_post(
			array(
				'ID'           => $batch['posts'][1],
				'post_content' => wp_slash( $twice ),
			)
		);

		$fired = array();
		add_action(
			'magiclinking_batch_undone',
			static function ( string $id, int $undone ) use ( &$fired ): void {
				$fired[] = array( $id, $undone );
			},
			10,
			2
		);

		$job = $this->request( 'POST', '/undo', array( 'batch_id' => $batch['batch'] ) )->get_data()['job'];
		$run = Plugin::container()->get( BatchJob::class );
		$run->run( $job['id'] );

		$final = $this->request( 'GET', '/history/jobs/' . $job['id'] )->get_data()['job'];
		$this->assertSame( 'done', $final['status'] );
		$this->assertSame( 2, $final['counts']->restored );
		$this->assertSame( 1, $final['counts']->manual );
		$this->assertCount( 1, $final['issues'] );
		$this->assertSame( $batch['posts'][1], $final['issues'][0]['post_id'] );
		$this->assertSame( 'manual', $final['issues'][0]['status'] );
		$this->assertStringContainsString( 'post=' . $batch['posts'][1], (string) $final['issues'][0]['edit_url'] );
		$this->assertNotEmpty( $final['issues'][0]['post_title'] );
		$this->assertSame( $twice, $this->stored( $batch['posts'][1] ) );
		$this->assertSame( array( array( $batch['batch'], 2 ) ), $fired );
	}

	public function test_redoing_a_large_group_also_goes_to_the_background(): void {
		$batch = $this->batch( 3 );
		$this->request( 'POST', '/undo', array( 'batch_id' => $batch['batch'] ) );
		add_filter( 'magiclinking_undo_sync_limit', static fn(): int => 1 );

		$response = $this->request( 'POST', '/redo', array( 'batch_id' => $batch['batch'] ) );

		$this->assertSame( 202, $response->get_status() );
		$job = $response->get_data()['job'];
		$this->assertSame( 'redo', $job['mode'] );
		Plugin::container()->get( BatchJob::class )->run( $job['id'] );
		$this->assertSame( 3, $this->request( 'GET', '/history/' . $batch['batch'] )->get_data()['group']['active'] );
		foreach ( $batch['posts'] as $post ) {
			$this->assertStringContainsString( '<a href=', $this->stored( $post ) );
		}
	}

	public function test_a_process_that_does_not_move_is_flagged_as_stalled(): void {
		add_filter( 'magiclinking_undo_sync_limit', static fn(): int => 1 );
		$batch = $this->batch( 2 );
		$job   = $this->request( 'POST', '/undo', array( 'batch_id' => $batch['batch'] ) )->get_data()['job'];
		$this->assertFalse( $job['stalled'] );

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'magiclinking_jobs', array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'id' => $job['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->assertTrue( $this->request( 'GET', '/history/jobs/' . $job['id'] )->get_data()['job']['stalled'] );
	}

	public function test_the_job_endpoint_only_shows_undo_processes(): void {
		$index = $this->jobs()->create( 'index', 1, array(), $this->admin );

		$this->assertSame( 404, $this->request( 'GET', '/history/jobs/' . $index )->get_status() );
	}
}
