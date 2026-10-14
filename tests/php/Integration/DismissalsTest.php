<?php
/**
 * Descartar persistente (F1-28, docs/04 §11): el par (origen, destino) descartado no vuelve a proponerse en
 * ningún sentido, se puede deshacer, un destino muy descartado baja su prior y sin descartes todo queda igual.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\Index\DismissalRepository;
use MagicLinking\Jobs\Jobs;
use WP_REST_Request;
use WP_REST_Response;

final class DismissalsTest extends GraphTestCase {

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

	/**
	 * Peticiones HTTP que intentó hacer el plugin.
	 *
	 * @var list<string>
	 */
	private array $http = array();

	public function set_up(): void {
		parent::set_up();

		add_filter( 'locale', static fn(): string => 'es_ES' );
		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->author     = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );

		$this->http = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->http[] = (string) $url;
				return new \WP_Error( 'blocked', 'Sin red en las pruebas.' );
			},
			10,
			3
		);

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

	private function dismiss( int $source, int $target, ?int $user = null ): WP_REST_Response {
		return $this->request(
			'POST',
			'/suggestions/dismissals',
			array(
				'source_id' => $source,
				'target_id' => $target,
			),
			$user
		);
	}

	private function restore( int $source, int $target, ?int $user = null ): WP_REST_Response {
		return $this->request( 'DELETE', "/suggestions/dismissals/{$source}/{$target}", array(), $user );
	}

	private function repo(): DismissalRepository {
		return Plugin::container()->get( DismissalRepository::class );
	}

	private function has( int $source, int $target ): bool {
		return in_array( $target, $this->repo()->targets_for_source( $source ), true );
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

	/**
	 * Destinos que propone la API saliente de un origen.
	 *
	 * @return list<int>
	 */
	private function outbound_targets( int $source, ?int $user = null ): array {
		$response = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $source ), $user );
		$this->assertSame( 200, $response->get_status() );

		return array_map( static fn( array $item ): int => $item['target']['id'], $response->get_data()['items'] );
	}

	/**
	 * Orígenes que propone la API entrante de un destino.
	 *
	 * @return list<int>
	 */
	private function inbound_sources( int $target ): array {
		$response = $this->request( 'GET', '/suggestions/inbound', array_merge( array( 'post_id' => $target ), array( 'per_page' => 50 ) ) );
		$this->assertSame( 200, $response->get_status() );

		return array_map( static fn( array $item ): int => $item['source']['id'], $response->get_data()['items'] );
	}

	/**
	 * Puntuación de la sugerencia de un origen hacia un destino (la del motor, sin pasar por la API).
	 */
	private function score( int $source, int $target ): ?float {
		foreach ( Plugin::container()->get( \MagicLinking\Index\Suggestions::class )->outgoing( $source ) as $suggestion ) {
			if ( $suggestion->target === $target ) {
				return $suggestion->score->value;
			}
		}

		return null;
	}

	// ------------------------------------------------------------------ Descartar y no volver a proponer.

	public function test_a_dismissed_outbound_suggestion_does_not_come_back(): void {
		$this->corpus();
		$source = $this->sources[1];
		$before = $this->outbound_targets( $source );
		$this->assertContains( $this->target, $before );

		$response = $this->dismiss( $source, $this->target );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['dismissed'] );

		// «Recargar el editor»: otra petición, sin estado de la anterior.
		$after = $this->outbound_targets( $source );
		$this->assertNotContains( $this->target, $after );
		$this->assertSame( array_values( array_diff( $before, array( $this->target ) ) ), $after, 'Las demás siguen igual y en el mismo orden.' );

		// El borrador del editor (POST) tampoco la propone.
		$draft = $this->request(
			'POST',
			'/suggestions/outbound',
			array(
				'post_id' => $source,
				'content' => get_post_field( 'post_content', $source, 'raw' ),
			)
		);
		$this->assertSame( 200, $draft->get_status() );
		$this->assertNotContains( $this->target, array_map( static fn( array $i ): int => $i['target']['id'], $draft->get_data()['items'] ) );
	}

	public function test_a_dismissed_inbound_suggestion_does_not_come_back_in_either_direction(): void {
		$this->corpus();
		$origin  = $this->sources[2];
		$sources = $this->inbound_sources( $this->target );
		$this->assertContains( $origin, $sources );

		$this->assertSame( 200, $this->dismiss( $origin, $this->target )->get_status() );

		$this->assertSame( array_values( array_diff( $sources, array( $origin ) ) ), $this->inbound_sources( $this->target ) );
		$this->assertNotContains( $this->target, $this->outbound_targets( $origin ), 'El mismo par tampoco sale en las salientes del origen.' );
		$total = $this->request( 'GET', '/suggestions/inbound', array( 'post_id' => $this->target ) )->get_data()['total'];
		$this->assertSame( count( $sources ) - 1, $total, 'El total entrante deja de contarla.' );
	}

	public function test_a_dismissal_only_affects_its_own_pair(): void {
		$this->corpus();
		$this->dismiss( $this->sources[1], $this->target );

		$this->assertContains( $this->target, $this->outbound_targets( $this->sources[2] ), 'Otro origen hacia el mismo destino sigue.' );
		$this->assertTrue( $this->has( $this->sources[1], $this->target ) );
		$this->assertFalse( $this->has( $this->target, $this->sources[1] ), 'El par es dirigido.' );
	}

	public function test_dismissing_twice_keeps_one_row_and_succeeds(): void {
		$this->corpus();
		$this->assertSame( 200, $this->dismiss( $this->sources[1], $this->target )->get_status() );
		$this->assertSame( 200, $this->dismiss( $this->sources[1], $this->target )->get_status() );

		$this->assertSame( array( $this->target ), $this->repo()->targets_for_source( $this->sources[1] ) );
	}

	// ------------------------------------------------------------------ Deshacer.

	public function test_undoing_a_dismissal_brings_the_suggestion_back_unchanged(): void {
		$this->corpus();
		$source = $this->sources[1];
		$before = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $source ) )->get_data();
		$score  = $this->score( $source, $this->target );

		$this->dismiss( $source, $this->target );
		$this->assertNotContains( $this->target, $this->outbound_targets( $source ) );

		$response = $this->restore( $source, $this->target );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['dismissed'] );

		$this->assertEquals( $before, $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $source ) )->get_data(), 'Sin descartes vuelve exactamente lo de antes.' );
		$this->assertSame( $score, $this->score( $source, $this->target ) );
		$this->assertSame( 200, $this->restore( $source, $this->target )->get_status(), 'Deshacer dos veces no falla.' );
	}

	// ------------------------------------------------------------------ Prior del destino.

	public function test_a_target_dismissed_from_more_than_ten_origins_loses_a_tenth_everywhere(): void {
		$this->corpus( 3 );
		$repo   = $this->repo();
		$source = $this->sources[1];
		$other  = $this->sources[2];
		$ids    = self::factory()->post->create_many( 11, array( 'post_title' => 'Relleno' ) );
		$base_a = $this->score( $source, $this->target );
		$base_b = $this->score( $other, $this->target );
		$this->assertNotNull( $base_a );
		$this->assertNotNull( $base_b );

		foreach ( array_slice( $ids, 0, 10 ) as $id ) {
			$this->assertSame( 200, $this->dismiss( $id, $this->target )->get_status() );
		}
		$this->assertSame( array(), $repo->penalized_targets( array( 'post' ) ), 'Con 10 orígenes todavía no se rebaja.' );
		$this->assertSame( $base_a, $this->score( $source, $this->target ) );

		$this->assertSame( 200, $this->dismiss( $ids[10], $this->target )->get_status() );
		$this->assertSame( array( $this->target ), $repo->penalized_targets( array( 'post' ) ) );
		$this->assertEqualsWithDelta( $base_a - 0.1, (float) $this->score( $source, $this->target ), 1e-9 );
		$this->assertEqualsWithDelta( $base_b - 0.1, (float) $this->score( $other, $this->target ), 1e-9, 'En todo el sitio, no solo en los orígenes descartados.' );

		// Deshacer uno de los once devuelve el prior.
		$this->restore( $ids[10], $this->target );
		$this->assertSame( array(), $repo->penalized_targets( array( 'post' ) ) );
		$this->assertSame( $base_a, $this->score( $source, $this->target ) );
	}

	// ------------------------------------------------------------------ Permisos y errores.

	public function test_both_routes_need_a_login_and_edit_posts(): void {
		$this->corpus();
		$source = $this->sources[1];

		$this->assertSame( 401, $this->dismiss( $source, $this->target, 0 )->get_status() );
		$this->assertSame( 403, $this->dismiss( $source, $this->target, $this->subscriber )->get_status() );
		$this->assertSame( 401, $this->restore( $source, $this->target, 0 )->get_status() );
		$this->assertSame( 403, $this->restore( $source, $this->target, $this->subscriber )->get_status() );
		$this->assertFalse( $this->has( $source, $this->target ), 'Nada se ha guardado.' );
	}

	public function test_the_capability_is_edit_post_on_the_origin(): void {
		$this->corpus();
		$mine   = $this->sources[0];
		$theirs = $this->sources[1];

		// Un autor no puede descartar para una entrada ajena como origen, ni deshacerlo.
		$this->assertSame( 403, $this->dismiss( $theirs, $this->target, $this->author )->get_status() );
		$this->assertFalse( $this->has( $theirs, $this->target ) );
		$this->repo()->add( $theirs, $this->target, $this->admin );
		$this->assertSame( 403, $this->restore( $theirs, $this->target, $this->author )->get_status() );
		$this->assertTrue( $this->has( $theirs, $this->target ), 'Sigue descartado.' );

		// Sí con la suya, aunque el destino sea ajeno.
		$this->assertSame( 200, $this->dismiss( $mine, $this->target, $this->author )->get_status() );
		$this->assertTrue( $this->has( $mine, $this->target ) );
		$this->assertSame( 200, $this->restore( $mine, $this->target, $this->author )->get_status() );
		$this->assertFalse( $this->has( $mine, $this->target ) );
	}

	public function test_bad_requests(): void {
		$this->corpus();

		$this->assertSame( 404, $this->dismiss( 999999, $this->target )->get_status(), 'Origen inexistente.' );
		$this->assertSame( 404, $this->dismiss( $this->sources[1], 999999 )->get_status(), 'Destino inexistente.' );
		$this->assertSame( 400, $this->dismiss( $this->sources[1], $this->sources[1] )->get_status(), 'No se enlaza a uno mismo.' );
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/suggestions/dismissals',
				array(
					'source_id' => 0,
					'target_id' => $this->target,
				)
			)->get_status()
		);
		$this->assertSame( array(), $this->repo()->targets_for_source( $this->sources[1] ) );
	}

	// ------------------------------------------------------------------ Limpieza, esquema y privacidad.

	public function test_deleting_an_entry_forgets_its_pairs(): void {
		$this->corpus();
		$this->dismiss( $this->sources[1], $this->target );
		$this->dismiss( $this->sources[2], $this->target );

		wp_delete_post( $this->sources[1], true );
		$this->assertFalse( $this->has( $this->sources[1], $this->target ) );
		$this->assertTrue( $this->has( $this->sources[2], $this->target ) );

		wp_delete_post( $this->target, true );
		$this->assertSame( array(), $this->repo()->targets_for_source( $this->sources[2] ) );
	}

	public function test_the_table_is_created_and_nothing_goes_out_of_the_site(): void {
		global $wpdb;
		$this->corpus();
		$this->dismiss( $this->sources[1], $this->target );
		$this->restore( $this->sources[1], $this->target );
		$this->outbound_targets( $this->sources[1] );

		$name = Schema::table( $wpdb->prefix, 'dismissed' );
		$this->assertContains( 'dismissed', Schema::TABLES );
		$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( array(), $this->http, 'Ninguna petición HTTP.' );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on') AND option_name LIKE %s", $wpdb->esc_like( 'magiclinking_' ) . '%dismiss%' ) ) ); // phpcs:ignore WordPress.DB
	}

	// ------------------------------------------------------------------ Revisión de código del PR.

	public function test_drafts_and_unpublished_origins_do_not_count_for_the_site_wide_penalty(): void {
		$this->corpus( 2 );
		$drafts = self::factory()->post->create_many( 11, array( 'post_status' => 'draft' ) );
		foreach ( $drafts as $id ) {
			$this->dismiss( $id, $this->target );
		}
		$this->assertSame( array(), $this->repo()->penalized_targets( array( 'post' ) ), 'Once borradores no rebajan el destino.' );

		$published = self::factory()->post->create_many( 11 );
		foreach ( $published as $i => $id ) {
			$this->dismiss( $id, $this->target );
			$expected = $i + 1 > 10 ? array( $this->target ) : array();
			$this->assertSame( $expected, $this->repo()->penalized_targets( array( 'post' ) ) );
		}

		$this->assertSame( array(), $this->repo()->penalized_targets( array( 'page' ) ), 'Solo cuentan orígenes de tipos analizados.' );
	}

	public function test_only_analyzed_origins_are_accepted(): void {
		$this->corpus( 2 );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( 200, $this->dismiss( $page, $this->target )->get_status(), 'Una página se analiza por defecto.' );

		$revision = wp_save_post_revision( $this->sources[1] );
		$this->assertNotEmpty( $revision );
		$attachment = self::factory()->attachment->create();
		$auto       = self::factory()->post->create( array( 'post_status' => 'auto-draft' ) );
		foreach ( array( (int) $revision, $attachment, $auto ) as $bad ) {
			$this->assertSame( 404, $this->dismiss( $bad, $this->target )->get_status(), "Origen no válido {$bad}" );
			$this->assertSame( array(), $this->repo()->targets_for_source( $bad ) );
		}
	}

	public function test_a_target_that_does_not_exist_or_cannot_be_read_gets_the_same_answer(): void {
		$this->corpus( 2 );
		$private    = self::factory()->post->create(
			array(
				'post_status' => 'private',
				'post_author' => $this->admin,
			)
		);
		$other_type = self::factory()->attachment->create();
		$mine       = $this->sources[0];

		$missing = $this->dismiss( $mine, 999999, $this->author );
		$denied  = $this->dismiss( $mine, $private, $this->author );
		$typed   = $this->dismiss( $mine, $other_type, $this->author );

		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( $missing->get_status(), $denied->get_status() );
		$this->assertSame( $missing->get_status(), $typed->get_status() );
		$this->assertSame( $missing->get_data()['code'], $denied->get_data()['code'] );
		$this->assertSame( $missing->get_data()['message'], $denied->get_data()['message'] );
		$this->assertSame( array(), $this->repo()->targets_for_source( $mine ) );

		// Quien sí puede leer el privado, puede descartarlo.
		$this->assertSame( 200, $this->dismiss( $this->sources[1], $private, $this->admin )->get_status() );
	}

	public function test_deleting_unrelated_content_does_not_touch_the_table(): void {
		$this->corpus( 2 );
		$this->dismiss( $this->sources[1], $this->target );
		$deletes = 0;
		add_filter(
			'query',
			static function ( $sql ) use ( &$deletes ) {
				if ( str_contains( (string) $sql, 'magiclinking_dismissed' ) ) {
					++$deletes;
				}
				return $sql;
			}
		);

		wp_delete_post( (int) wp_save_post_revision( $this->sources[0] ), true );
		wp_delete_post( self::factory()->attachment->create(), true );
		$this->assertSame( 0, $deletes, 'Revisiones y adjuntos no consultan la tabla.' );

		wp_delete_post( $this->sources[1], true );
		$this->assertGreaterThan( 0, $deletes );
	}

	public function test_forgetting_a_deleted_origin_renews_the_suggestion_cache(): void {
		$this->corpus( 2 );
		// Un borrador no renueva la caché por sí mismo: si se renueva, es por haber borrado sus pares.
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->dismiss( $draft, $this->target );
		$before = wp_cache_get_last_changed( \MagicLinking\Index\SuggestionCache::GROUP );
		usleep( 2000 );

		wp_delete_post( $draft, true );

		$this->assertSame( array(), $this->repo()->targets_for_source( $draft ) );
		$this->assertNotSame( $before, wp_cache_get_last_changed( \MagicLinking\Index\SuggestionCache::GROUP ) );
	}

	public function test_the_table_name_follows_the_current_site(): void {
		global $wpdb;
		$original = $wpdb->prefix;
		$other    = $original . 'otro_';
		$wpdb->query( "CREATE TEMPORARY TABLE {$other}magiclinking_dismissed LIKE {$original}magiclinking_dismissed" ); // phpcs:ignore WordPress.DB
		$repo = $this->repo();

		$wpdb->prefix = $other;
		try {
			$repo->add( 11, 22, 1 );
			$this->assertSame( array( 22 ), $repo->targets_for_source( 11 ) );
		} finally {
			$wpdb->prefix = $original;
		}
		$this->assertSame( array(), $repo->targets_for_source( 11 ), 'El sitio original no ve el par del otro.' );
	}

	public function test_the_penalized_list_is_cached_until_something_changes(): void {
		$this->corpus( 2 );
		$queries = 0;
		add_filter(
			'query',
			static function ( $sql ) use ( &$queries ) {
				if ( str_contains( (string) $sql, 'HAVING COUNT' ) ) {
					++$queries;
				}
				return $sql;
			}
		);
		$repo = $this->repo();

		$repo->penalized_targets( array( 'post' ) );
		$repo->penalized_targets( array( 'post' ) );
		$this->assertSame( 1, $queries, 'La segunda lectura sale de la caché.' );

		$this->dismiss( $this->sources[1], $this->target );
		$repo->penalized_targets( array( 'post' ) );
		$this->assertSame( 2, $queries, 'Descartar renueva la época y se recalcula.' );
	}
}
