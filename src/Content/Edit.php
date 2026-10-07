<?php
/**
 * Cambio calculado sobre un contenido, todavía sin escribir.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

/**
 * Resultado de {@see BlockEditor} y {@see ClassicEditor}: el contenido nuevo completo, el tramo que ha
 * cambiado en el contenido original y su HTML antes y después (lo que se guarda en el historial).
 */
final class Edit {

	/**
	 * Constructor.
	 *
	 * @param string $content     Contenido completo después del cambio.
	 * @param string $path        Ruta del bloque (`3.0.1`) o, en el editor clásico, `@` y el byte donde empieza el tramo.
	 * @param int    $start       Primer byte del tramo modificado en el contenido original.
	 * @param int    $end         Byte siguiente al último del tramo modificado en el contenido original.
	 * @param string $before_html HTML del tramo antes.
	 * @param string $after_html  HTML del tramo después.
	 */
	public function __construct(
		public readonly string $content,
		public readonly string $path,
		public readonly int $start,
		public readonly int $end,
		public readonly string $before_html,
		public readonly string $after_html
	) {
	}
}
