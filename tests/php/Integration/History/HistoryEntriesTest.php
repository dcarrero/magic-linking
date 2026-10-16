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

	public function test_titles_come_out_as_plain_text_without_entities(): void {
		$destination = self::factory()->post->create(
			array(
				'post_title'   => 'Ver Brujas en un día&#8230; desde las alturas',
				'post_name'    => 'ver-brujas-alturas',
				'post_content' => $this->p( 'Guía del destino.' ),
			)
		);
		$a           = $this->two_slot_post( array( 'post_title' => 'Tom &amp; Jerry’s <b>día</b>...' ) );
		$this->assertStringContainsString( '&#8230;', get_post( $destination )->post_title, 'El título guardado lleva entidades.' );
		Plugin::container()->get( Inserter::class )->insert(
			new InsertRequest( $a, (string) get_permalink( $destination ), 'aire acondicionado', 'Compra ', ' ya.' )
		);

		$data  = $this->request( 'GET', '/history', array( 'group' => 'post' ) )->get_data();
		$entry = $this->group_for( $data['items'], $a );

		$this->assertSame( 'Tom & Jerry’s día...', $entry['post_title'] );
		$this->assertSame( 'Ver Brujas en un día… desde las alturas', $entry['changes'][0]['target_title'] );
	}

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

		$page = $this->request( 'GET', '/history/posts/' . $a . '/changes', array( 'per_page' => 1 ) )->get_data();

		$this->assertSame( 2, $page['total'] );
		$this->assertCount( 1, $page['items'] );
		$this->assertSame( 'ventilador industrial', $page['items'][0]['anchor'] );
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

	// ------------------------------------------------------------------ Revisión de código (PR 22).

	public function test_undo_all_with_an_origin_filter_undoes_exactly_what_was_counted(): void {
		$s = $this->seed();
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'magiclinking_changes', array( 'origin' => ChangeRepository::ORIGIN_EDITOR ), array( 'id' => $s['a1'] ) );

		$entry = $this->request( 'GET', '/history/posts/' . $s['a'], array( 'origin' => 'plugin' ) )->get_data()['entry'];
		$this->assertSame( 1, $entry['active'], 'Con el filtro, la entrada cuenta un cambio.' );

		$data = $this->request(
			'POST',
			'/undo',
			array(
				'post_id' => $s['a'],
				'origin'  => 'plugin',
			)
		)->get_data();

		$this->assertSame( array( $s['a2'] ), array_column( $data['results'], 'change_id' ), 'Solo el que se contó.' );
		$this->assertStringContainsString( 'aire acondicionado</a>', $this->stored( $s['a'] ), 'El del editor sigue puesto.' );
		$this->assertSame( 1, $data['entry']['total'], 'La entrada que vuelve respeta el filtro.' );
		$this->assertCount( 1, $data['entry']['changes'] );
	}

	public function test_every_change_appears_once_in_the_results(): void {
		$s = $this->seed();
		update_post_meta( $s['a'], '_edit_lock', time() . ':' . $this->editor );

		$data = $this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ) )->get_data();

		$ids = array_column( $data['results'], 'change_id' );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
		$this->assertArrayNotHasKey( 'remaining', $data );
	}

	public function test_a_role_with_edit_others_pages_but_not_posts_sees_the_pages_of_others(): void {
		add_role(
			'page_editor',
			'Page editor',
			array(
				'read'                 => true,
				'edit_posts'           => true,
				'edit_pages'           => true,
				'edit_others_pages'    => true,
				'edit_published_pages' => true,
			)
		);
		$user = self::factory()->user->create( array( 'role' => 'page_editor' ) );
		$page = $this->link(
			$this->post(
				array(
					'post_type'   => 'page',
					'post_author' => $this->editor,
				)
			)
		);
		$this->link( $this->post( array( 'post_author' => $this->editor ) ) );

		$data = $this->request( 'GET', '/history', array( 'group' => 'post' ), $user )->get_data();

		$this->assertSame( array( $page['post'] ), array_column( $data['items'], 'post_id' ), 'Ve la página ajena y no la entrada ajena.' );
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 200, $this->request( 'GET', '/history/posts/' . $page['post'] . '/changes', array(), $user )->get_status() );
		remove_role( 'page_editor' );
	}

	public function test_show_more_pages_by_cursor_and_keeps_the_origin_filter(): void {
		$a  = $this->two_slot_post();
		$c1 = $this->link( $a )['change'];
		$c2 = $this->second_link( $a );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'magiclinking_changes', array( 'origin' => ChangeRepository::ORIGIN_EDITOR ), array( 'id' => $c1 ) );

		$all   = $this->request(
			'GET',
			'/history/posts/' . $a . '/changes',
			array(
				'per_page' => 1,
				'before'   => $c2,
			)
		)->get_data();
		$plain = $this->request(
			'GET',
			'/history/posts/' . $a . '/changes',
			array(
				'per_page' => 5,
				'origin'   => 'plugin',
			)
		)->get_data();

		$this->assertSame( array( $c1 ), array_column( $all['items'], 'id' ), 'Cursor: solo los anteriores al último mostrado.' );
		$this->assertSame( array( $c2 ), array_column( $plain['items'], 'id' ) );
		$this->assertSame( 1, $plain['total'] );
	}

	public function test_busy_check_reads_the_changes_in_one_query(): void {
		$a     = $this->two_slot_post();
		$ids   = array( $this->link( $a )['change'], $this->second_link( $a ) );
		$count = 0;
		add_filter(
			'query',
			static function ( string $query ) use ( &$count ): string {
				if ( preg_match( '/FROM `?\w*magiclinking_changes`? WHERE id = \d+/', $query ) ) {
					++$count;
				}

				return $query;
			}
		);

		$this->request(
			'POST',
			'/undo',
			array(
				'post_id'    => $a,
				'change_ids' => $ids,
			)
		);

		$this->assertLessThanOrEqual( 2, $count, 'Las comprobaciones previas no leen cambio a cambio.' );
	}

	public function test_undoing_many_changes_of_an_entry_reindexes_it_once(): void {
		$s      = $this->seed();
		$writes = 0;
		add_filter(
			'query',
			static function ( string $query ) use ( &$writes ): string {
				if ( preg_match( '/^\s*(INSERT|REPLACE|UPDATE|DELETE)[^;]*magiclinking_(docs|links)/i', $query ) ) {
					++$writes;
				}

				return $query;
			}
		);
		$this->request( 'POST', '/undo', array( 'post_id' => $s['b'] ) );
		$one    = $writes;
		$writes = 0;
		$this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ) );

		$this->assertGreaterThan( 0, $one, 'La entrada se reindexa.' );
		$this->assertLessThanOrEqual( $one, $writes, 'Dos cambios de una entrada reindexan una vez, como uno.' );
	}

	public function test_the_list_resolves_the_targets_without_a_query_per_destination(): void {
		$count = function ( int $entries ): int {
			for ( $i = 0; $i < $entries; $i++ ) {
				$target = self::factory()->post->create(
					array(
						'post_title'   => 'Destino ' . $i . wp_generate_password( 3, false ),
						'post_content' => $this->p( 'x' ),
					)
				);
				$post   = $this->post();
				Plugin::container()->get( Inserter::class )->insert( new InsertRequest( $post, (string) get_permalink( $target ), 'aire acondicionado', 'Compra ', ' ya.' ) );
			}
			wp_cache_flush();
			$n = 0;
			$f = static function ( string $query ) use ( &$n ): string {
				++$n;

				return $query;
			};
			add_filter( 'query', $f );
			$data = $this->request(
				'GET',
				'/history',
				array(
					'group'    => 'post',
					'per_page' => 50,
				)
			)->get_data();
			remove_filter( 'query', $f );
			$this->assertNotNull( $data['items'][0]['changes'][0]['target_title'] );

			return $n;
		};

		$few  = $count( 2 );
		$many = $count( 10 );

		$this->assertLessThanOrEqual( $few + 6, $many, 'Ocho destinos más no añaden consultas por destino.' );
	}

	public function test_a_large_entry_goes_to_the_background_and_can_be_followed(): void {
		add_filter( 'magiclinking_undo_sync_limit', static fn(): int => 1 );
		$s = $this->seed();

		$response = $this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ) );

		$this->assertSame( 202, $response->get_status() );
		$job = $response->get_data()['job'];
		$this->assertSame( 2, $job['total'] );
		$this->assertSame( $s['a'], $job['post_id'] );
		$this->assertStringContainsString( '<a href=', $this->stored( $s['a'] ), 'Todavía no se ha tocado nada.' );

		$list = $this->request( 'GET', '/history', array( 'group' => 'post' ) )->get_data()['items'];
		$this->assertSame( $job['id'], $this->group_for( $list, $s['a'] )['job']['id'] );
		$this->assertSame( 409, $this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ) )->get_status(), 'La entrada tiene un proceso.' );
		$this->assertSame( 200, $this->request( 'GET', '/history/jobs/' . $job['id'] )->get_status() );

		Plugin::container()->get( \MagicLinking\History\BatchJob::class )->step( $job['id'] );

		$this->assertStringNotContainsString( '<a href=', $this->stored( $s['a'] ) );
		$this->assertStringContainsString( '<a href=', $this->stored( $s['b'] ), 'Solo esa entrada.' );
		$done = $this->request( 'GET', '/history/jobs/' . $job['id'] )->get_data()['job'];
		$this->assertSame( 'done', $done['status'] );
	}

	public function test_undoing_by_entry_fires_the_batch_action_for_each_affected_batch(): void {
		$s     = $this->seed();
		$fired = array();
		add_action(
			'magiclinking_batch_undone',
			static function ( string $batch, int $count ) use ( &$fired ): void {
				$fired[ $batch ] = $count;
			},
			10,
			2
		);

		$this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ) );

		$this->assertCount( 2, $fired, 'Los dos cambios de A son de lotes distintos.' );
		$this->assertSame( array( 1, 1 ), array_values( $fired ) );
	}

	// ------------------------------------------------------------------ Segunda revisión (PR 22).

	/**
	 * Entrada con N frases enlazables (`termino1`…), todas enlazadas en un mismo lote.
	 *
	 * @param int $count Cuántas.
	 *
	 * @return array{post: int, batch: string, changes: list<int>}
	 */
	private function many_links( int $count ): array {
		$content = '';
		for ( $i = 1; $i <= $count; $i++ ) {
			$content .= $this->p( "Mira el termino{$i} hoy." ) . "\n\n";
		}
		$post    = $this->post( array( 'post_content' => trim( $content ) ) );
		$batch   = BatchId::generate();
		$changes = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$changes[] = Plugin::container()->get( Inserter::class )->insert( new InsertRequest( $post, (string) get_permalink( $this->target ), "termino{$i}", 'Mira el ', ' hoy.' ), $batch )->change_id;
		}

		return array(
			'post'    => $post,
			'batch'   => $batch,
			'changes' => $changes,
		);
	}

	/**
	 * Hace que el segundo guardado de WordPress lance una excepción.
	 */
	private function explode_on_second_save(): void {
		$calls = 0;
		add_action(
			'wp_after_insert_post',
			static function () use ( &$calls ): void {
				if ( 2 === ++$calls ) {
					throw new \RuntimeException( 'boom' );
				}
			}
		);
	}

	public function test_an_exception_midway_keeps_what_was_applied_reindexed_and_notified(): void {
		$m     = $this->many_links( 3 );
		$fired = array();
		add_action(
			'magiclinking_batch_undone',
			static function ( string $batch, int $count ) use ( &$fired ): void {
				$fired[ $batch ] = $count;
			},
			10,
			2
		);
		$writes = 0;
		add_filter(
			'query',
			static function ( string $query ) use ( &$writes ): string {
				if ( preg_match( '/^\s*(INSERT|REPLACE|UPDATE|DELETE)[^;]*magiclinking_(docs|links)/i', $query ) ) {
					++$writes;
				}

				return $query;
			}
		);
		$this->explode_on_second_save();

		$results = Plugin::container()->get( \MagicLinking\History\Undo::class )->revert_post( $m['post'], $m['changes'], $this->admin );

		$statuses = array_count_values( array_column( array_map( static fn( $r ): array => array( 'status' => $r->status ), $results ), 'status' ) );
		$this->assertCount( 3, $results );
		$this->assertSame( 1, $statuses['failed'] ?? 0, 'Solo falla el cambio en el que saltó.' );
		$this->assertSame( 2, array_sum( array_diff_key( $statuses, array( 'failed' => 1 ) ) ), 'Los otros dos se aplicaron.' );
		$this->assertGreaterThan( 0, $writes, 'Lo aplicado se reindexa.' );
		$this->assertSame( array( $m['batch'] => 2 ), $fired, 'El aviso lleva lo realmente deshecho.' );
	}

	public function test_a_background_slice_counts_only_the_change_that_failed(): void {
		$m = $this->many_links( 3 );
		$this->explode_on_second_save();
		$jobs = Plugin::container()->get( \MagicLinking\History\BatchJob::class );
		$job  = $jobs->start_for_post( $m['post'], array_reverse( $m['changes'] ), false, $this->admin, false );

		$jobs->step( (int) $job['id'] );

		$done = Plugin::container()->get( \MagicLinking\Jobs\JobRepository::class )->get( (int) $job['id'] );
		$this->assertSame( 1, $done['params']['counts']['failed'] ?? 0, 'No se marcan como fallidos los que se aplicaron.' );
		$this->assertSame( 2, $done['params']['undone'] );
		$this->assertCount( 1, $done['params']['issues'] );
	}

	public function test_a_background_process_fires_the_batch_action_once_with_the_total(): void {
		$m     = $this->many_links( 11 );
		$fired = array();
		add_action(
			'magiclinking_batch_undone',
			static function ( string $batch, int $count ) use ( &$fired ): void {
				$fired[] = array( $batch, $count );
			},
			10,
			2
		);
		$jobs = Plugin::container()->get( \MagicLinking\History\BatchJob::class );
		$job  = $jobs->start_for_post( $m['post'], array_reverse( $m['changes'] ), false, $this->admin, false );

		$this->assertTrue( $jobs->step( (int) $job['id'], 0.0 ), 'El presupuesto corta tras la primera tanda de 10.' );
		$this->assertSame( array(), $fired );
		$this->assertFalse( $jobs->step( (int) $job['id'] ) );

		$this->assertSame( array( array( $m['batch'], 11 ) ), $fired, 'Una vez, con el total.' );
	}

	public function test_the_busy_guard_sees_the_process_of_an_entry_from_every_route(): void {
		add_filter( 'magiclinking_undo_sync_limit', static fn(): int => 1 );
		$s = $this->seed();
		$this->assertSame( 202, $this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ) )->get_status() );

		$this->assertSame( 409, $this->request( 'POST', '/undo', array( 'change_id' => $s['a1'] ) )->get_status(), 'Cambio suelto.' );
		$this->assertSame( 409, $this->request( 'POST', '/redo', array( 'change_id' => $s['a1'] ) )->get_status(), 'Rehacer suelto.' );
		$batch = Plugin::container()->get( ChangeRepository::class )->get( $s['a1'] )['batch_id'];
		$this->assertSame( 409, $this->request( 'POST', '/undo', array( 'batch_id' => $batch ) )->get_status(), 'Lote que toca la entrada.' );
		$this->assertSame(
			409,
			$this->request(
				'POST',
				'/undo',
				array(
					'post_id'    => $s['a'],
					'change_ids' => array( $s['a1'] ),
				)
			)->get_status(),
			'Entrada.'
		);
		$this->assertStringContainsString( '<a href=', $this->stored( $s['a'] ) );
	}

	public function test_redo_notices_come_after_the_reindex(): void {
		$s = $this->seed();
		$this->request( 'POST', '/undo', array( 'post_id' => $s['a'] ) );
		$indexed = false;
		add_filter(
			'query',
			static function ( string $query ) use ( &$indexed ): string {
				if ( preg_match( '/^\s*(INSERT|REPLACE|UPDATE|DELETE)[^;]*magiclinking_(docs|links)/i', $query ) ) {
					$indexed = true;
				}

				return $query;
			}
		);
		$seen = array();
		add_action(
			'magiclinking_link_inserted',
			static function () use ( &$indexed, &$seen ): void {
				$seen[] = $indexed;
			}
		);

		$this->request( 'POST', '/redo', array( 'post_id' => $s['a'] ) );

		$this->assertSame( array( true, true ), $seen, 'Los avisos salen con la entrada ya reindexada.' );
	}

	public function test_the_grouped_query_stays_fast_with_twenty_thousand_rows(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'magiclinking_changes';
		$batch = BatchId::generate();
		$now   = gmdate( 'Y-m-d H:i:s' );
		for ( $chunk = 0; $chunk < 20; $chunk++ ) {
			$values = array();
			for ( $i = 0; $i < 1000; $i++ ) {
				$n        = $chunk * 1000 + $i;
				$post     = 900000 + intdiv( $n, 4 );
				$undone   = 0 === $n % 3 ? "'{$now}'" : 'NULL';
				$values[] = "('{$batch}', {$post}, 'insert', '0', 'antes {$n}', 'despues {$n}', '', 1, '{$now}', {$undone}, 'plugin')";
			}
			$wpdb->query( "INSERT INTO {$table} (batch_id, post_id, action, block_path, before_html, after_html, content_hash_after, user_id, created_at, undone_at, origin) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
		}

		$start = microtime( true );
		$data  = $this->request(
			'GET',
			'/history',
			array(
				'group'    => 'post',
				'per_page' => 10,
			)
		)->get_data();
		$took  = microtime( true ) - $start;

		fwrite( STDERR, sprintf( "\n[rendimiento] 20.000 filas, %d entradas: %.3f s\n", $data['total'], $took ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Medida de rendimiento en la salida de la prueba.
		$this->assertSame( 5000, $data['total'] );
		$this->assertLessThan( 3.0, $took );
	}
}
