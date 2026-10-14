<?php
/**
 * El verificador de cambios de destino rechaza todo lo que no sea el `href` de un enlace.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Fix;

use MagicLinking\Content\AnchorScanner;
use MagicLinking\Content\Verifier;
use WP_UnitTestCase;

final class RetargetVerifierTest extends WP_UnitTestCase {

	private const BASE = "<!-- wp:paragraph -->\n<p>Hola <a href=\"https://example.org/a/\" class=\"c\">mundo <b>bueno</b></a> y <a href=\"https://example.org/b/\">otro</a>.</p>\n<!-- /wp:paragraph -->";

	/**
	 * @return array{0: int, 1: int}
	 */
	private function whole( string $content ): array {
		return array( 0, strlen( $content ) );
	}

	private function check( string $after ): string {
		$result = Verifier::check_retarget( self::BASE, $after, $this->whole( self::BASE ) );

		return $result->ok ? 'ok' : $result->reason;
	}

	public function test_only_the_href_of_one_link_passes(): void {
		$this->assertSame( 'ok', $this->check( str_replace( 'https://example.org/a/', 'https://example.org/z/', self::BASE ) ) );
	}

	public function test_no_change_is_not_a_fix(): void {
		$this->assertSame( 'link_count', $this->check( self::BASE ) );
	}

	public function test_two_links_changed_are_refused(): void {
		$this->assertSame( 'link_count', $this->check( str_replace( array( '/a/', '/b/' ), array( '/y/', '/z/' ), self::BASE ) ) );
	}

	public function test_another_attribute_changed_is_refused(): void {
		$this->assertSame( 'attributes', $this->check( str_replace( 'class="c"', 'class="d"', self::BASE ) ) );
		$this->assertSame( 'attributes', $this->check( str_replace( 'href="https://example.org/a/" class="c"', 'href="https://example.org/z/" class="c" rel="nofollow"', self::BASE ) ) );
	}

	public function test_changed_text_is_refused(): void {
		$this->assertSame( 'text', $this->check( str_replace( 'bueno', 'malo', self::BASE ) ) );
	}

	public function test_changed_tags_are_refused(): void {
		$this->assertSame( 'tags', $this->check( str_replace( '<b>bueno</b>', '<i>bueno</i>', self::BASE ) ) );
		$this->assertSame( 'tags', $this->check( str_replace( 'y <a href="https://example.org/b/">otro</a>', 'y otro', self::BASE ) ) );
	}

	public function test_a_change_outside_the_range_is_refused(): void {
		$after = str_replace( 'https://example.org/a/', 'https://example.org/z/', self::BASE ) . ' ';
		$this->assertFalse( Verifier::check_retarget( self::BASE, $after, array( 0, strlen( self::BASE ) - 10 ) )->ok );
	}

	public function test_changed_block_structure_is_refused(): void {
		$after = str_replace( 'wp:paragraph', 'wp:heading', str_replace( 'https://example.org/a/', 'https://example.org/z/', self::BASE ) );
		$this->assertSame( 'blocks', $this->check( $after ) );
	}

	public function test_scanner_finds_links_and_refuses_nesting(): void {
		$found = AnchorScanner::scan( 'x <a href="/a">uno</a> y <a href="/b?x=1>2">dos</a>' );
		$this->assertCount( 2, $found );
		$this->assertSame( '<a href="/b?x=1>2">', substr( 'x <a href="/a">uno</a> y <a href="/b?x=1>2">dos</a>', $found[1]['open_start'], $found[1]['open_end'] - $found[1]['open_start'] ) );

		$this->assertNull( AnchorScanner::scan( '<a href="/a">uno <a href="/b">dos</a></a>' ) );
		$this->assertNull( AnchorScanner::scan( '<a href="/a">sin cerrar' ) );
		$this->assertSame( array(), AnchorScanner::scan( 'sin enlaces <!-- <a href="/x">comentado</a> -->' ) );
	}

	public function test_with_href_keeps_every_other_byte_of_the_tag(): void {
		$this->assertSame( '<a  class="x"   href="https://e.org/n/"  data-k=\'v\' >', AnchorScanner::with_href( '<a  class="x"   href="/old"  data-k=\'v\' >', 'https://e.org/n/' ) );
	}
}
