<?php
/**
 * Los enlaces del historial, aunque se pongan, quiten y vuelvan a poner.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\History;

use MagicLinking\History\Slots;
use PHPUnit\Framework\TestCase;

final class SlotsTest extends TestCase {

	/**
	 * Fila ligera.
	 *
	 * @param int         $id   ID.
	 * @param string      $slot Hueco.
	 * @param string|null $undone Fecha de deshacer.
	 */
	private function row( int $id, string $slot, ?string $undone = null ): array {
		return array(
			'id'         => $id,
			'post_id'    => 10,
			'user_id'    => 1,
			'created_at' => '2026-10-01 10:00:00',
			'undone_at'  => $undone,
			'slot'       => $slot,
		);
	}

	public function test_distinct_links_stay_distinct_in_order(): void {
		$rows = array( $this->row( 1, 'a' ), $this->row( 2, 'b' ), $this->row( 3, 'c' ) );

		$this->assertSame( array( 1, 2, 3 ), array_column( Slots::collapse( $rows ), 'id' ) );
	}

	public function test_a_redone_link_counts_once_and_is_the_latest_row(): void {
		$rows = array(
			$this->row( 1, 'a', '2026-10-02 10:00:00' ),
			$this->row( 2, 'b' ),
			$this->row( 3, 'a' ),
		);

		$slots = Slots::collapse( $rows );

		$this->assertSame( array( 3, 2 ), array_column( $slots, 'id' ), 'El hueco conserva su sitio y se queda con la fila nueva.' );
		$this->assertNull( $slots[0]['undone_at'] );
	}

	public function test_a_link_undone_after_being_redone_is_undone(): void {
		$rows = array(
			$this->row( 1, 'a', '2026-10-02 10:00:00' ),
			$this->row( 4, 'a', '2026-10-03 10:00:00' ),
		);

		$slots = Slots::collapse( $rows );

		$this->assertCount( 1, $slots );
		$this->assertSame( 4, $slots[0]['id'] );
		$this->assertNotNull( $slots[0]['undone_at'] );
	}

	public function test_empty(): void {
		$this->assertSame( array(), Slots::collapse( array() ) );
	}
}
