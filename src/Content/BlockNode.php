<?php
/**
 * Un bloque del documento con su posición en bytes.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

/**
 * Bloque localizado en el contenido guardado: dónde empieza y acaba, qué trozos de HTML son suyos (no de
 * sus hijos) y cuáles son sus hijos. `parse_blocks()` no da posiciones; {@see BlockMap} las calcula.
 */
final class BlockNode {

	/**
	 * Constructor.
	 *
	 * @param string $name     Nombre completo (`core/paragraph`); null en el HTML suelto entre bloques.
	 * @param string $path     Índices desde la raíz separados por puntos (`3.0.1`), los de `parse_blocks()`.
	 * @param int    $start    Primer byte del bloque (su comentario de apertura).
	 * @param int    $end      Byte siguiente al último (tras su comentario de cierre).
	 * @param array  $attrs    Atributos del comentario de apertura.
	 * @param array  $segments Tramos [inicio, fin) de HTML propio, entre sus comentarios y los de sus hijos.
	 * @param array  $children Bloques hijos.
	 * @param bool   $freeform HTML suelto, sin comentario de bloque.
	 *
	 * @phpstan-param array<string, mixed>        $attrs
	 * @phpstan-param list<array{0: int, 1: int}> $segments
	 * @phpstan-param list<BlockNode>             $children
	 */
	public function __construct(
		public readonly ?string $name,
		public readonly string $path,
		public readonly int $start,
		public int $end,
		public readonly array $attrs,
		public array $segments = array(),
		public array $children = array(),
		public readonly bool $freeform = false
	) {
	}
}
