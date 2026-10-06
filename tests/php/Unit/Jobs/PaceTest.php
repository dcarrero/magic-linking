<?php
/**
 * Ritmo del indexado: tamaño de corte y estimación del tiempo restante.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Jobs;

use MagicLinking\Jobs\Pace;
use PHPUnit\Framework\TestCase;

final class PaceTest extends TestCase {

	public function test_slice_grows_when_fast_shrinks_when_slow_and_stays_within_limits(): void {
		$this->assertSame( 100, Pace::next_slice( 50, 0.2, 1.5 ), 'Rápido: se duplica.' );
		$this->assertSame( 25, Pace::next_slice( 50, 3.0, 1.5 ), 'Lento: se reduce a la mitad.' );
		$this->assertSame( 50, Pace::next_slice( 50, 1.4, 1.5 ), 'En el objetivo: igual.' );
		$this->assertSame( Pace::MAX_SLICE, Pace::next_slice( 150, 0.01, 1.5 ) );
		$this->assertSame( Pace::MIN_SLICE, Pace::next_slice( 6, 30.0, 1.5 ) );
		$this->assertSame( Pace::MIN_SLICE, Pace::next_slice( 1, 1.5, 1.5 ), 'Nunca por debajo del mínimo.' );
	}

	public function test_samples_keep_only_the_latest(): void {
		$samples = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$samples = Pace::push_sample( $samples, 1000 + $i, $i * 100 );
		}

		$this->assertCount( Pace::KEEP_SAMPLES, $samples );
		$this->assertSame( array( 1010, 1000 ), $samples[ Pace::KEEP_SAMPLES - 1 ] );
		$this->assertSame( array( 1005, 500 ), $samples[0] );
	}

	public function test_rate_is_wall_clock_entries_per_second(): void {
		$this->assertNull( Pace::rate( array() ) );
		$this->assertNull( Pace::rate( array( array( 100, 0 ) ) ) );
		$this->assertNull( Pace::rate( array( array( 100, 5 ), array( 100, 50 ) ) ), 'Sin tiempo transcurrido no hay ritmo.' );
		$this->assertNull( Pace::rate( array( array( 100, 5 ), array( 160, 5 ) ) ), 'Sin avance no hay ritmo.' );
		$this->assertEqualsWithDelta( 10.0, (float) Pace::rate( array( array( 100, 0 ), array( 130, 300 ) ) ), 0.0001 );
	}

	public function test_eta_is_remaining_over_rate_rounded_up(): void {
		$samples = array( array( 0, 0 ), array( 100, 1000 ) );

		$this->assertSame( 4192, Pace::eta( 41917, $samples ), '41.917 pendientes a 10 entradas/s.' );
		$this->assertSame( 0, Pace::eta( 0, $samples ) );
		$this->assertNull( Pace::eta( 500, array( array( 0, 0 ) ) ) );
	}
}
