<?php
/**
 * Sugerencia de enlace: ancla en el origen → destino.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Resultado del motor, con su puntuación, su desglose y sus motivos.
 */
final class Suggestion {

	/**
	 * Crea la sugerencia.
	 *
	 * @param int      $source    ID del origen.
	 * @param int      $target    ID del destino.
	 * @param string   $anchor    Texto del ancla, literal del origen.
	 * @param string   $sentence  Frase del origen que contiene el ancla.
	 * @param int      $offset    Inicio del ancla en bytes dentro de la frase.
	 * @param int      $paragraph Índice del párrafo del origen.
	 * @param Score    $score     Puntuación.
	 * @param Reason[] $reasons   Motivos.
	 * @param array    $alternatives Frases alternativas del mismo destino, sin motivos ni más alternativas.
	 *
	 * @phpstan-param list<Reason> $reasons
	 * @phpstan-param list<Suggestion> $alternatives
	 */
	public function __construct(
		public readonly int $source,
		public readonly int $target,
		public readonly string $anchor,
		public readonly string $sentence,
		public readonly int $offset,
		public readonly int $paragraph,
		public readonly Score $score,
		public readonly array $reasons,
		public readonly array $alternatives = array()
	) {
	}
}
