<?php
/**
 * Extractor de texto: encabezados, párrafos, bloques excluidos y enlaces.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\DomExtractor;
use PHPUnit\Framework\TestCase;

final class DomExtractorTest extends TestCase {

	public function test_separates_headings_from_paragraphs(): void {
		$result = ( new DomExtractor() )->extract( "<!-- wp:heading -->\n<h2>Centros de datos</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Primer párrafo.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Segundo párrafo.</p>\n<!-- /wp:paragraph -->" );

		$this->assertSame( array( 'Centros de datos' ), $result->headings );
		$this->assertSame( array( 'Primer párrafo.', 'Segundo párrafo.' ), array_map( static fn( $p ): string => $p->text, $result->paragraphs ) );
	}

	public function test_skips_excluded_blocks_and_shortcodes(): void {
		$content = "<!-- wp:code -->\n<pre><code>echo 1;</code></pre>\n<!-- /wp:code -->\n\n<!-- wp:paragraph -->\n<p>Texto útil [gallery ids=\"1,2\"] del artículo.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:table -->\n<figure><table><tr><td>celda</td></tr></table></figure>\n<!-- /wp:table -->";
		$result  = ( new DomExtractor() )->extract( $content );

		$this->assertCount( 1, $result->paragraphs );
		$this->assertStringContainsString( 'Texto útil', $result->paragraphs[0]->text );
		$this->assertStringNotContainsString( 'celda', $result->paragraphs[0]->text );
		$this->assertStringNotContainsString( 'echo', $result->paragraphs[0]->text );
	}

	public function test_marks_existing_links(): void {
		$result = ( new DomExtractor() )->extract( '<p>Más sobre <a href="/otra/">centros de datos</a> aquí.</p>' );

		$this->assertCount( 1, $result->paragraphs );
		$paragraph = $result->paragraphs[0];
		$this->assertSame( 'Más sobre centros de datos aquí.', $paragraph->text );
		$this->assertTrue( $paragraph->has_link_in( 10, 25 ) );
		$this->assertFalse( $paragraph->has_link_in( 0, 9 ) );
	}

	public function test_empty_content(): void {
		$result = ( new DomExtractor() )->extract( '   ' );

		$this->assertSame( array(), $result->headings );
		$this->assertSame( array(), $result->paragraphs );
	}

	private const RICH = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Título con aerotermia</h2>\n<!-- /wp:heading -->\n\n<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>Dentro de la cita.</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->\n\n<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><thead><tr><th>Cabecera</th></tr></thead><tbody><tr><td>Celda uno</td><td>Celda dos</td></tr></tbody></table><figcaption class=\"wp-element-caption\">Pie de tabla</figcaption></figure>\n<!-- /wp:table -->\n\n<!-- wp:paragraph -->\n<p>Texto normal.</p>\n<!-- /wp:paragraph -->";

	/**
	 * Textos de los párrafos que lee el extractor.
	 *
	 * @param DomExtractor $extractor Extractor.
	 * @param string       $content   Contenido.
	 *
	 * @return list<string>
	 */
	private function texts( DomExtractor $extractor, string $content ): array {
		return array_map( static fn( $p ): string => $p->text, $extractor->extract( $content )->paragraphs );
	}

	public function test_by_default_headings_quotes_and_tables_are_not_sentences(): void {
		$result = ( new DomExtractor() )->extract( self::RICH );

		$this->assertSame( array( 'Texto normal.' ), array_map( static fn( $p ): string => $p->text, $result->paragraphs ) );
		$this->assertSame( array( 'Título con aerotermia' ), $result->headings, 'Los encabezados siguen contando como señal.' );
	}

	public function test_headings_become_sentences_when_allowed(): void {
		$extractor = new DomExtractor( true );

		$this->assertSame( array( 'Título con aerotermia', 'Texto normal.' ), $this->texts( $extractor, self::RICH ) );
		$this->assertSame( array( 'Título con aerotermia' ), $extractor->extract( self::RICH )->headings );
	}

	public function test_quotes_become_sentences_when_allowed(): void {
		$this->assertSame( array( 'Dentro de la cita.', 'Texto normal.' ), $this->texts( new DomExtractor( false, true ), self::RICH ) );
	}

	public function test_table_cells_become_separate_sentences_when_allowed(): void {
		$this->assertSame(
			array( 'Cabecera', 'Celda uno', 'Celda dos', 'Texto normal.' ),
			$this->texts( new DomExtractor( false, false, true ), self::RICH ),
			'Cada celda es un texto aparte y el pie de la tabla no cuenta.'
		);
	}

	public function test_classic_content_follows_the_same_options(): void {
		$html = '<p>Intro.</p><blockquote>Cita clásica.</blockquote><table><tr><td>Celda clásica</td></tr></table><h3>Encabezado clásico</h3>';

		$this->assertSame( array( 'Intro.' ), $this->texts( new DomExtractor(), $html ) );
		$this->assertSame( array( 'Intro.', 'Cita clásica.' ), $this->texts( new DomExtractor( false, true ), $html ) );
		$this->assertSame( array( 'Intro.', 'Celda clásica' ), $this->texts( new DomExtractor( false, false, true ), $html ) );
		$this->assertSame( array( 'Intro.', 'Encabezado clásico' ), $this->texts( new DomExtractor( true ), $html ) );
	}
}
