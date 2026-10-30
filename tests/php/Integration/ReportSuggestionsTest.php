<?php
/**
 * Añadir enlaces desde el informe (F1-26, docs/02 §4.3): las sugerencias entrantes y salientes de la fila se
 * insertan una a una en el servidor, sin abrir el editor, con su Historial y su deshacer, y la fila del informe
 * refleja las cifras de ahora.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\Jobs\Jobs;
use WP_REST_Request;
use WP_REST_Response;

final class ReportSuggestionsTest extends GraphTestCase {

	private const BASE = '/magic-linking/v1';

	private int $admin = 0;

	private int $author = 0;

	private int $subscriber = 0;

	private int $target = 0;

	/**
	 * Orígenes que mencionan la aerotermia: el 0 es del autor.
	 *
	 * @var list<int>
	 */
	private array $sources = array();

	public function set_up(): void {
		parent::set_up();

		add_filter( 'locale', static fn(): string => 'es_ES' );
		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
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

	// ------------------------------------------------------------------ Ayudas.

	/**
	 * @param array<string, mixed> $params
	 */
	private function request( string $method, string $route, array $params = array(), ?int $user = null ): WP_REST_Response {
		wp_set_current_user( $user ?? $this->admin );
		$request = new WP_REST_Request( $method, self::BASE . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Fila del informe de una entrada.
	 *
	 * @return array<string, mixed>
	 */
	private function row( int $post_id, ?int $user = null ): array {
		$response = $this->request( 'GET', "/report/{$post_id}", array(), $user );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data()['item'];
	}

	/**
	 * Primera sugerencia entrante hacia el destino cuyo origen es ese.
	 *
	 * @return array<string, mixed>
	 */
	private function inbound_from( int $target, int $source, ?int $user = null ): array {
		$response = $this->request(
			'GET',
			'/suggestions/inbound',
			array(
				'post_id'  => $target,
				'per_page' => 50,
			),
			$user
		);
		$this->assertSame( 200, $response->get_status() );
		foreach ( $response->get_data()['items'] as $item ) {
			if ( $item['source']['id'] === $source ) {
				return $item;
			}
		}

		$this->fail( "No hay sugerencia entrante desde {$source}." );
	}

	/**
	 * Primera sugerencia saliente de un origen hacia el destino.
	 *
	 * @return array<string, mixed>
	 */
	private function outbound_to( int $source, int $target ): array {
		$response = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $source ) );
		$this->assertSame( 200, $response->get_status() );
		foreach ( $response->get_data()['items'] as $item ) {
			if ( $item['target']['id'] === $target ) {
				return $item;
			}
		}

		$this->fail( "No hay sugerencia saliente hacia {$target}." );
	}

	private function content( int $post_id ): string {
		clean_post_cache( $post_id );

		return (string) get_post_field( 'post_content', $post_id, 'raw' );
	}

	private function p( string $html ): string {
		return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Un destino sobre aerotermia y orígenes que la mencionan; el índice construido.
	 *
	 * @param int $count Orígenes (hasta 7).
	 */
	private function corpus( int $count = 3 ): void {
		$this->target = self::factory()->post->create(
			array(
				'post_title'   => 'Aerotermia: guía completa',
				'post_name'    => 'aerotermia',
				'post_author'  => $this->admin,
				'post_content' => $this->p( 'La aerotermia aprovecha la energía del aire exterior para calentar la vivienda con una bomba de calor eficiente.' )
					. $this->p( 'El consumo de una instalación de aerotermia depende del aislamiento, del suelo radiante y de la zona climática.' ),
			)
		);

		$topics = array(
			'Reforma del cuarto de baño'      => 'azulejos, plato de ducha, mampara, grifería y desagües',
			'Cambiar las ventanas de casa'    => 'perfiles de aluminio, doble acristalamiento, persianas y herrajes',
			'Pintar el salón sin obras'       => 'rodillos, imprimación, esmalte, brochas y cinta de carrocero',
			'Ahorrar en la factura de la luz' => 'tarifas, potencia contratada, bombillas led y electrodomésticos',
			'Mantenimiento del jardín'        => 'césped, riego por goteo, poda, abono y macetas',
			'Elegir un buen colchón'          => 'muelles, látex, firmeza, almohadas y somieres',
			'Limpiar la cocina a fondo'       => 'campana extractora, encimera, horno, azulejos y fregadero',
		);
		$i      = 0;
		foreach ( array_slice( $topics, 0, $count, true ) as $title => $words ) {
			$this->sources[] = self::factory()->post->create(
				array(
					'post_title'   => $title,
					'post_author'  => 0 === $i++ ? $this->author : $this->admin,
					'post_content' => $this->p( 'Este fin de semana nos hemos puesto con ' . lcfirst( $title ) . ' y hemos repasado ' . $words . ' antes de comprar nada.' )
						. $this->p( 'Mientras tanto, la aerotermia nos ha dado mucho que pensar, porque ' . $words . ' cuestan lo suyo.' )
						. $this->p( 'Lo importante de ' . $words . ' es no tener prisa.' ),
				)
			);
		}

		foreach ( array( 'aceite de oliva', 'gazpacho andaluz', 'tortilla de patatas', 'pan de masa madre', 'queso manchego', 'cocina mediterránea', 'arroz con verduras', 'lentejas estofadas' ) as $dish ) {
			self::factory()->post->create(
				array(
					'post_title'   => 'Receta de ' . $dish,
					'post_content' => $this->p( 'Una receta sencilla de ' . $dish . ' con ingredientes de temporada, paso a paso y con trucos para que salga jugosa en la mesa.' ),
				)
			);
		}

		global $wpdb;
		foreach ( array( 'docs', 'links', 'jobs', 'terms', 'postings' ) as $table ) {
			$name = Schema::table( $wpdb->prefix, $table );
			$wpdb->query( "DELETE FROM {$name}" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$jobs = Plugin::container()->get( Jobs::class );
		$job  = $jobs->start_index( 0, false, true, true, true );
		$jobs->run_to_completion( $job['id'] );
	}


	// ------------------------------------------------------------------ La fila del informe.

	public function test_the_row_route_returns_the_current_figures_and_the_summary(): void {
		$this->corpus();

		$response = $this->request( 'GET', "/report/{$this->target}" );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $this->target, $data['item']['id'] );
		$this->assertSame( 0, $data['item']['inbound'] );
		$this->assertSame( 'orphan', $data['item']['status'] );
		$this->assertNotSame( '', $data['item']['edit_url'] );
		$this->assertArrayHasKey( 'internal_links', $data['summary'] );

		$this->assertSame( 404, $this->request( 'GET', '/report/99999' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', "/report/{$this->target}", array(), $this->subscriber )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', "/report/{$this->target}", array(), 0 )->get_status() );
	}

	public function test_the_row_hides_the_edit_link_from_who_cannot_edit_the_entry(): void {
		$this->corpus();

		$this->assertSame( '', $this->row( $this->target, $this->author )['edit_url'] );
		$this->assertNotSame( '', $this->row( $this->sources[0], $this->author )['edit_url'], 'Su propia entrada sí.' );
	}

	// ------------------------------------------------------------------ Entrantes: el enlace se escribe en el origen.

	public function test_adding_an_inbound_suggestion_writes_the_link_in_the_source_and_refreshes_the_row(): void {
		$this->corpus();
		$origin        = $this->sources[1];
		$original      = $this->content( $origin );
		$before        = $this->row( $this->target );
		$source_before = $this->row( $origin );

		$item = $this->inbound_from( $this->target, $origin );
		$this->assertTrue( $item['can_insert'] );
		$response = $this->request( 'POST', '/links', array( 'links' => array( $item['insert'] ) ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['inserted'] );
		$this->assertNotNull( $data['batch_id'] );

		// Solo cambia el trozo del enlace: quitándolo vuelve exactamente el contenido anterior.
		$content = $this->content( $origin );
		$this->assertStringContainsString( 'href="' . get_permalink( $this->target ) . '"', $content );
		$this->assertSame( $original, (string) preg_replace( '#<a href="[^"]*">(.*?)</a>#', '$1', $content, 1 ) );

		// La fila refleja ya las cifras de ahora, sin esperar a un trabajo en segundo plano.
		$this->assertSame( $before['inbound'] + 1, $this->row( $this->target )['inbound'] );
		$this->assertSame( $source_before['outbound'] + 1, $this->row( $origin )['outbound'] );
		$this->assertNotSame( 'orphan', $this->row( $this->target )['status'] );

		// Queda en el Historial y no se vuelve a proponer.
		$group = $this->request( 'GET', '/history/' . $data['batch_id'] );
		$this->assertSame( 200, $group->get_status() );
		$this->assertSame( $data['batch_id'], $group->get_data()['group']['batch_id'] );
		$this->assertSame( 1, $group->get_data()['group']['links'] );
		$this->assertSame( $data['batch_id'], $data['group']['batch_id'] );
		$ids = array_map(
			static fn( array $i ): int => $i['source']['id'],
			$this->request(
				'GET',
				'/suggestions/inbound',
				array(
					'post_id'  => $this->target,
					'per_page' => 50,
				)
			)->get_data()['items']
		);
		$this->assertNotContains( $origin, $ids );
	}

	public function test_undoing_restores_the_content_byte_for_byte_and_the_figures(): void {
		$this->corpus();
		$origin   = $this->sources[2];
		$original = $this->content( $origin );
		$before   = $this->row( $this->target );

		$item  = $this->inbound_from( $this->target, $origin );
		$batch = $this->request( 'POST', '/links', array( 'links' => array( $item['insert'] ) ) )->get_data()['batch_id'];
		$this->assertNotSame( $original, $this->content( $origin ) );

		$undo = $this->request( 'POST', '/undo', array( 'batch_id' => $batch ) );
		$this->assertSame( 200, $undo->get_status() );
		$this->assertContains( $undo->get_data()['results'][0]['status'], array( 'restored', 'link_removed' ) );

		$this->assertSame( $original, $this->content( $origin ) );
		$this->assertSame( $before['inbound'], $this->row( $this->target )['inbound'] );
		$this->assertSame( $before['outbound'], $this->row( $this->target )['outbound'] );
	}

	// ------------------------------------------------------------------ Salientes: el enlace se escribe en la entrada de la fila.

	public function test_adding_an_outbound_suggestion_writes_the_link_in_the_row_entry(): void {
		$this->corpus();
		$source   = $this->sources[1];
		$original = $this->content( $source );
		$before   = $this->row( $source );
		$target   = $this->row( $this->target );

		$item = $this->outbound_to( $source, $this->target );
		$this->assertSame( $source, $item['insert']['post_id'] );
		$response = $this->request( 'POST', '/links', array( 'links' => array( $item['insert'] ) ) );
		$this->assertSame( 1, $response->get_data()['inserted'] );

		$this->assertStringContainsString( 'href="' . get_permalink( $this->target ) . '"', $this->content( $source ) );
		$this->assertSame( $before['outbound'] + 1, $this->row( $source )['outbound'] );
		$this->assertSame( $target['inbound'] + 1, $this->row( $this->target )['inbound'] );
		$this->assertNotSame( $original, $this->content( $source ) );

		$this->assertSame( 200, $this->request( 'POST', '/undo', array( 'batch_id' => $response->get_data()['batch_id'] ) )->get_status() );
		$this->assertSame( $original, $this->content( $source ) );
		$this->assertSame( $before['outbound'], $this->row( $source )['outbound'] );
	}

	// ------------------------------------------------------------------ Permisos.

	public function test_the_permission_is_edit_post_on_the_source_not_on_the_row_entry(): void {
		$this->corpus();
		$admin_source = $this->sources[1];
		$item         = $this->inbound_from( $this->target, $admin_source );
		$original     = $this->content( $admin_source );

		// El autor ve la sugerencia entrante (el destino es del administrador, así que ni siquiera la lista).
		$list = $this->request( 'GET', '/suggestions/inbound', array( 'post_id' => $this->target ), $this->author );
		$this->assertSame( 403, $list->get_status() );

		// Aun con el cuerpo, escribir en la entrada de otro no está permitido y no toca nada.
		$response = $this->request( 'POST', '/links', array( 'links' => array( $item['insert'] ) ), $this->author );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( $original, $this->content( $admin_source ) );

		$this->assertSame( 403, $this->request( 'POST', '/links', array( 'links' => array( $item['insert'] ) ), $this->subscriber )->get_status() );
		$this->assertSame( 401, $this->request( 'POST', '/links', array( 'links' => array( $item['insert'] ) ), 0 )->get_status() );
	}

	public function test_an_author_sees_the_inbound_origins_they_cannot_edit_without_an_insert_body(): void {
		$this->corpus();
		// Destino del autor: las entrantes vienen de entradas del administrador, que no puede editar.
		wp_update_post(
			array(
				'ID'          => $this->target,
				'post_author' => $this->author,
			)
		);
		clean_post_cache( $this->target );

		$response = $this->request(
			'GET',
			'/suggestions/inbound',
			array(
				'post_id'  => $this->target,
				'per_page' => 50,
			),
			$this->author
		);
		$this->assertSame( 200, $response->get_status() );
		foreach ( $response->get_data()['items'] as $item ) {
			$mine = $item['source']['id'] === $this->sources[0];
			$this->assertSame( $mine, $item['can_insert'] );
			$this->assertSame( $mine, isset( $item['insert'] ) );
		}
	}

	// ------------------------------------------------------------------ Contenido que ya no cuadra.

	public function test_a_sentence_that_no_longer_matches_is_refused_and_nothing_is_written(): void {
		$this->corpus();
		$origin = $this->sources[1];
		$item   = $this->inbound_from( $this->target, $origin );

		// Alguien edita la entrada mientras el panel está abierto.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => str_replace( 'aerotermia', 'bomba', $this->content( $origin ) ) ), array( 'ID' => $origin ) );
		clean_post_cache( $origin );
		$edited = $this->content( $origin );
		$before = $this->row( $this->target );

		$response = $this->request( 'POST', '/links', array( 'links' => array( $item['insert'] ) ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 0, $data['inserted'] );
		$this->assertNull( $data['batch_id'] );
		$this->assertSame( 'failed', $data['results'][0]['status'] );
		$this->assertNotSame( '', $data['results'][0]['message'] );
		$this->assertSame( $edited, $this->content( $origin ) );
		$this->assertSame( $before['inbound'], $this->row( $this->target )['inbound'] );
	}

	public function test_the_client_cannot_make_the_server_write_a_sentence_that_is_not_in_the_entry(): void {
		$this->corpus();
		$origin   = $this->sources[1];
		$item     = $this->inbound_from( $this->target, $origin );
		$original = $this->content( $origin );
		$forged   = array_merge(
			$item['insert'],
			array(
				'sentence' => 'Una frase inventada sobre la aerotermia.',
				'offset'   => 17,
				'anchor'   => 'aerotermia',
			)
		);

		$response = $this->request( 'POST', '/links', array( 'links' => array( $forged ) ) );
		$this->assertSame( 0, $response->get_data()['inserted'] );
		$this->assertSame( $original, $this->content( $origin ) );
	}

	// ------------------------------------------------------------------ Idioma (regla 9).

	public function test_a_link_between_different_languages_is_neither_suggested_nor_accepted(): void {
		$this->corpus();
		$origin   = $this->sources[1];
		$item     = $this->inbound_from( $this->target, $origin );
		$original = $this->content( $origin );

		add_filter( 'magiclinking_post_language', fn( $lang, $post_id ) => $post_id === $this->target ? 'fr' : $lang, 10, 2 );

		$response = $this->request( 'POST', '/links', array( 'links' => array( $item['insert'] ) ) );
		$this->assertSame( 0, $response->get_data()['inserted'] );
		$this->assertSame( 'language_mismatch', $response->get_data()['results'][0]['reason'] );
		$this->assertSame( $original, $this->content( $origin ) );
	}
}
