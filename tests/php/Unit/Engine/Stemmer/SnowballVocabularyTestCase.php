<?php
/**
 * Base de las pruebas de stemmers contra los vocabularios oficiales de Snowball.
 *
 * Fixtures: tests/php/fixtures/snowball/{idioma}.tsv.gz, una línea
 * «palabra<TAB>raíz» generada con `paste voc.txt output.txt | gzip -9n` a partir
 * de https://github.com/snowballstem/snowball-data (commit a0ec0d0, 2026-06-09),
 * BSD-3-Clause (ver COPYING en la misma carpeta). No entran en el ZIP.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine\Stemmer;

use MagicLinking\Engine\Stemmer\StemmerInterface;
use PHPUnit\Framework\TestCase;

abstract class SnowballVocabularyTestCase extends TestCase {

	/**
	 * Acierto mínimo exigido.
	 */
	private const MIN_ACCURACY = 0.99;

	abstract protected function stemmer(): StemmerInterface;

	abstract protected function language(): string;

	public function test_official_vocabulary_accuracy(): void {
		$pairs   = $this->vocabulary();
		$stemmer = $this->stemmer();
		$misses  = array();

		foreach ( $pairs as $word => $expected ) {
			$actual = $stemmer->stem( (string) $word );
			if ( $actual !== $expected ) {
				$misses[] = "{$word} → {$actual} (esperado {$expected})";
			}
		}

		$accuracy = 1 - count( $misses ) / count( $pairs );

		$this->assertGreaterThan( 20000, count( $pairs ) );
		$this->assertGreaterThanOrEqual(
			self::MIN_ACCURACY,
			$accuracy,
			sprintf( 'Acierto %.3f %%. Primeros fallos: %s', 100 * $accuracy, implode( ', ', array_slice( $misses, 0, 20 ) ) )
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function vocabulary(): array {
		$raw = gzdecode( (string) file_get_contents( __DIR__ . '/../../../fixtures/snowball/' . $this->language() . '.tsv.gz' ) );
		$this->assertIsString( $raw );

		$pairs = array();
		foreach ( explode( "\n", trim( $raw ) ) as $line ) {
			[ $word, $stem ]         = explode( "\t", $line );
			$pairs[ (string) $word ] = $stem;
		}

		return $pairs;
	}
}
