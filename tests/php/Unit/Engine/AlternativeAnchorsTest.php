<?php
/**
 * Las frases alternativas no repiten el ancla de otra sugerencia que se muestra (docs/04 §5.3).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\AlternativeAnchors;
use MagicLinking\Engine\Analyzer;
use MagicLinking\Engine\Score;
use MagicLinking\Engine\Suggestion;
use PHPUnit\Framework\TestCase;

final class AlternativeAnchorsTest extends TestCase {

	private function suggestion( int $target, string $anchor, array $alternatives = array() ): Suggestion {
		$sentence = "Una frase con {$anchor} dentro.";

		return new Suggestion( 1, $target, $anchor, $sentence, 13, 0, new Score( 0.5, array(), array(), true ), array(), $alternatives );
	}

	public function test_an_alternative_with_the_anchor_of_another_shown_suggestion_is_dropped(): void {
		$analyzer = Analyzer::for_language( 'es' );
		$a        = $this->suggestion( 2, 'bomba de calor', array( $this->suggestion( 2, 'Calefacción' ), $this->suggestion( 2, 'bomba de calor' ), $this->suggestion( 2, 'suelo' ) ) );
		$b        = $this->suggestion( 3, 'calefaccion' );

		$shown = AlternativeAnchors::prune( array( $a, $b ), $analyzer );

		$this->assertSame( array( 'bomba de calor', 'suelo' ), array_map( static fn( Suggestion $s ): string => $s->anchor, $shown[0]->alternatives ), 'Su propia ancla puede repetirse; la de b no.' );
		$this->assertSame( $b, $shown[1] );
	}

	public function test_it_only_looks_at_the_suggestions_it_is_given(): void {
		$analyzer = Analyzer::for_language( 'es' );
		$a        = $this->suggestion( 2, 'bomba de calor', array( $this->suggestion( 2, 'calefacción' ) ) );

		// b no se muestra (filtro, recorte...): su ancla no quita nada.
		$this->assertSame( $a, AlternativeAnchors::prune( array( $a ), $analyzer )[0] );
	}
}
