<?php
/**
 * Escritura de CSV.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Report;

use MagicLinking\Report\CsvExporter;
use PHPUnit\Framework\TestCase;

final class CsvExporterTest extends TestCase {

	private function render( array $header, array $rows, bool $bom = false ): string {
		$stream = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Memoria, no disco.
		CsvExporter::write( $stream, $header, $rows, $bom );
		rewind( $stream );

		return (string) stream_get_contents( $stream );
	}

	public function test_writes_header_and_rows_with_quoting(): void {
		$csv = $this->render( array( 'id', 'title' ), array( array( 1, 'Hola, "mundo"' ), array( 2, "Dos\nlíneas" ) ) );

		$this->assertSame( "id,title\n1,\"Hola, \"\"mundo\"\"\"\n2,\"Dos\nlíneas\"\n", $csv );
	}

	public function test_optional_utf8_bom(): void {
		$this->assertSame( "\xEF\xBB\xBF", substr( $this->render( array( 'a' ), array(), true ), 0, 3 ) );
		$this->assertNotSame( "\xEF\xBB\xBF", substr( $this->render( array( 'a' ), array() ), 0, 3 ) );
	}

	/**
	 * @dataProvider formulas
	 */
	public function test_cells_that_look_like_formulas_are_neutralised( string $value ): void {
		$this->assertSame( "'" . $value, CsvExporter::cell( $value ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function formulas(): array {
		return array(
			'igual'     => array( '=HYPERLINK("http://x")' ),
			'más'       => array( '+1+1' ),
			'menos'     => array( '-2+3' ),
			'arroba'    => array( '@SUM(A1)' ),
			'tabulador' => array( "\t=1" ),
			'retorno'   => array( "\r=1" ),
		);
	}

	public function test_normal_cells_and_numbers_are_left_alone(): void {
		$this->assertSame( 'Guía de aerotermia', CsvExporter::cell( 'Guía de aerotermia' ) );
		$this->assertSame( 0, CsvExporter::cell( 0 ) );
		$this->assertSame( '', CsvExporter::cell( '' ) );
		$this->assertSame( 'https://a.com/?a=-1', CsvExporter::cell( 'https://a.com/?a=-1' ) );
	}
}
