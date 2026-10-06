<?php
/**
 * Señales en bruto de un par (origen, destino, ancla) antes de puntuar.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Entrada de {@see Scorer::score()}.
 */
final class Candidate {

	/**
	 * Crea el candidato.
	 *
	 * @param float  $relevance     Similitud léxica normalizada por el máximo del lote (0–1).
	 * @param string $anchor_kind   Tipo de frase objetivo del ancla (Phrase::TITLE…).
	 * @param int    $anchor_words  Palabras del ancla.
	 * @param int    $inbound       Enlaces entrantes actuales del destino.
	 * @param float  $position      Posición relativa del ancla en el origen (0–1).
	 * @param bool   $last          Si el ancla está en el último párrafo.
	 * @param int    $age_days      Días desde la última fecha conocida del destino.
	 * @param int    $source_links  Enlaces internos que ya tiene el origen.
	 * @param int    $source_words  Palabras del origen.
	 * @param int    $anchor_uses   Entradas que ya enlazan al destino con esa ancla.
	 * @param bool   $unlike        Si el destino es de otro tipo (página legal, contacto…).
	 * @param bool   $pillar        Si el destino es contenido pilar.
	 * @param int    $affinity      Filtros en modo Considerar que comparten origen y destino.
	 */
	public function __construct(
		public readonly float $relevance,
		public readonly string $anchor_kind,
		public readonly int $anchor_words,
		public readonly int $inbound,
		public readonly float $position,
		public readonly bool $last,
		public readonly int $age_days,
		public readonly int $source_links,
		public readonly int $source_words,
		public readonly int $anchor_uses,
		public readonly bool $unlike,
		public readonly bool $pillar = false,
		public readonly int $affinity = 0
	) {
	}
}
