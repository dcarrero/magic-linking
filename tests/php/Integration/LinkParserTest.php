<?php
/**
 * Extracción y clasificación de enlaces.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Graph\LinkParser;
use WP_UnitTestCase;

final class LinkParserTest extends WP_UnitTestCase {

	private function parser( array $alias = array() ): LinkParser {
		return new LinkParser( 'https://example.org', $alias );
	}

	/**
	 * @return list<array{0: string, 1: string, 2: bool}> URL, ancla, interno.
	 */
	private function parse( string $html, array $alias = array() ): array {
		return array_map(
			static fn( $l ) => array( $l->url, $l->anchor, $l->internal ),
			$this->parser( $alias )->parse( $html )
		);
	}

	public function test_classifies_internal_and_external_links(): void {
		$html = '<p><a href="https://example.org/guia/">Guía</a> <a href="https://otro.com/x">Otro</a>'
			. ' <a href="//example.org/b/">B</a> <a href="/c/?utm_source=x&amp;p=1#frag">C</a> <a href="d/">D</a></p>';

		$this->assertSame(
			array(
				array( 'https://example.org/guia/', 'Guía', true ),
				array( 'https://otro.com/x', 'Otro', false ),
				array( 'https://example.org/b/', 'B', true ),
				array( 'https://example.org/c/?p=1', 'C', true ),
				array( 'https://example.org/d/', 'D', true ),
			),
			$this->parse( $html )
		);
	}

	public function test_www_and_aliases_are_the_same_site(): void {
		$html = '<a href="http://www.example.org/a/">A</a><a href="https://blog.example.net/b/">B</a>';

		$this->assertSame(
			array(
				array( 'https://example.org/a/', 'A', true ),
				array( 'https://example.org/b/', 'B', true ),
			),
			$this->parse( $html, array( 'blog.example.net' ) )
		);
		$this->assertFalse( $this->parse( $html )[1][2] );
	}

	public function test_skips_what_is_not_a_page_link(): void {
		$html = '<a href="#top">x</a><a href="">x</a><a href="mailto:a@b.c">x</a><a href="tel:+34600">x</a>'
			. '<a href="javascript:void(0)">x</a><a href="https://example.org/wp-content/uploads/a.pdf">x</a>'
			. '<a href="/foto.jpg">x</a><a href="/wp-json/wp/v2/posts">x</a><a>sin href</a><a href="?x=1">x</a>';

		$this->assertSame( array(), $this->parse( $html ) );
	}

	public function test_keeps_page_extensions_and_strips_tracking(): void {
		$html = '<a href="/pagina.html?gclid=1&fbclid=2">P</a>';

		$this->assertSame( array( array( 'https://example.org/pagina.html', 'P', true ) ), $this->parse( $html ) );
	}

	public function test_anchor_text_joins_nested_markup_and_falls_back_to_alt(): void {
		$html = '<a href="/a/">Reforma <strong>de la</strong>   cocina</a>'
			. '<a href="/b/"><img src="x.png" alt="Logo de la tienda"></a>'
			. '<a href="/c/"><img src="x.png"></a>';

		$this->assertSame(
			array(
				array( 'https://example.org/a/', 'Reforma de la cocina', true ),
				array( 'https://example.org/b/', 'Logo de la tienda', true ),
				array( 'https://example.org/c/', '', true ),
			),
			$this->parse( $html )
		);
	}

	public function test_handles_unclosed_anchors_entities_and_block_markup(): void {
		$html = "<!-- wp:paragraph -->\n<p>Ver <a href=\"/a/\">caf&eacute; &amp; t&eacute;</p>\n<!-- /wp:paragraph -->"
			. '<a href="/b/">Dos</a>';

		$links = $this->parse( $html );
		$this->assertCount( 2, $links );
		$this->assertSame( 'café & té', $links[0][1] );
		$this->assertSame( 'Dos', $links[1][1] );
	}

	public function test_long_anchors_are_cut_to_255_characters(): void {
		$links = $this->parse( '<a href="/a/">' . str_repeat( 'é', 400 ) . '</a>' );

		$this->assertSame( 255, mb_strlen( $links[0][1] ) );
	}

	public function test_a_site_in_a_subdirectory_keeps_its_path(): void {
		$parser = new LinkParser( 'https://example.org/blog' );
		$links  = $parser->parse( '<a href="/blog/a/">A</a><a href="/blog/wp-content/x/y">X</a><a href="/blog/wp-admin/">Y</a>' );

		$this->assertCount( 1, $links );
		$this->assertSame( 'https://example.org/blog/a/', $links[0]->url );
	}
}
