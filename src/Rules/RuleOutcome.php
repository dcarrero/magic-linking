<?php
/**
 * Resultado de aplicar una regla a una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rules;

/**
 * Qué pasó al aplicar una regla: enlaces puestos, los que no se pudieron poner o el motivo por el que no se puso ninguno.
 */
final class RuleOutcome {

	/**
	 * Crea el resultado.
	 *
	 * @param int                                                $post_id  Entrada.
	 * @param int                                                $rule_id  Regla.
	 * @param int                                                $inserted Enlaces puestos.
	 * @param array<int, array{reason: string, message: string}> $failed   Enlaces que no se pudieron poner.
	 * @param string|null                                        $skipped  Motivo por el que no se intentó nada (código de {@see RuleService}).
	 * @param string                                             $batch_id Lote del Historial ('' si no se escribió nada).
	 *
	 * @phpstan-param list<array{reason: string, message: string}> $failed
	 */
	public function __construct(
		public readonly int $post_id,
		public readonly int $rule_id,
		public readonly int $inserted = 0,
		public readonly array $failed = array(),
		public readonly ?string $skipped = null,
		public readonly string $batch_id = ''
	) {
	}
}
