<?php
/**
 * Puntuación de un candidato con su desglose.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Resultado de {@see Scorer::score()}.
 */
final class Score {

	/**
	 * Crea la puntuación.
	 *
	 * @param float $value     Puntuación final.
	 * @param array $signals   Señal → valor normalizado (0–1).
	 * @param array $penalties Penalización → cantidad restada.
	 * @param bool  $passes    Si supera el umbral.
	 * @param array $bonuses   Bonificación → cantidad sumada (`prioridad_destino`, `afinidad`).
	 *
	 * @phpstan-param array<string, float> $signals
	 * @phpstan-param array<string, float> $penalties
	 * @phpstan-param array<string, float> $bonuses
	 */
	public function __construct(
		public readonly float $value,
		public readonly array $signals,
		public readonly array $penalties,
		public readonly bool $passes,
		public readonly array $bonuses = array()
	) {
	}
}
