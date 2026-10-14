<?php
/**
 * Historial por entrada (F1-36, D-59): REST agrupada y paginada en SQL, y deshacer o rehacer varios cambios de una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\History;

use MagicLinking\Content\InsertRequest;
use MagicLinking\Content\Inserter;
use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\History\BatchId;
use MagicLinking\History\ChangeRepository;
use WP_REST_Request;
use WP_REST_Response;

final class HistoryEntriesTest extends HistoryTestCase {

	private const BASE = '/magic-linking/v1';

	private int $editor = 0;

	private int $author = 0;

	public function set_up(): void {
		parent::set_up();

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
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

	/**
	 * Entrada con dos frases enlazables, para poder enlazarla dos veces (dos cambios en dos lotes).
	 *
	 * @param array $extra Campos extra.
	 */
	private function two_slot_post( array $extra = array() ): int {
		return $this->post(
			array_merge(
				array(
					'post_content' => $this->p( 'Compra aire acondicionado ya.' ) . "\n\n" . $this->p( 'Mira el ventilador industrial hoy.' ),
				),
				$extra
			)
		);
	}

	/**
	 * Pone un segundo enlace (otro ancla) en una entrada, en su propio lote.
	 *
	 * @param int         $post_id Entrada.
	 * @param string|null $batch   Lote.
	 */
	private function second_link( int $post_id, ?string $batch = null ): int {
		$result = Plugin::container()->get( Inserter::class )->insert(
			new InsertRequest( $post_id, (string) get_permalink( $this->target ), 'ventilador industrial', 'Mira el ', ' hoy.' ),
			$batch
		);

		return $result->change_id;
	}

	/**
	 * @return array{a: int, b: int, c: int, a1: int, a2: int, b1: int, c1: int}
	 */
	private function seed(): array {
		$a  = $this->two_slot_post( array( 'post_title' => 'Entrada A' ) );
		$b  = $this->post( array( 'post_title' => 'Entrada B' ) );
		$c  = $this->post( array( 'post_title' => 'Casa C' ) );
		$a1 = $this->link( $a )['change'];
		$b1 = $this->link( $b )['change'];
		$c1 = $this->link( $c )['change'];
		$a2 = $this->second_link( $a );

		return compact( 'a', 'b', 'c', 'a1', 'a2', 'b1', 'c1' );
	}

	private function group_for( array $items, int $post_id ): array {
		foreach ( $items as $item ) {
			if ( $item['post_id'] === $post_id ) {
				return $item;
			}
		}

		$this->fail( "La entrada {$post_id} no está en la lista." );
	}

	// ------------------------------------------------------------------ Lectura agrupada.

	public function test_groups_the_changes_by_entry_newest_activity_first_with_counts(): void {
		$s = $this->seed();

		$data = $this->request( 'GET', '/history', array( 'group' => 'post' ) )->get_data();

		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 1, $data['total_pages'] );
		$this->assertSame( array( $s['a'], $s['c'], $s['b'] ), array_column( $data['items'], 'post_id' ), 'La entrada A tuvo el último cambio.' );

		$a = $this->group_for( $data['items'], $s['a'] );
		$this->assertSame( 'Entrada A', $a['post_title'] );
		$this->assertSame( 2, $a['total'] );
		$this->assertSame( 2, $a['active'] );
		$this->assertSame( 0, $a['undone'] );
		$this->assertSame( array( $s['a2'], $s['a1'] ), array_column( $a['changes'], 'id' ), 'Del más reciente al más antiguo.' );
		$this->assertSame( 'ventilador industrial', $a['changes'][0]['anchor'] );
		$this->assertSame( 'Aire acondicionado', $a['changes'][0]['target_title'], 'El destino se resuelve a su título.' );
		$this->assertSame( 'active', $a['changes'][0]['state'] );
		$this->assertSame( 1, $a['changes'][0]['batch_posts'] );
		$this->assertNotSame( '', $a['changes'][0]['created_at'] );
		$this->assertNotNull( $a['edit_url'] );
		$this->assertNotNull( $a['view_url'] );
	}

	public function test_a_batch_that_touches_several_entries_says_so_on_each_row(): void {
		$batch = $this->batch( 3 );

		$items = $this->request( 'GET', '/history', array( 'group' => 'post' ) )->get_data()['items'];

		$this->assertCount( 3, $items );
		foreach ( $items as $item ) {
			$this->assertSame( 3, $item['changes'][0]['batch_posts'] );
			$this->assertSame( $batch['batch'], $item['changes'][0]['batch_id'] );
		}
	}

	public function test_paginates_by_entry_with_the_total_from_sql(): void {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->link()['post'];
		}

		$one = $this->request(
			'GET',
			'/history',
			array(
				'group'    => 'post',
				'per_page' => 2,
				'page'     => 1,
			)
		)->get_data();
		$two = $this->request(
			'GET',
			'/history',
			array(
				'group'    => 'post',
				'per_page' => 2,
				'page'     => 3,
			)
		)->get_data();

		$this->assertSame( 5, $one['total'] );
		$this->assertSame( 3, $one['total_pages'] );
		$this->assertCount( 2, $one['items'] );
		$this->assertCount( 1, $two['items'], 'La última página tiene el resto.' );
		$this->assertSame( $ids[4], $one['items'][0]['post_id'] );
		$this->assertSame( $ids[0], $two['items'][0]['post_id'] );
	}

	public function test_a_redone_link_counts_once(): void {
		$linked = $this->link();
		$this->request( 'POST', '/undo', array( 'post_id' => $linked['post'] ) );
		$this->request( 'POST', '/redo', array( 'post_id' => $linked['post'] ) );

		$group = $this->request( 'GET', '/history', array( 'group' => 'post' ) )->get_data()['items'][0];

		$this->assertSame( 1, $group['total'], 'Poner, quitar y volver a poner es un solo enlace.' );
		$this->assertSame( 1, $group['active'] );
		$this->assertCount( 1, $group['changes'] );
	}

	public function test_filters_by_status_origin_and_title(): void {
		$s = $this->seed();
		Plugin::container()->get( \MagicLinking\History\Undo::class )->revert( $s['b1'] );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'magiclinking_changes', array( 'origin' => ChangeRepository::ORIGIN_EDITOR ), array( 'id' => $s['c1'] ) );

		$undone = $this->request(
			'GET',
			'/history',
			array(
				'group'  => 'post',
				'status' => 'undone',
			)
		)->get_data();
		$this->assertSame( array( $s['b'] ), array_column( $undone['items'], 'post_id' ) );
		$this->assertSame( 1, $undone['total'] );

		$active = $this->request(
			'GET',
			'/history',
			array(
				'group'  => 'post',
				'status' => 'active',
			)
		)->get_data();
		$this->assertEqualsCanonicalizing( array( $s['a'], $s['c'] ), array_column( $active['items'], 'post_id' ) );

		$editor = $this->request(
			'GET',
			'/history',
			array(
				'group'  => 'post',
				'origin' => 'editor',
			)
		)->get_data();
		$this->assertSame( array( $s['c'] ), array_column( $editor['items'], 'post_id' ) );
		$this->assertSame( 'editor', $editor['items'][0]['changes'][0]['origin'] );

		$title = $this->request(
			'GET',
			'/history',
			array(
				'group'  => 'post',
				'search' => 'Casa',
			)
		)->get_data();
		$this->assertSame( array( $s['c'] ), array_column( $title['items'], 'post_id' ) );
		$this->assertSame( 1, $title['total'] );

		$none = $this->request(
			'GET',
			'/history',
			array(
				'group'  => 'post',
				'search' => 'no existe',
			)
		)->get_data();
		$this->assertSame( array(), $none['items'] );
		$this->assertSame( 0, $none['total'] );
	}

	public function test_the_list_does_not_query_per_entry(): void {
		for ( $i = 0; $i < 2; $i++ ) {
			$this->link();
		}
		$few = $this->queries_of_history_list();

		for ( $i = 0; $i < 8; $i++ ) {
			$this->link();
		}
		$many = $this->queries_of_history_list();

		$this->assertLessThanOrEqual( $few + 2, $many, 'Ocho entradas más no añaden consultas propias por entrada.' );
		$this->assertLessThanOrEqual( 8, $many );
	}

	private function queries_of_history_list(): int {
		global $wpdb;
		wp_cache_flush();
		$wpdb->queries = array();
		$previous      = defined( 'SAVEQUERIES' ) ? SAVEQUERIES : null;
		$saved         = $wpdb->queries;
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}

		$before = count( (array) $wpdb->queries );
		$this->request(
			'GET',
			'/history',
			array(
				'group'    => 'post',
				'per_page' => 50,
			)
		);
		$own = array_filter(
			array_slice( (array) $wpdb->queries, $before ),
			static fn( array $q ): bool => str_contains( (string) $q[0], 'magiclinking_changes' )
		);
		unset( $previous, $saved );

		return count( $own );
	}

	public function test_an_author_only_sees_their_own_entries_and_the_total_matches(): void {
		$mine = $this->link( $this->post( array( 'post_author' => $this->author ) ) );
		$this->link( $this->post( array( 'post_author' => $this->editor ) ) );

		$data = $this->request( 'GET', '/history', array( 'group' => 'post' ), $this->author )->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( array( $mine['post'] ), array_column( $data['items'], 'post_id' ) );

		$denied = $this->request( 'GET', '/history/posts/' . $this->post( array( 'post_author' => $this->editor ) ) . '/changes', array(), $this->author );
		$this->assertSame( 403, $denied->get_status() );
	}

	public function test_more_changes_of_an_entry_are_paged_on_their_own(): void {
		$a = $this->two_slot_post();
		$this->link( $a );
		$this->second_link( $a );

		$page = $this->request(
			'GET',
			'/history/posts/' . $a . '/changes',
			array(
				'per_page' => 1,
				'page'     => 2,
			)
		)->get_data();

		$this->assertSame( 2, $page['total'] );
		$this->assertSame( 2, $page['total_pages'] );
		$this->assertSame( 'aire acondicionado', $page['items'][0]['anchor'] );
	}

	// ------------------------------------------------------------------ Deshacer y rehacer varios.

	public function test_undo_all_of_an_entry_even_after_a_later_edit(): void {
		$s = $this->seed();
		// Una edición posterior en medio: el contenido ya no es el de después del último enlace.
		wp_update_post(
			array(
				'ID'           => $s['a'],
				'post_content' => wp_slash( $this->stored( $s['a'] ) . "\n\n" . $this->p( 'Añadido después.' ) ),
			)
		);

		$response = $this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $s['a2'], $s['a1'] ), array_column( $data['results'], 'change_id' ), 'En orden inverso.' );
		$this->assertSame( 0, $data['remaining'] );
		$this->assertSame( 0, $data['entry']['active'] );
		$this->assertSame( 2, $data['entry']['undone'] );
		$stored = $this->stored( $s['a'] );
		$this->assertStringNotContainsString( '<a href=', $stored );
		$this->assertStringContainsString( 'Añadido después.', $stored, 'Lo editado después se conserva.' );
		$this->assertStringContainsString( '<a href=', $this->stored( $s['b'] ), 'Otras entradas no se tocan.' );
	}

	public function test_undo_a_chosen_list_of_changes_of_the_same_entry(): void {
		$s = $this->seed();

		$data = $this->request(
			'POST',
			'/undo',
			array(
				'post_id'    => $s['a'],
				'change_ids' => array( $s['a1'] ),
			)
		)->get_data();

		$this->assertSame( array( $s['a1'] ), array_column( $data['results'], 'change_id' ) );
		$this->assertSame( 1, $data['entry']['active'] );
		$this->assertStringContainsString( 'ventilador industrial</a>', $this->stored( $s['a'] ) );
		$this->assertStringNotContainsString( 'aire acondicionado</a>', $this->stored( $s['a'] ) );
	}

	public function test_a_list_with_changes_of_another_entry_is_refused_without_touching_anything(): void {
		$s = $this->seed();

		$response = $this->request(
			'POST',
			'/undo',
			array(
				'post_id'    => $s['a'],
				'change_ids' => array( $s['a1'], $s['b1'] ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( '<a href=', $this->stored( $s['a'] ) );
		$this->assertStringContainsString( '<a href=', $this->stored( $s['b'] ) );
	}

	public function test_mixing_targets_is_a_bad_request(): void {
		$s = $this->seed();

		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/undo',
				array(
					'post_id'   => $s['a'],
					'change_id' => $s['a1'],
				)
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/undo',
				array(
					'post_id'  => $s['a'],
					'batch_id' => BatchId::generate(),
				)
			)->get_status()
		);
	}

	public function test_permissions_are_checked_for_the_entry(): void {
		$s = $this->seed();

		$author = $this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ), $this->author );
		$this->assertSame( 403, $author->get_status() );

		$anonymous = $this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ), 0 );
		$this->assertSame( 401, $anonymous->get_status() );

		$subscriber = $this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ), self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $subscriber->get_status() );

		$this->assertStringContainsString( '<a href=', $this->stored( $s['a'] ), 'Nada se ha tocado.' );
	}

	public function test_an_entry_open_in_the_editor_is_not_written_and_says_why_for_each_change(): void {
		$s = $this->seed();
		update_post_meta( $s['a'], '_edit_lock', time() . ':' . $this->editor );

		$data = $this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ) )->get_data();

		$this->assertCount( 2, $data['results'] );
		foreach ( $data['results'] as $result ) {
			$this->assertSame( 'failed', $result['status'] );
		}
		$this->assertSame( 2, $data['entry']['active'] );
		$this->assertStringContainsString( '<a href=', $this->stored( $s['a'] ) );
	}

	public function test_redo_all_of_an_entry_puts_the_links_back(): void {
		$s = $this->seed();
		$this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ) );

		$response = $this->request( 'POST', '/redo', array( 'post_id' => $s['a'] ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, count( $data['results'] ) );
		foreach ( $data['results'] as $result ) {
			$this->assertSame( 'redone', $result['status'] );
		}
		$this->assertSame( 2, $data['entry']['active'] );
		$this->assertSame( 2, $data['entry']['total'] );
		$this->assertSame( 2, substr_count( $this->stored( $s['a'] ), '<a href=' ) );
	}

	public function test_a_large_entry_is_undone_in_slices_and_says_how_many_remain(): void {
		add_filter( 'magiclinking_undo_sync_limit', static fn(): int => 1 );
		$s = $this->seed();

		$first = $this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ) )->get_data();
		$this->assertCount( 1, $first['results'] );
		$this->assertSame( 1, $first['remaining'] );

		$second = $this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ) )->get_data();
		$this->assertSame( 0, $second['remaining'] );
		$this->assertSame( 0, $second['entry']['active'] );
	}

	public function test_nothing_to_undo_is_a_404(): void {
		$s = $this->seed();
		$this->request( 'POST', '/undo', array( 'post_id' => $s['b'] ) );

		$this->assertSame( 404, $this->request( 'POST', '/undo', array( 'post_id' => $s['b'] ) )->get_status() );
	}

	public function test_an_entry_with_a_batch_in_the_background_is_busy(): void {
		add_filter( 'magiclinking_undo_sync_limit', static fn(): int => 2 );
		$batch = $this->batch( 5 );
		$this->assertSame( 202, $this->request( 'POST', '/undo', array( 'batch_id' => $batch['batch'] ) )->get_status() );

		$response = $this->request( 'POST', '/undo', array( 'post_id' => $batch['posts'][0] ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertStringContainsString( '<a href=', $this->stored( $batch['posts'][0] ) );
	}

	public function test_the_entry_lock_is_taken_once_for_all_the_changes(): void {
		$s     = $this->seed();
		$locks = 0;
		add_filter(
			'query',
			static function ( string $query ) use ( &$locks ): string {
				if ( str_contains( $query, 'GET_LOCK' ) ) {
					++$locks;
				}

				return $query;
			}
		);

		$this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ) );

		$this->assertSame( 1, $locks, 'Un único candado para los dos cambios de la entrada.' );
	}
}
