<?php
/**
 * La versión y los requisitos se declaran en tres sitios; tienen que coincidir.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PluginMetadataTest extends TestCase {

	private const ROOT = __DIR__ . '/../../../';

	public function test_header_version_matches_constant(): void {
		$main = (string) file_get_contents( self::ROOT . 'magic-linking.php' );

		$this->assertSame( $this->match( '/^\s*\*\s*Version:\s*(\S+)/m', $main ), $this->match( "/define\( 'MAGICLINKING_VERSION', '([^']+)' \)/", $main ) );
	}

	public function test_readme_matches_plugin_header(): void {
		$main   = (string) file_get_contents( self::ROOT . 'magic-linking.php' );
		$readme = (string) file_get_contents( self::ROOT . 'readme.txt' );

		$this->assertSame( $this->match( '/^\s*\*\s*Version:\s*(\S+)/m', $main ), $this->match( '/^Stable tag:\s*(\S+)/m', $readme ) );
		$this->assertSame( $this->match( '/^\s*\*\s*Requires at least:\s*(\S+)/m', $main ), $this->match( '/^Requires at least:\s*(\S+)/m', $readme ) );
		$this->assertSame( $this->match( '/^\s*\*\s*Requires PHP:\s*(\S+)/m', $main ), $this->match( '/^Requires PHP:\s*(\S+)/m', $readme ) );
	}

	private function match( string $pattern, string $subject ): string {
		$this->assertSame( 1, preg_match( $pattern, $subject, $m ), "No se encuentra {$pattern}" );

		return $m[1];
	}
}
