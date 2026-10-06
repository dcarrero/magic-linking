<?php
/**
 * Resultado de indexar una entrada: lo que se guarda de ella.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Datos, términos principales con su peso y enlaces salientes de una entrada.
 */
final class IndexedDoc {

	/**
	 * Crea el resultado.
	 *
	 * @param DocMeta $meta   Datos de la entrada.
	 * @param array   $terms  Los K términos de más peso, de mayor a menor.
	 * @param array   $fields Campos en los que aparece cada término (bits de Analyzer).
	 * @param array   $links  Enlaces internos salientes: [destino, clave del ancla].
	 *
	 * @phpstan-param array<string, float> $terms
	 * @phpstan-param array<string, int> $fields
	 * @phpstan-param list<array{0: int, 1: string}> $links
	 */
	public function __construct(
		public readonly DocMeta $meta,
		public readonly array $terms,
		public readonly array $fields,
		public readonly array $links
	) {
	}
}
