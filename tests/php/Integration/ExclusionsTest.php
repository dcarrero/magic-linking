<?php
/**
 * Excluir una entrada del análisis (F1-12): sale del índice, de las sugerencias (como origen y como destino) y del
 * informe, salvo en su filtro; se puede volver a incluir y no cuesta consultas en las sugerencias.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Cli\Command;
use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\Graph\GraphRepository;
use MagicLinking\Index\Exclusions;
use MagicLinking\Jobs\Jobs;
use MagicLinking\Rules\Rule;
use MagicLinking\Rules\RuleService;
use WP_CLI;
use WP_REST_Request;
use WP_REST_Response;

final class ExclusionsTest extends GraphTestCase {

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

		$this->corpus();
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

	private function exclude( int $post_id, bool $excluded = true, ?int $user = null ): WP_REST_Response {
		return $this->request( 'POST', '/exclusions/' . $post_id, array( 'excluded' => $excluded ), $user );
	}

	private function p( string $html ): string {
		return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
	}

	private function corpus(): void {
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
			'Reforma del cuarto de baño'   => 'azulejos, plato de ducha, mampara, grifería y desagües',
			'Cambiar las ventanas de casa' => 'perfiles de aluminio, doble acristalamiento, persianas y herrajes',
			'Pintar el salón sin obras'    => 'rodillos, imprimación, esmalte, brochas y cinta de carrocero',
		);
		$i      = 0;
		foreach ( $topics as $title => $words ) {
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
	 * Orígenes que se sugieren hacia el destino.
	 *
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
		$this->assertSame( 200, $response->get_status() );

		return array_map( static fn( array $item ): int => (int) $item['source']['id'], $response->get_data()['items'] );
	}

	/**
	 * Destinos que se sugieren desde el origen.
	 *
	 * @return list<int>
	 */
	private function outbound_targets( int $source ): array {
		$response = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $source ) );
		$this->assertSame( 200, $response->get_status() );

		return array_map( static fn( array $item ): int => (int) $item['target']['id'], $response->get_data()['items'] );
	}

	// ------------------------------------------------------------------ El estado.

	public function test_excluding_stores_a_hidden_meta_and_leaves_the_index(): void {
		$this->assertNotNull( $this->doc_row( $this->sources[1] ) );

		$response = $this->exclude( $this->sources[1] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'post_id'  => $this->sources[1],
				'excluded' => true,
			),
			$response->get_data()
		);
		$this->assertSame( '1', get_post_meta( $this->sources[1], '_magiclinking_excluded', true ) );
		$this->assertTrue( Exclusions::is_excluded( $this->sources[1] ) );
		$this->assertNull( $this->doc_row( $this->sources[1] ) );
		$this->assertSame( 0, $this->postings_of( $this->sources[1] ) );
	}

	public function test_including_again_removes_the_meta_and_indexes_the_entry(): void {
		$this->exclude( $this->sources[1] );

		$response = $this->exclude( $this->sources[1], false );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['excluded'] );
		$this->assertSame( '', get_post_meta( $this->sources[1], '_magiclinking_excluded', true ) );
		$this->assertNotNull( $this->doc_row( $this->sources[1] ) );
		$this->assertGreaterThan( 0, $this->postings_of( $this->sources[1] ) );
	}

	public function test_an_excluded_entry_is_not_reindexed_by_saves_or_full_analyses(): void {
		$this->exclude( $this->sources[1] );

		wp_update_post(
			array(
				'ID'           => $this->sources[1],
				'post_content' => $this->p( 'Contenido nuevo sobre aerotermia y bombas de calor para la vivienda.' ),
			)
		);
		$jobs = Plugin::container()->get( Jobs::class );
		$job  = $jobs->start_index( 0, false, true, true, true );
		$jobs->run_to_completion( $job['id'] );

		$this->assertNull( $this->doc_row( $this->sources[1] ) );
		$repo = Plugin::container()->get( GraphRepository::class );
		$this->assertNotContains( $this->sources[1], $repo->eligible_ids( array( 'post' ), 0, 100 ) );
		$this->assertSame( $repo->count_eligible( array( 'post' ) ), $repo->count_docs() );
	}

	public function test_its_outgoing_links_stop_counting_as_inbound_of_others(): void {
		$source = self::factory()->post->create(
			array(
				'post_title'   => 'Enlaza',
				'post_content' => '<a href="' . get_permalink( $this->target ) . '">guía</a> y texto suficiente para ser una entrada normal.',
			)
		);
		Plugin::container()->get( Jobs::class )->reindex_now( $source );
		$this->assertSame( 1, (int) $this->doc_row( $this->target )['inbound'] );

		$this->exclude( $source );

		$this->assertSame( 0, (int) $this->doc_row( $this->target )['inbound'] );
	}

	// ------------------------------------------------------------------ Permisos.

	public function test_only_who_can_edit_the_entry_can_exclude_it(): void {
		$this->assertSame( 401, $this->exclude( $this->sources[0], true, 0 )->get_status() );
		$this->assertSame( 403, $this->exclude( $this->sources[0], true, $this->subscriber )->get_status() );
		// Un autor no puede tocar la entrada de otro.
		$this->assertSame( 403, $this->exclude( $this->sources[1], true, $this->author )->get_status() );
		$this->assertFalse( Exclusions::is_excluded( $this->sources[0] ) );
		$this->assertFalse( Exclusions::is_excluded( $this->sources[1] ) );

		$this->assertSame( 200, $this->exclude( $this->sources[0], true, $this->author )->get_status() );
		$this->assertTrue( Exclusions::is_excluded( $this->sources[0] ) );
	}

	public function test_unknown_or_not_analyzed_entries_give_404_and_the_flag_is_required(): void {
		$this->assertSame( 404, $this->exclude( 999999 )->get_status() );
		register_post_type( 'book', array( 'public' => true ) );
		$book = self::factory()->post->create( array( 'post_type' => 'book' ) );
		$this->assertSame( 404, $this->exclude( $book )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/exclusions/' . $this->sources[1] )->get_status() );
	}

	// ------------------------------------------------------------------ Sugerencias.

	public function test_an_excluded_entry_is_neither_origin_nor_destination_of_suggestions(): void {
		$this->assertContains( $this->sources[1], $this->inbound_sources( $this->target ) );
		$this->assertContains( $this->target, $this->outbound_targets( $this->sources[1] ) );

		$this->exclude( $this->sources[1] );
		$this->assertNotContains( $this->sources[1], $this->inbound_sources( $this->target ) );
		$this->assertContains( $this->sources[2], $this->inbound_sources( $this->target ) );

		$this->exclude( $this->sources[1], false );
		$this->exclude( $this->target );
		$this->assertNotContains( $this->target, $this->outbound_targets( $this->sources[1] ) );
	}

	public function test_suggestions_of_an_excluded_entry_report_the_state(): void {
		$this->exclude( $this->sources[1] );

		$outbound = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $this->sources[1] ) )->get_data();
		$this->assertSame( 'excluded', $outbound['state'] );
		$this->assertSame( array(), $outbound['items'] );

		$this->exclude( $this->target );
		$inbound = $this->request( 'GET', '/suggestions/inbound', array( 'post_id' => $this->target ) )->get_data();
		$this->assertSame( 'excluded', $inbound['state'] );
	}

	public function test_excluding_renews_the_suggestion_cache_epoch(): void {
		$before = wp_cache_get_last_changed( \MagicLinking\Index\SuggestionCache::GROUP );
		usleep( 2000 );

		$this->exclude( $this->sources[1] );

		$this->assertNotSame( $before, wp_cache_get_last_changed( \MagicLinking\Index\SuggestionCache::GROUP ) );
	}

	public function test_computing_suggestions_costs_no_query_about_the_exclusion(): void {
		$this->exclude( $this->sources[2] );
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

		$mentioning = array_filter( $queries, static fn( string $sql ): bool => str_contains( $sql, '_magiclinking_excluded' ) );
		$this->assertSame( array(), array_values( $mentioning ), 'La recuperación filtra por ausencia en el índice, sin mirar el metadato.' );
	}

	// ------------------------------------------------------------------ Informe.

	public function test_the_report_hides_excluded_entries_except_in_their_filter(): void {
		$this->exclude( $this->sources[1] );

		$all = $this->request( 'GET', '/report', array( 'per_page' => 100 ) )->get_data();
		$this->assertNotContains( $this->sources[1], array_column( $all['items'], 'id' ) );
		$this->assertSame( 1, $all['summary']['excluded'] );
		$this->assertSame( 11, $all['summary']['analyzed'] );

		$list = $this->request( 'GET', '/report', array( 'filter' => 'excluded' ) )->get_data();
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( $this->sources[1], $list['items'][0]['id'] );
		$this->assertSame( 'excluded', $list['items'][0]['status'] );
		$this->assertSame( 'Cambiar las ventanas de casa', $list['items'][0]['title'] );
	}

	public function test_the_excluded_filter_honours_search_and_type_and_is_empty_when_none(): void {
		$this->assertSame( 0, $this->request( 'GET', '/report', array( 'filter' => 'excluded' ) )->get_data()['total'] );

		$this->exclude( $this->sources[1] );
		$this->exclude( $this->sources[2] );

		$hit = $this->request(
			'GET',
			'/report',
			array(
				'filter' => 'excluded',
				'search' => 'ventanas',
			)
		)->get_data();
		$this->assertSame( array( $this->sources[1] ), array_column( $hit['items'], 'id' ) );
		$none = $this->request(
			'GET',
			'/report',
			array(
				'filter'    => 'excluded',
				'post_type' => 'page',
			)
		)->get_data();
		$this->assertSame( 0, $none['total'] );
	}

	// ------------------------------------------------------------------ Reglas.

	public function test_rules_skip_an_excluded_origin_or_destination(): void {
		$service = Plugin::container()->get( RuleService::class );
		$rule    = new Rule( 1, array( 'aerotermia' ), $this->target, '', 'es' );
		$this->assertNull( $service->why_not( get_post( $this->sources[1] ), $rule ) );

		$this->exclude( $this->sources[1] );
		$this->assertSame( RuleService::EXCLUDED_ENTRY, $service->why_not( get_post( $this->sources[1] ), $rule ) );

		$this->exclude( $this->sources[1], false );
		$this->exclude( $this->target );
		$this->assertSame( RuleService::EXCLUDED_ENTRY, $service->why_not( get_post( $this->sources[1] ), $rule ) );
	}

	// ------------------------------------------------------------------ Revisión del PR.

	public function test_the_excluded_filter_also_lists_drafts_with_their_state_and_never_the_trashed(): void {
		$this->exclude( $this->sources[1] );
		wp_update_post(
			array(
				'ID'          => $this->sources[1],
				'post_status' => 'draft',
			)
		);

		$list = $this->request( 'GET', '/report', array( 'filter' => 'excluded' ) )->get_data();
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( $this->sources[1], $list['items'][0]['id'] );
		$this->assertSame( 'draft', $list['items'][0]['post_status'] );
		$this->assertSame( 1, $list['summary']['excluded'] );

		// Y se puede volver a incluir desde el Informe.
		$this->assertSame( 200, $this->exclude( $this->sources[1], false )->get_status() );
		$this->assertSame( 0, $this->request( 'GET', '/report', array( 'filter' => 'excluded' ) )->get_data()['total'] );

		$this->exclude( $this->sources[2] );
		wp_trash_post( $this->sources[2] );
		$trashed = $this->request( 'GET', '/report', array( 'filter' => 'excluded' ) )->get_data();
		$this->assertSame( 0, $trashed['total'] );
		$this->assertSame( 0, $trashed['summary']['excluded'] );
	}

	public function test_deleting_an_excluded_entry_does_not_reindex_it_on_the_way(): void {
		$this->exclude( $this->sources[1] );
		$seen = array();
		$id   = $this->sources[1];
		add_action(
			'deleted_post',
			function ( int $post_id ) use ( &$seen, $id ): void {
				if ( $post_id === $id ) {
					$seen[] = $this->doc_row( $id );
				}
			},
			1
		);

		wp_delete_post( $id, true );

		$this->assertSame( array( null ), $seen, 'Al borrarla no se indexa un instante.' );
		$this->assertNull( $this->doc_row( $id ) );
	}

	public function test_only_the_value_one_counts_as_excluded_everywhere(): void {
		update_post_meta( $this->sources[1], Exclusions::KEY, '0' );

		$this->assertFalse( Exclusions::is_excluded( $this->sources[1] ) );
		$repo = Plugin::container()->get( GraphRepository::class );
		$this->assertContains( $this->sources[1], $repo->eligible_ids( array( 'post' ), 0, 100 ) );
		$this->assertNotNull( $this->doc_row( $this->sources[1] ) );
	}

	public function test_the_excluded_filter_ignores_the_language_filter_so_rows_match_the_tab(): void {
		$this->exclude( $this->sources[1] );

		$list = $this->request(
			'GET',
			'/report',
			array(
				'filter' => 'excluded',
				'lang'   => 'es',
			)
		)->get_data();

		$this->assertSame( 1, $list['total'] );
		$this->assertSame( 1, $list['summary']['excluded'] );
	}

	public function test_the_nightly_purge_removes_excluded_entries_that_slipped_in(): void {
		// Una importación SQL o una restauración dejan la marca sin que el hook se dispare.
		$jobs = Plugin::container()->get( Jobs::class );
		remove_action( 'added_post_meta', array( $jobs, 'on_exclusion_meta' ), 10 );
		add_post_meta( $this->sources[1], Exclusions::KEY, '1' );
		$this->assertNotNull( $this->doc_row( $this->sources[1] ) );

		$removed = Plugin::container()->get( GraphRepository::class )->purge_out_of_scope( array( 'post' ) );

		$this->assertSame( 1, $removed );
		$this->assertNull( $this->doc_row( $this->sources[1] ) );
		add_action( 'added_post_meta', array( $jobs, 'on_exclusion_meta' ), 10, 3 );
	}

	public function test_a_rule_cannot_target_an_excluded_entry(): void {
		$this->exclude( $this->target );

		$result = Plugin::container()->get( \MagicLinking\Rules\RuleValidator::class )->validate(
			array(
				'phrases'   => array( 'aerotermia' ),
				'target_id' => $this->target,
			),
			null,
			$this->admin
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'excluded', $result->get_error_message() );
	}

	// ------------------------------------------------------------------ Segunda revisión.

	public function test_deleting_the_meta_by_key_reindexes_what_becomes_eligible_again(): void {
		$this->exclude( $this->sources[1] );
		$this->assertNull( $this->doc_row( $this->sources[1] ) );
		$jobs = Plugin::container()->get( Jobs::class );
		$this->assertNull( $jobs->status()['job'] );

		// Sin entrada concreta: WordPress avisa con el ID 0.
		delete_post_meta_by_key( Exclusions::KEY );

		$this->assertNotNull( $jobs->status()['job'], 'Queda un análisis en cola para las que vuelven a ser elegibles.' );
	}

	public function test_a_failing_reindex_does_not_break_the_request_and_is_queued(): void {
		$this->exclude( $this->sources[1] );
		add_filter(
			'magiclinking_post_html',
			static function (): string {
				throw new \RuntimeException( 'boom' );
			}
		);

		$response = $this->exclude( $this->sources[1], false );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( Exclusions::is_excluded( $this->sources[1] ) );
		$this->assertTrue( as_has_scheduled_action( Jobs::HOOK_POSTS, null, Installer::ACTION_GROUP ), 'Se deja en cola.' );
	}

	public function test_several_meta_rows_do_not_duplicate_the_entry_and_any_one_counts(): void {
		add_post_meta( $this->sources[1], Exclusions::KEY, '0' );
		add_post_meta( $this->sources[1], Exclusions::KEY, '1' );
		add_post_meta( $this->sources[1], Exclusions::KEY, '1' );

		$this->assertTrue( Exclusions::is_excluded( $this->sources[1] ) );
		$list = $this->request( 'GET', '/report', array( 'filter' => 'excluded' ) )->get_data();
		$this->assertSame( 1, $list['total'] );
		$this->assertCount( 1, $list['items'] );
		$this->assertSame( 1, $list['summary']['excluded'] );

		$this->exclude( $this->sources[1], false );
		$this->assertSame( array(), get_post_meta( $this->sources[1], Exclusions::KEY, false ), 'Al incluir se borran todas.' );
	}

	public function test_the_excluded_tab_reuses_its_total_for_the_summary(): void {
		$this->exclude( $this->sources[1] );

		$data = $this->request( 'GET', '/report', array( 'filter' => 'excluded' ) )->get_data();

		$this->assertSame( $data['total'], $data['summary']['excluded'] );
	}

	// ------------------------------------------------------------------ WP-CLI.

	public function test_wp_cli_excludes_and_includes_entries(): void {
		WP_CLI::reset();
		$command = $this->command();

		$command->exclude( array( (string) $this->sources[1], (string) $this->sources[2] ), array() );

		$this->assertTrue( Exclusions::is_excluded( $this->sources[1] ) );
		$this->assertTrue( Exclusions::is_excluded( $this->sources[2] ) );
		$this->assertNull( $this->doc_row( $this->sources[2] ) );
		$this->assertSame( 'Success: 2 entries excluded from the analysis.', end( WP_CLI::$lines ) );

		$command->restore( array( (string) $this->sources[1] ), array() );
		$this->assertFalse( Exclusions::is_excluded( $this->sources[1] ) );
		$this->assertNotNull( $this->doc_row( $this->sources[1] ) );

		$this->expectException( \RuntimeException::class );
		$command->exclude( array( '999999' ), array() );
	}

	private function command(): Command {
		$c = Plugin::container();

		return new Command(
			$c->get( Jobs::class ),
			$c->get( \MagicLinking\Graph\ReportRepository::class ),
			$c->get( \MagicLinking\Graph\BrokenRepository::class ),
			$c->get( \MagicLinking\History\Reader::class ),
			$c->get( \MagicLinking\History\Undo::class ),
			$c->get( \MagicLinking\History\Retention::class ),
			$c->get( \MagicLinking\History\ChangeRepository::class ),
			$c->get( \MagicLinking\History\Redo::class ),
			$c->get( \MagicLinking\History\BatchJob::class ),
			$c->get( \MagicLinking\Core\Settings::class )
		);
	}

	private function postings_of( int $post_id ): int {
		global $wpdb;
		$name = Schema::table( $wpdb->prefix, 'postings' );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$name} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
