<?php
/**
 * Elección del aviso visible (F1-17, docs/07 §5): como máximo uno, el de más prioridad que no esté cerrado.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Admin;

use MagicLinking\Admin\Notices;
use PHPUnit\Framework\TestCase;

final class NoticesSelectionTest extends TestCase {

	/**
	 * @return list<array{id: string, priority: int, type: string, applies: bool}>
	 */
	private function defs(): array {
		return array(
			array(
				'id'       => 'low',
				'priority' => 50,
				'type'     => 'info',
				'applies'  => true,
			),
			array(
				'id'       => 'high',
				'priority' => 10,
				'type'     => 'warning',
				'applies'  => true,
			),
			array(
				'id'       => 'never',
				'priority' => 1,
				'type'     => 'info',
				'applies'  => false,
			),
		);
	}

	public function test_picks_only_one_the_most_urgent_that_applies(): void {
		$this->assertSame( 'high', Notices::select( $this->defs(), array() )['id'] ?? null );
	}

	public function test_skips_dismissed_ones(): void {
		$this->assertSame( 'low', Notices::select( $this->defs(), array( 'high' ) )['id'] ?? null );
	}

	public function test_nothing_when_all_are_dismissed_or_do_not_apply(): void {
		$this->assertNull( Notices::select( $this->defs(), array( 'high', 'low' ) ) );
		$this->assertNull( Notices::select( array(), array() ) );
	}

	public function test_a_dismissed_notice_is_not_evaluated(): void {
		$called = false;
		$defs   = array(
			array(
				'id'       => 'x',
				'priority' => 1,
				'type'     => 'info',
				'applies'  => static function () use ( &$called ): bool {
					$called = true;
					return true;
				},
			),
		);
		$this->assertNull( Notices::select( $defs, array( 'x' ) ) );
		$this->assertFalse( $called, 'Un aviso cerrado no gasta consultas.' );
	}
}
