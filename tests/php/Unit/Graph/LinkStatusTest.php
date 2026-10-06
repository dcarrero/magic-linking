<?php
/**
 * Estado de enlazado.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Graph;

use MagicLinking\Graph\LinkStatus;
use PHPUnit\Framework\TestCase;

final class LinkStatusTest extends TestCase {

	public function test_outbound_limit_is_one_per_n_words_with_a_minimum_of_one(): void {
		$this->assertSame( 1, LinkStatus::outbound_limit( 0, 100 ) );
		$this->assertSame( 1, LinkStatus::outbound_limit( 99, 100 ) );
		$this->assertSame( 2, LinkStatus::outbound_limit( 250, 100 ) );
		$this->assertSame( 10, LinkStatus::outbound_limit( 1000, 100 ) );
		$this->assertSame( 500, LinkStatus::outbound_limit( 500, 0 ), 'Un divisor cero no rompe.' );
	}

	/**
	 * @dataProvider cases
	 */
	public function test_status( int $inbound, int $outbound, int $words, string $expected ): void {
		$this->assertSame( $expected, LinkStatus::of( $inbound, $outbound, $words, 2, 100 ) );
	}

	/**
	 * @return array<string, array{0: int, 1: int, 2: int, 3: string}>
	 */
	public function cases(): array {
		return array(
			'sin entrantes'                => array( 0, 0, 500, LinkStatus::ORPHAN ),
			'huérfana aunque esté cargada' => array( 0, 50, 100, LinkStatus::ORPHAN ),
			'una entrante, umbral 2'       => array( 1, 0, 500, LinkStatus::LOW ),
			'en el umbral'                 => array( 2, 1, 500, LinkStatus::OK ),
			'justo en el límite de salida' => array( 5, 5, 500, LinkStatus::OK ),
			'por encima del límite'        => array( 5, 6, 500, LinkStatus::OVER ),
			'texto corto, un enlace'       => array( 5, 1, 30, LinkStatus::OK ),
			'texto corto, dos enlaces'     => array( 5, 2, 30, LinkStatus::OVER ),
		);
	}
}
