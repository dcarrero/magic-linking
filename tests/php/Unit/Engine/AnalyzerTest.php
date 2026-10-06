<?php
/**
 * Análisis: términos por campo y frases con enlaces.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\Analyzer;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase {

	public function test_terms_follow_tokenizer_ngrams(): void {
		$analyzer = Analyzer::for_language( 'es' );
		$text     = 'El centro de datos de Madrid, con refrigeración líquida, abre en 2027.';
		$expected = array();
		foreach ( $analyzer->tokenizer()->terms( $text ) as $ngram ) {
			if ( array() === array_filter( $ngram->tokens, array( $analyzer, 'is_content' ) ) ) {
				continue;
			}
			$key              = implode( ' ', $analyzer->keys( $ngram->tokens ) );
			$expected[ $key ] = ( $expected[ $key ] ?? 0 ) + 1;
		}

		$this->assertSame( $expected, $analyzer->terms( $text ) );
		$this->assertArrayHasKey( 'centr de dat', $expected );
		$this->assertArrayNotHasKey( 'madr con', $expected, 'no cruza la coma' );
		$this->assertArrayNotHasKey( '2027', $expected, 'una cifra sola no es término' );
	}

	/**
	 * @dataProvider provide_numeric
	 */
	public function test_numbers_and_units_alone_are_not_terms( string $text ): void {
		$this->assertSame( array(), Analyzer::for_language( 'es' )->terms( $text ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_numeric(): array {
		return array(
			'cifra con unidad'     => array( '1 GW' ),
			'cifra con magnitud'   => array( '100.000 millones' ),
			'fecha'                => array( '25 de septiembre' ),
			'año'                  => array( '2025' ),
			'porcentaje'           => array( '15 %' ),
			'edad'                 => array( '60 años' ),
			'mes y año'            => array( 'septiembre de 2026' ),
			'cifra con moneda'     => array( '30 euros' ),
			'decimal con unidades' => array( '3,5 kWh' ),
		);
	}

	public function test_numbers_inside_a_content_ngram_are_kept(): void {
		$terms = Analyzer::for_language( 'es' )->terms( 'Un centro de datos de 1 GW con chips 5G.' );

		$this->assertArrayHasKey( 'centr de dat', $terms );
		$this->assertArrayHasKey( 'dat de 1', $terms, 'una palabra de contenido basta' );
		$this->assertArrayHasKey( '5g', $terms, 'alfanumérico cuenta como contenido' );
		$this->assertArrayNotHasKey( '1 gw', $terms );
		$this->assertArrayNotHasKey( '1', $terms );
	}

	/**
	 * @dataProvider provide_verbs
	 */
	public function test_verb_like( string $lang, string $word, ?string $next, bool $expected ): void {
		$analyzer = Analyzer::for_language( $lang );
		$tokens   = $analyzer->tokenizer()->tokenize( $word . ( null === $next ? '' : ' ' . $next ) );

		$this->assertSame( $expected, $analyzer->is_verb_like( $tokens[0], $tokens[1] ?? null ) );
	}

	/**
	 * @return array<string, array{string, string, ?string, bool}>
	 */
	public static function provide_verbs(): array {
		return array(
			'modal'                       => array( 'es', 'podría', null, true ),
			'modal sin tilde'             => array( 'es', 'podria', null, true ),
			'condicional plural'          => array( 'es', 'comprarían', null, true ),
			'pretérito'                   => array( 'es', 'recibió', null, true ),
			'pretérito plural'            => array( 'es', 'lanzaron', null, true ),
			'participio + vacía'          => array( 'es', 'reafirmado', 'el', true ),
			'participio sin vacía'        => array( 'es', 'mercado', 'central', false ),
			'sustantivo en -ía'           => array( 'es', 'batería', null, false ),
			'sustantivo en -ción'         => array( 'es', 'refrigeración', null, false ),
			'sustantivo'                  => array( 'es', 'aerotermia', null, false ),
			'inglés modal'                => array( 'en', 'could', null, true ),
			'inglés sustantivo'           => array( 'en', 'packaging', null, false ),
			'sufijo castellano en inglés' => array( 'en', 'studio', null, false ),
		);
	}

	public function test_keys_ignore_accents_and_stem(): void {
		$analyzer = Analyzer::for_language( 'es' );

		$this->assertSame( $analyzer->phrase_key( 'Bombas de Calor' ), $analyzer->phrase_key( 'bomba de calor' ) );
		$this->assertSame( $analyzer->phrase_key( 'aerotérmica' ), $analyzer->phrase_key( 'aerotermica' ) );
	}

	public function test_fields_length_and_sentences(): void {
		$source   = new ArraySource();
		$document = $source->add( 1, 'Suelo radiante', array( 'El suelo radiante calienta. Ver la [[bomba de calor|2]] aquí.' ), 'es', 'post', '', 0, array( 'Ventajas del suelo' ) );
		$analyzed = Analyzer::for_language( 'es' )->analyze( $document, true );

		$this->assertSame( 1, $analyzed->counts[ Analyzer::TITLE ]['suel radiant'] );
		$this->assertSame( 1, $analyzed->counts[ Analyzer::HEADING ]['ventaj'] );
		$this->assertSame( 1, $analyzed->counts[ Analyzer::BODY ]['bomb de calor'] );
		$this->assertCount( 2, $analyzed->sentences );
		$this->assertFalse( $analyzed->sentences[0]->has_link );
		$this->assertTrue( $analyzed->sentences[1]->has_link );
		$this->assertSame( 'bomba de calor', $analyzed->surfaces['bomb de calor'] );
		$this->assertSame( 10, $analyzed->words );
	}
}
