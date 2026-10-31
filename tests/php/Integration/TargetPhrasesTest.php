<?php
/**
 * Frases objetivo del usuario (F1-24, D-30): meta sin autoload, ruta REST, indexado y hueco de 0-1 palabras.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Engine\Suggestion;
use MagicLinking\Index\Fingerprint;
use MagicLinking\Index\SuggestionCache;
use MagicLinking\Index\Suggestions;
use MagicLinking\Index\TargetPhrases;
use MagicLinking\Jobs\Jobs;
use WP_REST_Request;
use WP_REST_Response;

final class TargetPhrasesTest extends GraphTestCase {

	private const BASE = '/magic-linking/v1';

	private int $admin = 0;

	private int $target = 0;

	private int $one = 0;

	private int $two = 0;

	public function set_up(): void {
		parent::set_up();

		add_filter( 'locale', static fn(): string => 'es_ES' );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );
		update_option( Installer::SETTINGS_OPTION, array( 'post_types' => array( 'post', 'page' ) ) );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->target = $this->post( 'Cocinas a medida', 'Diseñamos cocinas a medida con muebles y encimeras de calidad para cada piso de la ciudad.' );
		$this->one    = $this->post( 'Presupuesto de obra', 'Presupuesto y materiales para la reforma de la cocina de un piso antiguo con muebles nuevos y encimera de cuarzo.' );
		$this->two    = $this->post( 'Presupuesto de piso', 'Presupuesto y materiales para la reforma de toda la cocina de un piso antiguo con muebles nuevos y encimera de cuarzo.' );
		foreach ( array( 'aceite de oliva', 'gazpacho andaluz', 'tortilla de patatas', 'pan de masa madre', 'queso manchego' ) as $dish ) {
			$this->post( 'Receta de ' . $dish, 'Una receta sencilla de ' . $dish . ' con ingredientes de temporada, paso a paso y con trucos para que salga jugosa en la mesa.' );
		}

		global $wpdb;
		foreach ( array( 'docs', 'links', 'jobs', 'terms', 'postings' ) as $table ) {
			$name = \MagicLinking\Core\Schema::table( $wpdb->prefix, $table );
			$wpdb->query( "DELETE FROM {$name}" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$jobs = Plugin::container()->get( Jobs::class );
		$job  = $jobs->start_index( 0, false, true, true, true );
		$jobs->run_to_completion( $job['id'] );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function post( string $title, string $text ): int {
		return self::factory()->post->create(
			array(
				'post_title'   => $title,
				'post_content' => "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->",
			)
		);
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
	 * Anclas con las que el origen propone enlazar al destino (las entrantes del destino).
	 *
	 * @return list<string>
	 */
	private function anchors( int $source ): array {
		$found = Plugin::container()->get( Suggestions::class )->incoming( $this->target );

		return array_values( array_map( static fn( Suggestion $s ): string => $s->anchor, array_filter( $found, static fn( Suggestion $s ): bool => $s->source === $source ) ) );
	}

	private function save( array $phrases ): WP_REST_Response {
		return $this->request( 'POST', '/target-phrases/' . $this->target, array( 'phrases' => $phrases ) );
	}

	public function test_a_phrase_with_a_gap_fits_one_word_in_between_and_not_two(): void {
		$this->assertNotContains( 'reforma de la cocina', $this->anchors( $this->one ), 'Sin frases del usuario no hay esa ancla.' );

		$response = $this->save(
			array(
				array(
					'phrase'   => 'reforma de cocina',
					'variants' => array(),
					'gap'      => true,
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );

		$this->assertContains( 'reforma de la cocina', $this->anchors( $this->one ) );
		$this->assertNotContains( 'reforma de toda la cocina', $this->anchors( $this->two ) );
	}

	public function test_without_the_gap_the_phrase_is_exact(): void {
		$this->save(
			array(
				array(
					'phrase'   => 'reforma de cocina',
					'variants' => array(),
					'gap'      => false,
				),
			)
		);

		$this->assertNotContains( 'reforma de la cocina', $this->anchors( $this->one ) );
	}

	public function test_variants_are_phrases_too(): void {
		$this->save(
			array(
				array(
					'phrase'   => 'reforma integral',
					'variants' => array( 'reforma de la cocina' ),
					'gap'      => false,
				),
			)
		);

		$this->assertContains( 'reforma de la cocina', $this->anchors( $this->one ) );
	}

	public function test_route_cleans_stores_and_returns_the_phrases(): void {
		$response = $this->save(
			array(
				array(
					'phrase'   => '  <b>reforma</b>   de cocina ',
					'variants' => array( 'Reforma de cocina', 'obra de cocina', '', 'obra de cocina' ),
					'gap'      => true,
				),
				array(
					'phrase'   => '   ',
					'variants' => array( 'huérfana', 'cocina nueva' ),
					'gap'      => false,
				),
				array(
					'phrase'   => 'REFORMA DE COCINA',
					'variants' => array(),
					'gap'      => false,
				),
			)
		);

		$data = $response->get_data();
		$this->assertSame(
			array(
				array(
					'phrase'   => 'reforma de cocina',
					'variants' => array( 'obra de cocina' ),
					'gap'      => true,
				),
				array(
					'phrase'   => 'huérfana',
					'variants' => array( 'cocina nueva' ),
					'gap'      => false,
				),
			),
			$data['phrases'],
			'Una frase vacía con variantes promueve la primera variante a frase.'
		);
		$this->assertEqualsCanonicalizing( array( 'REFORMA DE COCINA', 'obra de cocina' ), $data['removed'], 'Lo repetido se devuelve para avisar al usuario.' );
		$this->assertSame( $data['phrases'], $this->request( 'GET', '/target-phrases/' . $this->target )->get_data()['phrases'] );
		$this->assertSame( array(), $this->request( 'GET', '/target-phrases/' . $this->target )->get_data()['removed'] );
		$this->assertSame( array( 'reforma de cocina', 'obra de cocina', 'huérfana', 'cocina nueva' ), Fingerprint::focus( $this->target ) );
	}

	public function test_a_phrase_equal_to_an_earlier_variant_loses_only_that_text(): void {
		$data = $this->save(
			array(
				array(
					'phrase'   => 'reforma de cocina',
					'variants' => array( 'obra de cocina' ),
					'gap'      => false,
				),
				array(
					'phrase'   => 'Obra de cocina',
					'variants' => array( 'cocinas a medida', 'cocina a medida' ),
					'gap'      => true,
				),
			)
		)->get_data();

		$this->assertSame(
			array(
				array(
					'phrase'   => 'reforma de cocina',
					'variants' => array( 'obra de cocina' ),
					'gap'      => false,
				),
				array(
					'phrase'   => 'cocinas a medida',
					'variants' => array( 'cocina a medida' ),
					'gap'      => true,
				),
			),
			$data['phrases'],
			'Se conservan sus otras variantes y su hueco.'
		);
		$this->assertSame( array( 'Obra de cocina' ), $data['removed'] );
	}

	public function test_variant_limit_counts_cleaned_variants_not_blank_lines_or_repeats(): void {
		$variants = array( 'alfa', 'beta', '', '  ', 'beta', 'gamma', 'delta', 'epsilon', 'Alfa' );
		$ok       = $this->save(
			array(
				array(
					'phrase'   => 'principal',
					'variants' => $variants,
					'gap'      => false,
				),
			)
		);
		$this->assertSame( 200, $ok->get_status() );
		$this->assertCount( 5, $ok->get_data()['phrases'][0]['variants'] );

		$too_many = $this->save(
			array(
				array(
					'phrase'   => 'principal',
					'variants' => array( 'alfa', 'beta', 'gamma', 'delta', 'epsilon', 'zeta' ),
					'gap'      => false,
				),
			)
		);
		$this->assertSame( 400, $too_many->get_status() );
	}

	public function test_length_limits_have_their_own_messages_and_count_words_like_the_engine(): void {
		$chars = $this->save(
			array(
				array(
					'phrase'   => str_repeat( 'a', TargetPhrases::MAX_CHARS + 1 ),
					'variants' => array(),
					'gap'      => false,
				),
			)
		);
		$this->assertSame( 400, $chars->get_status() );
		$this->assertStringContainsString( 'characters', $chars->get_data()['message'] );

		// Sin espacios, pero nueve palabras para el motor: no podría encajar nunca en un ancla.
		$slashes = $this->save(
			array(
				array(
					'phrase'   => 'alfa/beta/gamma/delta/epsilon/zeta/eta/theta/iota',
					'variants' => array(),
					'gap'      => false,
				),
			)
		);
		$this->assertSame( 400, $slashes->get_status() );
		$this->assertStringContainsString( 'words', $slashes->get_data()['message'] );

		// Una palabra con guiones es una sola palabra, también para el motor.
		$hyphens = $this->save(
			array(
				array(
					'phrase'   => 'el state-of-the-art de la cocina moderna con encimera',
					'variants' => array(),
					'gap'      => false,
				),
			)
		);
		$this->assertSame( 200, $hyphens->get_status() );
	}

	public function test_clearing_the_list_deletes_the_meta(): void {
		$this->save(
			array(
				array(
					'phrase'   => 'reforma de cocina',
					'variants' => array(),
					'gap'      => true,
				),
			)
		);
		$this->assertNotSame( '', get_post_meta( $this->target, TargetPhrases::KEY, true ) );

		$this->save( array() );
		$this->assertSame( array(), get_post_meta( $this->target, TargetPhrases::KEY, false ), 'Se borra la meta, no se guarda una lista vacía.' );
		$this->assertSame( array(), $this->request( 'GET', '/target-phrases/' . $this->target )->get_data()['phrases'] );
	}

	public function test_limits_and_permissions(): void {
		$words = array(
			array(
				'phrase'   => 'una dos tres cuatro cinco seis siete ocho nueve',
				'variants' => array(),
				'gap'      => false,
			),
		);
		$this->assertSame( 400, $this->save( $words )->get_status(), 'Más palabras de las que cabe un ancla.' );

		$many = array_map(
			static fn( int $i ): array => array(
				'phrase'   => 'frase numero ' . $i,
				'variants' => array(),
				'gap'      => false,
			),
			range( 1, TargetPhrases::MAX_PHRASES + 1 )
		);
		$this->assertSame( 400, $this->save( $many )->get_status() );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertSame( 403, $this->request( 'GET', '/target-phrases/' . $this->target, array(), $subscriber )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/target-phrases/' . $this->target, array( 'phrases' => array() ), $subscriber )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/target-phrases/99999999' )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/target-phrases/' . $this->target )->get_status(), 'Falta `phrases`.' );
	}

	public function test_saving_renews_the_suggestion_cache_and_reindexes_the_entry(): void {
		$epoch = wp_cache_get_last_changed( SuggestionCache::GROUP );
		$this->save(
			array(
				array(
					'phrase'   => 'reforma de cocina',
					'variants' => array(),
					'gap'      => true,
				),
			)
		);
		$this->assertNotSame( $epoch, wp_cache_get_last_changed( SuggestionCache::GROUP ) );

		// Solo cambia el hueco: no cambia el índice, pero las sugerencias guardadas dejan de valer.
		$epoch = wp_cache_get_last_changed( SuggestionCache::GROUP );
		$this->save(
			array(
				array(
					'phrase'   => 'reforma de cocina',
					'variants' => array(),
					'gap'      => false,
				),
			)
		);
		$this->assertNotSame( $epoch, wp_cache_get_last_changed( SuggestionCache::GROUP ) );

		$terms = Plugin::container()->get( \MagicLinking\Index\TableRepository::class )->terms( $this->target );
		$this->assertNotEmpty( array_filter( array_keys( $terms ), static fn( $t ): bool => str_contains( (string) $t, 'reform' ) ), 'La frase pesa en el índice de la entrada.' );
	}

	public function test_meta_is_never_autoloaded_and_adds_no_option(): void {
		global $wpdb;
		$list = static fn(): array => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%magiclinking%' AND autoload IN ('yes','on','auto','auto-on')" ); // phpcs:ignore WordPress.DB
		$was  = $list();

		$this->save(
			array(
				array(
					'phrase'   => 'reforma de cocina',
					'variants' => array( 'obra de cocina' ),
					'gap'      => true,
				),
			)
		);
		$this->anchors( $this->one );

		$this->assertSame( $was, $list() );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", TargetPhrases::KEY ) ) ); // phpcs:ignore WordPress.DB
	}

	public function test_a_corrupt_meta_is_ignored(): void {
		add_post_meta( $this->target, TargetPhrases::KEY, 'no es json' );
		$this->assertSame( array(), TargetPhrases::get( $this->target ) );
		update_post_meta( $this->target, TargetPhrases::KEY, wp_slash( wp_json_encode( array( 'x', array( 'phrase' => 5 ), array( 'phrase' => 'bien' ) ) ) ) );
		$this->assertSame(
			array(
				array(
					'phrase'   => 'bien',
					'variants' => array(),
					'gap'      => false,
				),
			),
			TargetPhrases::get( $this->target )
		);
	}

	public function test_building_a_candidate_reads_the_phrases_once(): void {
		$this->save(
			array(
				array(
					'phrase'   => 'reforma de cocina',
					'variants' => array( 'obra de cocina' ),
					'gap'      => true,
				),
			)
		);

		$reads = 0;
		$count = static function ( $value, $object_id, $meta_key ) use ( &$reads ) {
			if ( TargetPhrases::KEY === $meta_key ) {
				++$reads;
			}
			return $value;
		};
		add_filter( 'get_post_metadata', $count, 10, 3 );
		$meta = ( new \ReflectionMethod( \MagicLinking\Index\TableRepository::class, 'build_meta' ) );
		$repo = Plugin::container()->get( \MagicLinking\Index\TableRepository::class );
		$meta->invoke(
			$repo,
			$this->target,
			array(
				'post_type'  => 'post',
				'lang'       => 'es',
				'word_count' => 10,
				'doc_len'    => 5,
				'outbound'   => 0,
			)
		);
		remove_filter( 'get_post_metadata', $count, 10 );

		$this->assertSame( 1, $reads, 'Textos y hueco salen de una sola lectura.' );
	}

	public function test_a_malformed_request_is_rejected_and_never_erases_the_saved_list(): void {
		$good = array(
			array(
				'phrase'   => 'reforma de cocina',
				'variants' => array(),
				'gap'      => true,
			),
		);
		$this->save( $good );

		foreach ( array(
			array( 'reforma' ),
			array( 5 ),
			array( array( 'phrase' => array( 'x' ) ) ),
			array(
				array(
					'phrase'   => 'bien',
					'variants' => array( array( 'a' ) ),
				),
			),
		) as $bad ) {
			$this->assertSame( 400, $this->save( $bad )->get_status(), wp_json_encode( $bad ) );
		}
		$this->assertSame( 'reforma de cocina', TargetPhrases::get( $this->target )[0]['phrase'] );
	}

	public function test_numbers_become_text_and_are_never_lost(): void {
		$clean = TargetPhrases::clean(
			array(
				array(
					'phrase'   => 'tarifa',
					'variants' => array( 2026, 1.5, null ),
					'gap'      => false,
				),
			)
		);
		$this->assertSame(
			array(
				'tarifa',
				'variants' => array( '2026', '1.5' ),
			),
			array(
				$clean['phrases'][0]['phrase'],
				'variants' => $clean['phrases'][0]['variants'],
			)
		);
	}

	public function test_a_phrase_without_a_content_word_is_rejected_with_a_clear_message(): void {
		foreach ( array( 'de la y', 'los 2025', '2026' ) as $text ) {
			$response = $this->save(
				array(
					array(
						'phrase'   => $text,
						'variants' => array(),
						'gap'      => false,
					),
				)
			);
			$this->assertSame( 400, $response->get_status(), $text );
			$this->assertStringContainsString( 'content word', $response->get_data()['message'], $text );
		}

		$this->assertSame(
			400,
			$this->save(
				array(
					array(
						'phrase'   => 'reforma de cocina',
						'variants' => array( 'de la' ),
						'gap'      => false,
					),
				)
			)->get_status(),
			'También las variantes.'
		);
	}

	public function test_what_the_cap_cuts_is_not_marked_as_seen(): void {
		$variants = array( 'v1', 'v2', 'v3', 'v4', 'v5', 'v6', 'v7' );
		$clean    = TargetPhrases::clean(
			array(
				array(
					'phrase'   => 'principal',
					'variants' => $variants,
					'gap'      => false,
				),
				array(
					'phrase'   => 'V7',
					'variants' => array(),
					'gap'      => false,
				),
			)
		);

		$this->assertSame( array( 'v1', 'v2', 'v3', 'v4', 'v5' ), $clean['phrases'][0]['variants'] );
		$this->assertSame( 'V7', $clean['phrases'][1]['phrase'], 'La variante recortada no hace repetida a la frase siguiente.' );
		$this->assertSame( array(), $clean['removed'] );
	}

	public function test_indexing_an_entry_reads_the_phrases_once(): void {
		$this->save(
			array(
				array(
					'phrase'   => 'reforma de cocina',
					'variants' => array( 'obra de cocina' ),
					'gap'      => true,
				),
			)
		);

		$reads = 0;
		$count = static function ( $value, $object_id, $meta_key ) use ( &$reads ) {
			if ( TargetPhrases::KEY === $meta_key ) {
				++$reads;
			}
			return $value;
		};
		add_filter( 'get_post_metadata', $count, 10, 3 );
		$indexer   = Plugin::container()->get( \MagicLinking\Index\LexicalIndexer::class );
		$documents = new \ReflectionMethod( $indexer, 'documents' );
		iterator_to_array( $documents->invoke( $indexer, array( $this->target ) ) );
		remove_filter( 'get_post_metadata', $count, 10 );

		$this->assertSame( 1, $reads, 'El documento y la huella salen de una sola lectura.' );
	}
}
