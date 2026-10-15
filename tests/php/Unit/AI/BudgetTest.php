<?php
/**
 * Tope diario de gasto de IA (docs/05 §5.3).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\AI;

use MagicLinking\AI\Budget;
use MagicLinking\AI\UsageStore;
use PHPUnit\Framework\TestCase;

final class BudgetTest extends TestCase {

	private function store( int $used = 0 ): UsageStore {
		return new class( $used ) implements UsageStore {
			/** @var list<array<string, mixed>> */
			public array $rows = array();

			public function __construct( private int $used ) {
			}

			public function ai_tokens_today(): int {
				return $this->used;
			}

			public function record_ai( array $row ): int {
				$this->rows[] = $row;
				$this->used  += $row['tokens_real'];

				return count( $this->rows );
			}
		};
	}

	public function test_default_limit_is_two_million_tokens(): void {
		$this->assertSame( 2000000, Budget::DEFAULT_LIMIT );
	}

	public function test_a_request_that_fits_is_allowed(): void {
		$budget = new Budget( $this->store( 1000 ), static fn(): int => 5000 );

		$this->assertTrue( $budget->enabled() );
		$this->assertFalse( $budget->exhausted() );
		$this->assertSame( 4000, $budget->remaining() );
		$this->assertTrue( $budget->allows( 4000 ), 'Justo lo que queda cabe.' );
		$this->assertFalse( $budget->allows( 4001 ), 'Pasarse de lo que queda no.' );
	}

	public function test_reaching_the_limit_turns_ai_off_for_the_day(): void {
		$budget = new Budget( $this->store( 5000 ), static fn(): int => 5000 );

		$this->assertTrue( $budget->exhausted() );
		$this->assertSame( 0, $budget->remaining() );
		$this->assertFalse( $budget->allows( 0 ), 'Con el tope alcanzado no se permite ni una petición vacía.' );
	}

	public function test_a_limit_of_zero_means_no_ai(): void {
		$budget = new Budget( $this->store( 0 ), static fn(): int => 0 );

		$this->assertFalse( $budget->enabled() );
		$this->assertTrue( $budget->exhausted() );
		$this->assertFalse( $budget->allows( 1 ) );
	}

	public function test_the_limit_is_read_each_time_so_a_change_in_settings_applies_at_once(): void {
		$limit  = 100;
		$budget = new Budget(
			$this->store( 100 ),
			static function () use ( &$limit ): int {
				return $limit;
			}
		);

		$this->assertTrue( $budget->exhausted() );
		$limit = 500;
		$this->assertFalse( $budget->exhausted() );
		$limit = -3;
		$this->assertSame( 0, $budget->limit(), 'Un tope negativo cuenta como cero.' );
	}

	public function test_recording_spend_moves_the_counter(): void {
		$store  = $this->store( 0 );
		$budget = new Budget( $store, static fn(): int => 3000 );

		$budget->record(
			array(
				'type'        => 'ai_rerank',
				'status'      => 'done',
				'tokens_est'  => 2000,
				'tokens_real' => 2500,
				'cost_est'    => 0.0,
				'cost_real'   => 0.0,
				'provider'    => 'ollama',
				'model'       => 'llama3.2:3b',
				'user_id'     => 1,
				'params'      => array(),
				'error'       => '',
			)
		);

		$this->assertSame( 2500, $budget->used_today() );
		$this->assertTrue( $budget->allows( 500 ) );
		$this->assertFalse( $budget->allows( 501 ) );
	}
}
