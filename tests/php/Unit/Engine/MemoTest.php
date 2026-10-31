<?php
/**
 * Memoria acotada de palabras.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\Memo;
use PHPUnit\Framework\TestCase;

final class MemoTest extends TestCase {

	public function test_it_never_passes_the_limit_and_drops_only_the_oldest_half(): void {
		$map = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			Memo::put( $map, 'k' . $i, $i, 10 );
		}
		$this->assertCount( 10, $map );

		Memo::put( $map, 'k11', 11, 10 );

		$this->assertSame( array( 'k6', 'k7', 'k8', 'k9', 'k10', 'k11' ), array_keys( $map ) );
		$this->assertSame( 6, $map['k6'] );
	}

	public function test_numeric_keys_keep_their_value(): void {
		$map = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			Memo::put( $map, (string) ( 2000 + $i ), 'v' . $i, 4 );
		}

		$this->assertSame( 'v5', $map[2005] );
		$this->assertSame( 'v3', $map[2003] );
		$this->assertArrayNotHasKey( 2001, $map );
	}
}
