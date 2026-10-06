<?php
/**
 * Frase del cuerpo ya tokenizada, donde se buscan anclas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Frase con sus tokens y la clave de raíz de cada uno.
 */
final class Sentence {

	/**
	 * Crea la frase.
	 *
	 * @param string   $text      Texto de la frase (NFC); las posiciones de los tokens son bytes de este texto.
	 * @param int      $paragraph Índice del párrafo en el documento.
	 * @param Token[]  $tokens    Tokens.
	 * @param string[] $keys     Clave de raíz de cada token, en el mismo orden.
	 * @param bool     $has_link  Si la frase ya contiene un enlace (una frase, un enlace como máximo).
	 *
	 * @phpstan-param list<Token> $tokens
	 * @phpstan-param list<string> $keys
	 */
	public function __construct(
		public readonly string $text,
		public readonly int $paragraph,
		public readonly array $tokens,
		public readonly array $keys,
		public readonly bool $has_link
	) {
	}
}
