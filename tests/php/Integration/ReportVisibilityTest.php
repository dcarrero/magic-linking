<?php
/**
 * Control de acceso del informe (1.0.1): un colaborador no ve entradas excluidas ni enlaces rotos de entradas que
 * no puede leer; un editor lo ve todo; el total, el contador y el CSV cuadran con la lista.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Plugin;
use MagicLinking\Index\Exclusions;
use MagicLinking\Jobs\Jobs;
use MagicLinking\Report\ExportHandler;
use WP_REST_Request;
use WP_REST_Response;

final class ReportVisibilityTest extends GraphTestCase {

	private const BASE = '/magic-linking/v1';

	private int $editor;
	private int $contributor;
	private int $admin;

	/**
	 * @var array<string, int>
	 */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();

		$this->admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$make = function ( string $slug, string $status, int $author ): int {
			$id = self::factory()->post->create(
				array(
					'post_name'    => $slug,
					'post_title'   => ucfirst( $slug ),
					'post_content' => 'Texto de ' . $slug . '.',
					'post_status'  => $status,
					'post_author'  => $author,
				)
			);
			update_post_meta( $id, Exclusions::KEY, '1' );

			return $id;
		};

		$this->ids = array(
			'published' => $make( 'publicada', 'publish', $this->admin ),
			'draft'     => $make( 'borrador-ajeno', 'draft', $this->admin ),
			'pending'   => $make( 'pendiente-ajena', 'pending', $this->admin ),
			'private'   => $make( 'privada-ajena', 'private', $this->admin ),
			'mine'      => $make( 'mi-borrador', 'draft', $this->contributor ),
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function request( string $route, array $params, int $user ): WP_REST_Response {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( 'GET', self::BASE . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function csv( string $kind, array $args, int $user ): string {
		wp_set_current_user( $user );
		$stream = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Memoria.
		Plugin::container()->get( ExportHandler::class )->write( $stream, $kind, $args );
		rewind( $stream );

		return (string) stream_get_contents( $stream );
	}

	public function test_a_contributor_only_sees_published_and_own_excluded_entries(): void {
		$data = $this->request( '/report', array( 'filter' => 'excluded' ), $this->contributor )->get_data();

		$this->assertEqualsCanonicalizing( array( $this->ids['published'], $this->ids['mine'] ), array_column( $data['items'], 'id' ) );
		$this->assertSame( 2, $data['total'] );
		// El contador de la pestaña, pedido desde otro filtro, cuenta lo mismo.
		$this->assertSame( 2, $this->request( '/report', array(), $this->contributor )->get_data()['summary']['excluded'] );
		$this->assertSame( 5, $this->request( '/report', array(), $this->editor )->get_data()['summary']['excluded'] );
	}

	public function test_an_editor_sees_every_excluded_entry_and_the_total_matches(): void {
		$data = $this->request( '/report', array( 'filter' => 'excluded' ), $this->editor )->get_data();

		$this->assertEqualsCanonicalizing( array_values( $this->ids ), array_column( $data['items'], 'id' ) );
		$this->assertSame( 5, $data['total'] );
	}

	public function test_pagination_and_total_agree_for_a_contributor(): void {
		$page = $this->request(
			'/report',
			array(
				'filter'   => 'excluded',
				'per_page' => 1,
				'page'     => 2,
			),
			$this->contributor
		)->get_data();

		$this->assertSame( 2, $page['total'] );
		$this->assertCount( 1, $page['items'] );
	}

	public function test_the_csv_of_excluded_entries_leaves_out_what_the_user_cannot_see(): void {
		$csv = $this->csv( 'report', array( 'filter' => 'excluded' ), $this->contributor );

		$this->assertStringContainsString( 'Mi-borrador', $csv );
		$this->assertStringContainsString( 'Publicada', $csv );
		$this->assertStringNotContainsString( 'Borrador-ajeno', $csv );
		$this->assertStringNotContainsString( 'Pendiente-ajena', $csv );
		$this->assertStringNotContainsString( 'Privada-ajena', $csv );

		$all = $this->csv( 'report', array( 'filter' => 'excluded' ), $this->editor );
		$this->assertStringContainsString( 'Privada-ajena', $all );
	}

	public function test_broken_links_of_an_unpublished_foreign_source_are_not_listed(): void {
		global $wpdb;
		delete_post_meta( $this->ids['draft'], Exclusions::KEY );
		delete_post_meta( $this->ids['mine'], Exclusions::KEY );
		$visible = self::factory()->post->create(
			array(
				'post_name'    => 'visible',
				'post_title'   => 'Visible',
				'post_status'  => 'publish',
				'post_content' => '<a href="' . home_url( '/no-existe-1/' ) . '">uno</a>',
			)
		);
		$hidden  = self::factory()->post->create(
			array(
				'post_name'    => 'oculta',
				'post_title'   => 'Oculta',
				'post_status'  => 'publish',
				'post_author'  => $this->admin,
				'post_content' => '<a href="' . home_url( '/no-existe-2/' ) . '">dos</a>',
			)
		);
		$jobs    = Plugin::container()->get( Jobs::class );
		$jobs->run_to_completion( $jobs->start_index( 0, false )['id'] );
		// El índice solo guarda publicadas: se simula una fila que quedó de cuando lo estaba.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $hidden ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $hidden );

		$seen = array_column( $this->request( '/broken', array(), $this->contributor )->get_data()['items'], 'source_id' );
		$this->assertSame( array( $visible ), $seen );
		$this->assertSame( 1, $this->request( '/broken', array(), $this->contributor )->get_data()['total'] );
		$this->assertStringNotContainsString( 'no-existe-2', $this->csv( 'broken', array(), $this->contributor ) );

		$this->assertEqualsCanonicalizing( array( $visible, $hidden ), array_column( $this->request( '/broken', array(), $this->editor )->get_data()['items'], 'source_id' ) );
	}
}
