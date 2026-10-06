<?php
/**
 * Un enlace encontrado en el HTML de una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

/**
 * Enlace ya clasificado. Los internos llevan la URL absoluta y normalizada.
 */
final class ParsedLink {

	/**
	 * Constructor.
	 *
	 * @param string $url      URL: absoluta y sin seguimiento si es interno; tal cual en el HTML si es externa.
	 * @param string $anchor   Texto del enlace (o el alt de su imagen), como mucho 255 caracteres.
	 * @param bool   $internal Si apunta al propio sitio.
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $anchor,
		public readonly bool $internal
	) {
	}
}
