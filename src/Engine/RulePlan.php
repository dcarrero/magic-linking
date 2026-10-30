<?php
/**
 * Qué haría una regla en una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Resultado de {@see RuleMatcher::plan()}: los enlaces que pondría o el motivo por el que no pone ninguno.
 */
final class RulePlan {

	public const SELF           = 'self';
	public const DENSITY        = 'density';
	public const TARGET_LINKED  = 'target_linked';
	public const ANCHOR_OVERUSE = 'anchor_overused';
	public const NO_MATCH       = 'no_match';

	/**
	 * Crea el plan.
	 *
	 * @param RuleMatch[] $matches Enlaces que pondría, en orden de aparición.
	 * @param string|null $skipped Motivo (una de las constantes) si no pone ninguno.
	 *
	 * @phpstan-param list<RuleMatch> $matches
	 */
	public function __construct(
		public readonly array $matches = array(),
		public readonly ?string $skipped = null
	) {
	}
}
