<?php
/**
 * Reglas: API REST, permisos y aplicar a una entrada con deshacer.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Rules;

use WP_REST_Request;
use WP_REST_Response;

final class RulesRestTest extends RulesTestCase {

	private const BASE = '/magic-linking/v1';

	private int $editor = 0;

	public function set_up(): void {
		parent::set_up();

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
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
	 * @param array<string, mixed> $params Parámetros (en el cuerpo si es POST).
	 */
	private function request( string $method, string $route, array $params = array(), ?int $user = null ): WP_REST_Response {
		wp_set_current_user( $user ?? $this->admin );
		$request = new WP_REST_Request( $method, self::BASE . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_only_administrators_manage_rules(): void {
		$rule = $this->rule();
		$post = $this->entry( $this->body( array( 'Una bomba de calor ahorra.' ) ) );

		foreach (
			array(
				array( 'GET', '/rules' ),
				array( 'POST', '/rules' ),
				array( 'POST', "/rules/{$rule->id}" ),
				array( 'DELETE', "/rules/{$rule->id}" ),
				array( 'GET', "/rules/{$rule->id}/candidates" ),
				array( 'GET', "/rules/{$rule->id}/preview" ),
				array( 'POST', "/rules/{$rule->id}/apply" ),
				array( 'GET', '/rules/options' ),
				array( 'GET', '/rules/targets' ),
			) as [ $method, $route ]
		) {
			$params = array( 'post_id' => $post->ID );
			$this->assertSame( 403, $this->request( $method, $route, $params, $this->editor )->get_status(), "$method $route (editor)" );
			$this->assertSame( 401, $this->request( $method, $route, $params, 0 )->get_status(), "$method $route (visitante)" );
		}
		$this->assertSame( $post->post_content, $this->content_of( $post->ID ) );
		$this->assertNotNull( $this->repo()->find( $rule->id ), 'Un editor no borra reglas.' );
	}

	public function test_crud_over_rest(): void {
		$created = $this->request( 'POST', '/rules', array( 'phrases' => "bomba de calor\naerotermia", 'target_id' => $this->target, 'word_gap' => true ) );
		$this->assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$data = $created->get_data()['rule'];
		$this->assertSame( array( 'bomba de calor', 'aerotermia' ), $data['phrases'] );
		$this->assertTrue( $data['word_gap'] );
		$this->assertSame( 'Bomba de calor: guía', $data['target_title'] );
		$this->assertSame( 'es', $data['lang'] );

		$list = $this->request( 'GET', '/rules' );
		$this->assertSame( 1, $list->get_data()['total'] );
		$this->assertSame( 100, $list->get_data()['limits']['words_per_link'] );

		$updated = $this->request( 'POST', '/rules/' . $data['id'], array( 'active' => false, 'max_per_post' => 3 ) );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertFalse( $updated->get_data()['rule']['active'] );
		$this->assertSame( 3, $updated->get_data()['rule']['max_per_post'] );
		$this->assertSame( array( 'bomba de calor', 'aerotermia' ), $updated->get_data()['rule']['phrases'] );

		$this->assertSame( 400, $this->request( 'POST', '/rules', array( 'phrases' => array( 'x' ), 'target_id' => $this->target ) )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/rules/9999', array( 'active' => true ) )->get_status() );

		$this->assertSame( 200, $this->request( 'DELETE', '/rules/' . $data['id'] )->get_status() );
		$this->assertSame( 404, $this->request( 'DELETE', '/rules/' . $data['id'] )->get_status() );
	}

	public function test_candidates_preview_and_apply_one_entry_then_undo_it(): void {
		$rule = $this->rule();
		$fits = $this->entry( $this->body( array( 'Una bomba de calor ahorra energía.' ) ) );
		$this->entry( $this->body( array( 'Nada relevante.' ) ) );

		$found = $this->request( 'GET', "/rules/{$rule->id}/candidates", array( 'limit' => 10 ) )->get_data();
		$this->assertTrue( $found['done'] );
		$this->assertSame( array( $fits->ID ), array_column( $found['items'], 'post_id' ) );
		$this->assertSame( 'bomba de calor', $found['items'][0]['links'][0]['anchor'] );
		$this->assertStringContainsString( 'Una', $found['items'][0]['links'][0]['before'] );
		$this->assertNotSame( '', $found['items'][0]['edit_url'] );

		$preview = $this->request( 'GET', "/rules/{$rule->id}/preview", array( 'post_id' => $fits->ID ) )->get_data();
		$this->assertCount( 1, $preview['links'] );
		$this->assertSame( $fits->post_content, $this->content_of( $fits->ID ), 'La vista previa no escribe.' );

		$applied = $this->request( 'POST', "/rules/{$rule->id}/apply", array( 'post_id' => $fits->ID ) );
		$this->assertSame( 200, $applied->get_status() );
		$this->assertSame( 1, $applied->get_data()['inserted'] );
		$batch = $applied->get_data()['batch_id'];
		$this->assertNotSame( '', $batch );
		$this->assertStringContainsString( '<a href=', $this->content_of( $fits->ID ) );

		$again = $this->request( 'POST', "/rules/{$rule->id}/apply", array( 'post_id' => $fits->ID ) )->get_data();
		$this->assertSame( 0, $again['inserted'] );
		$this->assertNotSame( '', $again['skipped_message'], 'Dice por qué no.' );

		$history = $this->request( 'GET', '/history', array( 'origin' => 'rule', 'group' => 'post' ) )->get_data();
		$this->assertSame( 1, $history['total'] );
		$this->assertSame( 'rule', $history['items'][0]['changes'][0]['origin'] );
		$this->assertSame( 0, $this->request( 'GET', '/history', array( 'origin' => 'plugin', 'group' => 'post' ) )->get_data()['total'], 'No se mezcla con el resto de orígenes.' );

		$undo = $this->request( 'POST', '/undo', array( 'batch_id' => $batch ) );
		$this->assertSame( 200, $undo->get_status() );
		$this->assertSame( $fits->post_content, $this->content_of( $fits->ID ) );
	}

	public function test_applying_needs_edit_permission_on_the_entry(): void {
		$rule   = $this->rule();
		$theirs = $this->entry( $this->body( array( 'Una bomba de calor ahorra.' ) ), array( 'post_author' => $this->admin ) );
		$gone   = $this->request( 'POST', "/rules/{$rule->id}/apply", array( 'post_id' => 999999 ) );
		$this->assertSame( 404, $gone->get_status() );

		// Un administrador sin capacidad de edición sobre esa entrada (p. ej. por un mapeo de capacidades de otro plugin).
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user, array $args ) use ( $theirs ): array {
				return 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $theirs->ID ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);
		$this->assertSame( 403, $this->request( 'POST', "/rules/{$rule->id}/apply", array( 'post_id' => $theirs->ID ) )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', "/rules/{$rule->id}/preview", array( 'post_id' => $theirs->ID ) )->get_status() );
		$this->assertSame( $theirs->post_content, $this->content_of( $theirs->ID ) );
	}

	public function test_there_is_no_route_to_apply_a_rule_to_every_entry(): void {
		$routes = array_keys( rest_get_server()->get_routes( 'magic-linking/v1' ) );

		$this->assertSame( array(), array_filter( $routes, static fn( string $route ): bool => str_contains( $route, 'bulk' ) || str_contains( $route, 'apply-all' ) || str_ends_with( $route, '/rules/apply' ) ) );
	}

	public function test_targets_search_only_returns_published_linkable_entries(): void {
		self::factory()->post->create( array( 'post_title' => 'Calor borrador', 'post_status' => 'draft' ) );
		self::factory()->post->create( array( 'post_title' => 'Calor privado', 'post_password' => 'x' ) );
		$ok = self::factory()->post->create( array( 'post_title' => 'Calor público' ) );

		$items = $this->request( 'GET', '/rules/targets', array( 'search' => 'Calor' ) )->get_data()['items'];

		$ids = array_column( $items, 'id' );
		$this->assertContains( $ok, $ids );
		$this->assertContains( $this->target, $ids );
		$this->assertCount( 2, $ids );
		$this->assertSame( array(), $this->request( 'GET', '/rules/targets' )->get_data()['items'] );
	}
}
