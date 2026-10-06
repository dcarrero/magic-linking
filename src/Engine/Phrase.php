<?php
/**
 * Frase objetivo de un destino.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Secuencia de claves de raíz que, si aparece en un origen, sirve de ancla.
 */
final class Phrase {

	public const TITLE   = 'title';
	public const FOCUS   = 'focus';
	public const NGRAM   = 'ngram';
	public const UNIGRAM = 'unigram';

	/**
	 * Crea la frase.
	 *
	 * @param string[] $keys Claves de raíz, una por palabra (las vacías internas incluidas).
	 * @param string   $kind Origen: título, frase objetivo, n-grama o unigrama de peso alto.
	 *
	 * @phpstan-param non-empty-list<string> $keys
	 */
	public function __construct(
		public readonly array $keys,
		public readonly string $kind
	) {
	}

	/**
	 * Clave de la frase entera.
	 */
	public function key(): string {
		return implode( ' ', $this->keys );
	}
}
