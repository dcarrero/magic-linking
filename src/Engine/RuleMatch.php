<?php
/**
 * Enlace que una regla pondría en una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Un tramo literal de una frase de la entrada.
 */
final class RuleMatch {

	/**
	 * Crea la aparición.
	 *
	 * @param string $sentence Frase del texto de la entrada.
	 * @param int    $offset   Byte de la frase donde empieza el ancla.
	 * @param string $anchor   Texto del ancla, tal cual está.
	 * @param string $key      Clave de raíz del ancla.
	 */
	public function __construct(
		public readonly string $sentence,
		public readonly int $offset,
		public readonly string $anchor,
		public readonly string $key
	) {
	}

	/**
	 * Texto de la frase antes del ancla.
	 */
	public function before(): string {
		return substr( $this->sentence, 0, $this->offset );
	}

	/**
	 * Texto de la frase después del ancla.
	 */
	public function after(): string {
		return substr( $this->sentence, $this->offset + strlen( $this->anchor ) );
	}
}
