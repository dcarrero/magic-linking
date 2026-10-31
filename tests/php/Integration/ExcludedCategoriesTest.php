<?php
/**
 * Excluir categorías del análisis, K términos y umbral de puntuación (F1-16): las entradas de una categoría
 * excluida quedan fuera como una entrada excluida (D-70), y K y el umbral se aplican en el motor.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\Core\Settings;
use MagicLinking\Graph\GraphRepository;
use MagicLinking\Index\Exclusions;
use MagicLinking\Index\SuggestionCache;
use MagicLinking\Jobs\Jobs;
use MagicLinking\Rules\Rule;
use MagicLinking\Rules\RuleService;
use WP_REST_Request;
use WP_REST_Response;

final class ExcludedCategoriesTest extends GraphTestCase {

	private const BASE = '/magic-linking/v1';

	private int $admin = 0;

	private int $target = 0;

	/**
	 * @var list<int>
	 */
	private array $sources = array();

	private int $category = 0;

	/**
	 * `term_taxonomy_id` de la categoría.
	 */
	private int $tt = 0;

	public function set_up(): void {
		parent::set_up();

		add_filter( 'locale', static fn(): string => 'es_ES' );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->category = self::factory()->category->create( array( 'name' => 'Privada' ) );
		$this->tt       = (int) get_term( $this->category, 'category' )->term_taxonomy_id;
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

	private function corpus(): void {
		$this->target = self::factory()->post->create(
			array(
				'post_title'   => 'Aerotermia: guía completa',
				'post_name'    => 'aerotermia',
				'post_content' => $this->p( 'La aerotermia aprovecha la energía del aire exterior para calentar la vivienda con una bomba de calor eficiente.' )
					. $this->p( 'El consumo de una instalación de aerotermia depende del aislamiento, del suelo radiante y de la zona climática.' ),
			)
		);
		$topics       = array(
			'Reforma del cuarto de baño'   => 'azulejos, plato de ducha, mampara, grifería y desagües',
			'Cambiar las ventanas de casa' => 'perfiles de aluminio, doble acristalamiento, persianas y herrajes',
			'Pintar el salón sin obras'    => 'rodillos, imprimación, esmalte, brochas y cinta de carrocero',
		);
		foreach ( $topics as $title => $words ) {
			$this->sources[] = self::factory()->post->create(
				array(
					'post_title'   => $title,
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
		$this->reindex_all();
	}

	private function reindex_all(): void {
		global $wpdb;
		foreach ( array( 'docs', 'links', 'jobs', 'terms', 'postings' ) as $table ) {
			$name = Schema::table( $wpdb->prefix, $table );
			$wpdb->query( "DELETE FROM {$name}" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$jobs = Plugin::container()->get( Jobs::class );
		$jobs->run_to_completion( $jobs->start_index( 0, false, true, true, true )['id'] );
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function request( string $method, string $route, array $params = array() ): WP_REST_Response {
		wp_set_current_user( $this->admin );
		$request = new WP_REST_Request( $method, self::BASE . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Guarda las categorías excluidas por la API y termina el análisis que lanza.
	 *
	 * @param list<int> $tts `term_taxonomy_id`.
	 */
	private function save_excluded( array $tts ): WP_REST_Response {
		$response = $this->request( 'POST', '/settings', array( 'excluded_categories' => $tts ) );
		$this->assertSame( 200, $response->get_status() );
		if ( ! empty( $response->get_data()['job'] ) ) {
			Plugin::container()->get( Jobs::class )->run_to_completion( $response->get_data()['job']['id'] );
		}

		return $response;
	}

	/**
	 * @return list<int>
	 */
	private function inbound_sources( int $target ): array {
		$response = $this->request(
			'GET',
			'/suggestions/inbound',
			array(
				'post_id'  => $target,
				'per_page' => 50,
			)
		);
		return array_map( static fn( array $item ): int => (int) $item['source']['id'], $response->get_data()['items'] );
	}

	/**
	 * @return list<int>
	 */
	private function outbound_targets( int $source ): array {
		$response = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $source ) );
		return array_map( static fn( array $item ): int => (int) $item['target']['id'], $response->get_data()['items'] );
	}

	// ------------------------------------------------------------------ Categorías excluidas.

	public function test_by_default_no_category_is_excluded(): void {
		$this->assertSame( array(), Plugin::container()->get( Settings::class )->all()['excluded_categories'] );
		wp_set_post_categories( $this->sources[1], array( $this->category ) );
		$this->assertFalse( Exclusions::is_out( $this->sources[1] ) );
	}

	public function test_posts_of_an_excluded_category_leave_the_analysis_like_an_excluded_entry(): void {
		wp_set_post_categories( $this->sources[1], array( $this->category ) );
		$this->assertContains( $this->sources[1], $this->inbound_sources( $this->target ) );
		$this->assertNotNull( $this->doc_row( $this->sources[1] ) );

		$this->save_excluded( array( $this->tt ) );

		$this->assertTrue( Exclusions::is_out( $this->sources[1] ) );
		$this->assertFalse( Exclusions::is_excluded( $this->sources[1] ), 'No es la marca de la entrada: no se toca ni se ve como excluida a mano.' );
		$this->assertNull( $this->doc_row( $this->sources[1] ) );
		$repo = Plugin::container()->get( GraphRepository::class );
		$this->assertNotContains( $this->sources[1], $repo->eligible_ids( array( 'post' ), 0, 100 ) );
		$this->assertSame( $repo->count_eligible( array( 'post' ) ), $repo->count_docs() );
		$this->assertNotContains( $this->sources[1], $this->inbound_sources( $this->target ) );
		$this->assertContains( $this->sources[2], $this->inbound_sources( $this->target ) );

		$state = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $this->sources[1] ) )->get_data();
		$this->assertSame( 'excluded_category', $state['state'] );
		$this->assertSame( array(), $state['items'] );
	}

	public function test_an_excluded_category_also_hides_the_entry_as_a_destination_and_from_rules(): void {
		wp_set_post_categories( $this->target, array( $this->category ) );
		$this->save_excluded( array( $this->tt ) );

		$this->assertNotContains( $this->target, $this->outbound_targets( $this->sources[1] ) );

		$rule = new Rule( 1, array( 'aerotermia' ), $this->target, '', 'es' );
		$this->assertSame( RuleService::EXCLUDED_ENTRY, Plugin::container()->get( RuleService::class )->why_not( get_post( $this->sources[1] ), $rule ) );
	}

	public function test_saving_the_setting_starts_a_resumable_job_and_renews_the_cache(): void {
		wp_set_post_categories( $this->sources[1], array( $this->category ) );
		$before = wp_cache_get_last_changed( SuggestionCache::GROUP );
		usleep( 2000 );

		$response = $this->request( 'POST', '/settings', array( 'excluded_categories' => array( $this->tt ) ) );

		$this->assertSame( 'queued', $response->get_data()['job']['status'] );
		$this->assertNotSame( $before, wp_cache_get_last_changed( SuggestionCache::GROUP ) );
		$this->assertNotNull( as_next_scheduled_action( Jobs::HOOK_BATCH, null, Installer::ACTION_GROUP ), 'Va por Action Scheduler.' );

		$again = $this->request( 'POST', '/settings', array( 'excluded_categories' => array( $this->tt ) ) );
		$this->assertNull( $again->get_data()['job'], 'Guardar lo mismo no relanza nada.' );
	}

	public function test_removing_the_category_from_the_setting_brings_the_entries_back(): void {
		wp_set_post_categories( $this->sources[1], array( $this->category ) );
		$this->save_excluded( array( $this->tt ) );
		$this->assertNull( $this->doc_row( $this->sources[1] ) );

		$this->save_excluded( array() );

		$this->assertNotNull( $this->doc_row( $this->sources[1] ) );
		$this->assertContains( $this->sources[1], $this->inbound_sources( $this->target ) );
	}

	private function flush(): void {
		Plugin::container()->get( Jobs::class )->flush_category_changes();
	}

	public function test_changing_the_categories_of_an_entry_reevaluates_it(): void {
		$this->save_excluded( array( $this->tt ) );
		$this->assertNotNull( $this->doc_row( $this->sources[1] ) );
		$before = wp_cache_get_last_changed( SuggestionCache::GROUP );
		usleep( 2000 );

		wp_set_post_categories( $this->sources[1], array( $this->category ) );
		$this->flush();

		$this->assertNull( $this->doc_row( $this->sources[1] ), 'Sale del índice al entrar en la categoría.' );
		$this->assertNotSame( $before, wp_cache_get_last_changed( SuggestionCache::GROUP ) );

		wp_set_post_categories( $this->sources[1], array( 1 ) );
		$this->flush();
		$this->assertNotNull( $this->doc_row( $this->sources[1] ), 'Vuelve al salir de ella.' );

		wp_set_post_categories( $this->sources[1], array( $this->category ) );
		$this->flush();
		$this->assertNull( $this->doc_row( $this->sources[1] ) );
		wp_remove_object_terms( $this->sources[1], $this->category, 'category' );
		$this->flush();
		$this->assertNotNull( $this->doc_row( $this->sources[1] ), 'Quitar el término también reevalúa.' );
	}

	public function test_replacing_the_categories_of_an_entry_reindexes_it_once(): void {
		wp_set_post_categories( $this->sources[1], array( $this->category ) );
		$this->save_excluded( array( $this->tt ) );
		$this->flush();

		$reads = 0;
		add_filter(
			'magiclinking_post_html',
			static function ( $html ) use ( &$reads ) {
				++$reads;
				return $html;
			}
		);
		// Lo que cuesta reindexar una entrada una vez (grafo e índice léxico leen su contenido).
		Plugin::container()->get( Jobs::class )->reindex_now( $this->sources[2] );
		$once  = $reads;
		$reads = 0;
		$this->assertGreaterThan( 0, $once );
		// Asignar otra y quitar la excluida dispara los dos hooks de términos.
		wp_set_post_categories( $this->sources[1], array( 1 ) );
		$this->assertSame( 0, $reads, 'Nada se reindexa dentro del hook.' );
		$this->flush();

		$this->assertSame( $once, $reads, 'Una sola reindexación por entrada y petición.' );
		$this->assertNotNull( $this->doc_row( $this->sources[1] ) );
	}

	public function test_a_bulk_change_goes_to_action_scheduler_not_to_the_request(): void {
		$ids = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_title'    => "Masiva $i",
					'post_content'  => $this->p( "Entrada masiva $i sobre aerotermia y bomba de calor con suelo radiante." ),
					'post_category' => array( $this->category ),
				)
			);
		}
		$this->save_excluded( array( $this->tt ) );
		$this->flush();
		foreach ( $ids as $id ) {
			$this->assertNull( $this->doc_row( $id ) );
		}
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );

		wp_delete_term( $this->category, 'category' );
		$this->flush();

		foreach ( $ids as $id ) {
			$this->assertNull( $this->doc_row( $id ), 'Ninguna se indexa dentro de la petición.' );
		}
		$queued = as_get_scheduled_actions(
			array(
				'hook'     => Jobs::HOOK_POSTS,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			),
			'ids'
		);
		$this->assertGreaterThanOrEqual( 2, count( $queued ), 'En lotes.' );
		$this->assertLessThan( count( $ids ), count( $queued ), 'Y no una acción por entrada.' );

		foreach ( $queued as $action_id ) {
			$action = \ActionScheduler::store()->fetch_action( $action_id );
			do_action_ref_array( Jobs::HOOK_POSTS, $action->get_args() );
		}
		foreach ( $ids as $id ) {
			$this->assertNotNull( $this->doc_row( $id ), 'Los lotes las reevalúan.' );
		}
	}

	public function test_excluded_taxonomies_are_looked_up_once_not_per_entry(): void {
		wp_set_post_categories( $this->sources[1], array( $this->category ) );
		$this->save_excluded( array( $this->tt ) );
		Exclusions::forget();
		$queries = array();
		add_filter(
			'query',
			static function ( string $sql ) use ( &$queries ): string {
				if ( preg_match( '/term_taxonomy_id\s*=\s*\d+/', $sql ) && str_contains( $sql, 'wp_terms' ) ) {
					$queries[] = $sql;
				}
				return $sql;
			}
		);

		foreach ( array_merge( array( $this->target ), $this->sources ) as $id ) {
			wp_cache_flush();
			Exclusions::is_out( $id );
		}

		$this->assertLessThanOrEqual( 1, count( $queries ) );
	}

	public function test_the_same_set_in_another_order_is_not_a_change(): void {
		$other = (int) get_term( self::factory()->category->create( array( 'name' => 'Otra' ) ), 'category' )->term_taxonomy_id;
		$this->save_excluded( array( $this->tt, $other ) );

		$again = $this->request( 'POST', '/settings', array( 'excluded_categories' => array( $other, $this->tt ) ) );

		$this->assertNull( $again->get_data()['job'] );
		$this->assertSame( array( min( $this->tt, $other ), max( $this->tt, $other ) ), $again->get_data()['settings']['excluded_categories'] );
	}

	public function test_dropping_a_content_type_drops_the_categories_of_taxonomies_no_longer_analyzed(): void {
		register_post_type(
			'libro',
			array(
				'public'     => true,
				'taxonomies' => array( 'genero' ),
			)
		);
		register_taxonomy(
			'genero',
			'libro',
			array(
				'public'       => true,
				'show_ui'      => true,
				'hierarchical' => true,
			)
		);
		$genre    = self::factory()->term->create(
			array(
				'taxonomy' => 'genero',
				'name'     => 'Novela',
			)
		);
		$genre_tt = (int) get_term( $genre, 'genero' )->term_taxonomy_id;

		$this->request( 'POST', '/settings', array( 'post_types' => array( 'post', 'libro' ) ) );
		$saved = $this->request( 'POST', '/settings', array( 'excluded_categories' => array( $this->tt, $genre_tt ) ) )->get_data()['settings'];
		$this->assertContains( $genre_tt, $saved['excluded_categories'] );

		$saved = $this->request( 'POST', '/settings', array( 'post_types' => array( 'post' ) ) )->get_data()['settings'];

		$this->assertSame( array(), array_values( array_diff( $saved['excluded_categories'], array( $this->tt ) ) ) );
		$this->assertNotContains( $genre_tt, $saved['excluded_categories'] );
	}

	public function test_the_list_always_includes_the_excluded_categories_even_past_the_cap(): void {
		$this->save_excluded( array( $this->tt ) );
		$groups = \MagicLinking\Index\Taxonomies::category_groups( array( 'post' ), 1 );

		$ids = array_column( $groups[0]['terms'], 'id' );
		$this->assertContains( $this->tt, $ids, 'La excluida se lista aunque no quepa en el tope.' );
		$this->assertLessThanOrEqual( 2, count( $ids ) );
	}

	public function test_the_editor_categories_decide_the_state_of_a_draft(): void {
		$this->save_excluded( array( $this->tt ) );
		$draft   = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Borrador',
				'post_content' => $this->p( 'Aerotermia y bomba de calor.' ),
			)
		);
		$service = Plugin::container()->get( \MagicLinking\Index\Suggestions::class );

		$this->assertSame( 'ok', $service->state( get_post( $draft ), false, true ) );
		$this->assertSame( 'excluded_category', $service->state( get_post( $draft ), false, true, array( 'category' => array( $this->category ) ) ) );

		wp_set_post_categories( $draft, array( $this->category ) );
		$this->assertSame( 'ok', $service->state( get_post( $draft ), false, true, array( 'category' => array( 1 ) ) ), 'Lo que el editor tiene elegido manda sobre lo guardado.' );
		$this->assertSame( 'excluded_category', $service->state( get_post( $draft ), false, true ) );

		// Por la API: el borrador con la categoría elegida en el editor.
		$response = $this->request(
			'POST',
			'/suggestions/outbound',
			array(
				'post_id' => $draft,
				'content' => $this->p( 'Aerotermia' ),
				'terms'   => array( 'category' => array( $this->category ) ),
			)
		);
		$this->assertSame( 'excluded_category', $response->get_data()['state'] );
	}

	public function test_k_is_resolved_once_per_value_not_per_document(): void {
		$lexical = Plugin::container()->get( \MagicLinking\Index\LexicalIndexer::class );
		$factory = ( new \ReflectionProperty( $lexical, 'engine' ) )->getValue( $lexical );
		$this->assertInstanceOf( \MagicLinking\Index\EngineFactory::class, $factory );

		$first = $factory->indexer();
		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertSame( $first, $factory->indexer(), 'El mismo objeto mientras K no cambia.' );
		}

		$this->request( 'POST', '/settings', array( 'k_terms' => 25 ) );
		$this->assertSame( 25, $factory->indexer()->config()['k'] );
		$this->assertSame( 25, Plugin::container()->get( Settings::class )->k_terms() );
	}

	public function test_saving_an_entry_into_the_setting_indexes_it_once(): void {
		wp_set_post_categories( $this->sources[1], array( $this->category ) );
		$this->save_excluded( array( $this->tt ) );
		$this->flush();
		$this->assertNull( $this->doc_row( $this->sources[1] ) );

		$reads = 0;
		add_filter(
			'magiclinking_post_html',
			static function ( $html ) use ( &$reads ) {
				++$reads;
				return $html;
			}
		);
		Plugin::container()->get( Jobs::class )->reindex_now( $this->sources[2] );
		$once  = $reads;
		$reads = 0;

		// Un guardado normal: la entrada sale de la categoría excluida y queda elegible.
		wp_update_post(
			array(
				'ID'            => $this->sources[1],
				'post_category' => array( 1 ),
			)
		);
		$this->flush();

		$this->assertSame( $once, $reads, 'La reindexa el guardado; los hooks de términos no lo repiten.' );
		$this->assertNotNull( $this->doc_row( $this->sources[1] ) );
	}

	public function test_a_rule_cannot_target_an_entry_in_an_excluded_category_and_the_message_says_where_to_change_it(): void {
		wp_set_post_categories( $this->target, array( $this->category ) );
		$this->save_excluded( array( $this->tt ) );

		wp_set_current_user( $this->admin );
		$result = Plugin::container()->get( \MagicLinking\Rules\RuleValidator::class )->validate(
			array(
				'phrases'   => array( 'aerotermia' ),
				'target_id' => $this->target,
			),
			null,
			$this->admin
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'excluded categories', $result->get_error_message() );
		$this->assertStringContainsString( 'Settings', $result->get_error_message() );
	}

	public function test_the_report_lists_entries_left_out_by_category_with_their_reason(): void {
		wp_set_post_categories( $this->sources[1], array( $this->category ) );
		update_post_meta( $this->sources[2], Exclusions::KEY, '1' );
		$this->save_excluded( array( $this->tt ) );

		$list = $this->request(
			'GET',
			'/report',
			array(
				'filter'   => 'excluded',
				'per_page' => 50,
			)
		)->get_data();

		$by_id = array_column( $list['items'], null, 'id' );
		$this->assertSame( 2, $list['total'] );
		$this->assertSame( 'category', $by_id[ $this->sources[1] ]['excluded_by'] );
		$this->assertSame( 'entry', $by_id[ $this->sources[2] ]['excluded_by'] );
		$this->assertSame( 2, $this->request( 'GET', '/report' )->get_data()['summary']['excluded'], 'Cuentan en el resumen.' );
	}

	public function test_more_categories_than_can_be_saved_is_an_error_not_a_silent_cut(): void {
		$many = range( 1, Settings::MAX_EXCLUDED_CATEGORIES + 1 );

		$response = $this->request( 'POST', '/settings', array( 'excluded_categories' => $many ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Settings::MAX_EXCLUDED_CATEGORIES, $this->request( 'GET', '/settings' )->get_data()['max_excluded'] );
	}

	public function test_a_draft_with_no_category_is_judged_by_the_default_category(): void {
		$default = (int) get_option( 'default_category' );
		$tt      = (int) get_term( $default, 'category' )->term_taxonomy_id;
		// Solo el ajuste, sin reindexar: casi todo el corpus está en la categoría por defecto y vaciaría el índice.
		update_option( Installer::SETTINGS_OPTION, array( 'excluded_categories' => array( $tt ) ) );
		$draft   = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Sin categoría',
				'post_content' => $this->p( 'Aerotermia.' ),
			)
		);
		$service = Plugin::container()->get( \MagicLinking\Index\Suggestions::class );

		$this->assertSame( 'excluded_category', $service->state( get_post( $draft ), false, true, array( 'category' => array() ) ), 'Sin ninguna marcada, WordPress le pondrá la de por defecto.' );
		$this->assertSame( 'ok', $service->state( get_post( $draft ), false, true, array( 'category' => array( $this->category ) ) ) );
	}

	public function test_a_new_entry_in_an_excluded_category_is_never_indexed(): void {
		$this->save_excluded( array( $this->tt ) );

		$id = self::factory()->post->create(
			array(
				'post_title'    => 'Nueva privada',
				'post_content'  => $this->p( 'La aerotermia y la bomba de calor en una casa nueva con suelo radiante.' ),
				'post_category' => array( $this->category ),
			)
		);

		$this->assertNull( $this->doc_row( $id ) );
	}

	public function test_only_the_exact_term_counts_and_the_setting_is_validated(): void {
		$child = self::factory()->category->create(
			array(
				'name'   => 'Hija',
				'parent' => $this->category,
			)
		);
		wp_set_post_categories( $this->sources[1], array( $child ) );
		$tag    = self::factory()->tag->create( array( 'name' => 'etiqueta' ) );
		$tag_tt = (int) get_term( $tag, 'post_tag' )->term_taxonomy_id;

		$saved = $this->save_excluded( array( $this->tt, $tag_tt, 999999, -4, 0 ) )->get_data()['settings'];

		$this->assertSame( array( $this->tt ), $saved['excluded_categories'], 'Solo términos de la categoría principal de un tipo analizado.' );
		$this->assertFalse( Exclusions::is_out( $this->sources[1] ), 'Una subcategoría no hereda la exclusión del padre.' );
	}

	public function test_the_check_costs_no_extra_query_per_entry_once_terms_are_loaded(): void {
		wp_set_post_categories( $this->sources[1], array( $this->category ) );
		$this->save_excluded( array( $this->tt ) );
		$ids = array_merge( array( $this->target ), $this->sources );
		_prime_post_caches( $ids, true, true );
		Exclusions::is_out( $this->target );

		$queries = array();
		add_filter(
			'query',
			static function ( string $sql ) use ( &$queries ): string {
				$queries[] = $sql;
				return $sql;
			}
		);
		foreach ( $ids as $id ) {
			Exclusions::is_out( $id );
		}

		$this->assertSame( array(), $queries );
	}

	public function test_computing_suggestions_costs_no_query_about_categories(): void {
		$this->save_excluded( array( $this->tt ) );
		$queries = array();
		add_filter(
			'query',
			static function ( string $sql ) use ( &$queries ): string {
				$queries[] = $sql;
				return $sql;
			}
		);

		$this->outbound_targets( $this->sources[1] );
		$this->inbound_sources( $this->target );

		$terms = array_filter( $queries, static fn( string $sql ): bool => str_contains( $sql, 'term_relationships' ) );
		$this->assertLessThanOrEqual( 4, count( $terms ), 'Constante: la categoría de cada entrada abierta y una carga por lote de candidatas; nada por cada candidata.' );
	}

	// ------------------------------------------------------------------ K y umbral.

	public function test_k_and_the_threshold_default_to_the_engine_values_and_are_bounded(): void {
		$all = Plugin::container()->get( Settings::class )->all();
		$this->assertSame( \MagicLinking\Engine\Indexer::DEFAULTS['k'], $all['k_terms'] );
		$this->assertSame( \MagicLinking\Engine\Scorer::THRESHOLD_PERCENT, $all['score_threshold'] );
		$this->assertSame( \MagicLinking\Engine\Scorer::THRESHOLD, Plugin::container()->get( Settings::class )->score_threshold() );

		$this->assertSame( array( 20, 80 ), Settings::INT_RANGES['k_terms'] );
		$saved = $this->request(
			'POST',
			'/settings',
			array(
				'k_terms'         => 5,
				'score_threshold' => 99,
			)
		)->get_data()['settings'];
		$this->assertSame( 20, $saved['k_terms'] );
		$this->assertSame( Settings::INT_RANGES['score_threshold'][1], $saved['score_threshold'] );
		$saved = $this->request(
			'POST',
			'/settings',
			array(
				'k_terms'         => 500,
				'score_threshold' => 1,
			)
		)->get_data()['settings'];
		$this->assertSame( 80, $saved['k_terms'] );
		$this->assertSame( Settings::INT_RANGES['score_threshold'][0], $saved['score_threshold'] );
	}

	public function test_a_different_k_rebuilds_the_index_with_that_many_terms_per_entry(): void {
		global $wpdb;
		$words = array();
		for ( $i = 0; $i < 70; $i++ ) {
			$words[] = 'palabrota' . chr( 97 + intdiv( $i, 26 ) ) . chr( 97 + $i % 26 ) . 'zeta';
		}
		$ids = array();
		foreach ( array( 'Uno', 'Dos' ) as $title ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_title'   => "Glosario $title",
					'post_content' => $this->p( implode( ' ', $words ) ),
				)
			);
		}
		$this->reindex_all();
		$postings = Schema::table( $wpdb->prefix, 'postings' );
		$count    = fn( int $id ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$postings} WHERE post_id = %d", $id ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 40, $count( $ids[0] ), 'Por defecto, K = 40.' );

		$response = $this->request( 'POST', '/settings', array( 'k_terms' => 20 ) );
		$this->assertSame( 'queued', $response->get_data()['job']['status'] );
		$this->assertTrue( $response->get_data()['job']['force'] );
		Plugin::container()->get( Jobs::class )->run_to_completion( $response->get_data()['job']['id'] );

		$this->assertSame( 20, $count( $ids[0] ) );
		$this->assertSame( 20, $count( $ids[1] ) );

		$response = $this->request( 'POST', '/settings', array( 'k_terms' => 60 ) );
		Plugin::container()->get( Jobs::class )->run_to_completion( $response->get_data()['job']['id'] );
		$this->assertSame( 60, $count( $ids[0] ) );
	}

	public function test_the_threshold_filters_suggestions_and_renews_the_cache_without_reindexing(): void {
		$default = $this->outbound_targets( $this->sources[1] );
		$this->assertContains( $this->target, $default );

		$response = $this->request( 'POST', '/settings', array( 'score_threshold' => 60 ) );
		$this->assertNull( $response->get_data()['job'], 'El umbral solo se aplica al puntuar: no reindexa.' );
		$strict = $this->outbound_targets( $this->sources[1] );
		$this->assertLessThan( count( $default ), count( $strict ) );

		$this->request( 'POST', '/settings', array( 'score_threshold' => 20 ) );
		$loose = $this->outbound_targets( $this->sources[1] );
		$this->assertGreaterThanOrEqual( count( $default ), count( $loose ) );

		$this->request( 'POST', '/settings', array( 'score_threshold' => 35 ) );
		$this->assertSame( $default, $this->outbound_targets( $this->sources[1] ), 'De vuelta al valor por defecto, lo de siempre.' );
	}

	public function test_the_new_settings_live_in_the_one_option_and_add_no_autoloaded_options(): void {
		global $wpdb;
		$this->save_excluded( array( $this->tt ) );
		$this->request(
			'POST',
			'/settings',
			array(
				'k_terms'         => 30,
				'score_threshold' => 40,
			)
		);

		$stored = get_option( Installer::SETTINGS_OPTION );
		$this->assertSame( array( $this->tt ), $stored['excluded_categories'] );
		$this->assertSame( 30, $stored['k_terms'] );
		$this->assertSame( 40, $stored['score_threshold'] );
		$auto = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'magiclinking%' AND autoload IN ('yes','on','auto-on','auto')" ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( Installer::SETTINGS_OPTION ), array_values( array_intersect( $auto, array( Installer::SETTINGS_OPTION ) ) ) );
		foreach ( $auto as $name ) {
			$this->assertSame( Installer::SETTINGS_OPTION, $name, 'Cero opciones propias en autoload salvo los ajustes.' );
		}
	}

	public function test_the_settings_screen_lists_the_categories_to_choose_from(): void {
		$data = $this->request( 'GET', '/settings' )->get_data();
		$this->assertArrayHasKey( 'category_groups', $data );
		$group = $data['category_groups'][0];
		$this->assertSame( 'category', $group['taxonomy'] );
		$terms = array_column( $group['terms'], null, 'id' );
		$this->assertSame( 'Privada', $terms[ $this->tt ]['name'] );
		$this->assertSame( 0, $terms[ $this->tt ]['depth'] );
	}
}
