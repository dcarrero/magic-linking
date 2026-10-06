<?php
/**
 * Enlace interno que ya existe en una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Destino (si se resuelve a una entrada) y texto del ancla.
 */
final class Link {

	/**
	 * Crea el enlace.
	 *
	 * @param int|null $target ID de la entrada destino, o null si no se resuelve.
	 * @param string   $anchor Texto del ancla tal cual.
	 */
	public function __construct(
		public readonly ?int $target,
		public readonly string $anchor
	) {
	}
}
