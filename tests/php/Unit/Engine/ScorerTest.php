<?php
/**
 * Puntuación final.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\Candidate;
use MagicLinking\Engine\Phrase;
use MagicLinking\Engine\Scorer;
use PHPUnit\Framework\TestCase;

final class ScorerTest extends TestCase {

	/**
	 * @param array<string, mixed> $changes
	 */
	private static function candidate( array $changes = array() ): Candidate {
		$values = array_merge(
			array(
				'relevance'    => 1.0,
				'anchor_kind'  => Phrase::TITLE,
				'anchor_words' => 2,
				'inbound'      => 0,
				'position'     => 0.1,
				'last'         => false,
				'age_days'     => 10,
				'source_links' => 0,
				'source_words' => 1000,
				'anchor_uses'  => 0,
				'unlike'       => false,
			),
			$changes
		);
		return new Candidate( ...$values );
	}

	public function test_weights_sum_to_one(): void {
		$this->assertEqualsWithDelta( 1.0, array_sum( Scorer::WEIGHTS ), 1e-9 );
		$this->assertSame( 0.35, Scorer::THRESHOLD );
	}

	public function test_perfect_candidate_scores_one(): void {
		$score = ( new Scorer() )->score( self::candidate() );

		$this->assertSame( 1.0, $score->value );
		$this->assertTrue( $score->passes );
		$this->assertSame( array(), $score->penalties );
	}

	public function test_formula(): void {
		$score = ( new Scorer() )->score(
			self::candidate(
				array(
					'relevance'    => 0.5,
					'anchor_kind'  => Phrase::NGRAM,
					'anchor_words' => 1,
					'inbound'      => 3,
					'position'     => 0.8,
					'age_days'     => 400,
				)
			)
		);

		$expected = 0.55 * 0.5 + 0.25 * 0.7 + 0.05 * 0.25 + 0.10 * 0.6 + 0.05 * 0.0;
		$this->assertEqualsWithDelta( $expected, $score->value, 1e-4 );
		$this->assertSame( 0.6, $score->signals['position'] );
	}

	public function test_anchor_quality(): void {
		$this->assertSame( 1.0, Scorer::anchor_quality( Phrase::TITLE, 3 ), 'con tope en 1' );
		$this->assertEqualsWithDelta( 0.8, Scorer::anchor_quality( Phrase::NGRAM, 2 ), 1e-9 );
		$this->assertSame( 0.4, Scorer::anchor_quality( Phrase::UNIGRAM, 1 ) );
	}

	public function test_last_paragraph_position(): void {
		$score = ( new Scorer() )->score( self::candidate( array( 'last' => true ) ) );

		$this->assertSame( 0.4, $score->signals['position'] );
	}

	public function test_penalties(): void {
		$scorer = new Scorer();

		$overload = $scorer->score( self::candidate( array( 'source_links' => 11 ) ) );
		$this->assertSame( array( 'overload' => 0.2 ), $overload->penalties );
		$this->assertSame( 0.8, $overload->value );
		$this->assertSame( array(), $scorer->score( self::candidate( array( 'source_links' => 10 ) ) )->penalties, 'exactamente 1 por cada 100 no penaliza' );

		$this->assertSame( array( 'anchor_overused' => 0.1 ), $scorer->score( self::candidate( array( 'anchor_uses' => 6 ) ) )->penalties );
		$this->assertSame( array(), $scorer->score( self::candidate( array( 'anchor_uses' => 5 ) ) )->penalties );
		$this->assertSame( array( 'unlike_target' => 0.3 ), $scorer->score( self::candidate( array( 'unlike' => true ) ) )->penalties );
	}

	public function test_threshold(): void {
		$weak = self::candidate(
			array(
				'relevance'    => 0.0,
				'anchor_kind'  => Phrase::UNIGRAM,
				'anchor_words' => 1,
				'inbound'      => 9,
				'last'         => true,
				'age_days'     => 900,
			)
		);

		$this->assertFalse( ( new Scorer() )->score( $weak )->passes );
		$this->assertTrue( ( new Scorer( array(), 0.1, Scorer::DENSITY, 0.0 ) )->score( $weak )->passes );
	}

	public function test_minimum_relevance(): void {
		// Destino huérfano, reciente, ancla de título al principio: 0,55 sin relevancia.
		$scorer = new Scorer();
		$none   = $scorer->score( self::candidate( array( 'relevance' => 0.0 ) ) );
		$low    = $scorer->score( self::candidate( array( 'relevance' => 0.149 ) ) );
		$enough = $scorer->score( self::candidate( array( 'relevance' => Scorer::MIN_RELEVANCE ) ) );

		$this->assertGreaterThan( Scorer::THRESHOLD, $none->value );
		$this->assertFalse( $none->passes );
		$this->assertFalse( $low->passes );
		$this->assertTrue( $enough->passes );
		$this->assertSame( 0.15, Scorer::MIN_RELEVANCE );
	}

	public function test_weights_can_be_overridden_for_the_bench(): void {
		$score = ( new Scorer( array( 'relevance' => 0.0 ) ) )->score( self::candidate() );

		$this->assertEqualsWithDelta( 0.45, $score->value, 1e-9 );
	}
}
