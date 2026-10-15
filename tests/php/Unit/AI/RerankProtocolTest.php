<?php
/**
 * Pregunta y respuesta del reordenado con IA (docs/04 §9): JSON válido, inválido, vacío y ancla no literal.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\AI;

use MagicLinking\AI\RerankProtocol;
use PHPUnit\Framework\TestCase;

final class RerankProtocolTest extends TestCase {

	private const SENTENCE = 'La instalación de una bomba de calor reduce el consumo de la vivienda.';

	/**
	 * Tres candidatas con la misma frase (basta para validar la respuesta).
	 *
	 * @return list<array{sentence: string, anchor: string, offset: int, title: string, summary: string}>
	 */
	private function candidates( int $count = 3 ): array {
		$out = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$out[] = array(
				'sentence' => self::SENTENCE,
				'anchor'   => 'bomba de calor',
				'offset'   => (int) strpos( self::SENTENCE, 'bomba de calor' ),
				'title'    => "Destino {$i}",
				'summary'  => "Resumen {$i}",
			);
		}

		return $out;
	}

	// ------------------------------------------------------------------ Parseo.

	public function test_a_valid_answer_gives_one_row_per_candidate(): void {
		$text = '{"results":[{"candidato":1,"apto":true,"confianza":0.9},{"candidato":2,"apto":false,"confianza":0.2},{"candidato":3,"apto":true,"confianza":0.6,"ancla_mejorada":"bomba de calor reduce"}]}';

		$rows = RerankProtocol::parse( $text, $this->candidates() );

		$this->assertNotNull( $rows );
		$this->assertCount( 3, $rows );
		$this->assertTrue( $rows[0]['fit'] );
		$this->assertSame( 0.9, $rows[0]['confidence'] );
		$this->assertFalse( $rows[1]['fit'] );
		$this->assertSame( 'bomba de calor reduce', $rows[2]['improved']['anchor'] ?? null );
	}

	public function test_a_plain_list_a_code_fence_and_chatter_around_the_json_are_tolerated(): void {
		$list = '[{"candidato":1,"apto":true,"confianza":0.5}]';
		$this->assertTrue( RerankProtocol::parse( $list, $this->candidates( 1 ) )[0]['fit'] ?? null );

		$fenced = "Claro:\n```json\n{\"results\":[{\"candidato\":1,\"apto\":false,\"confianza\":0.1}]}\n```\nEspero que sirva.";
		$this->assertFalse( RerankProtocol::parse( $fenced, $this->candidates( 1 ) )[0]['fit'] ?? null );

		$chatter = 'Aquí va: {"results":[{"candidato":1,"apto":true,"confianza":1}]} Fin.';
		$this->assertTrue( RerankProtocol::parse( $chatter, $this->candidates( 1 ) )[0]['fit'] ?? null );
	}

	public function test_empty_text_and_broken_json_are_invalid(): void {
		$this->assertNull( RerankProtocol::parse( '', $this->candidates() ) );
		$this->assertNull( RerankProtocol::parse( "  \n ", $this->candidates() ) );
		$this->assertNull( RerankProtocol::parse( 'no es json', $this->candidates() ) );
		$this->assertNull( RerankProtocol::parse( '{"results":[{"candidato":1,"apto":tr', $this->candidates() ) );
		$this->assertNull( RerankProtocol::parse( '{"otra":"cosa"}', $this->candidates() ), 'JSON válido sin la lista esperada.' );
		$this->assertNull( RerankProtocol::parse( '{"results":[]}', $this->candidates() ), 'Sin ninguna entrada válida no hay nada que usar.' );
		$this->assertNull( RerankProtocol::parse( '"texto"', $this->candidates() ) );
	}

	public function test_invalid_entries_are_skipped_and_do_not_spoil_the_valid_ones(): void {
		$text = self::json(
			array(
				'results' => array(
					array(
						'candidato' => 9,
						'apto'      => true,
						'confianza' => 0.9,
					),
					array(
						'candidato' => 1,
						'apto'      => 'quizá',
						'confianza' => 0.9,
					),
					array(
						'candidato' => '2',
						'apto'      => 'true',
						'confianza' => '0.7',
					),
					array(
						'candidato' => 2,
						'apto'      => false,
						'confianza' => 0.1,
					),
					'basura',
				),
			)
		);

		$rows = RerankProtocol::parse( $text, $this->candidates() );

		$this->assertNotNull( $rows );
		$this->assertNull( $rows[0]['fit'], 'Una entrada sin apto válido no cuenta: la candidata queda sin valorar.' );
		$this->assertTrue( $rows[1]['fit'], 'La primera entrada válida de una candidata gana a las repetidas.' );
		$this->assertSame( 0.7, $rows[1]['confidence'] );
		$this->assertNull( $rows[2]['fit'], 'Una candidata que el modelo no menciona queda sin valorar.' );
	}

	public function test_confidence_is_clamped_and_a_percentage_is_read_as_such(): void {
		$text = '{"results":[{"candidato":1,"apto":true,"confianza":85},{"candidato":2,"apto":true,"confianza":-4},{"candidato":3,"apto":true,"confianza":"x"}]}';

		$rows = RerankProtocol::parse( $text, $this->candidates() );

		$this->assertEqualsWithDelta( 0.85, (float) $rows[0]['confidence'], 1e-9 );
		$this->assertSame( 0.0, $rows[1]['confidence'] );
		$this->assertNull( $rows[2]['confidence'] );
	}

	public function test_a_non_literal_improved_anchor_is_dropped_but_the_rest_of_the_answer_is_kept(): void {
		$text = '{"results":[{"candidato":1,"apto":true,"confianza":0.9,"ancla_mejorada":"bombas de calor eficientes"}]}';

		$rows = RerankProtocol::parse( $text, $this->candidates( 1 ) );

		$this->assertNotNull( $rows );
		$this->assertTrue( $rows[0]['fit'] );
		$this->assertNull( $rows[0]['improved'] );
	}

	public function test_an_improved_anchor_is_ignored_for_a_candidate_that_is_not_fit(): void {
		$text = '{"results":[{"candidato":1,"apto":false,"confianza":0.1,"ancla_mejorada":"bomba de calor reduce"}]}';

		$rows = RerankProtocol::parse( $text, $this->candidates( 1 ) );

		$this->assertNotNull( $rows );
		$this->assertFalse( $rows[0]['fit'] );
		$this->assertNull( $rows[0]['improved'] );
	}

	// ------------------------------------------------------------------ Ancla literal.

	public function test_improved_anchor_must_be_a_literal_span_of_the_sentence(): void {
		$offset = (int) strpos( self::SENTENCE, 'bomba de calor' );

		$ok = RerankProtocol::improved_anchor( self::SENTENCE, 'bomba de calor', $offset, 'una bomba de calor' );
		$this->assertSame(
			array(
				'anchor' => 'una bomba de calor',
				'offset' => (int) strpos( self::SENTENCE, 'una bomba' ),
			),
			$ok
		);

		$this->assertNull( RerankProtocol::improved_anchor( self::SENTENCE, 'bomba de calor', $offset, 'una bomba de calor eficiente' ), 'Texto que no está en la frase.' );
		$this->assertNull( RerankProtocol::improved_anchor( self::SENTENCE, 'bomba de calor', $offset, 'Bomba de calor reduce' ), 'Las mayúsculas cuentan: no es literal.' );
		$this->assertNull( RerankProtocol::improved_anchor( self::SENTENCE, 'bomba de calor', $offset, 'bomba  de calor reduce' ), 'Un espacio de más tampoco es literal.' );
		$this->assertNull( RerankProtocol::improved_anchor( self::SENTENCE, 'bomba de calor', $offset, '' ) );
		$this->assertNull( RerankProtocol::improved_anchor( self::SENTENCE, 'bomba de calor', $offset, '   ' ) );
	}

	public function test_improved_anchor_must_differ_from_the_original_and_overlap_it(): void {
		$offset = (int) strpos( self::SENTENCE, 'bomba de calor' );

		$this->assertNull( RerankProtocol::improved_anchor( self::SENTENCE, 'bomba de calor', $offset, 'bomba de calor' ), 'La misma ancla no es una mejora.' );
		$this->assertNull( RerankProtocol::improved_anchor( self::SENTENCE, 'bomba de calor', $offset, 'consumo de la vivienda' ), 'Otro tramo de la frase es otra sugerencia, no una mejora.' );
		$this->assertNotNull( RerankProtocol::improved_anchor( self::SENTENCE, 'bomba de calor', $offset, 'calor reduce el consumo' ), 'Se solapa con la original.' );
	}

	public function test_improved_anchor_does_not_split_a_word(): void {
		$offset = (int) strpos( self::SENTENCE, 'bomba de calor' );

		$this->assertNull( RerankProtocol::improved_anchor( self::SENTENCE, 'bomba de calor', $offset, 'ba de calor' ), 'Empieza a mitad de palabra.' );
		$this->assertNull( RerankProtocol::improved_anchor( self::SENTENCE, 'bomba de calor', $offset, 'bomba de cal' ), 'Acaba a mitad de palabra.' );
	}

	public function test_improved_anchor_strips_edge_punctuation_and_keeps_accents(): void {
		$sentence = '¿Sabías que la energía solar fotovoltaica ahorra mucho?';
		$offset   = (int) strpos( $sentence, 'energía solar' );

		$result = RerankProtocol::improved_anchor( $sentence, 'energía solar', $offset, '“energía solar fotovoltaica”.' );
		$this->assertSame( 'energía solar fotovoltaica', $result['anchor'] ?? null );
		$this->assertSame( $offset, $result['offset'] ?? null );
		$this->assertNull( RerankProtocol::improved_anchor( $sentence, 'energía solar', $offset, 'energia solar fotovoltaica' ), 'Sin tilde ya no es literal.' );
	}

	public function test_improved_anchor_has_at_most_eight_words_and_needs_letters(): void {
		$sentence = 'uno dos tres cuatro cinco seis siete ocho nueve diez';
		$offset   = (int) strpos( $sentence, 'cinco' );

		$this->assertNotNull( RerankProtocol::improved_anchor( $sentence, 'cinco', $offset, 'uno dos tres cuatro cinco seis siete ocho' ), 'Ocho palabras caben.' );
		$this->assertNull( RerankProtocol::improved_anchor( $sentence, 'cinco', $offset, 'uno dos tres cuatro cinco seis siete ocho nueve' ), 'Nueve ya no.' );

		$this->assertNull( RerankProtocol::improved_anchor( 'Precio 2026 – 2027 ok', '2026', 7, '2026 – 2027' ), 'Sin letras no es un ancla.' );
	}

	public function test_a_repeated_span_takes_the_occurrence_that_overlaps_the_original(): void {
		$sentence = 'el calor llega y con el calor sube la factura del calor';
		$second   = (int) strpos( $sentence, 'calor', 10 );

		$result = RerankProtocol::improved_anchor( $sentence, 'calor', $second, 'el calor' );

		$this->assertSame( (int) strpos( $sentence, 'el calor', 10 ), $result['offset'] ?? null );
	}

	// ------------------------------------------------------------------ Orden.

	public function test_order_puts_fit_by_confidence_then_unrated_then_not_fit(): void {
		$rows = array(
			array(
				'fit'        => false,
				'confidence' => 0.1,
				'improved'   => null,
			),
			array(
				'fit'        => true,
				'confidence' => 0.4,
				'improved'   => null,
			),
			array(
				'fit'        => null,
				'confidence' => null,
				'improved'   => null,
			),
			array(
				'fit'        => true,
				'confidence' => 0.9,
				'improved'   => null,
			),
			array(
				'fit'        => true,
				'confidence' => 0.4,
				'improved'   => null,
			),
			array(
				'fit'        => false,
				'confidence' => 0.8,
				'improved'   => null,
			),
		);

		$this->assertSame( array( 3, 1, 4, 2, 0, 5 ), RerankProtocol::order( $rows ) );
	}

	public function test_order_keeps_the_lexical_order_when_nothing_says_otherwise(): void {
		$rows = array_fill(
			0,
			4,
			array(
				'fit'        => null,
				'confidence' => null,
				'improved'   => null,
			)
		);

		$this->assertSame( array( 0, 1, 2, 3 ), RerankProtocol::order( $rows ) );
	}

	// ------------------------------------------------------------------ Petición.

	public function test_prompt_numbers_the_candidates_from_one_and_marks_the_anchor(): void {
		$prompt = RerankProtocol::prompt( $this->candidates( 2 ), 'es' );

		$this->assertStringContainsString( '<candidate n="1">', $prompt );
		$this->assertStringContainsString( '<candidate n="2">', $prompt );
		$this->assertStringContainsString( 'La instalación de una [[bomba de calor]] reduce el consumo', $prompt );
		$this->assertStringContainsString( 'Target title: Destino 2', $prompt );
		$this->assertStringContainsString( 'Content language: es.', $prompt );
	}

	public function test_prompt_never_carries_tags_or_email_addresses(): void {
		$candidates               = $this->candidates( 1 );
		$candidates[0]['summary'] = 'Escribe a <b>ana@ejemplo.com</b> o a luis.perez@correo.es para más datos.';

		$prompt = RerankProtocol::prompt( $candidates, 'es' );

		$this->assertStringNotContainsString( '@', $prompt );
		$this->assertStringNotContainsString( '<b>', $prompt );
		$this->assertStringContainsString( 'para más datos', $prompt );
	}

	public function test_a_long_sentence_is_cut_around_the_anchor(): void {
		$sentence = str_repeat( 'palabra ', 200 ) . 'bomba de calor ' . str_repeat( 'otra ', 200 );
		$marked   = RerankProtocol::marked( $sentence, (int) strpos( $sentence, 'bomba de calor' ), 'bomba de calor' );

		$this->assertStringContainsString( '[[bomba de calor]]', $marked );
		$this->assertLessThanOrEqual( RerankProtocol::SENTENCE_CHARS + 10, mb_strlen( $marked, 'UTF-8' ) );
		$this->assertStringStartsWith( '…', $marked );
		$this->assertStringEndsWith( '…', $marked );
	}

	public function test_output_budget_is_not_below_what_reasoning_models_need(): void {
		$this->assertGreaterThanOrEqual( 2000, RerankProtocol::MAX_OUTPUT_TOKENS );
		$this->assertSame( 20, RerankProtocol::MAX_CANDIDATES );
	}

	public function test_schema_asks_for_the_fields_of_the_design(): void {
		$item = RerankProtocol::schema()['properties']['results']['items'];

		$this->assertSame( array( 'candidato', 'apto', 'confianza', 'ancla_mejorada' ), array_keys( $item['properties'] ) );
		$this->assertSame( array( 'candidato', 'apto', 'confianza' ), $item['required'], 'El ancla mejorada es opcional.' );
	}

	/**
	 * JSON sin depender de WordPress (las pruebas unitarias se ejecutan sin él).
	 *
	 * @param array<mixed> $data Datos.
	 */
	private static function json( array $data ): string {
		return (string) json_encode( $data, JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Sin WordPress en las pruebas unitarias.
	}
}
