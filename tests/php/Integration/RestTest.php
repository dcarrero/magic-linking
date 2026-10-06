<?php
/**
 * API REST del informe, los enlaces rotos, los procesos y los ajustes.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Plugin;
use MagicLinking\Graph\BrokenReason;
use MagicLinking\Graph\GraphIndexer;
use MagicLinking\Jobs\Jobs;
use MagicLinking\Report\ExportHandler;
use WP_REST_Request;
use WP_REST_Response;

final class RestTest extends GraphTestCase {

	private const BASE = '/magic-linking/v1';

	private int $admin;
	private int $editor;
	private int $subscriber;

	public function set_up(): void {
		parent::set_up();

		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

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
		wp_set_current_user( $user ?? 0 );
		$request = new WP_REST_Request( $method, self::BASE . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function post( string $slug, string $content = '', array $extra = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_name'    => $slug,
					'post_title'   => ucfirst( $slug ),
					'post_content' => $content,
					'post_status'  => 'publish',
				),
				$extra
			)
		);
	}

	private function link( string $slug ): string {
		return '<a href="' . home_url( "/{$slug}/" ) . '">' . $slug . '</a>';
	}

	/**
	 * Sitio de ejemplo.
	 *
	 * Enlaces: a → poca, popular, no-existe (roto); b → popular; c → popular, a, b.
	 * Entrantes: huerfana 0, c 0, poca 1, a 1, b 1, popular 3.
	 *
	 * @return array<string, int>
	 */
	private function site(): array {
		$ids            = array();
		$ids['orphan']  = $this->post( 'huerfana', 'Nadie me enlaza.' );
		$ids['low']     = $this->post( 'poca', 'Solo una me enlaza.' );
		$ids['popular'] = $this->post( 'popular', 'Muchos me enlazan.' );
		$ids['a']       = $this->post( 'a', $this->link( 'poca' ) . $this->link( 'popular' ) . ' texto de relleno. ' . $this->link( 'no-existe' ) );
		$ids['b']       = $this->post( 'b', $this->link( 'popular' ) . str_repeat( 'palabra ', 300 ) );
		$ids['c']       = $this->post( 'c', $this->link( 'popular' ) . $this->link( 'a' ) . $this->link( 'b' ) );
		$jobs           = Plugin::container()->get( Jobs::class );
		$jobs->run_to_completion( $jobs->start_index( 0, false )['id'] );

		return $ids;
	}

	public function test_permissions(): void {
		foreach ( array( '/report', '/broken', '/status' ) as $route ) {
			$this->assertSame( 401, $this->request( 'GET', $route )->get_status(), "$route sin sesión" );
			$this->assertSame( 403, $this->request( 'GET', $route, array(), $this->subscriber )->get_status(), "$route suscriptor" );
			$this->assertSame( 200, $this->request( 'GET', $route, array(), $this->editor )->get_status(), "$route editor" );
		}

		$admin_only = array(
			array( 'GET', '/jobs' ),
			array( 'POST', '/index' ),
			array( 'POST', '/jobs/1/pause' ),
			array( 'GET', '/settings' ),
			array( 'POST', '/settings' ),
		);
		foreach ( $admin_only as list( $method, $route ) ) {
			$this->assertSame( 401, $this->request( $method, $route )->get_status(), "$route sin sesión" );
			$this->assertSame( 403, $this->request( $method, $route, array(), $this->editor )->get_status(), "$route editor" );
		}
		$this->assertSame( 200, $this->request( 'GET', '/settings', array(), $this->admin )->get_status() );
	}

	public function test_report_lists_status_counts_and_summary(): void {
		$ids  = $this->site();
		$data = $this->request( 'GET', '/report', array( 'per_page' => 100 ), $this->editor )->get_data();

		$this->assertSame( 6, $data['total'] );
		$this->assertSame( 1, $data['total_pages'] );

		$by_id = array();
		foreach ( $data['items'] as $item ) {
			$by_id[ $item['id'] ] = $item;
		}
		$this->assertSame( 'orphan', $by_id[ $ids['orphan'] ]['status'] );
		$this->assertSame( 'orphan', $by_id[ $ids['c'] ]['status'] );
		$this->assertSame( 'low', $by_id[ $ids['low'] ]['status'] );
		$this->assertSame( 'low', $by_id[ $ids['a'] ]['status'] );
		$this->assertSame( 'ok', $by_id[ $ids['popular'] ]['status'] );
		$this->assertSame( 'Popular', $by_id[ $ids['popular'] ]['title'] );
		$this->assertSame( 3, $by_id[ $ids['popular'] ]['inbound'] );
		$this->assertSame( 1, $by_id[ $ids['a'] ]['broken'] );
		$this->assertSame( 'Post', $by_id[ $ids['a'] ]['type_label'] );
		$this->assertStringContainsString( 'post.php?post=' . $ids['a'], $by_id[ $ids['a'] ]['edit_url'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT/', $by_id[ $ids['a'] ]['indexed_at'] );

		$summary = $data['summary'];
		$this->assertSame( 6, $summary['analyzed'] );
		$this->assertSame( 2, $summary['orphans'], 'Huérfana y c (nadie enlaza a c).' );
		$this->assertSame( 3, $summary['low'] );
		$this->assertSame( 2, $summary['over'], 'a y c: tres enlaces en muy pocas palabras.' );
		$this->assertSame( 1, $summary['broken_posts'] );
		$this->assertSame( 1, $summary['broken_links'] );
		$this->assertSame( 7, $summary['internal_links'] );
		$this->assertSame(
			array(
				array(
					'name'  => 'post',
					'label' => 'Posts',
					'count' => 6,
				),
			),
			$summary['types']
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( array( 'en' ), $summary['langs'] );
	}

	public function test_report_filters_sorts_searches_and_paginates(): void {
		$ids = $this->site();
		$get = fn( array $p ) => $this->request( 'GET', '/report', $p, $this->editor );

		$orphans = $get( array( 'filter' => 'orphans' ) )->get_data();
		$this->assertEqualsCanonicalizing( array( $ids['orphan'], $ids['c'] ), array_column( $orphans['items'], 'id' ) );

		$this->assertEqualsCanonicalizing( array( $ids['low'], $ids['a'], $ids['b'] ), array_column( $get( array( 'filter' => 'low' ) )->get_data()['items'], 'id' ) );
		$this->assertSame( array( $ids['a'] ), array_column( $get( array( 'filter' => 'broken' ) )->get_data()['items'], 'id' ) );
		$this->assertEqualsCanonicalizing( array( $ids['a'], $ids['c'] ), array_column( $get( array( 'filter' => 'over' ) )->get_data()['items'], 'id' ) );

		$this->assertSame( 1, $get( array( 'search' => 'popu' ) )->get_data()['total'] );
		$this->assertSame( 0, $get( array( 'search' => '100%' ) )->get_data()['total'], 'El % se busca tal cual.' );

		$sorted = $get(
			array(
				'orderby' => 'inbound',
				'order'   => 'desc',
			)
		)->get_data(); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( $ids['popular'], $sorted['items'][0]['id'] );
		$titles = array_column(
			$get(
				array(
					'orderby' => 'title',
					'order'   => 'asc',
				)
			)->get_data()['items'],
			'title'
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( array( 'A', 'B', 'C', 'Huerfana', 'Poca', 'Popular' ), $titles );

		$page2 = $get(
			array(
				'per_page' => 4,
				'page'     => 2,
				'orderby'  => 'title',
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( array( 'Poca', 'Popular' ), array_column( $page2->get_data()['items'], 'title' ) );
		$this->assertSame( '6', $page2->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $page2->get_headers()['X-WP-TotalPages'] );
	}

	public function test_report_rejects_invalid_parameters(): void {
		$this->assertSame( 400, $this->request( 'GET', '/report', array( 'filter' => 'nada' ), $this->editor )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/report', array( 'orderby' => 'post_title; DROP TABLE x' ), $this->editor )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/report', array( 'per_page' => 1000 ), $this->editor )->get_status() );
	}

	public function test_thresholds_come_from_the_settings(): void {
		$ids = $this->site();

		$this->request(
			'POST',
			'/settings',
			array(
				'low_inbound_threshold' => 4,
				'words_per_link'        => 20,
			),
			$this->admin
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$low = array_column( $this->request( 'GET', '/report', array( 'filter' => 'low' ), $this->editor )->get_data()['items'], 'id' );

		$this->assertEqualsCanonicalizing( array( $ids['low'], $ids['popular'], $ids['a'], $ids['b'] ), $low, 'Con umbral 4 también popular (3) es poco enlazada.' );
	}

	public function test_edit_link_is_only_given_to_those_who_can_edit_the_post(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$mine   = $this->post( 'mia', '', array( 'post_author' => $author ) );
		$other  = $this->post( 'ajena', '', array( 'post_author' => $this->admin ) );
		( Plugin::container()->get( Jobs::class ) )->run_to_completion( ( Plugin::container()->get( Jobs::class ) )->start_index( 0, false )['id'] );

		$items = array();
		foreach ( $this->request( 'GET', '/report', array(), $author )->get_data()['items'] as $item ) {
			$items[ $item['id'] ] = $item;
		}

		$this->assertNotSame( '', $items[ $mine ]['edit_url'] );
		$this->assertSame( '', $items[ $other ]['edit_url'] );
	}

	public function test_broken_links_list_with_reasons_and_filters(): void {
		$trashed = $this->post( 'papelera' );
		wp_trash_post( $trashed );
		$a = $this->post( 'a', $this->link( 'papelera' ) . $this->link( 'no-existe' ) );
		$b = $this->post( 'b', '<a href="' . home_url( '/tambien-no/' ) . '">otra</a>' );
		( Plugin::container()->get( Jobs::class ) )->run_to_completion( ( Plugin::container()->get( Jobs::class ) )->start_index( 0, false )['id'] );

		$data = $this->request( 'GET', '/broken', array(), $this->editor )->get_data();
		$this->assertSame( 3, $data['total'] );
		$first = $data['items'][0];
		$this->assertSame( $a, $first['source_id'] );
		$this->assertSame( 'A', $first['source_title'] );
		$this->assertSame( home_url( '/papelera/' ), $first['url'] );
		$this->assertSame( 'papelera', $first['anchor'] );
		$this->assertSame( BrokenReason::TRASHED, $first['reason_code'] );
		$this->assertSame( 'The destination entry is in the trash.', $first['reason'] );
		$this->assertStringContainsString( 'post.php?post=' . $a, $first['edit_url'] );

		$only_b = $this->request( 'GET', '/broken', array( 'post_id' => $b ), $this->editor )->get_data();
		$this->assertSame( 1, $only_b['total'] );
		$this->assertSame( BrokenReason::NOT_FOUND, $only_b['items'][0]['reason_code'] );

		$this->assertSame( 1, $this->request( 'GET', '/broken', array( 'search' => 'tambien' ), $this->editor )->get_data()['total'] );
	}

	public function test_status_index_and_job_control(): void {
		$this->post( 'alfa', 'x' );

		$status = $this->request( 'GET', '/status', array(), $this->editor )->get_data();
		$this->assertSame( 1, $status['eligible'] );
		$this->assertNull( $status['job'] );

		$started = $this->request( 'POST', '/index', array(), $this->admin );
		$this->assertSame( 202, $started->get_status() );
		$job = $started->get_data()['job'];
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( 1, $job['total'] );

		$again = $this->request( 'POST', '/index', array(), $this->admin )->get_data()['job'];
		$this->assertSame( $job['id'], $again['id'], 'Solo un proceso activo.' );

		$this->assertSame( 'paused', $this->request( 'POST', "/jobs/{$job['id']}/pause", array(), $this->admin )->get_data()['job']['status'] );
		$this->assertSame( 409, $this->request( 'POST', "/jobs/{$job['id']}/pause", array(), $this->admin )->get_status() );
		$this->assertSame( 'queued', $this->request( 'POST', "/jobs/{$job['id']}/resume", array(), $this->admin )->get_data()['job']['status'] );
		$this->assertSame( 'cancelled', $this->request( 'POST', "/jobs/{$job['id']}/cancel", array(), $this->admin )->get_data()['job']['status'] );
		$this->assertSame( 404, $this->request( 'POST', '/jobs/99999/cancel', array(), $this->admin )->get_status() );

		$jobs = $this->request( 'GET', '/jobs', array(), $this->admin )->get_data();
		$this->assertNull( $jobs['active'] );
		$this->assertSame( 'cancelled', $jobs['latest']['status'] );
	}

	public function test_status_reports_the_last_completed_analysis_and_a_forced_reanalysis_can_start(): void {
		$this->post( 'alfa', 'x' );
		$jobs = Plugin::container()->get( Jobs::class );

		$this->assertNull( $this->request( 'GET', '/status', array(), $this->editor )->get_data()['last_done'] );

		$jobs->run_to_completion( $jobs->start_index( 0, false )['id'] );
		$done = $this->request( 'GET', '/status', array(), $this->editor )->get_data()['last_done'];
		$this->assertSame( 'done', $done['status'] );
		$this->assertSame( 1, $done['done'] );

		// Analizar cambios: un proceso nuevo, NO forzado por defecto, que solo lanza un administrador.
		$this->assertSame( 403, $this->request( 'POST', '/index', array(), $this->editor )->get_status() );
		$job = $this->request( 'POST', '/index', array(), $this->admin )->get_data()['job'];
		$this->assertNotSame( $done['id'], $job['id'] );
		$this->assertFalse( (bool) $jobs->repository()->get( $job['id'] )['params']['force'] );
		$this->assertFalse( $job['force'] );

		// Con force=true (volver a analizar todo desde cero).
		$jobs->cancel( $job['id'] );
		$forced = $this->request( 'POST', '/index', array( 'force' => true ), $this->admin )->get_data()['job'];
		$this->assertTrue( (bool) $jobs->repository()->get( $forced['id'] )['params']['force'] );
		$this->assertTrue( $forced['force'] );
		$this->assertSame( $done['id'], $this->request( 'GET', '/status', array(), $this->admin )->get_data()['last_done']['id'] );
	}

	public function test_settings_are_read_and_saved_and_changing_types_starts_an_index(): void {
		$get = $this->request( 'GET', '/settings', array(), $this->admin )->get_data();
		$this->assertSame( array( 'post', 'page' ), $get['settings']['post_types'] );
		$this->assertContains( 'post', array_column( $get['post_types'], 'name' ) );
		$this->assertNotContains( 'attachment', array_column( $get['post_types'], 'name' ) );

		$same = $this->request( 'POST', '/settings', array( 'low_inbound_threshold' => 3 ), $this->admin )->get_data();
		$this->assertSame( 3, $same['settings']['low_inbound_threshold'] );
		$this->assertNull( $same['job'] );

		$changed = $this->request(
			'POST',
			'/settings',
			array(
				'post_types'               => array( 'post' ),
				'delete_data_on_uninstall' => true,
			),
			$this->admin
		)->get_data(); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( array( 'post' ), $changed['settings']['post_types'] );
		$this->assertTrue( $changed['settings']['delete_data_on_uninstall'] );
		$this->assertSame( 'queued', $changed['job']['status'] );

		$this->assertSame( 400, $this->request( 'POST', '/settings', array( 'words_per_link' => 5 ), $this->admin )->get_status() );
	}

	public function test_csv_export_of_the_current_view(): void {
		$ids = $this->site();
		$this->post( 'formula', 'x', array( 'post_title' => '=1+1' ) );
		( Plugin::container()->get( Jobs::class ) )->run_to_completion( ( Plugin::container()->get( Jobs::class ) )->start_index( 0, false )['id'] );
		wp_set_current_user( $this->editor );

		$handler = Plugin::container()->get( ExportHandler::class );
		$stream  = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Memoria.
		$handler->write(
			$stream,
			'report',
			array(
				'filter'  => 'orphans',
				'orderby' => 'title',
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		rewind( $stream );
		$csv = (string) stream_get_contents( $stream );

		$this->assertStringStartsWith( "\xEF\xBB\xBFid,title,type,inbound,outbound,external,broken,status,words,indexed_at,url\n", $csv );
		$this->assertStringContainsString( "'=1+1", $csv );
		$this->assertStringContainsString( 'Huerfana', $csv );
		$this->assertStringNotContainsString( 'Popular', $csv, 'Solo la vista filtrada.' );
		unset( $ids );

		$stream = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Memoria.
		$handler->write( $stream, 'broken', array() );
		rewind( $stream );
		$broken = (string) stream_get_contents( $stream );
		$this->assertStringContainsString( 'source_id,source_title,url,anchor,reason,edit_url', $broken );
		$this->assertStringContainsString( 'No entry exists with this URL.', $broken );
	}

	public function test_language_is_only_shown_on_multilingual_sites(): void {
		$this->site();
		$handler = Plugin::container()->get( ExportHandler::class );
		$csv     = static function () use ( $handler ): string {
			$stream = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Memoria.
			$handler->write( $stream, 'report', array() );
			rewind( $stream );

			return (string) stream_get_contents( $stream );
		};

		// Un solo idioma y sin WPML ni Polylang: sin idioma.
		$this->assertFalse( $this->request( 'GET', '/report', array(), $this->editor )->get_data()['summary']['multilingual'] );
		$this->assertStringContainsString( 'id,title,type,inbound,', $csv() );

		// Un plugin multilingüe activo: con idioma, aunque el índice tenga uno solo.
		add_filter( 'magiclinking_is_multilingual', '__return_true' );
		$this->assertTrue( $this->request( 'GET', '/report', array(), $this->editor )->get_data()['summary']['multilingual'] );
		$this->assertStringContainsString( 'id,title,type,language,inbound,', $csv() );
		remove_filter( 'magiclinking_is_multilingual', '__return_true' );

		// Sin plugin, pero con dos idiomas distintos en el índice.
		global $wpdb;
		$docs = \MagicLinking\Core\Schema::table( $wpdb->prefix, 'docs' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET lang = %s LIMIT 1', $docs, 'fr' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertTrue( $this->request( 'GET', '/report', array(), $this->editor )->get_data()['summary']['multilingual'] );
		$this->assertStringContainsString( 'id,title,type,language,inbound,', $csv() );
	}

	public function test_export_url_carries_a_nonce_and_needs_permission(): void {
		$url = ExportHandler::url();
		$this->assertStringContainsString( 'admin-post.php?action=magiclinking_export', $url );
		$this->assertStringContainsString( '&_wpnonce=', $url );
		$this->assertStringNotContainsString( '&amp;', $url );
		$this->assertNotFalse( has_action( 'admin_post_magiclinking_export' ) );
	}
}
