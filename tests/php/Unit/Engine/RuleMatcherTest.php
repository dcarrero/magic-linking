<?php
/**
 * Coincidencia de las reglas automáticas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\Document;
use MagicLinking\Engine\RuleLimits;
use MagicLinking\Engine\RuleMatcher;
use MagicLinking\Engine\RulePlan;
use MagicLinking\Engine\RuleSpec;
use PHPUnit\Framework\TestCase;

final class RuleMatcherTest extends TestCase {

	private const TARGET = 9;

	/**
	 * Un texto largo sin ninguna frase de las reglas, para que la densidad no estorbe.
	 */
	private const FILLER = 'Este párrafo habla de otras cosas muy distintas y no contiene nada relevante para ninguna de las pruebas que se hacen aquí con el enlazado de entradas.';

	private RuleMatcher $matcher;

	protected function setUp(): void {
		$this->matcher = new RuleMatcher();
	}

	/**
	 * @param list<string> $paragraphs
	 */
	private function doc( array $paragraphs, int $id = 1, string $lang = 'es' ): Document {
		return ( new ArraySource() )->add( $id, 'Origen', $paragraphs, $lang );
	}

	/**
	 * Un documento largo (unas 250 palabras: caben 2 enlaces internos con la densidad por defecto).
	 *
	 * @param list<string> $paragraphs
	 */
	private function long_doc( array $paragraphs, int $id = 1 ): Document {
		return $this->doc( array_merge( $paragraphs, array_fill( 0, 9, self::FILLER ) ), $id );
	}

	/**
	 * @param list<string> $variants
	 */
	private function plan( Document $doc, array $variants, array $options = array(), ?RuleLimits $limits = null, ?callable $sites = null ): RulePlan {
		$rule = new RuleSpec( $variants, self::TARGET, $options['max'] ?? 1, $options['first'] ?? true, $options['gap'] ?? false );

		return $this->matcher->plan( $doc, $rule, $limits ?? new RuleLimits(), $sites ?? static fn(): int => 0 );
	}

	/**
	 * @return list<string>
	 */
	private static function anchors( RulePlan $plan ): array {
		return array_map( static fn( $m ): string => $m->anchor, $plan->matches );
	}

	public function test_links_the_phrase_once_by_default(): void {
		$plan = $this->plan( $this->doc( array( 'Instalar una bomba de calor ahorra energía. ' . self::FILLER, 'Otra bomba de calor distinta.' ) ), array( 'bomba de calor' ) );

		$this->assertSame( array( 'bomba de calor' ), self::anchors( $plan ) );
		$this->assertNull( $plan->skipped );
		$this->assertStringContainsString( 'ahorra energía', $plan->matches[0]->sentence );
		$this->assertSame( 'bomba de calor', substr( $plan->matches[0]->sentence, $plan->matches[0]->offset, strlen( $plan->matches[0]->anchor ) ) );
	}

	public function test_variants_match_by_stem(): void {
		$plan = $this->plan( $this->doc( array( 'Las bombas de calor modernas son eficientes. ' . self::FILLER ) ), array( 'bomba de calor', 'aerotermia doméstica' ) );

		$this->assertSame( array( 'bombas de calor' ), self::anchors( $plan ) );
	}

	public function test_second_variant_matches(): void {
		$plan = $this->plan( $this->doc( array( 'La aerotermia doméstica reduce la factura. ' . self::FILLER ) ), array( 'bomba de calor', 'aerotermia doméstica' ) );

		$this->assertSame( array( 'aerotermia doméstica' ), self::anchors( $plan ) );
	}

	public function test_gap_of_one_word_only_when_enabled(): void {
		$doc = $this->doc( array( 'Quiero una reforma de la cocina pronto. ' . self::FILLER ) );

		$this->assertSame( array(), self::anchors( $this->plan( $doc, array( 'reforma de cocina' ) ) ) );
		$this->assertSame( array( 'reforma de la cocina' ), self::anchors( $this->plan( $doc, array( 'reforma de cocina' ), array( 'gap' => true ) ) ) );
	}

	public function test_gap_does_not_accept_two_words_nor_punctuation(): void {
		$two   = $this->doc( array( 'Quiero una reforma de toda la cocina pronto. ' . self::FILLER ) );
		$comma = $this->doc( array( 'Quiero una reforma de, la cocina pronto. ' . self::FILLER ) );

		$this->assertSame( array(), self::anchors( $this->plan( $two, array( 'reforma de cocina' ), array( 'gap' => true ) ) ) );
		$this->assertSame( array(), self::anchors( $this->plan( $comma, array( 'reforma de cocina' ), array( 'gap' => true ) ) ) );
	}

	public function test_gap_word_cannot_be_a_verb(): void {
		$this->assertSame( array(), self::anchors( $this->plan( $this->doc( array( 'La energía llegó solar a casa. ' . self::FILLER ) ), array( 'energía solar' ), array( 'gap' => true ) ) ) );
		$this->assertSame( array( 'energía muy solar' ), self::anchors( $this->plan( $this->doc( array( 'La energía muy solar llega a casa. ' . self::FILLER ) ), array( 'energía solar' ), array( 'gap' => true ) ) ) );
	}

	public function test_never_links_inside_an_existing_link(): void {
		$plan = $this->plan( $this->long_doc( array( 'Mira [[la bomba de calor|5]] con calma.' ) ), array( 'bomba de calor' ) );

		$this->assertSame( array(), $plan->matches );
		$this->assertSame( RulePlan::NO_MATCH, $plan->skipped );
	}

	public function test_first_only_keeps_one_per_phrase_and_off_allows_more(): void {
		$doc  = $this->long_doc( array( 'La bomba de calor ahorra.', 'Una bomba de calor más cara.' ) );
		$many = new RuleLimits( 100, 3 );

		$this->assertCount(
			1,
			$this->plan(
				$doc,
				array( 'bomba de calor' ),
				array(
					'max'   => 3,
					'first' => true,
				),
				$many
			)->matches
		);
		$this->assertCount(
			2,
			$this->plan(
				$doc,
				array( 'bomba de calor' ),
				array(
					'max'   => 3,
					'first' => false,
				),
				$many
			)->matches
		);
	}

	public function test_max_per_post_is_capped_by_links_per_target(): void {
		$doc = $this->doc( array( 'La bomba de calor ahorra. ' . self::FILLER, 'Una bomba de calor más cara. ' . self::FILLER ) );

		$this->assertCount(
			1,
			$this->plan(
				$doc,
				array( 'bomba de calor' ),
				array(
					'max'   => 5,
					'first' => false,
				)
			)->matches,
			'Un enlace por destino y entrada por defecto.'
		);
	}

	public function test_density_cap_blocks_when_the_post_has_the_links_its_length_allows(): void {
		// Unas 30 palabras: tope max(1, ⌊30/100⌋) = 1 y ya tiene uno.
		$doc = $this->doc( array( 'Mira [[otra guía|7]] primero. La bomba de calor ahorra mucha energía en invierno y también en verano.' ) );

		$plan = $this->plan( $doc, array( 'bomba de calor' ) );
		$this->assertSame( array(), $plan->matches );
		$this->assertSame( RulePlan::DENSITY, $plan->skipped );
	}

	public function test_density_leaves_room_in_a_long_post(): void {
		$long = array_fill( 0, 12, self::FILLER );
		$doc  = $this->doc( array_merge( array( 'Mira [[otra guía|7]] primero.', 'La bomba de calor ahorra.' ), $long ) );

		$this->assertCount( 1, $this->plan( $doc, array( 'bomba de calor' ) )->matches );
		$this->assertSame( RulePlan::DENSITY, $this->plan( $doc, array( 'bomba de calor' ), array(), new RuleLimits( 2000 ) )->skipped, 'Con una densidad más estricta ya no cabe.' );
	}

	public function test_one_link_per_target_and_post(): void {
		$doc  = $this->long_doc( array( 'Lee [[la guía|' . self::TARGET . ']] antes. La bomba de calor ahorra.' ) );
		$plan = $this->plan( $doc, array( 'bomba de calor' ) );

		$this->assertSame( RulePlan::TARGET_LINKED, $plan->skipped );
		$this->assertSame( array(), $plan->matches );
	}

	public function test_same_anchor_cap_site_wide(): void {
		$doc = $this->doc( array( 'La bomba de calor ahorra. ' . self::FILLER ) );

		$full = $this->plan( $doc, array( 'bomba de calor' ), array(), null, static fn(): int => 5 );
		$this->assertSame( RulePlan::ANCHOR_OVERUSE, $full->skipped );
		$this->assertSame( array(), $full->matches );

		$room = $this->plan( $doc, array( 'bomba de calor' ), array(), null, static fn(): int => 4 );
		$this->assertCount( 1, $room->matches );

		$custom = $this->plan( $doc, array( 'bomba de calor' ), array(), new RuleLimits( 100, 1, 2 ), static fn(): int => 2 );
		$this->assertSame( RulePlan::ANCHOR_OVERUSE, $custom->skipped );
	}

	public function test_anchor_already_used_for_another_target_is_not_repeated(): void {
		$doc = $this->doc( array( 'Antes vimos [[la bomba de calor|5]] de otra casa. Más tarde la bomba de calor ahorra. ' . self::FILLER ) );

		$this->assertSame( array(), $this->plan( $doc, array( 'bomba de calor' ) )->matches );
	}

	public function test_never_links_the_target_to_itself(): void {
		$plan = $this->plan( $this->doc( array( 'La bomba de calor ahorra. ' . self::FILLER ), self::TARGET ), array( 'bomba de calor' ) );

		$this->assertSame( RulePlan::SELF, $plan->skipped );
	}

	public function test_overlapping_variants_do_not_overlap_links(): void {
		$plan = $this->plan(
			$this->doc( array( 'La bomba de calor aerotérmica ahorra. ' . self::FILLER ) ),
			array( 'bomba de calor', 'bomba de calor aerotérmica' ),
			array(
				'max'   => 2,
				'first' => false,
			),
			new RuleLimits( 100, 2 )
		);

		$this->assertCount( 1, $plan->matches );
	}

	public function test_generic_anchors_are_never_linked(): void {
		$this->assertSame( array(), $this->plan( $this->doc( array( 'Pulsa aquí para ver más. ' . self::FILLER ) ), array( 'aquí' ) )->matches );
	}

	public function test_defaults_of_the_limits_are_the_documented_ones(): void {
		$limits = new RuleLimits();

		$this->assertSame( 100, $limits->words_per_link );
		$this->assertSame( 1, $limits->links_per_target );
		$this->assertSame( 5, $limits->same_anchor_max );
		$this->assertSame( 1, $limits->density_cap( 99 ) );
		$this->assertSame( 3, $limits->density_cap( 350 ) );
	}
}
