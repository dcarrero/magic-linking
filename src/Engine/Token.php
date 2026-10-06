<?php
/**
 * Una palabra del texto, en sus tres formas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Palabra con su forma original, su forma normalizada y su clave de comparación.
 */
final class Token {

	/**
	 * Crea el token.
	 *
	 * @param string $surface      Texto tal cual aparece (tildes y mayúsculas incluidas).
	 * @param string $normal       Minúsculas, NFC y apóstrofos unificados; conserva las tildes.
	 * @param string $key          Forma normalizada sin diacríticos salvo la ñ; es la que se compara.
	 * @param int    $offset       Posición en bytes dentro del texto analizado (en NFC).
	 * @param bool   $is_stopword  Si es una palabra vacía del idioma del tokenizador.
	 * @param bool   $break_before Si entre este token y el anterior hay algo más que espacio
	 *                             (puntuación, emoji, URL); los n-gramas no cruzan ese hueco.
	 */
	public function __construct(
		public readonly string $surface,
		public readonly string $normal,
		public readonly string $key,
		public readonly int $offset,
		public readonly bool $is_stopword,
		public readonly bool $break_before
	) {
	}

	/**
	 * Posición en bytes justo después del token.
	 */
	public function end(): int {
		return $this->offset + strlen( $this->surface );
	}
}
