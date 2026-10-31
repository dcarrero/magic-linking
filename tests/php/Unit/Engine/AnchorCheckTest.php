<?php
/**
 * Validación de un ancla ajustada por el usuario (docs/04 §5.5, F1-22).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\Analyzer;
use MagicLinking\Engine\AnchorCheck;
use PHPUnit\Framework\TestCase;

final class AnchorCheckTest extends TestCase {

	private const SENTENCE = 'La instalación de una bomba de calor aerotérmica reduce el consumo de energía en las casas.';

	private function check( string $anchor, ?string $sentence = null, ?int $offset = null ): AnchorCheck {
		$sentence ??= self::SENTENCE;
		$offset   ??= (int) strpos( $sentence, $anchor );

		return AnchorCheck::of( Analyzer::for_language( 'es' ), $sentence, $offset, $anchor );
	}

	public function test_a_literal_span_of_content_words_is_valid(): void {
		$result = $this->check( 'bomba de calor aerotérmica' );

		$this->assertSame( array(), $result->errors );
		$this->assertSame( array(), $result->warnings );
		$this->assertTrue( $result->valid() );
	}

	public function test_it_accepts_accents_and_multibyte_offsets(): void {
		$sentence = 'Ñoño compró una bomba de calor en Málaga para su vivienda.';

		$result = $this->check( 'bomba de calor', $sentence );

		$this->assertTrue( $result->valid() );
	}

	public function test_an_anchor_that_is_not_where_the_offset_says_is_not_literal(): void {
		$result = $this->check( 'bomba de calor', null, 3 );

		$this->assertSame( array( AnchorCheck::NOT_LITERAL ), $result->errors );
	}

	public function test_an_anchor_that_is_not_in_the_sentence_is_not_literal(): void {
		$this->assertSame( array( AnchorCheck::NOT_LITERAL ), $this->check( 'bomba de frío', null, 0 )->errors );
		$this->assertSame( array( AnchorCheck::NOT_LITERAL ), $this->check( '', null, 0 )->errors );
		$this->assertSame( array( AnchorCheck::NOT_LITERAL ), $this->check( 'casas', null, 9999 )->errors );
		$this->assertSame( array( AnchorCheck::NOT_LITERAL ), $this->check( 'casas', null, -1 )->errors );
	}

	public function test_the_anchor_has_to_start_and_end_at_a_word_boundary(): void {
		$sentence = 'La instalación de la calefacción eléctrica sale cara en invierno.';

		foreach ( array( 'lefacción elé', 'calefacción elé', 'alefacción eléctrica', 'instalación de la calefacció' ) as $anchor ) {
			$result = $this->check( $anchor, $sentence );
			$this->assertSame( array( AnchorCheck::NOT_WORD_BOUNDARY ), $result->errors, $anchor );
		}

		// Un tramo que empieza y acaba en límite de palabra, con tildes y eñes, vale.
		$this->assertTrue( $this->check( 'calefacción eléctrica', $sentence )->valid() );
		$this->assertTrue( AnchorCheck::boundaries( 'Ñoño bebe café caliente.', 7, 'bebe café' ) );
		$this->assertFalse( AnchorCheck::boundaries( 'Ñoño bebe café caliente.', 8, 'ebe café' ) );
		// Un desplazamiento que cae dentro de un carácter de varios bytes no es un tramo válido.
		$this->assertFalse( AnchorCheck::boundaries( 'Ñoño bebe', 1, "\xB1o\xC3\xB1o" ) );
	}

	/**
	 * Los mismos casos que `wordsOf` en `variants.test.ts`: marcas combinantes (NFD), hindi y apóstrofos del francés.
	 *
	 * @return array<string, array{string, string, bool}> Frase, ancla y si empieza y acaba en límite de palabra.
	 */
	public static function unicode_words(): array {
		return array(
			'NFD entero'               => array( "Un cafe\u{0301} caliente sirve.", "cafe\u{0301} caliente", true ),
			'NFD corta la marca'       => array( "Un cafe\u{0301} caliente sirve.", "e\u{0301} caliente", false ),
			'NFD acaba en la marca'    => array( "Un cafe\u{0301} caliente sirve.", 'Un cafe', false ),
			'hindi entero'             => array( 'यह नमस्ते दुनिया है', 'नमस्ते दुनिया', true ),
			'hindi corta la sílaba'    => array( 'यह नमस्ते दुनिया है', 'ते दुनिया', false ),
			'francés tras apóstrofo'   => array( "Il aime l'école publique.", 'école publique', true ),
			'francés con apóstrofo'    => array( "Il aime l'école publique.", "l'école publique", true ),
			'francés corta la palabra' => array( "Il aime l'école publique.", 'cole publique', false ),
		);
	}

	/**
	 * @dataProvider unicode_words
	 *
	 * @param string $sentence Frase.
	 * @param string $anchor   Ancla.
	 * @param bool   $expected Si cae en límites de palabra.
	 */
	public function test_word_boundaries_in_unicode( string $sentence, string $anchor, bool $expected ): void {
		$offset = (int) strpos( $sentence, $anchor );

		$this->assertSame( $expected, AnchorCheck::boundaries( $sentence, $offset, $anchor ) );
		$this->assertSame( ! $expected, AnchorCheck::cuts_word( $sentence, $offset, $anchor ) );
	}

	public function test_cuts_word_only_says_yes_for_a_literal_span_that_cuts_a_word(): void {
		$sentence = 'La calefacción eléctrica sale cara.';

		$this->assertTrue( AnchorCheck::cuts_word( $sentence, (int) strpos( $sentence, 'lefacción' ), 'lefacción' ) );
		$this->assertFalse( AnchorCheck::cuts_word( $sentence, (int) strpos( $sentence, 'calefacción' ), 'calefacción' ) );
		// No literal: no es cosa de esta comprobación.
		$this->assertFalse( AnchorCheck::cuts_word( $sentence, 0, 'otra cosa' ) );
		$this->assertFalse( AnchorCheck::cuts_word( $sentence, -1, 'La' ) );
		$this->assertFalse( AnchorCheck::cuts_word( $sentence, 0, '' ) );
	}

	public function test_the_anchor_cannot_start_or_end_with_a_space(): void {
		$this->assertContains( AnchorCheck::SPACES, $this->check( ' bomba de calor' )->errors );
		$this->assertContains( AnchorCheck::SPACES, $this->check( 'bomba de calor ', null, (int) strpos( self::SENTENCE, 'bomba' ) )->errors );
	}

	/**
	 * @dataProvider rejected
	 */
	public function test_the_discards_of_the_engine_are_errors( string $sentence, string $anchor, string $code ): void {
		$result = $this->check( $anchor, $sentence );

		$this->assertContains( $code, $result->errors );
		$this->assertFalse( $result->valid() );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function rejected(): array {
		return array(
			'una letra'                  => array( 'Una x suelta en el texto del cuarto.', 'x', AnchorCheck::TOO_SHORT ),
			'más de ocho palabras'       => array( 'Hablamos de la bomba de calor aerotérmica para casas pequeñas en zonas frías.', 'bomba de calor aerotérmica para casas pequeñas en zonas', AnchorCheck::TOO_MANY_WORDS ),
			'solo palabras vacías'       => array( 'Hablamos de que esto es para el que sea.', 'para el que', AnchorCheck::NO_CONTENT ),
			'empieza por palabra vacía'  => array( 'Instalamos la bomba de calor ayer mismo.', 'la bomba de calor', AnchorCheck::STARTS_WITH_FILLER ),
			'acaba en unidad o magnitud' => array( 'Gastamos energía solar por 5 millones de euros.', 'energía solar por 5 millones', AnchorCheck::ENDS_WITH_UNIT ),
		);
	}

	public function test_spanish_filler_words_count_in_a_site_declared_in_english(): void {
		// Como el motor: en un sitio en inglés con texto en castellano, «una» y «de» son palabras vacías.
		$sentence = 'La instalación de una bomba de calor eficiente reduce el consumo de energía en casa.';
		$english  = Analyzer::for_language( 'en' );

		$starts = AnchorCheck::of( $english, $sentence, (int) strpos( $sentence, 'una bomba' ), 'una bomba de calor' );
		$this->assertContains( AnchorCheck::STARTS_WITH_FILLER, $starts->errors );

		$ends = AnchorCheck::of( $english, $sentence, (int) strpos( $sentence, 'bomba' ), 'bomba de calor eficiente reduce el' );
		$this->assertContains( AnchorCheck::ENDS_WITH_FILLER, $ends->warnings );

		$ok = AnchorCheck::of( $english, $sentence, (int) strpos( $sentence, 'bomba' ), 'bomba de calor eficiente' );
		$this->assertTrue( $ok->valid() );
		$this->assertSame( array(), $ok->warnings );
	}

	public function test_it_may_end_with_a_number(): void {
		$result = $this->check( 'iPhone 15', 'Compré un iPhone 15 en la tienda del barrio.' );

		$this->assertTrue( $result->valid() );
	}

	/**
	 * @dataProvider doubtful
	 */
	public function test_verbs_and_generic_anchors_are_only_a_warning( string $sentence, string $anchor, string $code ): void {
		$result = $this->check( $anchor, $sentence );

		$this->assertSame( array(), $result->errors, 'El usuario manda: no se rechaza.' );
		$this->assertContains( $code, $result->warnings );
		$this->assertTrue( $result->valid() );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function doubtful(): array {
		return array(
			'empieza por un verbo'   => array( 'Una bomba de calor podría ahorrar bastante energía.', 'podría ahorrar bastante energía', AnchorCheck::STARTS_WITH_VERB ),
			'acaba en un verbo'      => array( 'Una bomba de calor eficiente podría ahorrar.', 'bomba de calor eficiente podría', AnchorCheck::ENDS_WITH_VERB ),
			'es genérica'            => array( 'Consulta el artículo completo para ver el detalle.', 'artículo', AnchorCheck::GENERIC ),
			'acaba en palabra vacía' => array( 'Una bomba de calor para casas pequeñas funciona bien.', 'bomba de', AnchorCheck::ENDS_WITH_FILLER ),
		);
	}
}
