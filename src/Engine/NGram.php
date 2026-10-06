<?php
/**
 * Secuencia de una a tres palabras contiguas de una misma frase.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * N-grama candidato a término del índice y a ancla.
 */
final class NGram {

	/**
	 * Clave de comparación: claves de los tokens separadas por un espacio.
	 *
	 * @var string
	 */
	public readonly string $key;

	/**
	 * Forma normalizada (con tildes) separada por un espacio.
	 *
	 * @var string
	 */
	public readonly string $normal;

	/**
	 * Crea el n-grama.
	 *
	 * @param Token[] $tokens Tokens contiguos, al menos uno.
	 *
	 * @phpstan-param list<Token> $tokens
	 */
	public function __construct( public readonly array $tokens ) {
		$this->key    = implode( ' ', array_map( static fn( Token $t ): string => $t->key, $tokens ) );
		$this->normal = implode( ' ', array_map( static fn( Token $t ): string => $t->normal, $tokens ) );
	}

	/**
	 * Número de palabras.
	 */
	public function size(): int {
		return count( $this->tokens );
	}

	/**
	 * Posición en bytes del primer token dentro del texto analizado.
	 */
	public function offset(): int {
		return $this->tokens[0]->offset;
	}

	/**
	 * Longitud en bytes del tramo original que cubre, espacios internos incluidos.
	 */
	public function length(): int {
		return $this->tokens[ count( $this->tokens ) - 1 ]->end() - $this->offset();
	}
}
