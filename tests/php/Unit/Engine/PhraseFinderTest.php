<?php
/**
 * Frases objetivo y anclas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\AnalyzedDocument;
use MagicLinking\Engine\Analyzer;
use MagicLinking\Engine\DocMeta;
use MagicLinking\Engine\Phrase;
use MagicLinking\Engine\PhraseFinder;
use PHPUnit\Framework\TestCase;

final class PhraseFinderTest extends TestCase {

	private Analyzer $analyzer;

	private PhraseFinder $finder;

	protected function setUp(): void {
		$this->analyzer = Analyzer::for_language( 'es' );
		$this->finder   = new PhraseFinder();
	}

	private function meta( string $title ): DocMeta {
		return new DocMeta( 9, 'post', 'es', $title, 'destino', 0, 100, 50, 0 );
	}

	/**
	 * @param list<string> $paragraphs
	 */
	private function source( array $paragraphs ): AnalyzedDocument {
		$source = new ArraySource();
		return $this->analyzer->analyze( $source->add( 1, 'Origen', $paragraphs ), true );
	}

	/**
	 * @param list<Phrase> $phrases
	 * @return array<string, string> clave → tipo
	 */
	private static function kinds( array $phrases ): array {
		$kinds = array();
		foreach ( $phrases as $phrase ) {
			$kinds[ $phrase->key() ] = $phrase->kind;
		}
		return $kinds;
	}

	public function test_title_segments_without_edge_stopwords(): void {
		$phrases = $this->finder->target_phrases( $this->meta( 'Aerotermia: qué es y cómo funciona' ), array( 'aerotermi' => 5.0 ), $this->analyzer );
		$kinds   = self::kinds( $phrases );

		$this->assertSame( Phrase::TITLE, $kinds['aerotermi'] );
		// «qué es y cómo funciona» queda en «funciona», que no es término principal.
		$this->assertArrayNotHasKey( 'func', $kinds );
		$this->assertCount( 1, $kinds );
	}

	public function test_single_word_title_segment_needs_to_be_a_main_term(): void {
		$kinds = self::kinds( $this->finder->target_phrases( $this->meta( 'Guía: aerotermia en casa' ), array( 'aerotermi' => 5.0 ), $this->analyzer ) );

		$this->assertArrayNotHasKey( 'gui', $kinds );
		$this->assertSame( Phrase::TITLE, $kinds['aerotermi en cas'] );
	}

	public function test_long_title_uses_its_ngrams(): void {
		$title = 'Todo lo que debes saber antes de instalar una bomba de calor en una vivienda antigua';
		$kinds = self::kinds(
			$this->finder->target_phrases(
				$this->meta( $title ),
				array(
					'bomb de calor' => 3.0,
					'viviend'       => 1.0,
				),
				$this->analyzer
			)
		);

		$this->assertArrayNotHasKey( $this->analyzer->phrase_key( 'debes saber antes de instalar una bomba de calor en una vivienda antigua' ), $kinds );
		$this->assertSame( Phrase::NGRAM, $kinds['bomb de calor'] );
		$this->assertSame( Phrase::UNIGRAM, $kinds['viviend'] );
	}

	public function test_anchor_is_the_literal_text(): void {
		$source  = $this->source( array( 'Instalamos una Bomba de Calor aerotérmica en casa.' ) );
		$phrases = array( new Phrase( array( 'bomb', 'de', 'calor' ), Phrase::NGRAM ) );
		$matches = $this->finder->find( $source, $phrases, $this->analyzer );

		$this->assertCount( 1, $matches );
		$this->assertSame( 'Bomba de Calor', $matches[0]->anchor );
		$this->assertSame( 3, $matches[0]->words );
		$this->assertSame( strpos( 'Instalamos una Bomba de Calor aerotérmica en casa.', 'Bomba' ), $matches[0]->offset );
	}

	public function test_anchor_expands_with_a_target_word(): void {
		$source  = $this->source( array( 'Instalamos una bomba de calor aerotérmica en casa.' ) );
		$phrases = array( new Phrase( array( 'bomb', 'de', 'calor' ), Phrase::NGRAM ) );
		$matches = $this->finder->find( $source, $phrases, $this->analyzer, array( $this->analyzer->phrase_key( 'aerotérmica' ) => true ) );

		$this->assertSame( 'bomba de calor aerotérmica', $matches[0]->anchor );
		$this->assertSame( 4, $matches[0]->words );
	}

	public function test_anchor_does_not_expand_to_the_left(): void {
		$source  = $this->source( array( 'Una nueva bomba de calor en casa.' ) );
		$matches = $this->finder->find( $source, array( new Phrase( array( 'bomb', 'de', 'calor' ), Phrase::NGRAM ) ), $this->analyzer, array( $this->analyzer->phrase_key( 'nueva' ) => true ) );

		$this->assertSame( 'bomba de calor', $matches[0]->anchor );
	}

	public function test_no_anchor_in_a_sentence_that_already_has_a_link(): void {
		$source  = $this->source( array( 'La [[aerotermia|3]] usa una bomba de calor. Otra bomba de calor distinta.' ) );
		$matches = $this->finder->find( $source, array( new Phrase( array( 'bomb', 'de', 'calor' ), Phrase::NGRAM ) ), $this->analyzer );

		$this->assertCount( 1, $matches );
		$this->assertSame( 1, $matches[0]->sentence );
	}

	public function test_anchor_already_used_for_another_target_is_blocked(): void {
		$source  = $this->source( array( 'Hablamos de la bomba de calor.' ) );
		$phrases = array( new Phrase( array( 'bomb', 'de', 'calor' ), Phrase::NGRAM ) );

		$this->assertSame( array(), $this->finder->find( $source, $phrases, $this->analyzer, array(), array( 'bomb de calor' => 5 ), 9 ) );
		$this->assertCount( 1, $this->finder->find( $source, $phrases, $this->analyzer, array(), array( 'bomb de calor' => 9 ), 9 ) );
	}

	public function test_generic_and_too_long_anchors_are_rejected(): void {
		$source = $this->source( array( 'Puedes ver más información aquí sobre la x.' ) );

		$this->assertSame( array(), $this->finder->find( $source, array( new Phrase( array( 'aqu' ), Phrase::UNIGRAM ) ), $this->analyzer ) );
		$this->assertSame( array(), $this->finder->find( $source, array( new Phrase( explode( ' ', $this->analyzer->phrase_key( 'más información' ) ), Phrase::NGRAM ) ), $this->analyzer ) );
		$this->assertSame( array(), $this->finder->find( $source, array( new Phrase( array( 'x' ), Phrase::UNIGRAM ) ), $this->analyzer ), 'menos de 2 caracteres útiles' );

		$long   = 'uno dos tres cuatro cinco seis siete ocho nueve';
		$source = $this->source( array( $long . '.' ) );
		$short  = new PhraseFinder( 3 );
		$this->assertSame( array(), $short->find( $source, array( new Phrase( explode( ' ', $this->analyzer->phrase_key( 'uno dos tres cuatro' ) ), Phrase::NGRAM ) ), $this->analyzer ) );
	}

	public function test_number_only_anchor_is_rejected(): void {
		$source = $this->source( array( 'Anthropic quiere controlar 1 GW en un centro de datos de 1 GW de potencia.' ) );

		$this->assertSame( array(), $this->finder->find( $source, array( new Phrase( array( '1', 'gw' ), Phrase::NGRAM ) ), $this->analyzer ) );
		$this->assertCount( 1, $this->finder->find( $source, array( new Phrase( explode( ' ', $this->analyzer->phrase_key( 'datos de 1' ) ), Phrase::NGRAM ) ), $this->analyzer ) );
	}

	public function test_anchor_edges_with_numbers(): void {
		$source = $this->source( array( 'Tiene 5 GW de capacidad y usa el iPhone 15 en capacidad GW.' ) );
		$find   = fn( string $text ): array => $this->finder->find( $source, array( new Phrase( explode( ' ', $this->analyzer->phrase_key( $text ) ), Phrase::NGRAM ) ), $this->analyzer );

		$this->assertSame( array(), $find( 'GW de capacidad' ), 'no empieza por unidad' );
		$this->assertSame( array(), $find( 'capacidad GW' ), 'no acaba en unidad' );
		$this->assertSame( 'iPhone 15', $find( 'iPhone 15' )[0]->anchor ?? null, 'sí acaba en cifra' );
	}

	public function test_title_segment_without_content_is_not_a_phrase(): void {
		$kinds = self::kinds( $this->finder->target_phrases( $this->meta( 'Anthropic, 1 GW' ), array( 'anthrop' => 2.0 ), $this->analyzer ) );

		$this->assertArrayHasKey( 'anthrop', $kinds );
		$this->assertArrayNotHasKey( '1 gw', $kinds );
	}

	public function test_anchor_ending_in_a_verb_gives_way_to_an_alternative(): void {
		$source  = $this->source( array( 'Dice que Anthropic podría comprar memoria. Anthropic compra memoria HBM.' ) );
		$phrases = array(
			new Phrase( explode( ' ', $this->analyzer->phrase_key( 'Anthropic podría' ) ), Phrase::NGRAM ),
			new Phrase( explode( ' ', $this->analyzer->phrase_key( 'memoria HBM' ) ), Phrase::NGRAM ),
		);
		$matches = $this->finder->find( $source, $phrases, $this->analyzer );

		$this->assertSame( array( 'memoria HBM' ), array_map( static fn( $m ): string => $m->anchor, $matches ) );
	}

	public function test_anchor_starting_with_a_participle_phrase_is_rejected(): void {
		$source = $this->source( array( 'La alcaldesa ha reafirmado el apoyo municipal.' ) );

		$this->assertSame( array(), $this->finder->find( $source, array( new Phrase( explode( ' ', $this->analyzer->phrase_key( 'reafirmado el apoyo' ) ), Phrase::NGRAM ) ), $this->analyzer ) );
	}

	public function test_phrase_does_not_cross_punctuation(): void {
		$source = $this->source( array( 'Compramos la bomba, de calor hablamos luego.' ) );

		$this->assertSame( array(), $this->finder->find( $source, array( new Phrase( array( 'bomb', 'de', 'calor' ), Phrase::NGRAM ) ), $this->analyzer ) );
	}

	public function test_position_and_last_paragraph(): void {
		$source  = $this->source( array( 'Primero la bomba de calor.', 'Relleno sin nada.', 'Al final otra bomba de calor.' ) );
		$matches = $this->finder->find( $source, array( new Phrase( array( 'bomb', 'de', 'calor' ), Phrase::NGRAM ) ), $this->analyzer );

		$this->assertCount( 2, $matches );
		$this->assertSame( 0.0, $matches[0]->position );
		$this->assertFalse( $matches[0]->last );
		$this->assertTrue( $matches[1]->last );
	}
}
