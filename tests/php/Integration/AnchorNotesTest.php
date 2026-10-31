<?php
/**
 * «Ya enlazado» y aviso de ancla repetida (F1-23, D-30, docs/07 §3.3): las sugerencias traen los avisos de
 * canibalización y de ancla sobreexplotada con su texto, la respuesta de salientes trae los enlaces actuales de la
 * entrada y la comprobación de un ancla ajustada devuelve los mismos avisos.
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

final class AnchorNotesTest extends GraphTestCase {

	private const BASE = '/magic-linking/v1';

	private int $admin = 0;

	/**
	 * Destino: «Aerotermia».
	 */
	private int $target = 0;

	/**
	 * Orígenes que mencionan la aerotermia.
	 *
	 * @var list<int>
	 */
	private array $sources = array();

	public function set_up(): void {
		parent::set_up();

		add_filter( 'locale', static fn(): string => 'es_ES' );

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
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

	private function request( string $method, string $route, array $params = array() ): WP_REST_Response {
		wp_set_current_user( $this->admin );
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
	 * Un destino sobre aerotermia, `$count` orígenes que la mencionan y relleno de otros temas.
	 *
	 * @param int $count Orígenes.
	 */
	private function corpus( int $count = 3 ): void {
		$this->target = self::factory()->post->create(
			array(
				'post_title'   => 'Aerotermia: guía completa',
				'post_name'    => 'aerotermia',
				'post_content' => $this->p( 'La aerotermia aprovecha la energía del aire exterior para calentar la vivienda con una bomba de calor eficiente.' )
					. $this->p( 'El consumo de una instalación de aerotermia depende del aislamiento, del suelo radiante y de la zona climática.' ),
			)
		);

		$topics = array(
			'Reforma del cuarto de baño'   => 'azulejos, plato de ducha, mampara, grifería y desagües',
			'Cambiar las ventanas de casa' => 'perfiles de aluminio, doble acristalamiento, persianas y herrajes',
			'Pintar el salón sin obras'    => 'rodillos, imprimación, esmalte, brochas y cinta de carrocero',
		);
		foreach ( array_slice( $topics, 0, $count, true ) as $title => $words ) {
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

		// Relleno de otros temas: con pocas entradas, cada una que menciona la aerotermia le quita peso al término.
		for ( $i = 0; $i < 40; $i++ ) {
			self::factory()->post->create(
				array(
					'post_title'   => 'Viaje número ' . $i,
					'post_content' => $this->p( 'Una ruta por ' . wp_generate_password( 8, false ) . ' con paradas para comer, dormir y recorrer calles estrechas y plazas con fuentes antiguas.' ),
				)
			);
		}

		$this->build_index();
	}

	private function build_index(): void {
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
	 * Entradas de relleno que enlazan `$anchor` a `$to`.
	 *
	 * @param int    $count  Entradas.
	 * @param string $anchor Ancla.
	 * @param int    $to     Destino.
	 */
	private function linkers( int $count, string $anchor, int $to ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			self::factory()->post->create(
				array(
					'post_title'   => 'Entrada con enlace ' . $i . ' ' . wp_generate_password( 6, false ),
					'post_content' => $this->p( 'Antes de nada, mira <a href="' . get_permalink( $to ) . '">' . ( 0 === $i % 2 ? $anchor : ucfirst( $anchor ) ) . '</a> en nuestra guía de ahorro.' ),
				)
			);
		}
		$this->build_index();
	}

	/**
	 * La sugerencia saliente de un origen hacia el destino.
	 *
	 * @param int $source Origen.
	 *
	 * @return array<string, mixed>
	 */
	private function outbound_item( int $source ): array {
		$response = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $source ) );
		$this->assertSame( 200, $response->get_status() );
		foreach ( $response->get_data()['items'] as $item ) {
			if ( $this->target === $item['target']['id'] ) {
				return $item;
			}
		}
		$this->fail( 'No hay sugerencia hacia el destino. ' . wp_json_encode( array_diff_key( $response->get_data(), array( 'items' => 1 ) ) ) . count( $response->get_data()['items'] ) );
	}

	/**
	 * Códigos de los avisos de una sugerencia.
	 *
	 * @param array<string, mixed> $item Sugerencia.
	 *
	 * @return list<string>
	 */
	private function codes( array $item ): array {
		return array_column( $item['anchor_notes'], 'code' );
	}

	// ------------------------------------------------------------------ Avisos de ancla.

	public function test_an_anchor_nobody_uses_has_no_notes(): void {
		$this->corpus();

		$item = $this->outbound_item( $this->sources[0] );

		$this->assertArrayHasKey( 'anchor_notes', $item );
		$this->assertSame( array(), $item['anchor_notes'] );
	}

	public function test_an_anchor_that_already_links_to_another_destination_warns_about_cannibalization_in_words(): void {
		$this->corpus();
		$anchor = $this->outbound_item( $this->sources[0] )['anchor'];
		$other  = self::factory()->post->create( array( 'post_title' => 'Bombas de calor: precios' ) );

		// Dos entradas distintas (una con la primera letra en mayúscula): el aviso no distingue mayúsculas.
		$this->linkers( 2, $anchor, $other );
		$item = $this->outbound_item( $this->sources[0] );

		$this->assertSame( array( 'cannibalization' ), $this->codes( $item ) );
		$text = $item['anchor_notes'][0]['text'];
		$this->assertStringContainsString( 'Bombas de calor: precios', $text );
		$this->assertStringContainsString( '2 entries', $text );
	}

	public function test_one_entry_uses_the_singular(): void {
		$this->corpus();
		$anchor = $this->outbound_item( $this->sources[0] )['anchor'];
		$other  = self::factory()->post->create( array( 'post_title' => 'Bombas de calor: precios' ) );

		$this->linkers( 1, $anchor, $other );
		$item = $this->outbound_item( $this->sources[0] );

		$this->assertStringContainsString( '1 entry', $item['anchor_notes'][0]['text'] );
	}

	public function test_the_note_names_the_most_used_destination_and_counts_the_rest(): void {
		$this->corpus();
		$anchor = $this->outbound_item( $this->sources[0] )['anchor'];
		$one    = self::factory()->post->create( array( 'post_title' => 'Destino poco usado' ) );
		$two    = self::factory()->post->create( array( 'post_title' => 'Destino muy usado' ) );

		$this->linkers( 1, $anchor, $one );
		$this->linkers( 3, $anchor, $two );
		$item = $this->outbound_item( $this->sources[0] );

		$this->assertSame( array( 'cannibalization' ), $this->codes( $item ) );
		$text = $item['anchor_notes'][0]['text'];
		$this->assertStringContainsString( 'Destino muy usado', $text );
		$this->assertStringContainsString( '3 entries', $text );
		$this->assertStringContainsString( '1 other destination', $text );
		$this->assertStringNotContainsString( 'Destino poco usado', $text );
	}

	public function test_an_anchor_used_more_than_the_setting_toward_the_same_destination_is_overused_in_words(): void {
		$this->corpus();
		$anchor = $this->outbound_item( $this->sources[0] )['anchor'];
		update_option( 'magiclinking_settings', array( 'same_anchor_max' => 2 ) );

		// Con 2 (el tope) todavía no; con 3 sí. Es la misma cuenta que penaliza el motor (`04 §6.1`).
		$this->linkers( 2, $anchor, $this->target );
		$this->assertNotContains( 'overused', $this->codes( $this->outbound_item( $this->sources[0] ) ) );

		$this->linkers( 1, $anchor, $this->target );
		$item = $this->outbound_item( $this->sources[0] );
		$this->assertContains( 'overused', $this->codes( $item ) );
		$notes = array_column( $item['anchor_notes'], 'text', 'code' );
		$this->assertStringContainsString( '3 entries', $notes['overused'] );
		$this->assertStringNotContainsString( 'cannibalization', implode( ' ', $this->codes( $item ) ) );
	}

	public function test_the_alternatives_carry_their_own_notes(): void {
		update_option( 'magiclinking_settings', array( 'alternatives' => 2 ) );
		$this->corpus( 1 );
		$source = self::factory()->post->create(
			array(
				'post_title'   => 'Notas sueltas de la casa',
				'post_content' => $this->p( 'Primero hablamos de la aerotermia como sistema de calefacción para la vivienda.' )
					. $this->p( 'Después comparamos la aerotermia con la caldera de gas y con los radiadores eléctricos.' )
					. $this->p( 'Al final la aerotermia gana por consumo y por facilidad de instalación en casa.' )
					. $this->p( 'Una nota más sobre la aerotermia y el suelo radiante de toda la vivienda.' ),
			)
		);
		$this->build_index();
		$item = $this->outbound_item( $source );
		$this->assertNotEmpty( $item['alternatives'], 'La prueba necesita frases alternativas.' );

		foreach ( $item['alternatives'] as $alternative ) {
			$this->assertArrayHasKey( 'anchor_notes', $alternative );
			$this->assertSame( array(), $alternative['anchor_notes'] );
		}

		// Cada alternativa lleva los avisos de su propia ancla.
		$other = self::factory()->post->create( array( 'post_title' => 'Bombas de calor: precios' ) );
		$this->linkers( 2, $item['alternatives'][0]['anchor'], $other );
		$item = $this->outbound_item( $source );
		$this->assertSame( array( 'cannibalization' ), array_column( $item['alternatives'][0]['anchor_notes'], 'code' ) );
	}

	public function test_inbound_suggestions_carry_the_notes_too(): void {
		$this->corpus();
		$anchor = $this->outbound_item( $this->sources[0] )['anchor'];
		$other  = self::factory()->post->create( array( 'post_title' => 'Bombas de calor: precios' ) );
		$this->linkers( 2, $anchor, $other );

		$response = $this->request( 'GET', '/suggestions/inbound', array( 'post_id' => $this->target ) );
		$this->assertSame( 200, $response->get_status() );
		$found = array_values(
			array_filter(
				$response->get_data()['items'],
				fn( array $item ): bool => $item['source']['id'] === $this->sources[0] && $item['anchor'] === $anchor
			)
		);
		$this->assertNotEmpty( $found );
		$this->assertSame( array( 'cannibalization' ), $this->codes( $found[0] ) );
	}

	public function test_the_anchor_check_returns_the_notes_of_the_adjusted_anchor(): void {
		$this->corpus();
		$item  = $this->outbound_item( $this->sources[0] );
		$other = self::factory()->post->create( array( 'post_title' => 'Bombas de calor: precios' ) );
		$this->linkers( 2, $item['anchor'], $other );

		$answer = $this->request(
			'POST',
			'/suggestions/anchor',
			array(
				'post_id'   => $this->sources[0],
				'sentence'  => $item['sentence'],
				'offset'    => $item['offset'],
				'anchor'    => $item['anchor'],
				'target_id' => $this->target,
			)
		)->get_data();

		$this->assertTrue( $answer['valid'] );
		$this->assertSame( array( 'cannibalization' ), array_column( $answer['notes'], 'code' ) );
		$this->assertStringContainsString( 'Bombas de calor: precios', $answer['notes'][0]['text'] );

		// Sin destino no hay nada que comparar; un ancla nueva, tampoco.
		$none = $this->request(
			'POST',
			'/suggestions/anchor',
			array(
				'post_id'  => $this->sources[0],
				'sentence' => $item['sentence'],
				'offset'   => $item['offset'],
				'anchor'   => $item['anchor'],
			)
		)->get_data();
		$this->assertSame( array(), $none['notes'] );
	}

	// ------------------------------------------------------------------ Enlaces actuales.

	public function test_outbound_brings_the_destinations_the_entry_already_links_to(): void {
		$this->corpus();
		$guide = self::factory()->post->create( array( 'post_title' => 'Guía de ahorro' ) );
		$draft = self::factory()->post->create(
			array(
				'post_title'  => 'Un borrador',
				'post_status' => 'draft',
			)
		);
		$from  = self::factory()->post->create(
			array(
				'post_title'   => 'Casa eficiente',
				'post_content' => $this->p(
					'Lee <a href="' . get_permalink( $guide ) . '">la guía de ahorro</a> y también <a href="' . get_permalink( $guide ) . '#otra">esta guía</a>, '
					. 'mira <a href="' . get_permalink( $this->target ) . '">la aerotermia</a>, <a href="https://example.org/fuera">un sitio</a> y <a href="/no-existe-nada/">un roto</a>.'
				),
			)
		);
		$this->build_index();

		$response = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $from ) );
		$this->assertSame( 200, $response->get_status() );
		$current = $response->get_data()['current_links'];

		$this->assertSame( array( $guide, $this->target ), array_column( $current, 'id' ), 'Una fila por destino; ni externos ni rotos.' );
		$this->assertSame( 'Guía de ahorro', $current[0]['title'] );
		$this->assertSame( get_permalink( $guide ), $current[0]['url'] );
		$this->assertSame( array( 'la guía de ahorro', 'esta guía' ), $current[0]['anchors'] );
		$this->assertSame( array( 'la aerotermia' ), $current[1]['anchors'] );
		$this->assertNotContains( $draft, array_column( $current, 'id' ) );
	}

	public function test_outbound_without_links_brings_an_empty_list(): void {
		$this->corpus();

		$data = $this->request( 'GET', '/suggestions/outbound', array( 'post_id' => $this->sources[0] ) )->get_data();

		$this->assertSame( array(), $data['current_links'] );
	}

	public function test_the_editor_content_decides_the_current_links_not_the_saved_one(): void {
		$this->corpus();
		$guide = self::factory()->post->create( array( 'post_title' => 'Guía de ahorro' ) );
		$other = self::factory()->post->create( array( 'post_title' => 'Otra guía' ) );
		$from  = self::factory()->post->create(
			array(
				'post_title'   => 'Casa eficiente',
				'post_content' => $this->p( 'Lee <a href="' . get_permalink( $guide ) . '">la guía de ahorro</a>.' ),
			)
		);
		$this->build_index();

		$data = $this->request(
			'POST',
			'/suggestions/outbound',
			array(
				'post_id' => $from,
				'title'   => 'Casa eficiente',
				'content' => $this->p( 'Lee <a href="' . get_permalink( $other ) . '">la otra guía</a> sin guardar.' ),
			)
		)->get_data();

		$this->assertSame( array( $other ), array_column( $data['current_links'], 'id' ) );
	}
}
