<?php
/**
 * Opciones del motor: frases alternativas, pilar, filtros y topes.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\Candidate;
use MagicLinking\Engine\Phrase;
use MagicLinking\Engine\Scorer;
use MagicLinking\Engine\Suggester;
use MagicLinking\Engine\Suggestion;
use PHPUnit\Framework\TestCase;

final class SuggesterOptionsTest extends TestCase {

	private static function corpus(): Corpus {
		return new Corpus(
			static function ( ArraySource $s ): void {
				$s->add(
					8,
					'Guía de instalación',
					array(
						'Las placas solares se instalan en el tejado.',
						'Antes de nada conviene revisar la orientación.',
						'Las placas solares rinden más con buena orientación.',
						'Muchas familias eligen placas solares para el verano.',
					),
					'es',
					'post',
					'',
					1790000000,
					array(),
					array(
						'category' => array( 'energia' ),
						'post_tag' => array( 'solar' ),
					)
				);
			}
		);
	}

	private static function loose(): Scorer {
		return new Scorer( array(), 0.0, Scorer::DENSITY, 0.0 );
	}

	/**
	 * @param list<Suggestion> $suggestions
	 */
	private static function to( array $suggestions, int $target ): ?Suggestion {
		foreach ( $suggestions as $suggestion ) {
			if ( $suggestion->target === $target ) {
				return $suggestion;
			}
		}
		return null;
	}

	public function test_alternatives_are_off_by_default_and_never_change_the_main_phrase(): void {
		$corpus   = self::corpus();
		$document = $corpus->source->get( 8 );
		$this->assertNotNull( $document );

		$plain = self::to( $corpus->suggester( array(), self::loose() )->outgoing( $document ), 3 );
		$more  = self::to( $corpus->suggester( array( 'alternatives' => 2 ), self::loose() )->outgoing( $document ), 3 );

		$this->assertNotNull( $plain );
		$this->assertNotNull( $more );
		$this->assertSame( array(), $plain->alternatives );
		$this->assertSame( $plain->sentence, $more->sentence );
		$this->assertSame( $plain->score->value, $more->score->value );
	}

	public function test_up_to_two_alternatives_in_distinct_sentences_within_the_margin(): void {
		$corpus   = self::corpus();
		$document = $corpus->source->get( 8 );
		$this->assertNotNull( $document );

		$best = self::to( $corpus->suggester( array( 'alternatives' => 5 ), self::loose() )->outgoing( $document ), 3 );

		$this->assertNotNull( $best );
		$this->assertCount( Suggester::MAX_ALTERNATIVES, $best->alternatives );
		$sentences = array( $best->sentence );
		foreach ( $best->alternatives as $alternative ) {
			$this->assertSame( 3, $alternative->target );
			$this->assertNotContains( $alternative->sentence, $sentences, 'frases distintas' );
			$sentences[] = $alternative->sentence;
			$this->assertGreaterThanOrEqual( $best->score->value - Suggester::ALTERNATIVE_MARGIN, $alternative->score->value );
			$this->assertSame( $alternative->anchor, substr( $alternative->sentence, $alternative->offset, strlen( $alternative->anchor ) ) );
		}

		$none = self::to(
			$corpus->suggester(
				array(
					'alternatives'       => 2,
					'alternative_margin' => -1.0,
				),
				self::loose()
			)->outgoing( $document ),
			3
		);
		$this->assertNotNull( $none );
		$this->assertSame( array(), $none->alternatives, 'con margen negativo ninguna alternativa cabe' );
	}

	public function test_pillar_bonus_reorders_but_cannot_pass_the_threshold_alone(): void {
		$scorer = new Scorer( array(), Scorer::THRESHOLD, Scorer::DENSITY, Scorer::MIN_RELEVANCE, array( 'pillar' => 0.05 ) );
		$base   = array(
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
		);

		$plain  = $scorer->score( new Candidate( ...$base ) );
		$pillar = $scorer->score( new Candidate( ...( $base + array( 'pillar' => true ) ) ) );
		$this->assertEqualsWithDelta( $plain->value + 0.05, $pillar->value, 1e-9 );
		$this->assertSame( array( 'pillar' => 0.05 ), $pillar->bonuses );

		$weak = array(
			'relevance'    => 0.16,
			'anchor_kind'  => Phrase::UNIGRAM,
			'anchor_words' => 1,
			'inbound'      => 9,
			'last'         => true,
			'age_days'     => 900,
		) + $base;
		$this->assertFalse( $scorer->score( new Candidate( ...( array( 'pillar' => true ) + $weak ) ) )->passes );
	}

	public function test_pillar_bonus_is_zero_by_default(): void {
		$this->assertSame( 0.0, Scorer::BONUSES['pillar'] );
		$this->assertSame( 0.0, Scorer::BONUSES['affinity'] );
	}

	public function test_pillars_option_lifts_the_target_score(): void {
		$corpus   = self::corpus();
		$document = $corpus->source->get( 8 );
		$this->assertNotNull( $document );
		$scorer = new Scorer( array(), 0.0, Scorer::DENSITY, 0.0, array( 'pillar' => 0.05 ) );

		$plain  = self::to( $corpus->suggester( array(), $scorer )->outgoing( $document ), 3 );
		$pillar = self::to( $corpus->suggester( array( 'pillars' => array( 3 ) ), $scorer )->outgoing( $document ), 3 );

		$this->assertNotNull( $plain );
		$this->assertNotNull( $pillar );
		$this->assertEqualsWithDelta( $plain->score->value + 0.05, $pillar->score->value, 1e-9 );
	}

	public function test_affinity_bonus_per_considered_filter_with_cap(): void {
		$scorer = new Scorer( array(), 0.0, Scorer::DENSITY, 0.0, array( 'affinity' => 0.05 ) );
		$base   = array(
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
		);
		$one    = $scorer->score( new Candidate( ...( $base + array( 'affinity' => 1 ) ) ) );
		$three  = $scorer->score( new Candidate( ...( $base + array( 'affinity' => 3 ) ) ) );

		$this->assertSame( array( 'affinity' => 0.05 ), $one->bonuses );
		$this->assertSame( array( 'affinity' => Scorer::AFFINITY_CAP ), $three->bonuses );
	}

	private static function filter_corpus(): Corpus {
		return new Corpus(
			static function ( ArraySource $s ): void {
				$s->add( 8, 'Guía de geotermia', array( 'La geotermia aprovecha el calor del subsuelo.', 'Una instalación de geotermia dura décadas.' ), 'es', 'page', '', 1790000000, array(), self::terms( 'energia', 'solar' ) );
				$s->add( 9, 'Compostaje doméstico', array( 'El compostaje doméstico reduce los residuos.', 'Un buen compostaje necesita aire y humedad.' ), 'es', 'post', '', 1790000000, array(), self::terms( 'hogar', 'casa' ) );
				// Relleno para que los términos tengan df ≥ 2; no se sugiere (never).
				$s->add( 10, 'Notas', array( 'La geotermia y el compostaje doméstico son temas de moda.' ), 'es', 'post', '', 1790000000, array(), self::terms( 'notas', 'notas' ) );
			}
		);
	}

	/**
	 * @return array<string, list<string>>
	 */
	private static function terms( string $category, string $tag ): array {
		return array(
			'category' => array( $category ),
			'post_tag' => array( $tag ),
		);
	}

	private static function origin( Corpus $corpus ): \MagicLinking\Engine\Document {
		return $corpus->source->add(
			20,
			'Novedades del sector',
			array( 'La geotermia sigue creciendo en el sur.', 'El compostaje doméstico gana adeptos cada año.' ),
			'es',
			'post',
			'',
			1790000000,
			array(),
			self::terms( 'energia', 'casa' )
		);
	}

	public function test_require_type_keeps_only_candidates_of_the_same_type(): void {
		$corpus = self::filter_corpus();
		$origin = self::origin( $corpus );

		$free    = $corpus->suggester( array( 'never' => array( 10 ) ), self::loose() )->outgoing( $origin );
		$require = $corpus->suggester(
			array(
				'never'   => array( 10 ),
				'filters' => array( 'type' => 'require' ),
			),
			self::loose()
		)->outgoing( $origin );

		$this->assertNotNull( self::to( $free, 8 ), 'la página sale sin filtro' );
		$this->assertNull( self::to( $require, 8 ), 'con Exigir tipo, una entrada no enlaza a una página' );
		$this->assertNotNull( self::to( $require, 9 ) );
	}

	public function test_require_category_and_consider_tag(): void {
		$corpus = self::filter_corpus();
		$origin = self::origin( $corpus );

		$require = $corpus->suggester(
			array(
				'never'   => array( 10 ),
				'filters' => array( 'category' => 'require' ),
			),
			self::loose()
		)->outgoing( $origin );
		$this->assertNotNull( self::to( $require, 8 ), 'misma categoría' );
		$this->assertNull( self::to( $require, 9 ), 'otra categoría' );

		$scorer   = new Scorer( array(), 0.0, Scorer::DENSITY, 0.0, array( 'affinity' => 0.05 ) );
		$plain    = self::to( $corpus->suggester( array( 'never' => array( 10 ) ), $scorer )->outgoing( $origin ), 9 );
		$consider = self::to(
			$corpus->suggester(
				array(
					'never'   => array( 10 ),
					'filters' => array( 'tag' => 'consider' ),
				),
				$scorer
			)->outgoing( $origin ),
			9
		);
		$this->assertNotNull( $plain );
		$this->assertNotNull( $consider );
		$this->assertEqualsWithDelta( $plain->score->value + 0.05, $consider->score->value, 1e-9 );
	}

	public function test_unknown_filters_and_ignore_do_not_change_anything(): void {
		$corpus = self::filter_corpus();
		$origin = self::origin( $corpus );

		$base  = $corpus->suggester( array( 'never' => array( 10 ) ), self::loose() )->outgoing( $origin );
		$other = $corpus->suggester(
			array(
				'never'   => array( 10 ),
				'filters' => array(
					'type' => 'ignore',
					'nope' => 'require',
				),
			),
			self::loose()
		)->outgoing( $origin );

		$this->assertEquals( $base, $other );
	}

	public function test_overused_anchor_cap_is_configurable(): void {
		$base   = array(
			'relevance'    => 1.0,
			'anchor_kind'  => Phrase::TITLE,
			'anchor_words' => 2,
			'inbound'      => 0,
			'position'     => 0.1,
			'last'         => false,
			'age_days'     => 10,
			'source_links' => 0,
			'source_words' => 1000,
			'anchor_uses'  => 3,
			'unlike'       => false,
		);
		$strict = new Scorer( array(), Scorer::THRESHOLD, Scorer::DENSITY, Scorer::MIN_RELEVANCE, array(), 2 );

		$this->assertSame( array( 'anchor_overused' => 0.1 ), $strict->score( new Candidate( ...$base ) )->penalties );
		$this->assertSame( array(), ( new Scorer() )->score( new Candidate( ...$base ) )->penalties );
	}
}
