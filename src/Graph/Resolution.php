<?php
/**
 * Resultado de resolver una URL interna.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

/**
 * A qué entrada apunta una URL interna y, si está rota, por qué.
 */
final class Resolution {

	/**
	 * Constructor.
	 *
	 * @param int|null $target_id ID de la entrada de destino; null si no es una entrada (portada, archivo, categoría…) o no existe.
	 * @param int      $broken    Código de BrokenReason; 0 si funciona.
	 */
	public function __construct(
		public readonly ?int $target_id,
		public readonly int $broken = BrokenReason::NONE
	) {
	}
}
