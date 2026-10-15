<?php
/**
 * Estimación de tokens y de coste (docs/05 §5.1).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\AI;

use MagicLinking\AI\CostEstimator;
use PHPUnit\Framework\TestCase;

final class CostEstimatorTest extends TestCase {

	public function test_spanish_uses_3_6_characters_per_token_and_english_4(): void {
		$text = str_repeat( 'a', 360 );

		$this->assertSame( 100, CostEstimator::tokens( $text, 'es' ) );
		$this->assertSame( 90, CostEstimator::tokens( $text, 'en' ) );
		$this->assertSame( 90, CostEstimator::tokens( $text, 'en_GB' ), 'Un código regional también es inglés.' );
		$this->assertSame( 100, CostEstimator::tokens( $text, 'de' ), 'Cualquier otro idioma usa la medida del castellano.' );
	}

	public function test_tokens_are_rounded_up_and_counted_in_characters_not_bytes(): void {
		$this->assertSame( 0, CostEstimator::tokens( '' ) );
		$this->assertSame( 1, CostEstimator::tokens( 'a' ) );
		$this->assertSame( 2, CostEstimator::tokens( 'ñññú', 'es' ), 'Cuatro caracteres, no ocho bytes: 4 / 3,6 sube a 2.' );
		$this->assertSame( 1, CostEstimator::tokens( 'ñññ', 'es' ) );
	}

	public function test_cost_uses_the_price_table_per_million_tokens(): void {
		// gpt-4o-mini: 0,15 $ de entrada y 0,60 $ de salida por millón.
		$this->assertEqualsWithDelta( 0.15 + 0.60, (float) CostEstimator::cost( 1000000, 1000000, 'gpt-4o-mini' ), 1e-9 );
		$this->assertEqualsWithDelta( 0.00027, (float) CostEstimator::cost( 1000, 200, 'gpt-4o-mini-2024-07-18' ), 1e-9 );
	}

	public function test_a_model_that_is_not_in_the_table_has_no_cost_only_tokens(): void {
		$this->assertNull( CostEstimator::cost( 1000, 1000, 'modelo-desconocido' ) );
		$this->assertNull( CostEstimator::cost( 1000, 1000, null ) );

		$estimate = CostEstimator::estimate( 1000, 500, 'modelo-desconocido' );
		$this->assertSame( 1500, $estimate['tokens'] );
		$this->assertNull( $estimate['cost'] );
	}

	public function test_a_local_model_costs_zero_even_without_a_price(): void {
		$this->assertSame( 0.0, CostEstimator::cost( 5000, 5000, 'llama3.2:3b', true ) );
		$this->assertSame( 0.0, CostEstimator::cost( 5000, 5000, null, true ) );
	}
}
