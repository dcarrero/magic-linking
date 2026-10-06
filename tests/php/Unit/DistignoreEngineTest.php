<?php
/**
 * El ZIP lleva de src/Engine solo lo que usa el código que viaja con él (.distignore).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DistignoreEngineTest extends TestCase {

	private const ROOT = __DIR__ . '/../../../';

	/**
	 * Clases de src/Engine que .distignore deja fuera del ZIP.
	 *
	 * @return list<string>
	 */
	private function excluded(): array {
		$lines = (array) file( self::ROOT . '.distignore', FILE_IGNORE_NEW_LINES );
		$out   = array();
		foreach ( $lines as $line ) {
			if ( 1 === preg_match( '#^/src/Engine/(\w+)\.php$#', (string) $line, $m ) ) {
				$out[] = $m[1];
			}
		}

		return $out;
	}

	public function test_excluded_files_exist(): void {
		$excluded = $this->excluded();
		$this->assertNotEmpty( $excluded );

		foreach ( $excluded as $class ) {
			$this->assertFileExists( self::ROOT . "src/Engine/{$class}.php", "{$class} está en .distignore pero no existe." );
		}
	}

	public function test_shipped_code_does_not_use_excluded_classes(): void {
		$excluded = array_flip( $this->excluded() );
		$files    = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::ROOT . 'src', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		foreach ( $files as $path ) {
			$name = basename( $path, '.php' );
			if ( isset( $excluded[ $name ] ) && str_contains( $path, '/src/Engine/' ) ) {
				continue;
			}

			$tokens = token_get_all( (string) file_get_contents( $path ) );
			foreach ( $tokens as $i => $token ) {
				if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $excluded[ $token[1] ] ) ) {
					continue;
				}
				// `instanceof MemoryIndex` no carga la clase.
				$previous = $tokens[ $i - 2 ] ?? null;
				if ( is_array( $previous ) && T_INSTANCEOF === $previous[0] ) {
					continue;
				}
				$this->fail( basename( $path ) . " usa {$token[1]}, que .distignore deja fuera del ZIP." );
			}
		}

		$this->addToAssertionCount( 1 );
	}
}
