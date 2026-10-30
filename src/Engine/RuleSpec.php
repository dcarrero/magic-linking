<?php
/**
 * Lo que el motor necesita de una regla automática.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Frases (variantes) que se enlazan hacia un destino y cómo.
 */
final class RuleSpec {

	/**
	 * Crea la especificación.
	 *
	 * @param string[] $variants     Frases que disparan la regla (cada una es una variante).
	 * @param int      $target_id    Entrada de destino.
	 * @param int      $max_per_post Enlaces como máximo por entrada.
	 * @param bool     $first_only   Solo la primera aparición de cada frase.
	 * @param bool     $gap          Admitir una palabra más entre dos palabras de la frase (docs/04 §4.2).
	 *
	 * @phpstan-param list<string> $variants
	 */
	public function __construct(
		public readonly array $variants,
		public readonly int $target_id,
		public readonly int $max_per_post = 1,
		public readonly bool $first_only = true,
		public readonly bool $gap = false
	) {
	}
}
