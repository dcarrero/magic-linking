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

	public function test_the_estimate_of_the_real_prompt_is_a_modest_upper_bound_not_a_multiple(): void {
		$candidates = $this->real_candidates();
		$prompt     = RerankProtocol::prompt( $candidates, 'es' );
		$estimate   = Reranker::request_tokens( RerankProtocol::system(), $prompt, 3 );

		// Real (gemma3:4b, Ollama): 678 de entrada + 103 de salida = 781.
		$this->assertGreaterThanOrEqual( 781, $estimate, 'Tiene que ser una cota: no puede quedar por debajo del gasto real.' );
		$this->assertLessThanOrEqual( (int) round( 781 * 1.5 ), $estimate, 'Antes salía 3,2 veces el real por contar el tope de salida.' );
		$this->assertGreaterThanOrEqual( (int) round( 781 * 1.1 ), $estimate, 'Con algo de margen sobre lo real.' );
	}

	public function test_the_button_estimate_and_the_needed_tokens_are_the_same_figure_and_close_to_the_real_one(): void {
		$typical = Reranker::typical_estimate( 4, 'gemma3:4b', true );

		$this->assertSame( $typical['tokens'], Reranker::worst_case_tokens( 4 ) );
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
