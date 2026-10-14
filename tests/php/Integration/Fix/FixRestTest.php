<?php
/**
 * API REST para arreglar enlaces rotos: permisos, destino, errores y deshacer desde el Historial.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Fix;

use MagicLinking\Core\Installer;
use WP_REST_Request;
use WP_REST_Response;

final class FixRestTest extends FixTestCase {

	private const BASE = '/magic-linking/v1';

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

	private function call( string $method, string $route, array $params = array(), ?int $user = null ): WP_REST_Response {
		wp_set_current_user( $user ?? $this->admin );
		$request = new WP_REST_Request( $method, self::BASE . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Entrada con un enlace roto y la fila del grafo.
	 *
	 * @param array $extra Campos extra.
	 *
	 * @return array{0: int, 1: int, 2: string}
	 */
	private function broken( array $extra = array() ): array {
		$content = $this->p( 'Hola ' . $this->a( 'roto' ) . '.' );
		$post    = $this->post( $content, $extra );

		return array( $post, $this->broken_ids( $post )[0], $content );
	}

	public function test_routes_need_a_login_and_edit_rights_on_the_source(): void {
		[ $post, $link, $content ] = $this->broken( array( 'post_author' => $this->admin ) );

		foreach ( array(
			array( 'GET', "/broken/{$link}/targets", array() ),
			array(
				'POST',
				"/broken/{$link}/fix",
				array(
					'mode'      => 'retarget',
					'target_id' => $this->target,
				),
			),
			array( 'POST', "/broken/{$link}/fix", array( 'mode' => 'unlink' ) ),
		) as [ $method, $route, $params ] ) {
			$this->assertSame( 401, $this->call( $method, $route, $params, 0 )->get_status(), "Sin sesión: {$route}" );
			$this->assertSame( 403, $this->call( $method, $route, $params, $this->subscriber )->get_status(), "Suscriptor: {$route}" );
			$this->assertSame( 403, $this->call( $method, $route, $params, $this->author )->get_status(), "Autor de otra entrada: {$route}" );
		}

		$this->assertSame( $content, $this->stored( $post ), 'Nada se ha tocado.' );
	}

	public function test_an_author_can_fix_the_broken_links_in_their_own_entry(): void {
		[ $post, $link ] = $this->broken( array( 'post_author' => $this->author ) );

		$response = $this->call( 'POST', "/broken/{$link}/fix", array( 'mode' => 'unlink' ), $this->author );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( '<a href', $this->stored( $post ) );
	}

	public function test_the_list_says_who_can_fix_each_link(): void {
		[ $post ] = $this->broken();

		$mine = $this->call( 'GET', '/broken', array( 'post_id' => $post ) )->get_data()['items'];
		$this->assertTrue( $mine[0]['can_fix'] );

		wp_set_current_user( $this->author );
		$this->assertFalse( ( new \MagicLinking\Graph\BrokenRepository( $GLOBALS['wpdb'] ) )->query( array( 'post_id' => $post ) )['items'][0]['can_fix'] );
	}

	public function test_retarget_via_rest_fixes_the_link_and_returns_the_group_to_undo(): void {
		[ $post, $link, $content ] = $this->broken();

		$response = $this->call(
			'POST',
			"/broken/{$link}/fix",
			array(
				'mode'      => 'retarget',
				'target_id' => $this->target,
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'fixed', $data['status'] );
		$this->assertSame( 'retarget', $data['group']['kind'] );
		$this->assertSame( str_replace( home_url( '/no-existe/' ), (string) get_permalink( $this->target ), $content ), $this->stored( $post ) );
		$this->assertSame( array(), $this->call( 'GET', '/broken' )->get_data()['items'], 'La fila desaparece de la lista.' );

		// Ya no existe: un segundo intento es un 404 y no toca nada.
		$this->assertSame( 404, $this->call( 'POST', "/broken/{$link}/fix", array( 'mode' => 'unlink' ) )->get_status() );

		$undo = $this->call( 'POST', '/undo', array( 'batch_id' => $data['batch_id'] ) );
		$this->assertSame( 'restored', $undo->get_data()['results'][0]['status'] );
		$this->assertSame( $content, $this->stored( $post ) );
		$this->assertCount( 1, $this->call( 'GET', '/broken' )->get_data()['items'], 'Vuelve a la lista.' );

		$redo = $this->call( 'POST', '/redo', array( 'batch_id' => $data['batch_id'] ) );
		$this->assertSame( 'redone', $redo->get_data()['results'][0]['status'] );
		$this->assertSame( str_replace( home_url( '/no-existe/' ), (string) get_permalink( $this->target ), $content ), $this->stored( $post ) );
	}

	public function test_unlink_via_rest_leaves_the_text(): void {
		[ $post, $link ] = $this->broken();

		$this->assertSame( 200, $this->call( 'POST', "/broken/{$link}/fix", array( 'mode' => 'unlink' ) )->get_status() );
		$this->assertSame( $this->p( 'Hola roto.' ), $this->stored( $post ) );
	}

	public function test_the_destination_must_be_a_published_readable_analyzed_entry(): void {
		[ $post, $link, $content ] = $this->broken();

		$draft    = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$private  = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$password = self::factory()->post->create( array( 'post_password' => 'secreto' ) );
		register_post_type( 'libro', array( 'public' => true ) );
		$other_type = self::factory()->post->create( array( 'post_type' => 'libro' ) );

		foreach ( array( $draft, $private, $password, $other_type, $post, 999999 ) as $bad ) {
			$response = $this->call(
				'POST',
				"/broken/{$link}/fix",
				array(
					'mode'      => 'retarget',
					'target_id' => $bad,
				)
			);
			$this->assertSame( 400, $response->get_status(), "Destino {$bad}" );
		}

		$missing = $this->call( 'POST', "/broken/{$link}/fix", array( 'mode' => 'retarget' ) );
		$this->assertSame( 400, $missing->get_status(), 'Falta el destino.' );
		$this->assertSame( 400, $this->call( 'POST', "/broken/{$link}/fix", array( 'mode' => 'delete' ) )->get_status(), 'Modo desconocido.' );
		$this->assertSame( $content, $this->stored( $post ) );
	}

	public function test_a_link_that_is_no_longer_broken_or_does_not_exist_is_not_fixed(): void {
		$good = $this->post( $this->p( 'Bien <a href="' . get_permalink( $this->target ) . '">bueno</a>.' ) );
		$row  = $this->link_rows( $good )[0];

		$this->assertSame( 409, $this->call( 'POST', '/broken/' . $row['id'] . '/fix', array( 'mode' => 'unlink' ) )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/broken/987654/fix', array( 'mode' => 'unlink' ) )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/broken/987654/targets' )->get_status() );
	}

	public function test_a_stale_list_gives_a_conflict_and_writes_nothing(): void {
		[ $post, $link ] = $this->broken();
		$edited          = $this->p( 'Hola roto, ya sin enlace.' );
		$this->raw_update( $post, $edited );

		$response = $this->call( 'POST', "/broken/{$link}/fix", array( 'mode' => 'unlink' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'magiclinking_link_gone', $response->get_data()['code'] );
		$this->assertSame( $edited, $this->stored( $post ) );
	}

	public function test_search_lists_published_entries_by_title_without_the_source_or_unpublished_ones(): void {
		[ $post, $link ] = $this->broken();
		self::factory()->post->create(
			array(
				'post_title'   => 'Calefacción eficiente',
				'post_content' => 'aire',
			)
		);
		self::factory()->post->create(
			array(
				'post_title'  => 'Aire borrador',
				'post_status' => 'draft',
			)
		);
		self::factory()->post->create(
			array(
				'post_title'    => 'Aire secreto',
				'post_password' => 'x',
			)
		);

		$all = $this->call( 'GET', "/broken/{$link}/targets" )->get_data()['items'];
		$ids = array_column( $all, 'id' );
		$this->assertContains( $this->target, $ids );
		$this->assertNotContains( $post, $ids, 'No se ofrece la propia entrada.' );

		$found = $this->call( 'GET', "/broken/{$link}/targets", array( 'search' => 'Aire' ) )->get_data()['items'];
		$this->assertSame( array( 'Aire acondicionado' ), array_column( $found, 'title' ) );
		$this->assertSame( (string) get_permalink( $this->target ), $found[0]['url'] );

		$this->assertSame( array(), $this->call( 'GET', "/broken/{$link}/targets", array( 'search' => 'aire inexistente' ) )->get_data()['items'] );
	}
}
