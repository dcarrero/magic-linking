<?php
/**
 * Prefiltro por raíz de AnchorUsage: solo se adelanta en SQL lo que aparece tal cual en el texto de la ancla.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Graph;

use MagicLinking\Graph\AnchorUsage;
use PHPUnit\Framework\TestCase;

final class AnchorUsageStemTest extends TestCase {

	public function test_the_beginning_of_the_longest_stem_is_asked_for_when_it_is_in_the_text(): void {
		$this->assertSame( array( 'calo' ), AnchorUsage::stem_substrings( array( 'bomb de calor' => 'bomba de calor' ) ) );
		$this->assertSame( array( 'aeroterm' ), AnchorUsage::stem_substrings( array( 'aerotermi' => 'Aerotermia' ) ), 'Sin distinguir mayúsculas.' );
	}

	public function test_a_stem_that_is_not_literal_in_the_text_turns_the_prefilter_off(): void {
		// El lematizador cambia letras («ß» por «ss»): pedir «strass» en SQL perdería la variante «Straße».
		$this->assertNull( AnchorUsage::stem_substrings( array( 'strass' => 'Straße' ) ) );
		// Basta una para no adelantar nada: ninguna variante se pierde.
		$this->assertNull(
			AnchorUsage::stem_substrings(
				array(
					'bomb de calor' => 'bomba de calor',
					'strass'        => 'Straße',
				)
			)
		);
	}

	public function test_nothing_to_ask_for_is_no_prefilter(): void {
		$this->assertNull( AnchorUsage::stem_substrings( array() ) );
	}
}
