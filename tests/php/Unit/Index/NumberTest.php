<?php
/**
 * Los pesos que van dentro de una sentencia SQL no dependen del idioma del proceso.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Index;

use MagicLinking\Index\TableRepository;
use PHPUnit\Framework\TestCase;

final class NumberTest extends TestCase {

	private string $locale = 'C';

	protected function tearDown(): void {
		setlocale( LC_NUMERIC, $this->locale );
	}

	public function test_weights_use_a_decimal_point_with_a_comma_locale(): void {
		$this->locale = (string) setlocale( LC_NUMERIC, '0' );
		$set          = setlocale( LC_NUMERIC, 'es_ES.UTF-8', 'es_ES.utf8', 'es_ES', 'de_DE.UTF-8', 'de_DE.utf8', 'fr_FR.UTF-8', 'fr_FR.utf8', 'fr_FR' );
		if ( false === $set || '0,5' !== sprintf( '%.1f', 0.5 ) ) {
			$this->markTestSkipped( 'No hay una configuración regional con coma decimal en este sistema.' );
		}

		$this->assertSame( '0.5', TableRepository::number( 0.5 ) );
		$this->assertSame( '12.3456789012', TableRepository::number( 12.3456789012 ) );
		$this->assertSame( '0.123456789012', TableRepository::number( 0.123456789012345 ) );
		$this->assertSame( 0.5, (float) TableRepository::number( 0.5 ) );
	}

	public function test_small_and_large_weights_stay_numeric(): void {
		$this->assertEqualsWithDelta( 1.5e-7, (float) TableRepository::number( 1.5e-7 ), 1e-19 );
		$this->assertEqualsWithDelta( 123456.5, (float) TableRepository::number( 123456.5 ), 1e-6 );
	}
}
