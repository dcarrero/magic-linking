<?php
/**
 * Idioma de la entrada detectado por el texto (F1-37): opcional, desactivado por defecto, sin efecto con WPML o
 * Polylang, y sin cruces de idioma (regla 9) en un sitio en inglés con entradas en español.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Content\Destination;
use MagicLinking\Content\InsertionException;
use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\Core\Settings;
use MagicLinking\I18n\Language;
use MagicLinking\Jobs\Jobs;
use WP_REST_Request;
use WP_REST_Response;

final class DetectLanguageTest extends GraphTestCase {

	private const BASE = '/magic-linking/v1';

	private int $admin = 0;

	private int $es_target = 0;

	private int $es_source = 0;

	private int $en_target = 0;

	private int $en_source = 0;

	public function set_up(): void {
		parent::set_up();

		// El sitio está en inglés (en_US) y la mitad de las entradas están en español.
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->es_target = $this->make(
			'Aerotermia: guía completa',
			'La aerotermia aprovecha la energía del aire exterior para calentar la vivienda con una bomba de calor eficiente, y por eso es una de las soluciones que más se están instalando en las casas nuevas.',
			'El consumo de una instalación de aerotermia depende del aislamiento, del suelo radiante y de la zona climática en la que se encuentre la casa, que es lo que más pesa en la factura.'
		);
		$this->es_source = $this->make(
			'Reforma del cuarto de baño',
			'Este fin de semana nos hemos puesto con la reforma del cuarto de baño y hemos repasado los azulejos, el plato de ducha, la mampara y la grifería antes de comprar nada para la casa.',
			'Mientras tanto, la aerotermia nos ha dado mucho que pensar, porque el suelo radiante de la casa cuesta lo suyo y no tenemos prisa por decidir lo que vamos a hacer con el presupuesto.'
		);
		$this->en_target = $this->make(
			'Heat pumps: the complete guide',
			'A heat pump takes the energy from the outside air to warm up the house with an efficient system, and that is why it is one of the solutions that are being installed the most in new homes.',
			'The consumption of a heat pump installation depends on the insulation, the underfloor heating and the climate zone where the house is, which is what weighs the most on the bill.'
		);
		$this->en_source = $this->make(
			'Renovating the bathroom',
			'This weekend we started renovating the bathroom and we went over the tiles, the shower tray, the screen and the taps before buying anything for the house, as there is a lot to think about.',
			'In the meantime, the heat pump has given us a lot to think about, because the underfloor heating of the house costs a bit and we are in no hurry to decide what to do with the budget.'
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function make( string $title, string $one, string $two, string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => "<!-- wp:paragraph -->\n<p>{$one}</p>\n<!-- /wp:paragraph -->\n<!-- wp:paragraph -->\n<p>{$two}</p>\n<!-- /wp:paragraph -->",
			)
		);
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
	 * Fila de `magiclinking_terms`/`postings`: idiomas con entradas en el índice léxico.
	 *
	 * @return list<string>
	 */
	private function lexical_languages(): array {
		global $wpdb;
		$name = Schema::table( $wpdb->prefix, 'docs' );
		$list = $wpdb->get_col( "SELECT DISTINCT lang FROM {$name} ORDER BY lang" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'strval', $list );
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
	 * Cambia la opción por la API y termina el análisis que lanza.
	 */
	private function save_detect( bool $on ): WP_REST_Response {
		$response = $this->request( 'POST', '/settings', array( 'detect_language' => $on ) );
		$this->assertSame( 200, $response->get_status() );
		if ( ! empty( $response->get_data()['job'] ) ) {
			Plugin::container()->get( Jobs::class )->run_to_completion( $response->get_data()['job']['id'] );
		}

		return $response;
	}

	/**
	 * @return list<int>
	 */
	private function outbound_targets( int $source ): array {
		$response = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $source ) );

		return array_map( static fn( array $item ): int => (int) $item['target']['id'], $response->get_data()['items'] );
	}

	public function test_it_is_off_by_default_and_the_site_language_is_used(): void {
		$this->assertFalse( Plugin::container()->get( Settings::class )->detect_language() );
		$this->assertFalse( Plugin::container()->get( Settings::class )->all()['detect_language'] );

		$this->reindex_all();

		$language = Plugin::container()->get( Language::class );
		$this->assertFalse( $language->detects() );
		foreach ( array( $this->es_target, $this->es_source, $this->en_target, $this->en_source ) as $id ) {
			$this->assertSame( 'en', $language->for_post( $id ) );
			$this->assertSame( 'en', $this->doc_row( $id )['lang'] );
		}
		$this->assertSame( array( 'en' ), $this->lexical_languages() );
	}

	public function test_turning_it_on_reindexes_and_marks_a_spanish_entry_as_es(): void {
		$this->reindex_all();

		$response = $this->save_detect( true );

		$this->assertNotEmpty( $response->get_data()['job'], 'Cambiar la opción lanza un análisis reanudable.' );
		$this->assertTrue( $response->get_data()['settings']['detect_language'] );

		$language = Plugin::container()->get( Language::class );
		$this->assertTrue( $language->detects() );
		$this->assertSame( 'es', $language->for_post( $this->es_source ) );
		$this->assertSame( 'es', $language->for_post( $this->es_target ) );
		$this->assertSame( 'en', $language->for_post( $this->en_source ) );
		$this->assertSame( 'es', $this->doc_row( $this->es_source )['lang'] );
		$this->assertSame( 'es', $this->doc_row( $this->es_target )['lang'] );
		$this->assertSame( 'en', $this->doc_row( $this->en_target )['lang'] );
		$this->assertSame( array( 'en', 'es' ), $this->lexical_languages() );
	}

	public function test_languages_do_not_cross_with_the_option_on(): void {
		$this->save_detect( true );

		$destination = Plugin::container()->get( Destination::class );
		$this->assertIsString( $destination->resolve( $this->es_source, $this->es_target ) );
		$cross = $destination->resolve( $this->es_source, $this->en_target );
		$this->assertInstanceOf( InsertionException::class, $cross );
		$this->assertSame( InsertionException::LANGUAGE, $cross->reason() );

		$this->assertContains( $this->es_target, $this->outbound_targets( $this->es_source ) );
		$this->assertNotContains( $this->en_target, $this->outbound_targets( $this->es_source ) );
		$this->assertContains( $this->en_target, $this->outbound_targets( $this->en_source ) );
		$this->assertNotContains( $this->es_target, $this->outbound_targets( $this->en_source ) );
	}

	public function test_turning_it_off_goes_back_to_the_site_language(): void {
		$this->save_detect( true );
		$response = $this->save_detect( false );

		$this->assertNotEmpty( $response->get_data()['job'] );
		foreach ( array( $this->es_target, $this->es_source, $this->en_target ) as $id ) {
			$this->assertSame( 'en', $this->doc_row( $id )['lang'] );
		}
		$this->assertSame( array( 'en' ), $this->lexical_languages() );
	}

	public function test_saving_without_changing_the_option_does_not_reindex(): void {
		$this->save_detect( true );

		$response = $this->request( 'POST', '/settings', array( 'detect_language' => true ) );

		$this->assertEmpty( $response->get_data()['job'] );
	}

	public function test_a_multilingual_plugin_always_wins_and_the_option_does_nothing(): void {
		add_filter( 'wpml_post_language_details', static fn() => array( 'language_code' => 'fr' ) );
		add_filter( 'magiclinking_is_multilingual', '__return_true' );
		$this->save_detect( true );

		$language = Plugin::container()->get( Language::class );
		$this->assertFalse( $language->detects() );
		$this->assertSame( 'fr', $language->for_post( $this->es_source ) );
		$this->assertSame( 'fr', $this->doc_row( $this->es_source )['lang'] );

		$payload = $this->request( 'GET', '/settings' )->get_data();
		$this->assertTrue( $payload['multilingual'] );
	}

	public function test_polylang_wins_and_a_plugin_without_a_language_for_the_entry_does_not_detect(): void {
		$this->save_detect( true );

		$polylang = new Language( static fn() => false, Plugin::container()->get( Settings::class ) );

		$this->assertFalse( $polylang->detects(), 'Con Polylang activo la opción no hace nada.' );
		$this->assertSame( 'en', $polylang->for_post( $this->es_source ) );
	}

	public function test_the_filter_wins_over_the_detection(): void {
		$this->save_detect( true );
		add_filter( 'magiclinking_post_language', static fn() => 'ca' );

		$this->assertSame( 'ca', Plugin::container()->get( Language::class )->for_post( $this->es_source ) );
	}

	public function test_a_new_entry_that_is_not_indexed_yet_is_detected_from_its_text(): void {
		$this->save_detect( true );

		$draft = $this->make(
			'Un borrador sobre aerotermia',
			'Estoy escribiendo un borrador sobre la aerotermia y las casas con suelo radiante, y de momento solo tengo unas notas que luego voy a pasar a limpio para publicarlas en el blog.',
			'Todavía me falta decidir qué fotos poner, y cuántos enlaces hacen falta en el texto para que se lea bien y no parezca que está todo lleno de enlaces.',
			'draft'
		);

		$this->assertNull( $this->doc_row( $draft ) );
		$this->assertSame( 'es', Plugin::container()->get( Language::class )->for_post( $draft ) );
	}

	public function test_an_unclear_entry_uses_the_site_language(): void {
		$this->save_detect( true );
		$short = self::factory()->post->create(
			array(
				'post_title'   => 'Corto',
				'post_content' => '<!-- wp:paragraph --><p>Hola, esto es corto.</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertSame( 'en', Plugin::container()->get( Language::class )->for_post( $short ) );
	}

	public function test_editing_an_entry_updates_its_detected_language(): void {
		$this->save_detect( true );
		$this->assertSame( 'es', $this->doc_row( $this->es_source )['lang'] );

		wp_update_post(
			array(
				'ID'           => $this->es_source,
				'post_content' => $this->en_source_content(),
			)
		);
		Plugin::container()->get( \MagicLinking\Graph\GraphIndexer::class )->index_post( $this->es_source );

		$this->assertSame( 'en', $this->doc_row( $this->es_source )['lang'] );
	}

	private function en_source_content(): string {
		return get_post_field( 'post_content', $this->en_source );
	}
}
