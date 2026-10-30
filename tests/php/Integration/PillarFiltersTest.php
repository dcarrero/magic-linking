<?php
/**
 * Contenido pilar y filtros Ignorar/Considerar/Exigir (F1-25, D-30).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Settings;
use MagicLinking\Engine\Suggestion;
use MagicLinking\Index\Pillars;
use MagicLinking\Index\SuggestionCache;
use MagicLinking\Index\Suggestions;
use MagicLinking\Jobs\Jobs;
use WP_REST_Request;
use WP_REST_Response;

final class PillarFiltersTest extends GraphTestCase {

	private const BASE = '/magic-linking/v1';

	private int $admin = 0;

	/**
	 * Origen: categoría «energia», etiqueta «hogar».
	 */
	private int $source = 0;

	/**
	 * Destinos: misma categoría, otra categoría y misma categoría pero en inglés.
	 */
	private int $same = 0;

	private int $other = 0;

	private int $english = 0;

	/**
	 * Una página sobre lo mismo (tipo distinto).
	 */
	private int $page = 0;

	/**
	 * Un origen de otra categoría que menciona la aerotermia.
	 */
	private int $stray = 0;

	public function set_up(): void {
		parent::set_up();

		add_filter( 'locale', static fn(): string => 'es_ES' );
		add_filter( 'magiclinking_post_language', fn( $lang, $id ) => (int) $id === $this->english ? 'en' : null, 10, 2 );

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );
		update_option( Installer::SETTINGS_OPTION, array( 'post_types' => array( 'post', 'page' ) ) );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->corpus();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function p( string $html ): string {
		return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
	}

	private function category( string $name ): int {
		$term = term_exists( $name, 'category' );

		return is_array( $term ) ? (int) $term['term_id'] : (int) self::factory()->category->create( array( 'name' => $name ) );
	}

	/**
	 * @param array<string, mixed> $args Argumentos de la entrada.
	 */
	private function post( string $title, string $text, string $category, string $tag, array $args = array() ): int {
		$id = self::factory()->post->create(
			$args + array(
				'post_title'    => $title,
				'post_content'  => str_starts_with( $text, '<!--' ) ? $text : $this->p( $text ),
				'post_category' => array( $this->category( $category ) ),
				'tags_input'    => array( $tag ),
			)
		);

		return $id;
	}

	private function corpus(): void {
		$this->same    = $this->post( 'Aerotermia: guía completa', 'La aerotermia aprovecha la energía del aire exterior para calentar la vivienda con una bomba de calor eficiente.', 'energia', 'hogar' );
		$this->other   = $this->post( 'Placas solares para autoconsumo', 'Las placas solares permiten el autoconsumo y bajan la factura de la luz con un inversor adecuado.', 'bricolaje', 'hogar' );
		$this->english = $this->post( 'Solar panels at home', 'Solar panels allow self consumption and lower the electricity bill with a suitable inverter.', 'energia', 'hogar' );
		$this->page    = $this->post( 'Suelo radiante explicado', 'El suelo radiante reparte el calor por toda la vivienda con agua a baja temperatura.', 'energia', 'hogar', array( 'post_type' => 'page' ) );
		foreach ( array( 'aceite de oliva', 'gazpacho andaluz', 'tortilla de patatas', 'pan de masa madre', 'queso manchego', 'cocina mediterránea' ) as $dish ) {
			$this->post( 'Receta de ' . $dish, 'Una receta sencilla de ' . $dish . ' con ingredientes de temporada, paso a paso y con trucos para que salga jugosa en la mesa.', 'cocina', 'recetas' );
		}

		$this->stray = $this->post( 'Herramientas de bricolaje', 'Entre taladros y destornilladores, hoy hablamos de la aerotermia que instaló un vecino.', 'bricolaje', 'taller' );

		$this->source = $this->post(
			'Reformar la casa sin sorpresas',
			$this->p( 'Si vas a reformar, la aerotermia cambia el presupuesto del proyecto.' ) . "\n" . $this->p( 'Las placas solares también cambian el presupuesto del proyecto.' ) . "\n" . $this->p( 'El suelo radiante cambia el presupuesto del proyecto.' ),
			'energia',
			'hogar'
		);

		global $wpdb;
		foreach ( array( 'docs', 'links', 'jobs', 'terms', 'postings' ) as $table ) {
			$name = \MagicLinking\Core\Schema::table( $wpdb->prefix, $table );
			$wpdb->query( "DELETE FROM {$name}" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$jobs = Plugin::container()->get( Jobs::class );
		$job  = $jobs->start_index( 0, false, true, true, true );
		$jobs->run_to_completion( $job['id'] );
	}

	private function service(): Suggestions {
		return Plugin::container()->get( Suggestions::class );
	}

	private function filters( array $filters ): void {
		( new Settings() )->update( array( 'filters' => $filters ) );
	}

	/**
	 * @param list<Suggestion> $found Sugerencias.
	 *
	 * @return list<int>
	 */
	private static function targets( array $found ): array {
		return array_values( array_map( static fn( Suggestion $s ): int => $s->target, $found ) );
	}

	private function request( string $method, string $route, array $params = array(), ?int $user = null ): WP_REST_Response {
		wp_set_current_user( $user ?? $this->admin );
		$request = new WP_REST_Request( $method, self::BASE . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	// ------------------------------------------------------------------ Contenido pilar.

	public function test_pillar_is_detected_from_yoast_and_rank_math_without_touching_anything(): void {
		$pillars = Plugin::container()->get( Pillars::class );
		$this->assertSame( array(), $pillars->ids() );

		update_post_meta( $this->same, '_yoast_wpseo_is_cornerstone', '1' );
		update_post_meta( $this->other, 'rank_math_pillar_content', 'on' );
		update_post_meta( $this->page, '_yoast_wpseo_is_cornerstone', '0' );
		update_post_meta( $this->english, 'rank_math_pillar_content', 'off' );

		$ids = $pillars->ids();
		sort( $ids );
		$expected = array( $this->same, $this->other );
		sort( $expected );
		$this->assertSame( $expected, $ids, 'La lista se renueva sola al cambiar la meta y solo cuenta los valores activos.' );
		$this->assertSame( 'yoast', $pillars->source( $this->same ) );
		$this->assertSame( 'rank_math', $pillars->source( $this->other ) );
		$this->assertNull( $pillars->source( $this->page ) );
		$this->assertFalse( $pillars->own( $this->same ), 'No se ha tocado la casilla propia.' );
	}

	public function test_own_mark_wins_the_label_and_is_removed_not_zeroed(): void {
		$pillars = Plugin::container()->get( Pillars::class );
		update_post_meta( $this->same, '_yoast_wpseo_is_cornerstone', '1' );
		$pillars->set_own( $this->same, true );
		$this->assertSame( 'own', $pillars->source( $this->same ) );

		$pillars->set_own( $this->same, false );
		$this->assertSame( 'yoast', $pillars->source( $this->same ) );
		$this->assertSame( array(), get_post_meta( $this->same, Pillars::OWN_KEY, false ), 'Desmarcada, la meta se borra.' );
	}

	public function test_pillar_adds_a_bonus_that_reorders_without_adding_candidates(): void {
		$before = $this->service()->outgoing( $this->source );
		$this->assertContains( $this->same, self::targets( $before ), 'Precondición: la sugerencia existe sin pilar.' );

		update_post_meta( $this->same, '_yoast_wpseo_is_cornerstone', '1' );
		$after = $this->service()->outgoing( $this->source );

		$this->assertSame( array(), $this->bonuses( $before, $this->same ) );
		$this->assertSame( array( 'pillar' => Suggestions::BONUSES['pillar'] ), $this->bonuses( $after, $this->same ) );
		$this->assertEqualsCanonicalizing( self::targets( $before ), self::targets( $after ), 'La bonificación no añade ni quita candidatas.' );
	}

	/**
	 * @param list<Suggestion> $found  Sugerencias.
	 * @param int              $target Destino.
	 *
	 * @return array<string, float>
	 */
	private function bonuses( array $found, int $target ): array {
		foreach ( $found as $suggestion ) {
			if ( $suggestion->target === $target ) {
				return $suggestion->score->bonuses;
			}
		}

		return array();
	}

	public function test_pillar_meta_is_never_autoloaded_and_the_plugin_adds_no_option(): void {
		global $wpdb;
		$list = static fn(): array => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%magiclinking%' AND autoload IN ('yes','on','auto','auto-on')" ); // phpcs:ignore WordPress.DB
		$was  = $list();

		Plugin::container()->get( Pillars::class )->set_own( $this->same, true );
		update_post_meta( $this->other, 'rank_math_pillar_content', 'on' );
		$this->filters( array( 'category' => 'require' ) );
		$this->service()->outgoing( $this->source );

		$this->assertSame( $was, $list(), 'Ni la marca ni los filtros crean opciones en autoload.' );
		foreach ( $list() as $name ) {
			$this->assertSame( 'magiclinking_settings', $name );
		}
	}

	public function test_pillar_route_reports_the_external_mark_and_checks_permissions(): void {
		update_post_meta( $this->same, 'rank_math_pillar_content', 'on' );

		$data = $this->request( 'GET', '/pillar/' . $this->same )->get_data();
		$this->assertTrue( $data['pillar'] );
		$this->assertFalse( $data['own'] );
		$this->assertSame( 'rank_math', $data['external'] );

		$data = $this->request( 'POST', '/pillar/' . $this->other, array( 'pillar' => true ) )->get_data();
		$this->assertTrue( $data['pillar'] );
		$this->assertTrue( $data['own'] );
		$this->assertNull( $data['external'] );
		$data = $this->request( 'POST', '/pillar/' . $this->other, array( 'pillar' => false ) )->get_data();
		$this->assertFalse( $data['pillar'] );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertSame( 403, $this->request( 'POST', '/pillar/' . $this->other, array( 'pillar' => true ), $subscriber )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/pillar/' . $this->other, array(), $subscriber )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/pillar/99999999' )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/pillar/' . $this->other )->get_status(), 'Falta `pillar`.' );
	}

	// ------------------------------------------------------------------ Filtros.

	public function test_without_filters_other_categories_and_types_are_suggested(): void {
		$found = self::targets( $this->service()->outgoing( $this->source ) );

		$this->assertContains( $this->same, $found );
		$this->assertContains( $this->other, $found, 'Sin filtros, otra categoría sale.' );
		$this->assertNotContains( $this->english, $found, 'Nunca otro idioma.' );
	}

	public function test_require_category_never_returns_another_category_or_language(): void {
		$energia = $this->category( 'energia' );
		$this->assertContains( $this->stray, array_map( static fn( Suggestion $s ): int => $s->source, $this->service()->incoming( $this->same ) ), 'Precondición: sin filtro, el origen de otra categoría sale.' );
		$this->filters( array( 'category' => 'require' ) );

		$out = self::targets( $this->service()->outgoing( $this->source ) );
		$this->assertContains( $this->same, $out );
		$this->assertNotContains( $this->other, $out, 'Otra categoría.' );
		$this->assertNotContains( $this->english, $out, 'Otro idioma.' );
		foreach ( $out as $id ) {
			$this->assertContains( $energia, wp_get_post_categories( $id ) );
			$this->assertSame( 'es', Plugin::container()->get( \MagicLinking\I18n\Language::class )->for_post( $id ) );
		}

		// Entrantes: los orígenes de la categoría del destino y nadie más.
		$in = array_map( static fn( Suggestion $s ): int => $s->source, $this->service()->incoming( $this->same ) );
		$this->assertContains( $this->source, $in );
		foreach ( $in as $id ) {
			$this->assertContains( $energia, wp_get_post_categories( $id ) );
		}
		$this->assertNotContains( $this->stray, $in, 'Un origen de otra categoría no propone enlaces.' );
	}

	public function test_require_is_applied_in_the_query_before_the_limit(): void {
		$energia = $this->category( 'energia' );
		$repo    = Plugin::container()->get( \MagicLinking\Index\TableRepository::class );
		$repo->use_taxonomies( true );
		$weights = $repo->terms( $this->source );

		$tt = array_map( 'strval', wp_get_object_terms( $this->same, 'category', array( 'fields' => 'tt_ids' ) ) );
		$this->assertNotEmpty( $tt );
		$best = $repo->similar( $weights, 'es', 1, array( $this->source ) );
		$kept = $repo->similar( $weights, 'es', 1, array( $this->source ), array( 'taxonomies' => array( 'category' => $tt ) ) );
		$this->assertCount( 1, $kept, 'Con un límite de 1 la consulta devuelve la mejor que cumple, no la mejor a secas filtrada después.' );
		$this->assertContains( $energia, wp_get_post_categories( (int) array_key_first( $kept ) ) );

		// Troceado (muchos términos): mismo resultado.
		$chunked = new \MagicLinking\Index\TableRepository( $GLOBALS['wpdb'], 2 );
		$this->assertSame( $kept, $chunked->similar( $weights, 'es', 1, array( $this->source ), array( 'taxonomies' => array( 'category' => $tt ) ) ) );
		$this->assertSame(
			$repo->containing( array_keys( $weights ), 'es', 10, array( $this->source ), array( 'types' => array( 'page' ) ) ),
			$chunked->containing( array_keys( $weights ), 'es', 10, array( $this->source ), array( 'types' => array( 'page' ) ) )
		);
		$this->assertSame( array( $this->page ), array_keys( $repo->containing( array_keys( $weights ), 'es', 10, array( $this->source ), array( 'types' => array( 'page' ) ) ) ) );
		$repo->use_taxonomies( false );
	}

	public function test_require_type_and_tag(): void {
		$this->filters( array( 'type' => 'require' ) );
		$this->assertNotContains( $this->page, self::targets( $this->service()->outgoing( $this->source ) ), 'Una entrada no enlaza a una página con Exigir tipo.' );

		$this->filters( array( 'tag' => 'require' ) );
		$out = self::targets( $this->service()->outgoing( $this->source ) );
		$this->assertNotEmpty( $out );
		foreach ( $out as $id ) {
			$this->assertContains( 'hogar', wp_list_pluck( get_the_tags( $id ) ?: array(), 'name' ) ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Prueba.
		}
	}

	public function test_consider_adds_the_affinity_bonus_per_shared_filter_with_a_cap(): void {
		$this->filters( array( 'category' => 'consider' ) );
		$found = $this->service()->outgoing( $this->source );
		$this->assertSame( array( 'affinity' => 0.05 ), $this->bonuses( $found, $this->same ) );
		$this->assertSame( array(), $this->bonuses( $found, $this->other ), 'Otra categoría: sin afinidad, pero sigue saliendo.' );

		$this->filters(
			array(
				'category' => 'consider',
				'tag'      => 'consider',
				'type'     => 'consider',
			)
		);
		$found = $this->service()->outgoing( $this->source );
		$this->assertEqualsWithDelta( 0.10, $this->bonuses( $found, $this->same )['affinity'], 1e-9, 'Tope de +0,10.' );
	}

	public function test_require_with_a_source_without_the_taxonomy_finds_nothing_and_the_rest_says_which_filter(): void {
		$bare = self::factory()->post->create(
			array(
				'post_title'   => 'Nota suelta',
				'post_content' => $this->p( 'La aerotermia y las placas solares, sin categoría ni etiqueta.' ),
			)
		);
		wp_set_post_categories( $bare, array() );
		$this->filters( array( 'category' => 'require' ) );

		$this->assertSame( array(), $this->service()->outgoing( $bare ) );

		$data = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $bare ) )->get_data();
		$this->assertSame( 'ok', $data['state'] );
		$this->assertSame( 0, $data['total'] );
		$this->assertSame( array( 'category' ), $data['required'], 'El estado vacío puede explicar el filtro.' );

		$this->filters( array( 'category' => 'ignore' ) );
		$data = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $this->source ) )->get_data();
		$this->assertSame( array(), $data['required'] );
	}

	public function test_changing_a_pillar_mark_or_a_filter_renews_the_cache_epoch(): void {
		$epoch = wp_cache_get_last_changed( SuggestionCache::GROUP );
		wp_cache_set_last_changed( SuggestionCache::GROUP );
		$this->assertNotSame( $epoch, wp_cache_get_last_changed( SuggestionCache::GROUP ) );

		$epoch = wp_cache_get_last_changed( SuggestionCache::GROUP );
		update_post_meta( $this->same, 'rank_math_pillar_content', 'on' );
		$this->assertNotSame( $epoch, wp_cache_get_last_changed( SuggestionCache::GROUP ), 'La marca de un plugin de SEO invalida las sugerencias guardadas.' );

		$epoch = wp_cache_get_last_changed( SuggestionCache::GROUP );
		update_post_meta( $this->same, 'unrelated_meta', '1' );
		$this->assertSame( $epoch, wp_cache_get_last_changed( SuggestionCache::GROUP ) );

		$epoch = wp_cache_get_last_changed( SuggestionCache::GROUP );
		$this->filters( array( 'category' => 'require' ) );
		$this->assertNotSame( $epoch, wp_cache_get_last_changed( SuggestionCache::GROUP ), 'Cambiar un filtro también.' );
	}

	// ------------------------------------------------------------------ Ajustes.

	public function test_settings_store_the_filters_per_key_and_reject_unknown_modes(): void {
		$settings = new Settings();
		$this->assertSame(
			array(
				'type'     => 'ignore',
				'category' => 'ignore',
				'tag'      => 'ignore',
			),
			$settings->filters(),
			'Ignorar es el valor por defecto.'
		);

		$settings->update( array( 'filters' => array( 'category' => 'require' ) ) );
		$settings->update( array( 'filters' => array( 'tag' => 'consider' ) ) );
		$settings->update( array( 'filters' => array( 'type' => 'nonsense' ) ) );
		$this->assertSame(
			array(
				'type'     => 'ignore',
				'category' => 'require',
				'tag'      => 'consider',
			),
			$settings->filters(),
			'Cada filtro se guarda por separado y un modo desconocido vuelve a Ignorar.'
		);
		$this->assertSame( array( 'category' ), $settings->filters_in_mode( 'require' ) );

		$response = $this->request( 'POST', '/settings', array( 'filters' => array( 'category' => 'ignore' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ignore', $response->get_data()['settings']['filters']['category'] );
		$this->assertSame( 400, $this->request( 'POST', '/settings', array( 'filters' => array( 'tag' => 'maybe' ) ) )->get_status() );
	}
}
