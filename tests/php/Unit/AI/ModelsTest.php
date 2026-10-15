<?php
/**
 * Modelos de texto preferidos y precios (docs/05 §4 y §5.1).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\AI;

use MagicLinking\AI\Models;
use PHPUnit\Framework\TestCase;

final class ModelsTest extends TestCase {

	public function test_price_matches_by_the_longest_prefix(): void {
		$this->assertSame( array( 0.15, 0.60 ), Models::price( 'gpt-4o-mini' ) );
		$this->assertSame( array( 0.15, 0.60 ), Models::price( 'gpt-4o-mini-2024-07-18' ), 'No se confunde con gpt-4o.' );
		$this->assertSame( array( 2.50, 10.00 ), Models::price( 'gpt-4o-2024-08-06' ) );
		$this->assertSame( array( 0.30, 2.50 ), Models::price( 'models/gemini-2.5-flash' ), 'Se ignora el prefijo de ruta de Google.' );
		$this->assertSame( array( 0.10, 0.40 ), Models::price( 'Gemini-2.5-Flash-Lite' ), 'Mayúsculas no importan.' );
		$this->assertNull( Models::price( 'granite-embedding:278m' ) );
		$this->assertNull( Models::price( '' ) );
	}

	public function test_every_price_has_input_and_output_greater_than_zero(): void {
		foreach ( Models::PRICES as $model => $price ) {
			$this->assertGreaterThan( 0.0, $price[0], $model );
			$this->assertGreaterThan( 0.0, $price[1], $model );
		}
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', Models::PRICES_DATE );
	}

	public function test_pick_returns_the_model_chosen_by_the_user_if_it_exists(): void {
		$available = array( 'gpt-4o', 'gpt-4o-mini', 'gpt-4.1' );

		$this->assertSame( 'gpt-4.1', Models::pick( $available, 'gpt-4.1' ) );
		$this->assertSame( 'gpt-4o-mini', Models::pick( $available, 'ya-no-existe' ), 'Si el elegido ya no está, se vuelve a la preferencia.' );
	}

	public function test_pick_prefers_small_fast_models_in_list_order(): void {
		$this->assertSame( 'gpt-4o-mini-2024-07-18', Models::pick( array( 'gpt-4o', 'gpt-4o-mini-2024-07-18', 'o3' ) ) );
		$this->assertSame( 'models/gemini-2.5-flash', Models::pick( array( 'models/gemini-2.5-pro', 'models/gemini-2.5-flash' ) ) );
		$this->assertSame( 'llama3.2:3b', Models::pick( array( 'granite-embedding:278m', 'llama3.2:3b' ) ) );
		$this->assertSame( 'gpt-4.1-mini', Models::pick( array( 'gpt-4o-mini', 'gpt-4.1-mini' ) ), 'Manda el orden de la lista, no el de llegada.' );
	}

	public function test_pick_falls_back_to_the_cheapest_priced_model_then_to_the_first(): void {
		$this->assertSame( 'gpt-4.1', Models::pick( array( 'gpt-4o', 'gpt-4.1' ) ), 'Fuera de la lista, el más barato de la tabla (10 $ frente a 12,50 $ por millón).' );
		$this->assertSame( 'raro-1', Models::pick( array( 'raro-1', 'raro-2' ) ), 'Sin precios, el primero.' );
		$this->assertNull( Models::pick( array() ) );
	}

	/**
	 * Lista real de «AI Provider for OpenRouter» 1.0.0 (recortada).
	 *
	 * @return list<string>
	 */
	private static function openrouter(): array {
		return array(
			'meta/muse-spark-open',
			'~unbiased/pareto-latest',
			'nvidia/nemotron-3-super:free',
			'openai/gpt-4.1-mini:batch',
			'openai/gpt-4o-mini-2024-07-18',
			'openai/gpt-4.1-mini',
			'anthropic/claude-haiku-4.5',
		);
	}

	public function test_normalize_strips_provider_and_variant_and_claude_dots(): void {
		$this->assertSame( 'gpt-4.1-mini', Models::normalize( 'openai/gpt-4.1-mini' ) );
		$this->assertSame( 'gpt-4.1-mini', Models::normalize( 'openai/gpt-4.1-mini:batch' ) );
		$this->assertSame( 'pareto-latest', Models::normalize( '~unbiased/pareto-latest' ) );
		$this->assertSame( 'claude-haiku-4-5', Models::normalize( 'anthropic/claude-haiku-4.5' ) );
		$this->assertSame( 'gemini-2.5-flash', Models::normalize( 'models/gemini-2.5-flash' ), 'Los puntos de Gemini no cambian.' );
		$this->assertSame( 'llama3.2:3b', Models::normalize( 'llama3.2:3b' ), 'La etiqueta de Ollama no es una variante.' );
	}

	public function test_price_with_openrouter_ids(): void {
		$this->assertSame( array( 0.40, 1.60 ), Models::price( 'openai/gpt-4.1-mini' ) );
		$this->assertSame( array( 0.15, 0.60 ), Models::price( 'openai/gpt-4o-mini-2024-07-18' ) );
		$this->assertSame( array( 1.00, 5.00 ), Models::price( 'anthropic/claude-haiku-4.5' ) );
		$this->assertNull( Models::price( 'openai/gpt-4.1-mini:batch' ), 'Una variante no síncrona no tiene precio de tabla.' );
		$this->assertNull( Models::price( 'nvidia/nemotron-3-super:free' ) );
		$this->assertNull( Models::price( 'meta/muse-spark-open' ) );
	}

	public function test_pick_with_the_openrouter_list(): void {
		$this->assertSame( 'openai/gpt-4.1-mini', Models::pick( self::openrouter() ) );
		$this->assertSame( 'openai/gpt-4.1-mini', Models::pick( array_reverse( self::openrouter() ) ) );
	}

	public function test_pick_never_returns_batch_or_free_variants(): void {
		$this->assertSame( 'openai/gpt-4.1-mini', Models::pick( array( 'openai/gpt-4.1-mini:batch', 'openai/gpt-4.1-mini' ) ) );
		$this->assertNull( Models::pick( array( 'openai/gpt-4.1-mini:batch', 'nvidia/x:free' ) ) );
		$this->assertSame( 'openai/gpt-4o-mini', Models::pick( array( 'openai/gpt-4.1-mini:batch', 'openai/gpt-4o-mini' ) ), 'Sin la sincrónica de 4.1-mini, la siguiente.' );
	}

	public function test_pick_ignores_a_pinned_batch_model_but_keeps_other_pinned_ones(): void {
		$this->assertSame( 'openai/gpt-4.1-mini', Models::pick( self::openrouter(), 'openai/gpt-4.1-mini:batch' ) );
		$this->assertSame( 'anthropic/claude-haiku-4.5', Models::pick( self::openrouter(), 'anthropic/claude-haiku-4.5' ) );
	}

	public function test_pick_falls_back_to_cheapest_priced_with_openrouter_ids(): void {
		$this->assertSame( 'openai/gpt-4.1-nano', Models::pick( array( 'meta/muse-spark-open', 'openai/gpt-4.1', 'openai/gpt-4.1-nano' ) ) );
		$this->assertSame( 'meta/muse-spark-open', Models::pick( array( 'meta/muse-spark-open', 'x/y:batch' ) ), 'Sin precios, el primero utilizable.' );
	}
}
