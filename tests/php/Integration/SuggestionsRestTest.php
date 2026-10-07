<?php
/**
 * API REST de las sugerencias y de insertar enlaces (F1-08): forma, paginación, borrador, índice sin
 * construir, idiomas, permisos negativos, inserción y su paso por el historial.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Index\SuggestionCache;
use MagicLinking\Jobs\Jobs;
use WP_REST_Request;
use WP_REST_Response;

final class SuggestionsRestTest extends GraphTestCase {

	private const BASE = '/magic-linking/v1';

	private int $admin = 0;

	private int $editor = 0;

	private int $author = 0;

	private int $subscriber = 0;

	/**
	 * Destino: «Aerotermia».
	 */
	private int $target = 0;

	/**
	 * Orígenes con una frase que menciona la aerotermia: el 0 es del autor, los demás del administrador.
	 *
	 * @var list<int>
	 */
	private array $sources = array();

	/**
	 * Entradas que la prueba declara en inglés.
	 *
	 * @var list<int>
	 */
	private array $english = array();

	public function set_up(): void {
		parent::set_up();

		add_filter( 'locale', static fn(): string => 'es_ES' );
		add_filter(
			'magiclinking_post_language',
			fn( $lang, $id ) => in_array( (int) $id, $this->english, true ) ? 'en' : null,
			10,
			2
		);

		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
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
		wp_using_ext_object_cache( false );
		remove_all_filters( 'magiclinking_undo_sync_limit' );
		parent::tear_down();
	}

	// ------------------------------------------------------------------ Ayudas.

	private function request( string $method, string $route, array $params = array(), ?int $user = null ): WP_REST_Response {
		wp_set_current_user( $user ?? $this->admin );
		$request = new WP_REST_Request( $method, self::BASE . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function p( string $html ): string {
		return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Un corpus pequeño y el índice construido: un destino sobre aerotermia, `$count` orígenes que la
	 * mencionan (el primero es de un autor) y entradas de relleno de otros temas.
	 *
	 * @param int  $count Orígenes.
	 * @param bool $index Construir el índice.
	 */
	private function corpus( int $count = 3, bool $index = true ): void {
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

		if ( $index ) {
			$this->build_index();
		}
	}

	private function build_index(): void {
		global $wpdb;
		foreach ( array( 'docs', 'links', 'jobs', 'terms', 'postings' ) as $table ) {
			$name = \MagicLinking\Core\Schema::table( $wpdb->prefix, $table );
			$wpdb->query( "DELETE FROM {$name}" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$jobs = Plugin::container()->get( Jobs::class );
		$job  = $jobs->start_index( 0, false, true, true, true );
		$jobs->run_to_completion( $job['id'] );
	}

	private function stored( int $post_id ): string {
		return (string) get_post_field( 'post_content', $post_id, 'raw' );
	}

	/**
	 * Primera sugerencia saliente de un origen.
	 *
	 * @param int      $source Origen.
	 * @param int|null $user   Quien pide (por defecto, el administrador).
	 *
	 * @return array<string, mixed>
	 */
	private function first_outbound( int $source, ?int $user = null ): array {
		$response = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $source ), $user );
		$this->assertSame( 200, $response->get_status() );
		$items = $response->get_data()['items'];
		$this->assertNotEmpty( $items, 'Debe haber sugerencias para el origen ' . $source );

		foreach ( $items as $item ) {
			if ( $this->target === $item['target']['id'] ) {
				return $item;
			}
		}
		$this->fail( 'No hay sugerencia hacia el destino.' );
	}

	// ------------------------------------------------------------------ Permisos.

	public function test_every_route_needs_a_login_and_the_edit_posts_capability(): void {
		$this->corpus();
		$link   = $this->first_outbound( $this->sources[1] )['insert'];
		$routes = array(
			array( 'GET', '/suggestions/outbound', array( 'post_id' => $this->sources[1] ) ),
			array(
				'POST',
				'/suggestions/outbound',
				array(
					'post_id' => $this->sources[1],
					'content' => 'x',
				),
			),
			array( 'GET', '/suggestions/inbound', array( 'post_id' => $this->target ) ),
			array( 'POST', '/links', $link ),
		);

		foreach ( $routes as [ $method, $route, $params ] ) {
			$this->assertSame( 401, $this->request( $method, $route, $params, 0 )->get_status(), "Sin sesión: {$method} {$route}" );
			$this->assertSame( 403, $this->request( $method, $route, $params, $this->subscriber )->get_status(), "Suscriptor: {$method} {$route}" );
		}

		$this->assertStringNotContainsString( '<a href=', $this->stored( $this->sources[1] ), 'Nada se ha tocado.' );
	}

	public function test_an_author_cannot_read_suggestions_of_other_peoples_entries(): void {
		$this->corpus();

		$mine = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $this->sources[0] ), $this->author );
		$this->assertSame( 200, $mine->get_status() );

		foreach ( array( $this->sources[1], $this->target ) as $theirs ) {
			$this->assertSame( 403, $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $theirs ), $this->author )->get_status() );
			$this->assertSame( 403, $this->request( 'GET', '/suggestions/inbound', array( 'post_id' => $theirs ), $this->author )->get_status() );
			$this->assertSame(
				403,
				$this->request(
					'POST',
					'/suggestions/outbound',
					array(
						'post_id' => $theirs,
						'content' => 'texto',
					),
					$this->author
				)->get_status()
			);
		}
	}

	public function test_an_author_cannot_insert_into_other_peoples_entries_and_nothing_is_written(): void {
		$this->corpus();
		$own    = $this->first_outbound( $this->sources[0] )['insert'];
		$theirs = $this->first_outbound( $this->sources[1] )['insert'];

		$refused = $this->request( 'POST', '/links', $theirs, $this->author );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertStringNotContainsString( '<a href=', $this->stored( $this->sources[1] ) );

		// Un lote con una entrada propia y otra ajena se rechaza entero: tampoco se toca la propia.
		$mixed = $this->request( 'POST', '/links', array( 'links' => array( $own, $theirs ) ), $this->author );
		$this->assertSame( 403, $mixed->get_status() );
		$this->assertStringNotContainsString( '<a href=', $this->stored( $this->sources[0] ) );
		$this->assertStringNotContainsString( '<a href=', $this->stored( $this->sources[1] ) );

		// Lo propio sí.
		$ok = $this->request( 'POST', '/links', $own, $this->author );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 1, $ok->get_data()['inserted'] );
		$this->assertStringContainsString( '<a href=', $this->stored( $this->sources[0] ) );
	}

	public function test_a_missing_entry_is_a_404_and_bad_arguments_a_400(): void {
		$this->corpus();

		$this->assertSame( 404, $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => 999999 ) )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/suggestions/inbound', array( 'post_id' => 999999 ) )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/suggestions/outbound', array() )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => 0 ) )->get_status() );
		$this->assertSame(
			400,
			$this->request(
				'GET',
				'/suggestions/inbound',
				array(
					'post_id'  => $this->target,
					'per_page' => 500,
				)
			)->get_status()
		);
		$this->assertSame( 400, $this->request( 'POST', '/links', array() )->get_status(), 'Sin enlaces.' );
		$this->assertSame( 400, $this->request( 'POST', '/links', array( 'links' => array() ) )->get_status() );
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/links',
				array(
					'links' => array_fill(
						0,
						26,
						array(
							'post_id'   => 1,
							'target_id' => 2,
							'sentence'  => 'a b',
							'offset'    => 0,
							'anchor'    => 'a',
						)
					),
				)
			)->get_status(),
			'Más de 25 enlaces.'
		);
		$bad = $this->first_outbound( $this->sources[1] )['insert'];
		$this->assertSame( 400, $this->request( 'POST', '/links', array_merge( $bad, array( 'block_path' => '1;DROP' ) ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/links', array_merge( $bad, array( 'offset' => -1 ) ) )->get_status() );
	}

	// ------------------------------------------------------------------ Salientes.

	public function test_outbound_suggestions_have_the_shape_the_card_needs(): void {
		$this->corpus();
		$response = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $this->sources[1] ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['ready'] );
		$this->assertSame( 'ok', $data['state'] );
		$this->assertFalse( $data['draft'] );
		$this->assertSame( count( $data['items'] ), $data['total'] );

		$item = $this->first_outbound( $this->sources[1] );
		$this->assertSame( $this->sources[1], $item['source']['id'] );
		$this->assertSame( $this->target, $item['target']['id'] );
		$this->assertSame( 'Aerotermia: guía completa', $item['target']['title'] );
		$this->assertSame( get_permalink( $this->target ), $item['target']['url'] );
		$this->assertSame( 'post', $item['target']['type'] );
		$this->assertSame( 'es', substr( $item['target']['lang'], 0, 2 ) );
		$this->assertIsString( $item['target']['edit_url'] );
		$this->assertGreaterThan( 0, $item['score'] );
		$this->assertLessThanOrEqual( 1, $item['score'] );

		// La frase, el ancla y sus desplazamientos cuadran, en bytes y en unidades UTF-16.
		$this->assertSame( $item['anchor'], substr( $item['sentence'], $item['offset'], strlen( $item['anchor'] ) ) );
		$this->assertSame( $item['before'] . $item['anchor'] . $item['after'], $item['sentence'] );
		$this->assertSame( $item['anchor'], mb_substr( $item['sentence'], mb_strlen( $item['before'] ), mb_strlen( $item['anchor'] ) ) );
		$this->assertSame( $item['anchor_end'] - $item['anchor_start'], intdiv( strlen( (string) mb_convert_encoding( $item['anchor'], 'UTF-16LE', 'UTF-8' ) ), 2 ) );

		// Motivos con texto legible; las alternativas vienen aunque estén vacías (D-30: 0 por defecto).
		$this->assertNotEmpty( $item['reasons'] );
		foreach ( $item['reasons'] as $reason ) {
			$this->assertNotSame( '', $reason['code'] );
			$this->assertNotSame( '', $reason['text'] );
		}
		$this->assertSame( array(), $item['alternatives'] );

		// `insert` es el cuerpo de POST /links.
		$this->assertSame( $this->sources[1], $item['insert']['post_id'] );
		$this->assertSame( $this->target, $item['insert']['target_id'] );
		$this->assertSame( $item['sentence'], $item['insert']['sentence'] );
		$this->assertSame( $item['offset'], $item['insert']['offset'] );
		$this->assertSame( $item['anchor'], $item['insert']['anchor'] );
		$this->assertArrayHasKey( 'block_path', $item['insert'] );
	}

	public function test_the_edit_url_is_only_given_to_who_can_edit(): void {
		$this->corpus();

		// El autor ve entrantes solo de lo suyo, pero el destino de una saliente suya es del administrador.
		$item = $this->first_outbound( $this->sources[0], $this->author );
		$this->assertNull( $item['target']['edit_url'] );
		$this->assertNotNull( $item['source']['edit_url'] );
	}

	public function test_outbound_never_suggests_a_destination_in_another_language(): void {
		$this->corpus();
		$this->english[] = $this->target;
		$this->build_index();

		$data = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $this->sources[1] ) )->get_data();
		$this->assertNotContains( $this->target, array_column( array_column( $data['items'], 'target' ), 'id' ), 'Ninguna sugerencia hacia el destino en inglés.' );

		$inbound = $this->request( 'GET', '/suggestions/inbound', array( 'post_id' => $this->target ) )->get_data();
		$this->assertSame( 0, $inbound['total'], 'Ningún origen en español enlaza a una entrada en inglés.' );
	}

	public function test_a_link_across_languages_is_refused_even_if_the_client_asks(): void {
		$this->corpus();
		$link            = $this->first_outbound( $this->sources[1] )['insert'];
		$this->english[] = $this->target;

		$response = $this->request( 'POST', '/links', $link );
		$result   = $response->get_data()['results'][0];

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'language_mismatch', $result['reason'] );
		$this->assertNull( $response->get_data()['batch_id'] );
		$this->assertStringNotContainsString( '<a href=', $this->stored( $this->sources[1] ) );
	}

	public function test_unbuilt_index_is_reported_not_failed(): void {
		$this->corpus( 3, false );

		foreach ( array(
			array( 'GET', '/suggestions/outbound', array( 'post_id' => $this->sources[1] ) ),
			array(
				'POST',
				'/suggestions/outbound',
				array(
					'post_id' => $this->sources[1],
					'content' => $this->p( 'La aerotermia.' ),
				),
			),
			array( 'GET', '/suggestions/inbound', array( 'post_id' => $this->target ) ),
		) as [ $method, $route, $params ] ) {
			$response = $this->request( $method, $route, $params );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status(), $route );
			$this->assertFalse( $data['ready'], $route );
			$this->assertSame( 'index_not_ready', $data['state'], $route );
			$this->assertSame( array(), $data['items'], $route );
		}
	}

	public function test_entries_that_are_not_analyzed_say_why(): void {
		$this->corpus();
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'attachment',
				'post_title' => 'Una imagen',
			)
		);
		$data = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $page ) )->get_data();
		$this->assertSame( 'not_analyzed', $data['state'] );
		$this->assertTrue( $data['ready'] );

		$draft = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => $this->p( 'La aerotermia y el consumo de la bomba de calor.' ),
			)
		);
		$this->assertSame( 'not_published', $this->request( 'GET', '/suggestions/inbound', array( 'post_id' => $draft ) )->get_data()['state'] );
		$this->assertSame( 'ok', $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $draft ) )->get_data()['state'], 'Un borrador sí tiene salientes.' );
	}

	// ------------------------------------------------------------------ Borrador.

	public function test_the_editor_content_is_analyzed_without_saving_it(): void {
		$this->corpus();
		$unrelated = self::factory()->post->create(
			array(
				'post_title'   => 'Notas del fin de semana',
				'post_content' => $this->p( 'Salimos a pasear por el campo.' ),
			)
		);
		$before    = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $unrelated ) )->get_data();
		$this->assertNotContains( $this->target, array_column( array_column( $before['items'], 'target' ), 'id' ) );

		$content  = $this->p( 'Antes de reformar conviene comparar la aerotermia con otras opciones de calefacción y mirar el consumo real de la vivienda.' );
		$response = $this->request(
			'POST',
			'/suggestions/outbound',
			array(
				'post_id' => $unrelated,
				'content' => $content,
				'title'   => 'Reformar la calefacción',
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['draft'] );
		$this->assertContains( $this->target, array_column( array_column( $data['items'], 'target' ), 'id' ) );
		$this->assertSame( $this->p( 'Salimos a pasear por el campo.' ), $this->stored( $unrelated ), 'No se guarda nada.' );
		$this->assertSame( 'Notas del fin de semana', get_the_title( $unrelated ) );

		// Y el guardado sigue sin verlas: el borrador no se cuela en la caché.
		$after = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $unrelated ) )->get_data();
		$this->assertNotContains( $this->target, array_column( array_column( $after['items'], 'target' ), 'id' ) );
	}

	public function test_a_new_entry_can_be_analyzed_from_the_editor_content(): void {
		$this->corpus();
		$auto = self::factory()->post->create(
			array(
				'post_status' => 'auto-draft',
				'post_author' => $this->admin,
			)
		);

		$this->assertSame( 'not_analyzed', $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $auto ) )->get_data()['state'] );

		$data = $this->request(
			'POST',
			'/suggestions/outbound',
			array(
				'post_id' => $auto,
				'content' => $this->p( 'Antes de reformar conviene comparar la aerotermia con otras opciones de calefacción y mirar el consumo real de la vivienda.' ),
			)
		)->get_data();
		$this->assertSame( 'ok', $data['state'] );
		$this->assertContains( $this->target, array_column( array_column( $data['items'], 'target' ), 'id' ) );
	}

	public function test_an_enormous_draft_is_refused(): void {
		$this->corpus();
		$response = $this->request(
			'POST',
			'/suggestions/outbound',
			array(
				'post_id' => $this->sources[1],
				'content' => str_repeat( 'a', 2000001 ),
			)
		);
		$this->assertSame( 413, $response->get_status() );
	}

	// ------------------------------------------------------------------ Entrantes.

	public function test_inbound_suggestions_are_paginated_and_have_the_card_shape(): void {
		$this->corpus( 5 );

		$first = $this->request(
			'GET',
			'/suggestions/inbound',
			array(
				'post_id'  => $this->target,
				'per_page' => 2,
			)
		);
		$data  = $first->get_data();

		$this->assertSame( 200, $first->get_status() );
		$this->assertTrue( $data['ready'] );
		$this->assertSame( 5, $data['total'] );
		$this->assertSame( 3, $data['total_pages'] );
		$this->assertSame( 1, $data['page'] );
		$this->assertCount( 2, $data['items'] );
		$this->assertSame( '5', $first->get_headers()['X-WP-Total'] );
		$this->assertSame( '3', $first->get_headers()['X-WP-TotalPages'] );

		$item = $data['items'][0];
		$this->assertSame( $this->target, $item['target']['id'] );
		$this->assertContains( $item['source']['id'], $this->sources );
		$this->assertSame( $item['source']['id'], $item['insert']['post_id'] );
		$this->assertSame( $this->target, $item['insert']['target_id'] );
		$this->assertNotEmpty( $item['reasons'] );

		$seen = array_column( array_column( $data['items'], 'source' ), 'id' );
		foreach ( array(
			2 => 2,
			3 => 1,
		) as $page => $count ) {
			$next = $this->request(
				'GET',
				'/suggestions/inbound',
				array(
					'post_id'  => $this->target,
					'per_page' => 2,
					'page'     => $page,
				)
			)->get_data();
			$this->assertCount( $count, $next['items'], "Página {$page}" );
			$this->assertSame( 5, $next['total'] );
			$seen = array_merge( $seen, array_column( array_column( $next['items'], 'source' ), 'id' ) );
		}
		$this->assertCount( 5, array_unique( $seen ), 'Cada origen sale una sola vez.' );

		$beyond = $this->request(
			'GET',
			'/suggestions/inbound',
			array(
				'post_id'  => $this->target,
				'per_page' => 2,
				'page'     => 9,
			)
		)->get_data();
		$this->assertSame( array(), $beyond['items'] );
		$this->assertSame( 5, $beyond['total'] );
	}

	public function test_inbound_lists_every_source_and_marks_which_ones_the_user_can_insert(): void {
		$this->corpus( 3 );

		// El destino es del autor, que solo puede editar el primer origen.
		wp_update_post(
			array(
				'ID'          => $this->target,
				'post_author' => $this->author,
			)
		);

		$author = $this->request( 'GET', '/suggestions/inbound', array( 'post_id' => $this->target ), $this->author )->get_data();
		$this->assertSame( 3, $author['total'], 'Se listan todos los orígenes.' );
		$this->assertCount( 3, $author['items'] );

		foreach ( $author['items'] as $item ) {
			if ( $this->sources[0] === $item['source']['id'] ) {
				$this->assertTrue( $item['can_insert'] );
				$this->assertSame( $this->sources[0], $item['insert']['post_id'] );
				$this->assertNotNull( $item['source']['edit_url'] );
				continue;
			}
			$this->assertFalse( $item['can_insert'] );
			$this->assertArrayNotHasKey( 'insert', $item );
			$this->assertNull( $item['source']['edit_url'] );
			$this->assertSame( $this->target, $item['target']['id'] );
			$this->assertNotSame( '', $item['sentence'] );
		}

		// Y el servidor sigue rechazando insertar en lo ajeno.
		$other = $this->first_outbound( $this->sources[1] )['insert'];
		$this->assertSame( 403, $this->request( 'POST', '/links', $other, $this->author )->get_status() );

		$admin = $this->request( 'GET', '/suggestions/inbound', array( 'post_id' => $this->target ) )->get_data();
		$this->assertSame( 3, $admin['total'] );
		$this->assertSame( array( true ), array_values( array_unique( array_column( $admin['items'], 'can_insert' ) ) ) );
	}

	public function test_inbound_does_not_query_each_source_to_check_permissions(): void {
		$this->corpus( 7 );
		wp_update_post(
			array(
				'ID'          => $this->target,
				'post_author' => $this->author,
			)
		);
		wp_cache_flush();

		global $wpdb;
		$single = 0;
		$count  = static function ( string $sql ) use ( &$single, $wpdb ): string {
			if ( preg_match( '/FROM\s+`?' . preg_quote( $wpdb->posts, '/' ) . '`?\s+WHERE\s+ID\s*=\s*\d+/i', $sql ) ) {
				++$single;
			}

			return $sql;
		};
		add_filter( 'query', $count );
		$data = $this->request( 'GET', '/suggestions/inbound', array( 'post_id' => $this->target ), $this->author )->get_data();
		remove_filter( 'query', $count );

		$this->assertSame( 7, $data['total'] );
		$this->assertLessThanOrEqual( 2, $single, 'Las entradas de la página se cargan de una vez, no una a una.' );
	}

	// ------------------------------------------------------------------ Insertar.

	public function test_a_link_is_inserted_listed_in_history_and_undone(): void {
		$this->corpus();
		$item    = $this->first_outbound( $this->sources[1] );
		$content = $this->stored( $this->sources[1] );

		$response = $this->request( 'POST', '/links', $item['insert'] );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $data['inserted'] );
		$this->assertSame( 0, $data['failed'] );
		$this->assertNotNull( $data['batch_id'] );
		$this->assertSame( 'inserted', $data['results'][0]['status'] );
		$this->assertSame( $this->sources[1], $data['results'][0]['post_id'] );
		$this->assertGreaterThan( 0, $data['results'][0]['change_id'] );
		$this->assertSame( $data['batch_id'], $data['group']['batch_id'] );

		$stored = $this->stored( $this->sources[1] );
		$this->assertStringContainsString( '<a href="' . get_permalink( $this->target ) . '">' . $item['anchor'] . '</a>', $stored );
		$this->assertSame( $content, str_replace( array( '<a href="' . get_permalink( $this->target ) . '">', '</a>' ), '', $stored ), 'Solo cambia el enlace.' );

		// Aparece en el historial.
		$history = $this->request( 'GET', '/history' )->get_data();
		$this->assertContains( $data['batch_id'], array_column( $history['items'], 'batch_id' ) );
		$changes = $this->request( 'GET', '/history/' . $data['batch_id'] . '/changes' )->get_data();
		$this->assertSame( array( $data['results'][0]['change_id'] ), array_column( $changes['items'], 'id' ) );

		// Ya no se sugiere lo mismo (el destino ya está enlazado) y se deshace por el lote.
		$again = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $this->sources[1] ) )->get_data();
		$this->assertNotContains( $this->target, array_column( array_column( $again['items'], 'target' ), 'id' ) );

		$undo = $this->request( 'POST', '/undo', array( 'batch_id' => $data['batch_id'] ) );
		$this->assertSame( 200, $undo->get_status() );
		$this->assertSame( $content, $this->stored( $this->sources[1] ) );
	}

	public function test_several_inbound_links_in_several_entries_are_one_batch(): void {
		$this->corpus( 3 );
		$inbound = $this->request( 'GET', '/suggestions/inbound', array( 'post_id' => $this->target ) )->get_data();
		$links   = array_column( $inbound['items'], 'insert' );
		$this->assertCount( 3, $links );

		$response = $this->request( 'POST', '/links', array( 'links' => $links ), $this->editor );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 3, $data['inserted'] );
		$this->assertSame( array( 0, 1, 2 ), array_column( $data['results'], 'index' ) );
		foreach ( $this->sources as $source ) {
			$this->assertStringContainsString( '<a href=', $this->stored( $source ) );
		}

		$changes = $this->request( 'GET', '/history/' . $data['batch_id'] . '/changes' )->get_data();
		$this->assertSame( 3, $changes['total'] );

		// Un solo gesto los deshace todos.
		$undo = $this->request( 'POST', '/undo', array( 'batch_id' => $data['batch_id'] ) );
		$this->assertSame( 3, count( $undo->get_data()['results'] ) );
		foreach ( $this->sources as $source ) {
			$this->assertStringNotContainsString( '<a href=', $this->stored( $source ) );
		}
	}

	public function test_a_failed_link_is_reported_and_the_others_still_go_in(): void {
		$this->corpus( 3 );
		$a = $this->first_outbound( $this->sources[1] )['insert'];
		$b = $this->first_outbound( $this->sources[2] )['insert'];

		// El texto de la segunda ha cambiado desde que se calculó la sugerencia.
		wp_update_post(
			array(
				'ID'           => $this->sources[2],
				'post_content' => $this->p( 'Contenido completamente distinto, sin nada de lo que había.' ),
			)
		);

		$data = $this->request( 'POST', '/links', array( 'links' => array( $a, $b ) ) )->get_data();

		$this->assertSame( 1, $data['inserted'] );
		$this->assertSame( 1, $data['failed'] );
		$this->assertSame( 'inserted', $data['results'][0]['status'] );
		$this->assertSame( 'failed', $data['results'][1]['status'] );
		$this->assertSame( 'text_changed', $data['results'][1]['reason'] );
		$this->assertNotSame( '', $data['results'][1]['message'] );
		$this->assertStringContainsString( '<a href=', $this->stored( $this->sources[1] ) );
		$this->assertStringNotContainsString( '<a href=', $this->stored( $this->sources[2] ) );
	}

	public function test_an_entry_open_in_another_editor_is_locked(): void {
		$this->corpus();
		$link = $this->first_outbound( $this->sources[1] )['insert'];
		update_post_meta( $this->sources[1], '_edit_lock', time() . ':' . $this->editor );

		$data = $this->request( 'POST', '/links', $link )->get_data();

		$this->assertSame( 0, $data['inserted'] );
		$this->assertSame( 'locked', $data['results'][0]['reason'] );
		$this->assertNull( $data['batch_id'] );
		$this->assertStringNotContainsString( '<a href=', $this->stored( $this->sources[1] ) );
	}

	public function test_the_destination_is_validated_and_the_url_is_never_the_clients(): void {
		$this->corpus();
		$link = $this->first_outbound( $this->sources[1] )['insert'];

		$draft   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		foreach ( array(
			'borrador'  => $draft,
			'privada'   => $private,
			'inventada' => 987654,
			'propia'    => $this->sources[1],
		) as $what => $target_id ) {
			$result = $this->request( 'POST', '/links', array_merge( $link, array( 'target_id' => $target_id ) ) )->get_data()['results'][0];
			$this->assertSame( 'bad_target', $result['reason'], $what );
		}

		// Una dirección enviada por el cliente se ignora: no hay parámetro `url`.
		$data = $this->request( 'POST', '/links', array_merge( $link, array( 'url' => 'https://evil.example/' ) ) )->get_data();
		$this->assertSame( 1, $data['inserted'] );
		$this->assertStringNotContainsString( 'evil.example', $this->stored( $this->sources[1] ) );
		$this->assertStringContainsString( (string) get_permalink( $this->target ), $this->stored( $this->sources[1] ) );
	}

	public function test_an_anchor_that_is_not_part_of_the_sentence_is_refused(): void {
		$this->corpus();
		$link = $this->first_outbound( $this->sources[1] )['insert'];

		$result = $this->request( 'POST', '/links', array_merge( $link, array( 'anchor' => 'palabras que no están' ) ) )->get_data()['results'][0];

		$this->assertSame( 'bad_request', $result['reason'] );
		$this->assertStringNotContainsString( '<a href=', $this->stored( $this->sources[1] ) );
	}

	public function test_several_links_in_one_entry_are_one_write_and_undo_one_by_one(): void {
		$this->corpus( 3 );
		$post    = $this->sources[1];
		$content = $this->stored( $post );
		$first   = $this->first_outbound( $post )['insert'];
		$second  = array_merge(
			$first,
			array(
				'target_id' => $this->sources[2],
				'sentence'  => 'Lo importante de ventanas, persianas es no tener prisa.',
				'anchor'    => 'no tener prisa',
				'offset'    => (int) strpos( 'Lo importante de ventanas, persianas es no tener prisa.', 'no tener prisa' ),
			)
		);
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => $content . $this->p( 'Lo importante de ventanas, persianas es no tener prisa.' ),
			)
		);
		$content = $this->stored( $post );
		$writes  = 0;
		add_action(
			'post_updated',
			static function () use ( &$writes ): void {
				++$writes;
			}
		);
		$revisions = count( wp_get_post_revisions( $post ) );

		$data = $this->request( 'POST', '/links', array( 'links' => array( $first, $second ) ) )->get_data();

		$this->assertSame( 2, $data['inserted'] );
		$this->assertSame( 1, $writes, 'Una sola escritura para los dos enlaces.' );
		$this->assertSame( $revisions + 1, count( wp_get_post_revisions( $post ) ), 'Una sola revisión.' );
		$this->assertCount( 2, $this->link_rows( $post ), 'El grafo tiene los dos enlaces.' );
		$changes = $this->request( 'GET', '/history/' . $data['batch_id'] . '/changes' )->get_data();
		$this->assertSame( 2, $changes['total'], 'Cada enlace tiene su fila.' );

		// Deshacer solo el primero deja el segundo.
		$undo = $this->request( 'POST', '/undo', array( 'change_id' => $data['results'][0]['change_id'] ) );
		$this->assertSame( 200, $undo->get_status() );
		$this->assertSame( 'link_removed', $undo->get_data()['results'][0]['status'], 'Con el otro enlace puesto solo se quita el suyo.' );
		$this->assertSame( 1, substr_count( $this->stored( $post ), '<a href=' ) );
		$this->assertStringContainsString( (string) get_permalink( $this->sources[2] ), $this->stored( $post ) );

		// Y el lote entero lo deja como estaba.
		$redo = $this->request( 'POST', '/redo', array( 'change_id' => $data['results'][0]['change_id'] ) );
		$this->assertSame( 200, $redo->get_status() );
		$this->assertSame( 'redone', $redo->get_data()['results'][0]['status'] );
		$this->assertSame( 2, substr_count( $this->stored( $post ), '<a href=' ) );
		$batch_undo = $this->request( 'POST', '/undo', array( 'batch_id' => $data['batch_id'] ) );
		$this->assertSame( 200, $batch_undo->get_status() );
		foreach ( $batch_undo->get_data()['results'] as $result ) {
			$this->assertContains( $result['status'], array( 'restored', 'link_removed' ) );
		}
		$this->assertSame( $content, $this->stored( $post ) );
	}

	public function test_two_entries_where_one_fails_share_the_batch_and_report_a_partial_result(): void {
		$this->corpus( 3 );
		$good = $this->first_outbound( $this->sources[1] )['insert'];
		$ok2  = $this->first_outbound( $this->sources[2] )['insert'];
		update_post_meta( $this->sources[2], '_edit_lock', time() . ':' . $this->editor );

		$data = $this->request( 'POST', '/links', array( 'links' => array( $good, $ok2 ) ) )->get_data();

		$this->assertSame( 1, $data['inserted'] );
		$this->assertSame( 1, $data['failed'] );
		$this->assertNotNull( $data['batch_id'] );
		$this->assertSame( 'inserted', $data['results'][0]['status'] );
		$this->assertSame( 'failed', $data['results'][1]['status'] );
		$this->assertSame( 'locked', $data['results'][1]['reason'] );
		$changes = $this->request( 'GET', '/history/' . $data['batch_id'] . '/changes' )->get_data();
		$this->assertSame( array( $data['results'][0]['change_id'] ), array_column( $changes['items'], 'id' ) );
		$this->assertStringNotContainsString( '<a href=', $this->stored( $this->sources[2] ) );
	}

	public function test_an_invalid_link_in_a_grouped_entry_does_not_stop_the_others(): void {
		$this->corpus( 3 );
		$good = $this->first_outbound( $this->sources[1] )['insert'];
		$bad  = array_merge( $good, array( 'anchor' => 'nada de esto' ) );

		$data = $this->request( 'POST', '/links', array( 'links' => array( $bad, $good ) ) )->get_data();

		$this->assertSame( 'failed', $data['results'][0]['status'] );
		$this->assertSame( 'inserted', $data['results'][1]['status'] );
		$this->assertSame( 1, $data['inserted'] );
	}

	// ------------------------------------------------------------------ Título.

	public function test_an_empty_title_from_the_editor_is_respected(): void {
		$this->corpus();
		$seen = array();
		add_filter(
			'magiclinking_post_html',
			static function ( $html, $post ) use ( &$seen ) {
				$seen[] = $post->post_title;
				return $html;
			},
			10,
			2
		);

		$this->request(
			'POST',
			'/suggestions/outbound',
			array(
				'post_id' => $this->sources[1],
				'content' => $this->p( 'Texto cualquiera.' ),
				'title'   => '',
			)
		);
		$this->request(
			'POST',
			'/suggestions/outbound',
			array(
				'post_id' => $this->sources[1],
				'content' => $this->p( 'Texto cualquiera.' ),
			)
		);

		$this->assertSame( '', $seen[0], 'Un título vacío del editor no es el título guardado.' );
		$this->assertSame( get_the_title( $this->sources[1] ), $seen[1], 'Sin título se usa el guardado.' );
	}

	// ------------------------------------------------------------------ Caché.

	public function test_nothing_is_cached_in_the_database_without_a_persistent_object_cache(): void {
		global $wpdb;
		$this->corpus();

		$this->assertFalse( SuggestionCache::enabled() );
		$this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $this->sources[1] ) );
		$this->request( 'GET', '/suggestions/inbound', array( 'post_id' => $this->target ) );

		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%magiclinking\\_sg%'" ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, $rows );
	}

	public function test_with_an_object_cache_results_are_cached_and_invalidated_on_change(): void {
		global $wpdb;
		$this->corpus();
		wp_using_ext_object_cache( true );
		$this->assertTrue( SuggestionCache::enabled() );

		$first = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $this->sources[1] ) )->get_data();
		$this->assertNotEmpty( $first['items'] );

		// Un cambio que no pasa por WordPress (sin hooks): la caché sigue dando lo guardado.
		$wpdb->update( $wpdb->posts, array( 'post_content' => $this->p( 'Nada que ver.' ) ), array( 'ID' => $this->sources[1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $this->sources[1] );
		$cached = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $this->sources[1] ) )->get_data();
		$this->assertSame( $first['items'], $cached['items'] );

		// Guardar la entrada renueva la época.
		wp_update_post(
			array(
				'ID'           => $this->sources[1],
				'post_content' => $this->p( 'Nada que ver con ningún tema de la casa.' ),
			)
		);
		$fresh = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $this->sources[1] ) )->get_data();
		$this->assertNotContains( $this->target, array_column( array_column( $fresh['items'], 'target' ), 'id' ) );
	}

	private function epoch(): string {
		return (string) wp_cache_get_last_changed( SuggestionCache::GROUP );
	}

	public function test_the_first_save_of_the_settings_also_renews_the_cache_epoch(): void {
		$this->corpus();
		delete_option( 'magiclinking_settings' );
		$before = $this->epoch();
		usleep( 2000 );

		add_option( 'magiclinking_settings', array( 'post_types' => array( 'post' ) ) );
		$created = $this->epoch();
		$this->assertNotSame( $before, $created, 'add_option renueva la época.' );

		usleep( 2000 );
		update_option( 'magiclinking_settings', array( 'post_types' => array( 'post', 'page' ) ) );
		$this->assertNotSame( $created, $this->epoch() );

		$updated = $this->epoch();
		usleep( 2000 );
		delete_option( 'magiclinking_settings' );
		$this->assertNotSame( $updated, $this->epoch(), 'Borrarlos también.' );
	}

	public function test_only_relevant_saves_renew_the_epoch(): void {
		$this->corpus();
		$published  = $this->sources[1];
		$irrelevant = array(
			'borrador'          => fn() => self::factory()->post->create( array( 'post_status' => 'draft' ) ),
			'borrador auto'     => fn() => self::factory()->post->create( array( 'post_status' => 'auto-draft' ) ),
			'menú'              => fn() => self::factory()->post->create(
				array(
					'post_type'   => 'nav_menu_item',
					'post_status' => 'publish',
				)
			),
			'tipo sin análisis' => fn() => self::factory()->post->create(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
				)
			),
			'revisión'          => fn() => wp_save_post_revision( $published ),
		);

		foreach ( $irrelevant as $what => $action ) {
			$before = $this->epoch();
			usleep( 2000 );
			$action();
			$this->assertSame( $before, $this->epoch(), "No renueva: {$what}." );
		}

		// Un borrador que se guarda de nuevo, tampoco.
		$draft  = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$before = $this->epoch();
		usleep( 2000 );
		wp_update_post(
			array(
				'ID'           => $draft,
				'post_content' => 'otro texto',
			)
		);
		$this->assertSame( $before, $this->epoch(), 'Guardar un borrador no renueva.' );

		// Publicarlo, editar una publicada, despublicarla y borrarla, sí.
		foreach ( array(
			'publicar'    => fn() => wp_update_post(
				array(
					'ID'          => $draft,
					'post_status' => 'publish',
				)
			),
			'editar'      => fn() => wp_update_post(
				array(
					'ID'           => $draft,
					'post_content' => 'texto nuevo',
				)
			),
			'despublicar' => fn() => wp_update_post(
				array(
					'ID'          => $draft,
					'post_status' => 'draft',
				)
			),
		) as $what => $action ) {
			$before = $this->epoch();
			usleep( 2000 );
			$action();
			$this->assertNotSame( $before, $this->epoch(), "Renueva: {$what}." );
		}
	}
}
