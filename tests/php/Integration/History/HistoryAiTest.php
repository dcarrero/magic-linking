<?php
/**
 * Marca de los enlaces que recomendó la IA en el Historial (F1-38).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\History;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\History\AiOrigin;
use MagicLinking\History\ChangeRepository;
use MagicLinking\History\Reader;
use MagicLinking\History\Redo;
use MagicLinking\History\Undo;
use MagicLinking\History\UndoResult;
use WP_REST_Request;
use WP_REST_Response;

final class HistoryAiTest extends HistoryTestCase {

	private const BASE = '/magic-linking/v1';

	private const MODEL = 'openai/gpt-4.1-mini';

	private int $author = 0;

	private int $subscriber = 0;

	public function set_up(): void {
		parent::set_up();

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
		parent::tear_down();
	}

	/**
	 * Llama a la API como un usuario, con cuerpo JSON.
	 *
	 * @param string   $method Método.
	 * @param string   $route  Ruta.
	 * @param array    $body   Cuerpo o parámetros.
	 * @param int|null $user   Usuario; null = el administrador.
	 */
	private function request( string $method, string $route, array $body = array(), ?int $user = null ): WP_REST_Response {
		wp_set_current_user( $user ?? $this->admin );
		$request = new WP_REST_Request( $method, self::BASE . $route );
		if ( 'GET' === $method ) {
			foreach ( $body as $key => $value ) {
				$request->set_param( $key, $value );
			}
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Entrada con el enlace del editor ya guardado, como lo deja Gutenberg.
	 *
	 * @param string $anchor Ancla.
	 */
	private function saved_with_link( string $anchor = 'aire acondicionado' ): int {
		$href = (string) get_permalink( $this->target );

		return $this->post( array( 'post_content' => $this->p( 'Compra <a href="' . $href . '">' . $anchor . '</a> ya.' ) ) );
	}

	/**
	 * Un enlace del cliente, con o sin lo que dice de la IA.
	 *
	 * @param array  $ai     Campos `ai_*` extra.
	 * @param string $id     Identificador de la inserción.
	 * @param string $anchor Ancla.
	 *
	 * @return array<string, mixed>
	 */
	private function client_link( array $ai = array(), string $id = 'ins-00000001', string $anchor = 'aire acondicionado' ): array {
		return array_merge(
			array(
				'insertion_id' => $id,
				'suggestion'   => '1:2:10:' . $anchor,
				'target_id'    => $this->target,
				'anchor'       => $anchor,
			),
			$ai
		);
	}

	/**
	 * Avisa al servidor de los enlaces de una entrada y devuelve la respuesta.
	 *
	 * @param int      $post  Entrada.
	 * @param array    $links Enlaces.
	 * @param int|null $user  Usuario.
	 */
	private function notify( int $post, array $links, ?int $user = null ): WP_REST_Response {
		return $this->request(
			'POST',
			'/history/editor',
			array(
				'post_id' => $post,
				'links'   => $links,
			),
			$user
		);
	}

	/**
	 * Filas del historial de una entrada.
	 *
	 * @param int $post Entrada.
	 */
	private function rows_of( int $post ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'changes' );

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY id', $table, $post ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Lo que llega del panel cuando la IA recomendaba el enlace.
	 *
	 * @param bool $anchor Si se usó su ancla.
	 *
	 * @return array{ai_recommended: bool, ai_anchor: bool, ai_model: string}
	 */
	private function recommended( bool $anchor = false ): array {
		return array(
			'ai_recommended' => true,
			'ai_anchor'      => $anchor,
			'ai_model'       => self::MODEL,
		);
	}

	// ------------------------------------------------------------------ Salientes (panel del editor).

	public function test_an_outbound_link_recommended_by_the_ai_is_recorded_with_its_mark(): void {
		$post    = $this->saved_with_link();
		$content = $this->stored( $post );

		$response = $this->notify( $post, array( $this->client_link( $this->recommended( true ) ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'recorded', $response->get_data()['results'][0]['status'] );
		$this->assertSame( $content, $this->stored( $post ), 'Regla 5: el contenido no se toca ni lleva atributos nuevos.' );
		$this->assertStringNotContainsString( 'data-', $this->stored( $post ) );

		$rows = $this->rows_of( $post );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'editor', $rows[0]['origin'] );
		$this->assertSame( '1', (string) $rows[0]['ai_recommended'] );
		$this->assertSame( '1', (string) $rows[0]['ai_anchor'] );
		$this->assertSame( self::MODEL, $rows[0]['ai_model'] );
	}

	public function test_a_link_without_the_ai_notice_stays_a_plain_editor_link(): void {
		$post = $this->saved_with_link();

		$this->notify( $post, array( $this->client_link() ) );

		$rows = $this->rows_of( $post );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'editor', $rows[0]['origin'] );
		$this->assertSame( '0', (string) $rows[0]['ai_recommended'] );
		$this->assertSame( '0', (string) $rows[0]['ai_anchor'] );
		$this->assertNull( $rows[0]['ai_model'] );

		$change = $this->request( 'GET', '/history/posts/' . $post )->get_data()['entry']['changes'][0];
		$this->assertNull( $change['ai'] );
	}

	public function test_the_mark_is_sanitized_and_only_counts_when_the_ai_recommended(): void {
		$post = $this->saved_with_link();
		$this->notify(
			$post,
			array(
				$this->client_link(
					array(
						'ai_recommended' => true,
						'ai_anchor'      => 'yes please',
						'ai_model'       => "<b>mo\u{00A0}del</b><script>alert(1)</script>" . str_repeat( 'x', 300 ),
					)
				),
			)
		);

		$row = $this->rows_of( $post )[0];
		$this->assertSame( '1', (string) $row['ai_recommended'] );
		$this->assertSame( '0', (string) $row['ai_anchor'], 'Un valor que no es un sí claro no cuenta.' );
		$this->assertLessThanOrEqual( AiOrigin::MODEL_MAX, mb_strlen( (string) $row['ai_model'] ) );
		$this->assertStringNotContainsString( '<', (string) $row['ai_model'] );
		$this->assertStringNotContainsString( 'alert', (string) $row['ai_model'] );

		// Sin «recomendada», el ancla y el modelo no significan nada.
		$other = $this->saved_with_link( 'aire acondicionado' );
		$this->notify(
			$other,
			array(
				$this->client_link(
					array(
						'ai_recommended' => false,
						'ai_anchor'      => true,
						'ai_model'       => self::MODEL,
					)
				),
			)
		);
		$row = $this->rows_of( $other )[0];
		$this->assertSame( '0', (string) $row['ai_recommended'] );
		$this->assertSame( '0', (string) $row['ai_anchor'] );
		$this->assertNull( $row['ai_model'] );

		$this->assertNull(
			AiOrigin::from_input(
				array(
					'ai_anchor' => true,
					'ai_model'  => 'm',
				)
			)
		);
		$this->assertNull( AiOrigin::from_input( array( 'ai_recommended' => array( 'x' ) ) ) );
		$this->assertSame(
			'',
			AiOrigin::from_input(
				array(
					'ai_recommended' => true,
					'ai_model'       => array( 'x' ),
				)
			)?->model
		);
	}

	public function test_a_repeated_notice_does_not_duplicate_or_change_the_mark(): void {
		$post = $this->saved_with_link();
		$this->notify( $post, array( $this->client_link( $this->recommended() ) ) );
		$again = $this->notify( $post, array( $this->client_link() ) );

		$this->assertSame( 'duplicate', $again->get_data()['results'][0]['status'] );
		$rows = $this->rows_of( $post );
		$this->assertCount( 1, $rows );
		$this->assertSame( '1', (string) $rows[0]['ai_recommended'] );
	}

	public function test_the_history_shows_the_mark_in_the_entry_view_and_the_batch_summary(): void {
		$post = $this->saved_with_link();
		$data = $this->notify( $post, array( $this->client_link( $this->recommended( true ) ) ) )->get_data();

		$entry = $this->request( 'GET', '/history/posts/' . $post )->get_data()['entry'];
		$this->assertSame(
			array(
				'anchor' => true,
				'model'  => self::MODEL,
			),
			$entry['changes'][0]['ai']
		);
		$this->assertSame( 'editor', $entry['changes'][0]['origin'] );

		$group = Plugin::container()->get( Reader::class )->group( (string) $data['batch_id'], $this->admin );
		$this->assertSame( 1, $group['ai_links'] );
		$this->assertSame( 1, $group['links'] );

		$items = Plugin::container()->get( Reader::class )->changes_of( (string) $data['batch_id'], 1, 10 )['items'];
		$this->assertSame( self::MODEL, $items[0]['ai']['model'] );
	}

	public function test_a_batch_counts_only_the_links_the_ai_recommended(): void {
		$href = (string) get_permalink( $this->target );
		$post = $this->post( array( 'post_content' => $this->p( 'Compra <a href="' . $href . '">aire acondicionado</a> ya.' ) . "\n\n" . $this->p( 'Mira el <a href="' . $href . '">ventilador industrial</a> hoy.' ) ) );

		$data = $this->notify(
			$post,
			array(
				$this->client_link( $this->recommended() ),
				$this->client_link( array(), 'ins-00000002', 'ventilador industrial' ),
			)
		)->get_data();

		$group = Plugin::container()->get( Reader::class )->group( (string) $data['batch_id'], $this->admin );
		$this->assertSame( 2, $group['links'] );
		$this->assertSame( 1, $group['ai_links'] );
	}

	// ------------------------------------------------------------------ Entrantes (escritas en el servidor).

	/**
	 * Cuerpo de `POST /links` para poner el enlace en una entrada.
	 *
	 * @param int   $post Entrada donde se escribe.
	 * @param array $ai   Campos `ai_*`.
	 *
	 * @return array<string, mixed>
	 */
	private function insert_body( int $post, array $ai = array() ): array {
		return array_merge(
			array(
				'post_id'   => $post,
				'target_id' => $this->target,
				'sentence'  => 'Compra aire acondicionado ya.',
				'offset'    => 7,
				'anchor'    => 'aire acondicionado',
			),
			$ai
		);
	}

	public function test_an_inbound_insertion_stores_the_mark_directly(): void {
		$post = $this->post();

		$response = $this->request( 'POST', '/links', $this->insert_body( $post, $this->recommended( true ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['inserted'] );
		$rows = $this->rows_of( $post );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'plugin', $rows[0]['origin'] );
		$this->assertSame( '1', (string) $rows[0]['ai_recommended'] );
		$this->assertSame( '1', (string) $rows[0]['ai_anchor'] );
		$this->assertSame( self::MODEL, $rows[0]['ai_model'] );

		$group = $response->get_data()['group'];
		$this->assertSame( 1, $group['ai_links'] );
	}

	public function test_a_batch_of_inbound_insertions_marks_each_link_on_its_own(): void {
		$first  = $this->post();
		$second = $this->post();

		$this->request(
			'POST',
			'/links',
			array(
				'links' => array(
					$this->insert_body( $first, $this->recommended() ),
					$this->insert_body( $second ),
				),
			)
		);

		$this->assertSame( '1', (string) $this->rows_of( $first )[0]['ai_recommended'] );
		$this->assertSame( '0', (string) $this->rows_of( $second )[0]['ai_recommended'] );
	}

	public function test_an_insertion_without_the_mark_is_unchanged(): void {
		$post = $this->post();

		$this->request( 'POST', '/links', $this->insert_body( $post ) );

		$row = $this->rows_of( $post )[0];
		$this->assertSame( 'plugin', $row['origin'] );
		$this->assertSame( '0', (string) $row['ai_recommended'] );
		$this->assertStringContainsString( '>aire acondicionado</a>', $this->stored( $post ) );
	}

	// ------------------------------------------------------------------ Filtro, deshacer y rehacer.

	/**
	 * Dos entradas con enlace del editor: la primera recomendada por la IA, la segunda no.
	 *
	 * @return array{ai: int, plain: int}
	 */
	private function two_posts(): array {
		$ai    = $this->saved_with_link();
		$plain = $this->saved_with_link();
		$this->notify( $ai, array( $this->client_link( $this->recommended() ) ) );
		$this->notify( $plain, array( $this->client_link() ) );

		return array(
			'ai'    => $ai,
			'plain' => $plain,
		);
	}

	public function test_the_ai_filter_lists_only_the_posts_with_recommended_links(): void {
		$posts = $this->two_posts();

		$all = $this->request( 'GET', '/history', array( 'group' => 'post' ) )->get_data();
		$this->assertEqualsCanonicalizing( array( $posts['ai'], $posts['plain'] ), array_column( $all['items'], 'post_id' ) );

		$filtered = $this->request(
			'GET',
			'/history',
			array(
				'group'  => 'post',
				'origin' => 'ai',
			)
		)->get_data();
		$this->assertSame( array( $posts['ai'] ), array_column( $filtered['items'], 'post_id' ) );
		$this->assertSame( 1, $filtered['total'] );
		$this->assertSame( self::MODEL, $filtered['items'][0]['changes'][0]['ai']['model'] );

		$entry = $this->request( 'GET', '/history/posts/' . $posts['plain'], array( 'origin' => 'ai' ) );
		$this->assertSame( 0, $entry->get_data()['entry']['total'] ?? 0, 'Una entrada sin enlaces de la IA no los tiene con ese filtro.' );
	}

	public function test_the_ai_filter_also_works_with_inbound_insertions(): void {
		$marked = $this->post();
		$this->request( 'POST', '/links', $this->insert_body( $marked, $this->recommended() ) );
		$plain = $this->post();
		$this->request( 'POST', '/links', $this->insert_body( $plain ) );

		$filtered = $this->request(
			'GET',
			'/history',
			array(
				'group'  => 'post',
				'origin' => 'ai',
			)
		)->get_data();

		$this->assertSame( array( $marked ), array_column( $filtered['items'], 'post_id' ) );
		$this->assertSame( 'plugin', $filtered['items'][0]['changes'][0]['origin'] );
	}

	public function test_undo_all_with_the_ai_filter_undoes_only_the_recommended_links(): void {
		$href = (string) get_permalink( $this->target );
		$post = $this->post( array( 'post_content' => $this->p( 'Compra <a href="' . $href . '">aire acondicionado</a> ya.' ) . "\n\n" . $this->p( 'Mira el <a href="' . $href . '">ventilador industrial</a> hoy.' ) ) );
		$this->notify(
			$post,
			array(
				$this->client_link( $this->recommended() ),
				$this->client_link( array(), 'ins-00000002', 'ventilador industrial' ),
			)
		);
		$marked = (int) $this->rows_of( $post )[0]['id'];

		$data = $this->request(
			'POST',
			'/undo',
			array(
				'post_id' => $post,
				'origin'  => 'ai',
			)
		)->get_data();

		$this->assertSame( array( $marked ), array_column( $data['results'], 'change_id' ), 'Solo el que recomendó la IA.' );
		$this->assertStringNotContainsString( '>aire acondicionado</a>', $this->stored( $post ) );
		$this->assertStringContainsString( '>ventilador industrial</a>', $this->stored( $post ), 'El otro enlace sigue puesto.' );
	}

	public function test_redo_keeps_the_mark_on_the_new_row(): void {
		$post   = $this->saved_with_link();
		$change = $this->notify( $post, array( $this->client_link( $this->recommended( true ) ) ) )->get_data()['results'][0]['change_id'];

		Plugin::container()->get( Undo::class )->revert( $change );
		$this->assertSame( UndoResult::REDONE, Plugin::container()->get( Redo::class )->redo( $change )->status );

		$rows = $this->rows_of( $post );
		$last = end( $rows );
		$this->assertSame( 'insert', $last['action'] );
		$this->assertSame( '1', (string) $last['ai_recommended'] );
		$this->assertSame( '1', (string) $last['ai_anchor'] );
		$this->assertSame( self::MODEL, $last['ai_model'] );

		$entry = $this->request( 'GET', '/history/posts/' . $post, array( 'origin' => 'ai' ) )->get_data()['entry'];
		$this->assertSame( 1, $entry['active'], 'Rehecho, vuelve a contar con el filtro.' );
	}

	// ------------------------------------------------------------------ Permisos y degradación.

	public function test_without_permission_nothing_is_recorded_even_with_the_mark(): void {
		$post = $this->saved_with_link();
		$body = array(
			'post_id' => $post,
			'links'   => array( $this->client_link( $this->recommended() ) ),
		);

		$this->assertSame( 401, $this->request( 'POST', '/history/editor', $body, 0 )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/history/editor', $body, $this->subscriber )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/history/editor', $body, $this->author )->get_status(), 'Una entrada ajena.' );
		$this->assertSame( array(), $this->rows_of( $post ) );

		$insert = $this->insert_body( $post, $this->recommended() );
		$this->assertSame( 403, $this->request( 'POST', '/links', $insert, $this->subscriber )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/links', $insert, $this->author )->get_status() );
		$this->assertSame( array(), $this->rows_of( $post ) );
	}

	public function test_the_ai_filter_is_only_readable_with_the_history_permission(): void {
		$this->two_posts();

		$this->assertSame(
			403,
			$this->request(
				'GET',
				'/history',
				array(
					'group'  => 'post',
					'origin' => 'ai',
				),
				$this->subscriber
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->request(
				'GET',
				'/history',
				array(
					'group'  => 'post',
					'origin' => 'nope',
				)
			)->get_status(),
			'Un filtro desconocido se rechaza.'
		);
	}

	public function test_an_author_only_sees_the_ai_links_of_the_entries_they_can_edit(): void {
		$own   = $this->saved_with_link();
		$other = $this->saved_with_link();
		wp_update_post(
			array(
				'ID'          => $own,
				'post_author' => $this->author,
			)
		);
		$this->notify( $own, array( $this->client_link( $this->recommended() ) ) );
		$this->notify( $other, array( $this->client_link( $this->recommended() ) ) );

		$seen = $this->request(
			'GET',
			'/history',
			array(
				'group'  => 'post',
				'origin' => 'ai',
			),
			$this->author
		)->get_data();

		$this->assertSame( array( $own ), array_column( $seen['items'], 'post_id' ) );
	}

	public function test_without_the_new_columns_the_link_is_still_recorded_without_the_mark(): void {
		// Una actualización automática deja el código nuevo con el esquema anterior hasta la siguiente visita al administrador.
		update_option( Installer::DB_VERSION_OPTION, 5, false );
		$post = $this->saved_with_link();

		$response = $this->notify( $post, array( $this->client_link( $this->recommended() ) ) );

		$this->assertSame( 'recorded', $response->get_data()['results'][0]['status'] );
		$this->assertSame( '0', (string) $this->rows_of( $post )[0]['ai_recommended'] );

		$this->assertSame( 200, $this->request( 'POST', '/links', $this->insert_body( $this->post(), $this->recommended() ) )->get_status() );

		$filtered = $this->request(
			'GET',
			'/history',
			array(
				'group'  => 'post',
				'origin' => 'ai',
			)
		);
		$this->assertSame( 200, $filtered->get_status(), 'El filtro de la IA no falla sin las columnas: no encuentra nada.' );
		$this->assertSame( array(), $filtered->get_data()['items'] );
		$this->assertSame( 200, $this->request( 'GET', '/history', array( 'group' => 'post' ) )->get_status() );
		$this->assertSame( 200, $this->request( 'GET', '/history' )->get_status() );

		update_option( Installer::DB_VERSION_OPTION, MAGICLINKING_DB_VERSION, false );
	}

	public function test_the_repository_ignores_the_mark_it_cannot_store(): void {
		$repository = Plugin::container()->get( ChangeRepository::class );
		$this->assertTrue( $repository->has_ai_columns() );

		update_option( Installer::DB_VERSION_OPTION, 5, false );
		$this->assertFalse( $repository->has_ai_columns() );
		$id = $repository->record( 'B1', 0, 'insert', null, 'a', 'b', sha1( 'x' ), 1, ChangeRepository::ORIGIN_PLUGIN, null, new AiOrigin( true, 'm' ) );
		$this->assertGreaterThan( 0, $id, 'Sin columnas, el cambio se guarda igual.' );
		update_option( Installer::DB_VERSION_OPTION, MAGICLINKING_DB_VERSION, false );
	}
}
