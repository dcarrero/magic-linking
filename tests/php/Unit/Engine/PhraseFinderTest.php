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

	/**
	 * @param list<string> $paragraphs
	 */
	private function source_in( string $language, array $paragraphs ): AnalyzedDocument {
		$source = new ArraySource();
		return Analyzer::for_language( $language )->analyze( $source->add( 1, 'Origen', $paragraphs, $language ), true );
	}

	/**
	 * @param list<string>                $keys
	 * @param array<string, float>|null   $terms Términos principales del destino (null: sin esa comprobación).
	 * @return list<string>
	 */
	private function anchors( AnalyzedDocument $source, string $language, array $keys, ?array $terms = null ): array {
		$matches = $this->finder->find( $source, array( new Phrase( $keys, Phrase::NGRAM ) ), Analyzer::for_language( $language ), array(), array(), 0, $terms );
		return array_map( static fn( $m ): string => $m->anchor, $matches );
	}

	public function test_anchor_is_trimmed_to_its_core_when_it_starts_or_ends_in_a_stopword(): void {
		// La frase de F1-29 con la lista de castellano: las claves del destino ya vienen con palabras vacías en los extremos.
		$source = $this->source( array( 'La instalación de una bomba de calor reduce el consumo de energía en las casas con buen aislamiento.' ) );
		$es     = Analyzer::for_language( 'es' );

		$this->assertSame( array( 'consumo' ), $this->anchors( $source, 'es', explode( ' ', $es->phrase_key( 'el consumo de' ) ), array( $es->phrase_key( 'consumo' ) => 1.0 ) ) );
		$this->assertSame( array( 'energía' ), $this->anchors( $source, 'es', explode( ' ', $es->phrase_key( 'de energía en' ) ), array( $es->phrase_key( 'energía' ) => 1.0 ) ) );
		$this->assertSame( array(), $this->anchors( $source, 'es', explode( ' ', $es->phrase_key( 'de una' ) ) ), 'solo palabras vacías: se descarta' );
	}

	public function test_english_anchor_is_trimmed_to_its_core(): void {
		$source = $this->source_in( 'en', array( 'We compare the price of heat pumps and the cost of energy over ten years.' ) );
		$en     = Analyzer::for_language( 'en' );

		$this->assertSame( array( 'price' ), $this->anchors( $source, 'en', explode( ' ', $en->phrase_key( 'the price of' ) ), array( $en->phrase_key( 'price' ) => 1.0 ) ) );
		$this->assertSame( array( 'energy' ), $this->anchors( $source, 'en', explode( ' ', $en->phrase_key( 'of energy' ) ), array( $en->phrase_key( 'energy' ) => 1.0 ) ) );
		$this->assertSame( array( 'price' ), $this->anchors( $source, 'en', explode( ' ', $en->phrase_key( 'price of' ) ), array( $en->phrase_key( 'price' ) => 1.0 ) ) );
		$this->assertSame( array(), $this->anchors( $source, 'en', explode( ' ', $en->phrase_key( 'of the' ) ) ) );
	}

	public function test_a_trimmed_anchor_is_no_longer_the_whole_phrase(): void {
		$source  = $this->source( array( 'Reduce el consumo de calefacción en casa.' ) );
		$es      = Analyzer::for_language( 'es' );
		$whole   = $this->finder->find( $source, array( new Phrase( explode( ' ', $es->phrase_key( 'consumo de calefacción' ) ), Phrase::TITLE ) ), $es );
		$trimmed = $this->finder->find( $source, array( new Phrase( explode( ' ', $es->phrase_key( 'el consumo de' ) ), Phrase::TITLE ) ), $es );

		$this->assertSame( Phrase::TITLE, $whole[0]->kind );
		$this->assertSame( Phrase::UNIGRAM, $trimmed[0]->kind, 'si se recorta, ya no vale como título' );
	}

	public function test_spanish_text_in_an_english_entry_does_not_leave_spanish_stopwords_at_the_edges(): void {
		// Sitio o entrada declarados en inglés con texto en castellano: «el» y «de» no son vacías para el analizador.
		$source = $this->source_in( 'en', array( 'La instalación de una bomba de calor reduce el consumo de energía en las casas con buen aislamiento.' ) );
		$en     = Analyzer::for_language( 'en' );

		$this->assertSame( array( 'consumo' ), $this->anchors( $source, 'en', explode( ' ', $en->phrase_key( 'el consumo de' ) ), array( $en->phrase_key( 'consumo' ) => 1.0 ) ) );
		$this->assertSame( array( 'casas' ), $this->anchors( $source, 'en', explode( ' ', $en->phrase_key( 'casas con' ) ), array( $en->phrase_key( 'casas' ) => 1.0 ) ) );
	}

	public function test_english_sentence_keeps_words_that_are_spanish_stopwords(): void {
		// «sea» es vacía en castellano, pero la frase es inglesa: no se recorta.
		$source = $this->source_in( 'en', array( 'The rise of the sea level is a risk for the coast.' ) );
		$en     = Analyzer::for_language( 'en' );

		$this->assertSame( array( 'sea level' ), $this->anchors( $source, 'en', explode( ' ', $en->phrase_key( 'sea level' ) ) ) );
	}

	public function test_hyphenated_word_ending_in_a_stopword_is_not_an_edge(): void {
		$source = $this->source( array( 'Vive la salud en Castilla-La Mancha desde hace años.' ) );
		$es     = Analyzer::for_language( 'es' );

		$this->assertSame( array( 'salud' ), $this->anchors( $source, 'es', explode( ' ', $es->phrase_key( 'salud en Castilla-La' ) ), array( $es->phrase_key( 'salud' ) => 1.0 ) ) );
	}

	public function test_a_trimmed_single_word_anchor_must_be_a_main_term_of_the_target(): void {
		$source = $this->source( array( 'La instalación de una bomba de calor reduce el consumo de energía en las casas.' ) );
		$es     = Analyzer::for_language( 'es' );
		$keys   = explode( ' ', $es->phrase_key( 'el consumo de' ) );

		$this->assertSame( array(), $this->anchors( $source, 'es', $keys, array( 'bomb de calor' => 1.0 ) ), '«consumo» no es término principal: se descarta' );
		$this->assertSame( array( 'consumo' ), $this->anchors( $source, 'es', $keys, array( $es->phrase_key( 'consumo' ) => 1.0 ) ) );
		// Un recorte que deja varias palabras no necesita ser término principal.
		$this->assertSame( array( 'consumo de energía' ), $this->anchors( $source, 'es', explode( ' ', $es->phrase_key( 'el consumo de energía en' ) ), array( 'x' => 1.0 ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function compounds(): array {
		$data = array();
		foreach ( array( 'e-commerce', 'e-book', 'e-mail', 'pop-up', 'add-on', 'sign-up', 'plug-in', 'check-in', 'all-in-one', 'up-to-date' ) as $word ) {
			$data[ 'es ' . $word ] = array( 'es', $word );
			$data[ 'en ' . $word ] = array( 'en', $word );
		}
		return $data;
	}

	/**
	 * @dataProvider compounds
	 */
	public function test_hyphenated_compounds_are_kept( string $language, string $word ): void {
		$source = $this->source_in( $language, array( "Montamos un {$word} para el cliente y su tienda." ) );
		$keys   = explode( ' ', Analyzer::for_language( $language )->phrase_key( $word ) );

		$this->assertSame( array( $word ), $this->anchors( $source, $language, $keys ) );
	}

	public function test_a_trimmed_ngram_does_not_replace_a_better_match_on_the_same_span(): void {
		$this->markTestIncomplete( 'Caso conocido, D-52: con el mismo tramo gana la última frase, como en main; revisar con más juicios (la regla de mayor calidad bajó P@5 de 0,937 a 0,931).' );
		$source  = $this->source( array( 'Reduce el consumo de calefacción en casa.' ) );
		$es      = Analyzer::for_language( 'es' );
		$consumo = $es->phrase_key( 'consumo' );
		$phrases = array(
			new Phrase( array( $consumo ), Phrase::TITLE ),
			new Phrase( explode( ' ', $es->phrase_key( 'el consumo de' ) ), Phrase::NGRAM ),
		);
		$matches = $this->finder->find( $source, $phrases, $es, array(), array(), 0, array( $consumo => 1.0 ) );

		$this->assertCount( 1, $matches );
		$this->assertSame( Phrase::TITLE, $matches[0]->kind );
	}

	public function test_languages_without_a_stopword_list_are_left_alone(): void {
		$pt = $this->source_in( 'pt', array( 'O estado de São Paulo cresce muito.' ) );
		$fr = $this->source_in( 'fr', array( 'Le son de la guitare est fort.' ) );

		$this->assertNull( Analyzer::for_language( 'pt' )->foreign_language( $pt->sentences[0] ) );
		$this->assertSame( array( 'estado de São Paulo' ), $this->anchors( $pt, 'pt', explode( ' ', Analyzer::for_language( 'pt' )->phrase_key( 'estado de São Paulo' ) ) ) );
		$this->assertSame( array( 'son de la guitare' ), $this->anchors( $fr, 'fr', explode( ' ', Analyzer::for_language( 'fr' )->phrase_key( 'son de la guitare' ) ) ) );
	}
}
