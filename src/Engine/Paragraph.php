<?php
/**
 * Párrafo de texto limpio del cuerpo de una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Texto plano de un párrafo o elemento de lista, con los tramos que ya son enlace.
 */
final class Paragraph {

	/**
	 * Crea el párrafo.
	 *
	 * @param string $text  Texto plano; un salto de línea interno es un salto de línea del original.
	 * @param array  $links Tramos [inicio, fin) en bytes de $text que ya están dentro de un enlace.
	 *
	 * @phpstan-param list<array{0: int, 1: int}> $links
	 */
	public function __construct(
		public readonly string $text,
		public readonly array $links = array()
	) {
	}

	/**
	 * Si algún enlace existente toca el tramo [inicio, fin).
	 *
	 * @param int $start Inicio en bytes.
	 * @param int $end   Fin en bytes (excluido).
	 */
	public function has_link_in( int $start, int $end ): bool {
		foreach ( $this->links as $span ) {
			if ( $span[0] < $end && $span[1] > $start ) {
				return true;
			}
		}
		return false;
	}
}
