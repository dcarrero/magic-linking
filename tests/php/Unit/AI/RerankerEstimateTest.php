<?php
/**
 * Estimación de tokens del reordenado, calibrada con el prompt real (docs/05 §5.1).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\AI;

use MagicLinking\AI\Reranker;
use MagicLinking\AI\RerankProtocol;
use PHPUnit\Framework\TestCase;

final class RerankerEstimateTest extends TestCase {

	/**
	 * Las tres candidatas de la prueba real con gemma3:4b: gastaron 678 tokens de entrada y 103 de salida (781).
	 *
	 * @return list<array{sentence: string, anchor: string, offset: int, title: string, summary: string}>
	 */
	private function real_candidates(): array {
		$rows = array(
			array( 'El pulpo a feira se sirve con aceite, sal gruesa y pimentón en plato de madera.', 'pulpo a feira', 'Receta de pulpo a feira paso a paso', 'Para preparar un buen pulpo a feira hay que congelarlo antes para que quede tierno. Se cuece en una olla de cobre durante unos cuarenta minutos, asustándolo tres veces con agua fría. Después se corta con tijera en rodajas y se aliña con aceite de oliva, pimentón y sal gruesa. Las patatas cocidas, las cachelos, son el acompañamiento tradicional. Es uno de los platos más representativos de la gastronomía gallega.' ),
			array( 'Es habitual acompañarlo con un vino ribeiro en taza de cerámica.', 'vino ribeiro', 'Vino ribeiro y albariño', 'Galicia tiene cinco denominaciones de origen. El albariño de Rías Baixas es un blanco fresco y aromático que acompaña al marisco. El ribeiro, más ligero, se sirve tradicionalmente en cuenco de cerámica con el pulpo. Una visita a una bodega es un buen plan para romper la rutina del camino. Pregunta en tu albergue por las catas cercanas y completa la experiencia de la gastronomía gallega.' ),
			array( 'Otros platos típicos son el caldo gallego, el lacón con grelos y la tarta de Santiago.', 'tarta de Santiago', 'Tarta de Santiago casera', 'La tarta de Santiago lleva solo almendra molida, azúcar y huevos, además de ralladura de limón. Es un postre sin gluten que se decora con la cruz de Santiago en azúcar glas. Se hornea a ciento ochenta grados durante unos cuarenta minutos. Combina muy bien con un café o un licor de hierbas después de una comida copiosa. Si estás de viaje, la puedes comprar en las pastelerías de Santiago de Compostela.' ),
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'sentence' => $row[0],
				'anchor'   => $row[1],
				'offset'   => (int) strpos( $row[0], $row[1] ),
				'title'    => $row[2],
				'summary'  => $row[3],
			);
		}

		return $out;
	}

	public function test_the_bound_of_the_real_prompt_covers_the_real_spend_and_the_typical_estimate_is_close_to_it(): void {
		$candidates = $this->real_candidates();
		$prompt     = RerankProtocol::prompt( $candidates, 'es' );
		$input      = Reranker::request_tokens( RerankProtocol::system(), $prompt ) - RerankProtocol::MAX_OUTPUT_TOKENS;
		$typical    = Reranker::typical_estimate( 3, null, true )['tokens'];

		// Real (gemma3:4b, Ollama): 678 de entrada + 103 de salida = 781.
		$this->assertGreaterThanOrEqual( 678, $input, 'La entrada estimada no queda por debajo de la real.' );
		$this->assertGreaterThanOrEqual( 781, $typical );
		$this->assertLessThanOrEqual( (int) round( 781 * 1.5 ), $typical, 'Antes salía 3,2 veces el real por contar el tope de salida.' );
		$this->assertGreaterThanOrEqual( (int) round( 781 * 1.1 ), $typical );
	}

	/**
	 * Candidatas en el límite de lo que admite el panel: frase larguísima, título y resumen llenos.
	 *
	 * @return list<array{sentence: string, anchor: string, offset: int, title: string, summary: string}>
	 */
	private function maximal_candidates( int $count ): array {
		$out = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$sentence = str_repeat( 'palabra ', 600 ) . 'ancla de prueba ' . str_repeat( 'ñandú ', 300 );
			$out[]    = array(
				'sentence' => $sentence,
				'anchor'   => 'ancla de prueba',
				'offset'   => (int) strpos( $sentence, 'ancla de prueba' ),
				'title'    => str_repeat( 'Título ', 80 ),
				'summary'  => str_repeat( 'resumen ñ ', 200 ),
			);
		}

		return $out;
	}

	public function test_needs_is_an_upper_bound_of_any_valid_request(): void {
		foreach ( array( 1, 2, 4, 10, RerankProtocol::MAX_CANDIDATES ) as $count ) {
			$prompt = RerankProtocol::prompt( $this->maximal_candidates( $count ), 'es' );
			$bound  = Reranker::request_tokens( RerankProtocol::system(), $prompt );

			$this->assertLessThanOrEqual( Reranker::worst_case_tokens( $count ), $bound, "needs cubre una petición de {$count} candidatas llenas." );
			$this->assertGreaterThanOrEqual( Reranker::typical_estimate( $count, null, true )['tokens'], Reranker::worst_case_tokens( $count ), 'La cota nunca baja de la estimación típica.' );
		}
	}

	public function test_the_bound_counts_the_output_cap_that_the_request_really_sends(): void {
		$prompt = RerankProtocol::prompt( $this->real_candidates(), 'es' );
		$input  = \MagicLinking\AI\CostEstimator::tokens( RerankProtocol::system() . "\n" . $prompt, 'es' );

		$this->assertSame( $input + RerankProtocol::MAX_OUTPUT_TOKENS, Reranker::request_tokens( RerankProtocol::system(), $prompt ) );
		$this->assertGreaterThanOrEqual( 2000, RerankProtocol::MAX_OUTPUT_TOKENS, 'Los modelos de razonamiento no bajan de ~2.000 (05 §10).' );
	}

	public function test_the_button_estimate_and_the_needed_tokens_are_the_same_figure_and_close_to_the_real_one(): void {
		$typical = Reranker::typical_estimate( 4, 'gemma3:4b', true );

		$this->assertLessThan( Reranker::worst_case_tokens( 4 ), $typical['tokens'], 'El botón enseña lo típico; el tope diario se compara con la cota.' );
		// Real con 4 candidatas: 846 + 148 = 994.
		$this->assertGreaterThanOrEqual( 994, $typical['tokens'] );
		$this->assertLessThanOrEqual( (int) round( 994 * 1.5 ), $typical['tokens'] );
		$this->assertSame( 0.0, $typical['cost'] );
	}

	public function test_the_estimate_grows_with_the_candidates_and_never_passes_the_output_cap(): void {
		$this->assertLessThan( Reranker::worst_case_tokens( 10 ), Reranker::worst_case_tokens( 4 ) );
		$max = Reranker::typical_estimate( 99, null, true );
		$this->assertLessThanOrEqual( RerankProtocol::MAX_OUTPUT_TOKENS, $max['tokens_out'] );
	}
}
