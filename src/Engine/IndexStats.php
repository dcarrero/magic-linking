<?php
/**
 * Estadísticas del corpus de un idioma para BM25.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * N, longitud media y frecuencia documental de cada término.
 */
final class IndexStats {

	/**
	 * Crea las estadísticas.
	 *
	 * @param int   $count   Entradas del idioma (N).
	 * @param float $average Longitud media (avglen).
	 * @param array $df      Término → nº de entradas que lo contienen. Los que no están valen 0.
	 *
	 * @phpstan-param array<string, int> $df
	 */
	public function __construct(
		public readonly int $count,
		public readonly float $average,
		private array $df
	) {
	}

	/**
	 * Frecuencia documental de un término.
	 *
	 * @param string $term Término.
	 */
	public function df( string $term ): int {
		return $this->df[ $term ] ?? 0;
	}

	/**
	 * IDF de BM25: ln(1 + (N − df + 0,5) / (df + 0,5)).
	 *
	 * @param string $term Término.
	 */
	public function idf( string $term ): float {
		$df = $this->df[ $term ] ?? 0;
		return log( 1 + ( $this->count - $df + 0.5 ) / ( $df + 0.5 ) );
	}
}
