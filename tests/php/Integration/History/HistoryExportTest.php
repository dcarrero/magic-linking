<?php
/**
 * Exportación del Historial a CSV (F1-38).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\History;

use MagicLinking\Core\Plugin;
use MagicLinking\History\AiOrigin;
use MagicLinking\History\BatchId;
use MagicLinking\History\ChangeRepository;
use MagicLinking\History\Undo;
use MagicLinking\Report\CsvExporter;
use MagicLinking\Report\ExportHandler;
use WP_REST_Request;

final class HistoryExportTest extends HistoryTestCase {

	private int $author = 0;

	private int $subscriber = 0;

	public function set_up(): void {
		parent::set_up();

		$this->author     = self::factory()->user->create( array( 'role' => 'author' ) );
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

	/**
	 * Exporta como un usuario y devuelve las filas ya separadas (sin el BOM).
	 *
	 * @param array    $query Filtros.
	 * @param int|null $user  Usuario; null = el administrador.
	 *
	 * @return list<array<string, string>> Filas por nombre de columna.
	 */
	private function export( array $query = array(), ?int $user = null ): array {
		wp_set_current_user( $user ?? $this->admin );
		$stream = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Memoria.
		Plugin::container()->get( ExportHandler::class )->write( $stream, 'history', $query );
		rewind( $stream );
		$raw = (string) stream_get_contents( $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Memoria.

		$this->assertStringStartsWith( "\xEF\xBB\xBF", $raw, 'UTF-8 con BOM, como el CSV del Informe.' );
		$lines = array_values( array_filter( array_map( 'str_getcsv', explode( "\n", substr( $raw, 3 ) ) ), static fn( array $line ): bool => array( null ) !== $line ) );
		$head  = array_shift( $lines );
		$this->assertSame( CsvExporter::HISTORY_COLUMNS, $head );

		return array_map( static fn( array $line ): array => array_combine( $head, $line ), $lines );
	}

	/**
	 * Un enlace puesto por el servidor, con o sin IA.
	 *
	 * @param AiOrigin|null $ai   Marca de la IA.
	 * @param int|null      $post Entrada.
	 *
	 * @return array{post: int, change: int, batch: string}
	 */
	private function put( ?AiOrigin $ai = null, ?int $post = null ): array {
		$post   = $post ?? $this->post();
		$result = Plugin::container()->get( \MagicLinking\Content\Inserter::class )->insert(
			new \MagicLinking\Content\InsertRequest( $post, (string) get_permalink( $this->target ), 'aire acondicionado', 'Compra ', ' ya.', null, array(), null, false, false, false, $ai )
		);

		return array(
			'post'   => $post,
			'change' => $result->change_id,
			'batch'  => $result->batch_id,
		);
	}

	public function test_one_row_per_change_with_the_documented_columns(): void {
		$ai = $this->put( new AiOrigin( true, 'openai/gpt-4.1-mini' ) );

		$rows = $this->export();

		$this->assertCount( 1, $rows );
		$row = $rows[0];
		$this->assertSame( (string) $ai['change'], $row['id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $row['date'] );
		$this->assertSame( get_userdata( $this->admin )->user_login, $row['user'] );
		$this->assertSame( 'insert', $row['action'] );
		$this->assertSame( 'active', $row['state'] );
		$this->assertSame( (string) $ai['post'], $row['post_id'] );
		$this->assertSame( get_the_title( $ai['post'] ), $row['post_title'] );
		$this->assertSame( (string) get_permalink( $this->target ), $row['target_url'] );
		$this->assertSame( 'aire acondicionado', $row['anchor'] );
		$this->assertSame( "Another entry's panel", $row['origin'], 'La etiqueta de la pantalla, sin traducir.' );
		$this->assertSame( $ai['batch'], $row['batch_id'] );
		$this->assertSame( '1', $row['ai_recommended'] );
		$this->assertSame( '1', $row['ai_anchor'] );
		$this->assertSame( 'openai/gpt-4.1-mini', $row['ai_model'] );
	}

	public function test_an_undone_change_is_listed_once_as_undone(): void {
		$linked = $this->put();
		Plugin::container()->get( Undo::class )->revert( $linked['change'] );

		$rows = $this->export();

		$this->assertCount( 1, $rows, 'Un tramo cuenta una vez; la traza de deshacer no es una fila.' );
		$this->assertSame( 'undone', $rows[0]['state'] );
		$this->assertSame( '0', $rows[0]['ai_recommended'] );
		$this->assertSame( '', $rows[0]['ai_model'] );
	}

	public function test_the_filters_of_the_screen_apply(): void {
		$marked = $this->put( new AiOrigin( false, 'm' ) );
		$plain  = $this->put();
		$gone   = $this->put();
		Plugin::container()->get( Undo::class )->revert( $gone['change'] );
		$editor = $this->post( array( 'post_title' => 'Entrada del editor' ) );
		Plugin::container()->get( ChangeRepository::class )->record( BatchId::generate(), $editor, 'insert', '0', 'x', '<a href="' . get_permalink( $this->target ) . '">x</a>', sha1( 'x' ), $this->admin, ChangeRepository::ORIGIN_EDITOR );

		$ids = fn( array $query ): array => array_map( 'intval', array_column( $this->export( $query ), 'post_id' ) );

		$this->assertEqualsCanonicalizing( array( $marked['post'], $plain['post'], $gone['post'], $editor ), $ids( array() ) );
		$this->assertSame( array( $marked['post'] ), $ids( array( 'ai' => '1' ) ) );
		$this->assertSame( array( $editor ), $ids( array( 'origin' => 'editor' ) ) );
		$this->assertSame(
			array(),
			$ids(
				array(
					'origin' => 'editor',
					'ai'     => '1',
				)
			)
		);
		$this->assertSame( array( $gone['post'] ), $ids( array( 'status' => 'undone' ) ) );
		$this->assertEqualsCanonicalizing( array( $marked['post'], $plain['post'], $editor ), $ids( array( 'status' => 'active' ) ) );
		$this->assertSame( array( $editor ), $ids( array( 'search' => 'Entrada del editor' ) ) );
		$this->assertCount(
			4,
			$ids(
				array(
					'status' => 'bogus',
					'origin' => 'bogus',
				)
			),
			'Un valor desconocido no filtra.'
		);
	}

	public function test_there_is_no_row_limit_and_it_is_read_page_by_page(): void {
		$batch = BatchId::generate();
		$post  = $this->post();
		$repo  = Plugin::container()->get( ChangeRepository::class );
		$count = \MagicLinking\History\Reader::EXPORT_PAGE + 25;
		for ( $i = 0; $i < $count; $i++ ) {
			$repo->record( $batch, $post, 'insert', '0', "x{$i}", '<a href="' . get_permalink( $this->target ) . '">x</a>' . $i, sha1( (string) $i ), $this->admin );
		}

		$this->assertCount( $count, $this->export() );
	}

	public function test_cells_that_look_like_formulas_are_neutralized(): void {
		$post = $this->post( array( 'post_title' => '=1+1 título' ) );
		$user = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'at_evil',
			)
		);
		$href = (string) get_permalink( $this->target );
		foreach ( array( '=HYPERLINK("http://evil")', '+cmd', '-cmd', '@SUM(A1)' ) as $anchor ) {
			Plugin::container()->get( ChangeRepository::class )->record( BatchId::generate(), $post, 'insert', '0', 'antes ' . $anchor, 'antes <a href="' . $href . '">' . $anchor . '</a>', sha1( $anchor ), $user );
		}

		$rows = $this->export();

		$this->assertCount( 4, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( "'", substr( $row['anchor'], 0, 1 ), 'Ancla: ' . wp_json_encode( $rows ) );
			$this->assertSame( "'=1+1 título", $row['post_title'] );
		}
		$this->assertContains( "'=HYPERLINK(\"http://evil\")", array_column( $rows, 'anchor' ) );
		$this->assertSame( 'at_evil', $rows[0]['user'] );
	}

	public function test_an_author_only_exports_the_entries_they_can_edit(): void {
		$own = $this->post( array( 'post_author' => $this->author ) );
		$this->put( null, $own );
		$other = $this->put();

		$rows = $this->export( array(), $this->author );

		$this->assertSame( array( (string) $own ), array_column( $rows, 'post_id' ) );
		$this->assertNotContains( (string) $other['post'], array_column( $rows, 'post_id' ) );
	}

	public function test_the_download_needs_permission_and_a_nonce(): void {
		$this->put();
		$handler = Plugin::container()->get( ExportHandler::class );
		$_GET    = array(
			'dataset' => 'history',
			'ai'      => '1',
		);

		wp_set_current_user( $this->subscriber );
		try {
			$handler->handle();
			$this->fail( 'Un suscriptor no puede exportar.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		wp_set_current_user( $this->admin );
		try {
			$handler->handle();
			$this->fail( 'Sin nonce no se exporta.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'expired', $e->getMessage() );
		}
		$_GET = array();
	}

	public function test_the_history_screen_gets_the_export_url(): void {
		$this->assertStringContainsString( 'action=magiclinking_export', ExportHandler::url() );
	}
}
