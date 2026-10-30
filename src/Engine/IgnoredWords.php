<?php
/**
 * Palabras y frases ignoradas (Ajustes) dentro de un ancla.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Una sola regla para sugerencias y reglas automáticas: un ancla que contiene una palabra o frase ignorada como
 * palabra completa, sin distinguir mayúsculas, no se enlaza.
 */
final class IgnoredWords {

	/**
	 * Expresiones regulares de las palabras o frases ignoradas (se construyen una vez y se reutilizan).
	 *
	 * @param string[] $words Palabras o frases.
	 *
	 * @return list<string>
	 */
	public static function patterns( array $words ): array {
		return array_values( array_map( static fn( string $word ): string => '/(?<![\p{L}\p{N}])' . preg_quote( $word, '/' ) . '(?![\p{L}\p{N}])/iu', $words ) );
	}

	/**
	 * Si el texto contiene alguna de las palabras o frases.
	 *
	 * @param string[] $patterns Salida de {@see self::patterns()}.
	 * @param string   $text     Ancla.
	 */
	public static function matches( array $patterns, string $text ): bool {
		foreach ( $patterns as $pattern ) {
			if ( 1 === preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		return false;
	}
}
