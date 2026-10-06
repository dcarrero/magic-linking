<?php
/**
 * Aparición de una frase objetivo en el texto de un origen.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Ancla candidata: tramo literal de una frase del origen.
 */
final class AnchorMatch {

	/**
	 * Crea la aparición.
	 *
	 * @param int    $sentence  Índice de la frase en el documento analizado.
	 * @param int    $offset    Inicio del ancla en bytes dentro de la frase.
	 * @param string $anchor    Texto del ancla tal cual (tildes y mayúsculas).
	 * @param string $key       Clave de raíz del ancla.
	 * @param string $kind      Tipo de la frase objetivo que la produjo (Phrase::TITLE…).
	 * @param int    $words     Palabras del ancla.
	 * @param float  $position  Posición relativa de la frase en el texto (0–1).
	 * @param bool   $last      Si está en el último párrafo.
	 */
	public function __construct(
		public readonly int $sentence,
		public readonly int $offset,
		public readonly string $anchor,
		public readonly string $key,
		public readonly string $kind,
		public readonly int $words,
		public readonly float $position,
		public readonly bool $last
	) {
	}
}
