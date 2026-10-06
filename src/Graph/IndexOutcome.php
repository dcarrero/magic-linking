<?php
/**
 * Resultado de indexar una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

/**
 * Qué pasó con una entrada y a qué otras afecta.
 */
final class IndexOutcome {

	public const INDEXED   = 'indexed';
	public const UNCHANGED = 'unchanged';
	public const REMOVED   = 'removed';
	public const SKIPPED   = 'skipped';

	/**
	 * Constructor.
	 *
	 * @param string          $status   Una de las constantes.
	 * @param array<int, int> $affected Entradas cuyos entrantes hay que recalcular.
	 */
	public function __construct(
		public readonly string $status,
		public readonly array $affected = array()
	) {
	}
}
