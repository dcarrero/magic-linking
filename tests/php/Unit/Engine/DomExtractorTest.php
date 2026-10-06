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
}
