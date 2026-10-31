<?php
/**
 * Ajustes de F1-16: valores por defecto (iguales que en la 0.10.0), saneado, REST, asistente de arranque y
 * lectura del texto según «dónde se puede enlazar».
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Settings;
use MagicLinking\Index\PostSource;
use MagicLinking\Jobs\Jobs;
use MagicLinking\Rest\SettingsController;
use WP_REST_Request;
use WP_REST_Response;

final class SettingsTest extends GraphTestCase {

	private const BASE = '/magic-linking/v1';

	private int $admin;
	private int $editor;

	public function set_up(): void {
		parent::set_up();

		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
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

	private function request( string $method, string $route, array $params = array(), ?int $user = null ): WP_REST_Response {
		wp_set_current_user( $user ?? $this->admin );
		$request = new WP_REST_Request( $method, self::BASE . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function settings(): Settings {
		return Plugin::container()->get( Settings::class );
	}

	public function test_the_defaults_keep_the_behavior_of_0_10_0(): void {
		$all = $this->settings()->all();

		$this->assertSame( array( 'post', 'page' ), $all['post_types'] );
		$this->assertSame( 2, $all['low_inbound_threshold'] );
		$this->assertSame( 100, $all['words_per_link'] );
		$this->assertSame( 90, $all['history_retention_days'] );
		$this->assertFalse( $all['delete_data_on_uninstall'] );
		$this->assertSame(
			array(
				'headings' => false,
				'quotes'   => false,
				'tables'   => false,
			),
			$this->settings()->link_scope()
		); // phpcs:ignore Universal.Arrays.MultipleStatementAlignment, WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( array(), $this->settings()->link_attributes() );
		$this->assertSame( 15, $all['outbound_shown'], 'Igual que Suggester::MAX_OUTGOING.' );
		$this->assertSame( 10, $all['inbound_per_page'] );
		$this->assertSame( 5, $all['same_anchor_max'], 'Igual que Scorer::OVERUSED.' );
		$this->assertSame( array(), $all['ignored_urls'] );
		$this->assertSame( array(), $all['ignored_words'] );
		$this->assertSame( 0, $all['alternatives'], 'D-33: sin frases alternativas hasta que el banco las valide.' );
	}

	public function test_the_alternative_sentences_are_bounded_from_0_to_2(): void {
		$this->assertSame( 2, $this->settings()->update( array( 'alternatives' => 9 ) )['alternatives'] );
		$this->assertSame( 0, $this->settings()->update( array( 'alternatives' => -3 ) )['alternatives'] );
		$this->assertSame( 1, $this->settings()->update( array( 'alternatives' => '1' ) )['alternatives'] );
	}

	public function test_a_site_that_saved_settings_with_0_10_0_keeps_them_and_gets_the_new_defaults(): void {
		update_option(
			Installer::SETTINGS_OPTION,
			array(
				'post_types'            => array( 'page' ),
				'low_inbound_threshold' => 4,
				'words_per_link'        => 250,
			)
		);

		$all = $this->settings()->all();
		$this->assertSame( array( 'page' ), $all['post_types'] );
		$this->assertSame( 4, $all['low_inbound_threshold'] );
		$this->assertSame( 250, $all['words_per_link'] );
		$this->assertSame( 10, $all['inbound_per_page'] );
		$this->assertFalse( $all['link_in_quotes'] );
	}

	public function test_values_are_sanitized_and_bounded(): void {
		$saved = $this->settings()->update(
			array(
				'outbound_shown'   => 999,
				'inbound_per_page' => 1,
				'same_anchor_max'  => 0,
				'link_in_quotes'   => 'true',
				'link_in_tables'   => 'false',
				'open_in_new_tab'  => '1',
				'extra_rel'        => " NoFollow,  sponsored\nnofollow  <b>x</b> javascript:alert(1) 9bad ",
				'ignored_words'    => "  Aire  acondicionado \n\n<b>Gratis</b>\nGratis\n",
				'ignored_urls'     => array( 'https://example.org/a/', 'javascript:alert(1)', '  https://example.org/a/  ', '/ruta-relativa/' ),
			)
		);

		$this->assertSame( 50, $saved['outbound_shown'] );
		$this->assertSame( 5, $saved['inbound_per_page'] );
		$this->assertSame( 1, $saved['same_anchor_max'] );
		$this->assertTrue( $saved['link_in_quotes'] );
		$this->assertFalse( $saved['link_in_tables'] );
		$this->assertTrue( $saved['open_in_new_tab'] );
		$this->assertSame( 'nofollow sponsored', $saved['extra_rel'] );
		$this->assertSame( array( 'Aire acondicionado', 'Gratis' ), $saved['ignored_words'] );
		$this->assertSame( array( 'https://example.org/a/', '/ruta-relativa/' ), $saved['ignored_urls'] );
		$this->assertSame(
			array(
				'target' => '_blank',
				'rel'    => 'nofollow sponsored noopener',
			),
			$this->settings()->link_attributes()
		);

		// Un parcial no pisa lo demás y un valor inválido conserva el anterior.
		$again = $this->settings()->update( array( 'history_retention_days' => 7 ) );
		$this->assertSame( 90, $again['history_retention_days'] );
		$this->assertSame( 'nofollow sponsored', $again['extra_rel'] );
	}

	public function test_lists_are_capped(): void {
		$words = array_map( static fn( int $n ): string => "palabra $n", range( 1, 400 ) );
		$saved = $this->settings()->update( array( 'ignored_words' => $words ) );

		$this->assertCount( 200, $saved['ignored_words'] );
	}

	public function test_only_administrators_reach_the_settings_and_the_wizard(): void {
		foreach ( array(
			array( 'GET', '/settings' ),
			array( 'POST', '/settings' ),
			array( 'GET', '/wizard' ),
			array( 'POST', '/wizard' ),
		) as [ $method, $route ] ) {
			$this->assertSame( 403, $this->request( $method, $route, array( 'dismissed' => true ), $this->editor )->get_status(), "$method $route" );
			$this->assertSame( 401, $this->request( $method, $route, array( 'dismissed' => true ), 0 )->get_status(), "$method $route sin sesión" );
		}
	}

	public function test_the_new_settings_are_saved_by_rest_and_invalid_values_are_refused(): void {
		$data = $this->request(
			'POST',
			'/settings',
			array(
				'link_in_headings' => true,
				'outbound_shown'   => 7,
				'ignored_words'    => array( 'gratis' ),
			)
		)->get_data();

		$this->assertTrue( $data['settings']['link_in_headings'] );
		$this->assertSame( 7, $data['settings']['outbound_shown'] );
		$this->assertSame( array( 'gratis' ), $data['settings']['ignored_words'] );

		foreach ( array(
			array( 'link_in_quotes' => 'tal vez' ),
		) as $bad ) {
			$this->assertSame( 400, $this->request( 'POST', '/settings', $bad )->get_status(), wp_json_encode( $bad ) );
		}
	}

	public function test_changing_where_links_can_go_rebuilds_the_index_and_other_settings_do_not(): void {
		$other = $this->request( 'POST', '/settings', array( 'open_in_new_tab' => true ) )->get_data();
		$this->assertNull( $other['job'] );

		$same = $this->request( 'POST', '/settings', array( 'link_in_tables' => false ) )->get_data();
		$this->assertNull( $same['job'], 'Guardar el mismo valor no relanza nada.' );

		$changed = $this->request( 'POST', '/settings', array( 'link_in_tables' => true ) )->get_data();
		$this->assertSame( 'queued', $changed['job']['status'] );
		$this->assertTrue( $changed['job']['force'], 'Lee todas las entradas aunque no hayan cambiado.' );
	}

	public function test_the_text_the_engine_reads_follows_the_settings_without_restarting(): void {
		$post = self::factory()->post->create(
			array(
				'post_content' => "<!-- wp:heading -->\n<h2>Encabezado</h2>\n<!-- /wp:heading -->\n\n<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody><tr><td>Celda</td></tr></tbody></table></figure>\n<!-- /wp:table -->\n\n<!-- wp:paragraph -->\n<p>Texto.</p>\n<!-- /wp:paragraph -->",
			)
		);
		$read = static fn(): array => array_map(
			static fn( $p ): string => $p->text,
			Plugin::container()->get( PostSource::class )->from_post( get_post( $post ) )->paragraphs
		);

		$this->assertSame( array( 'Texto.' ), $read() );

		$this->settings()->update(
			array(
				'link_in_headings' => true,
				'link_in_tables'   => true,
			)
		);
		$this->assertSame( array( 'Encabezado', 'Celda', 'Texto.' ), $read() );

		$this->settings()->update(
			array(
				'link_in_headings' => false,
				'link_in_tables'   => false,
			)
		);
		$this->assertSame( array( 'Texto.' ), $read() );
	}

	public function test_the_wizard_is_closed_and_reopened_per_user(): void {
		$first = $this->request( 'GET', '/wizard' )->get_data();
		$this->assertFalse( $first['dismissed'] );
		$this->assertContains( $first['ai'], array( 'connected', 'none', 'unavailable' ) );
		$this->assertNotSame( '', $first['language'] );
		$this->assertIsBool( $first['multilingual'] );

		$this->assertTrue( $this->request( 'POST', '/wizard', array( 'dismissed' => true ) )->get_data()['dismissed'] );
		$this->assertSame( '1', get_user_meta( $this->admin, SettingsController::wizard_meta_key(), true ) );
		$this->assertTrue( $this->request( 'GET', '/wizard' )->get_data()['dismissed'] );

		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertFalse( $this->request( 'GET', '/wizard', array(), $other )->get_data()['dismissed'], 'Cada usuario cierra el suyo.' );

		$this->assertFalse( $this->request( 'POST', '/wizard', array( 'dismissed' => false ) )->get_data()['dismissed'] );
		$this->assertSame( '', get_user_meta( $this->admin, SettingsController::wizard_meta_key(), true ) );

		$this->assertSame( 400, $this->request( 'POST', '/wizard' )->get_status(), 'Hay que decir qué se quiere.' );
	}

	public function test_the_wizard_stores_nothing_in_the_settings_option_and_asks_for_no_email(): void {
		$before = get_option( Installer::SETTINGS_OPTION );
		$this->request( 'POST', '/wizard', array( 'dismissed' => true ) );

		$this->assertSame( $before, get_option( Installer::SETTINGS_OPTION ) );
		$this->assertSame( array( 'dismissed', 'language', 'multilingual', 'ai' ), array_keys( $this->request( 'GET', '/wizard' )->get_data() ) );
	}

	/**
	 * Parámetros de un proceso.
	 *
	 * @param array<string, mixed> $job Proceso presentado por la API.
	 *
	 * @return array<string, mixed>
	 */
	private function params( array $job ): array {
		return Plugin::container()->get( Jobs::class )->repository()->get( (int) $job['id'] )['params'];
	}

	public function test_out_of_range_numbers_are_limited_not_refused(): void {
		$response = $this->request(
			'POST',
			'/settings',
			array(
				'inbound_per_page' => 4,
				'outbound_shown'   => 0,
				'same_anchor_max'  => 51,
				'link_in_tables'   => true,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Un número fuera de rango no tira el guardado entero.' );
		$data = $response->get_data()['settings'];
		$this->assertSame( 5, $data['inbound_per_page'] );
		$this->assertSame( 1, $data['outbound_shown'] );
		$this->assertSame( 50, $data['same_anchor_max'] );
		$this->assertTrue( $data['link_in_tables'], 'Los demás cambios se guardan.' );
	}

	public function test_changing_scope_and_types_together_runs_one_job_that_also_reads_the_graph(): void {
		$data = $this->request(
			'POST',
			'/settings',
			array(
				'post_types'     => array( 'post' ),
				'link_in_tables' => true,
			)
		)->get_data();
		$this->assertNotNull( $data['job'] );
		$this->assertTrue( $this->params( $data['job'] )['graph'], 'Los tipos nuevos pasan por el grafo.' );
		$this->assertTrue( $this->params( $data['job'] )['force'] );
	}

	public function test_a_scope_change_during_an_index_is_not_lost(): void {
		$jobs = Plugin::container()->get( Jobs::class );
		$old  = $jobs->start_index( 0, false, false, false, false );

		$data = $this->request( 'POST', '/settings', array( 'link_in_quotes' => true ) )->get_data();

		$this->assertNotSame( $old['id'], $data['job']['id'], 'Arranca una reconstrucción nueva con el ámbito nuevo.' );
		$this->assertSame( 'cancelled', $jobs->repository()->get( $old['id'] )['status'] );
		$params = $this->params( $data['job'] );
		$this->assertTrue( $params['force'] );
		$this->assertTrue( $params['build'] );
	}

	public function test_ignored_addresses_are_resolved_to_ids_when_saved_and_never_when_read(): void {
		$page = self::factory()->post->create( array( 'post_name' => 'ignorada' ) );
		$this->settings()->update( array( 'ignored_urls' => array( get_permalink( $page ), 'https://example.org/no-existe/' ) ) );

		$this->assertSame( array( $page ), $this->settings()->all()['ignored_ids'] );

		$calls = 0;
		add_filter(
			'url_to_postid',
			static function ( $id ) use ( &$calls ) {
				++$calls;
				return $id;
			}
		);
		$this->settings()->all();
		$this->assertSame( 0, $calls );

		// Un cliente no puede fijar los IDs a mano.
		$this->settings()->update( array( 'ignored_ids' => array( 999 ) ) );
		$this->assertSame( array( $page ), $this->settings()->all()['ignored_ids'] );
	}

	public function test_the_engine_gives_the_same_with_default_settings_as_without_them(): void {
		$posts = array();
		foreach ( array( 'aerotermia', 'bomba de calor', 'suelo radiante' ) as $topic ) {
			$posts[] = self::factory()->post->create(
				array(
					'post_title'   => ucfirst( $topic ),
					'post_content' => "<!-- wp:paragraph -->\n<p>Una guía larga sobre {$topic} y otros temas de climatización para viviendas de tamaño medio con buen aislamiento.</p>\n<!-- /wp:paragraph -->",
				)
			);
		}
		$jobs = Plugin::container()->get( Jobs::class );
		$jobs->run_to_completion( $jobs->start_index( 0, false, true, true, true )['id'] );

		$service = Plugin::container()->get( \MagicLinking\Index\Suggestions::class );
		$bare    = new \MagicLinking\Engine\Suggester(
			Plugin::container()->get( \MagicLinking\Index\TableRepository::class ),
			Plugin::container()->get( \MagicLinking\Index\TableDocuments::class ),
			new \MagicLinking\Engine\Indexer(),
			new \MagicLinking\Engine\PhraseFinder(),
			new \MagicLinking\Engine\Scorer(),
			new \MagicLinking\Engine\Retriever( Plugin::container()->get( \MagicLinking\Index\TableRepository::class ) ),
			array( 'now' => 1790000000 )
		);
		foreach ( $posts as $id ) {
			$documents = Plugin::container()->get( \MagicLinking\Index\TableDocuments::class );
			$expected  = $bare->outgoing( $documents->from_post( get_post( $id ) ) );
			$actual    = $service->outgoing( $id, array( 'now' => 1790000000 ) );
			$this->assertEquals( $expected, $actual, "Entrada $id" );
		}
	}
}
