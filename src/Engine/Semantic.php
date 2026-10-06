<?php
/**
 * Capa semántica: coseno y mezcla con la relevancia léxica.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Funciones puras, sin red: los vectores los trae un {@see Embedder}.
 */
final class Semantic {

	/**
	 * Peso del coseno en la relevancia cuando hay vectores:
	 * `relevancia = (1 − peso) · léxica + peso · coseno`, ambas normalizadas en el lote.
	 */
	public const WEIGHT = 0.5;

	/**
	 * Palabras del cuerpo que entran en el texto que se vectoriza.
	 */
	public const TEXT_WORDS = 300;

	/**
	 * Tope de caracteres del texto que se vectoriza (~450 tokens a 4,4
	 * caracteres por token; el modelo de referencia admite 512).
	 */
	public const TEXT_CHARS = 2000;

	/**
	 * Encabezados que entran en el texto que se vectoriza.
	 */
	public const TEXT_HEADINGS = 10;

	/**
	 * Coseno normalizado a partir del cual se da el motivo «muy parecidas por significado».
	 */
	public const REASON_MIN = 0.8;

	/**
	 * Producto escalar (coseno si los dos vectores tienen norma 1).
	 *
	 * @param float[] $a Vector.
	 * @param float[] $b Vector de la misma dimensión.
	 *
	 * @phpstan-param list<float> $a
	 * @phpstan-param list<float> $b
	 */
	public static function dot( array $a, array $b ): float {
		$sum = 0.0;
		$n   = min( count( $a ), count( $b ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$sum += $a[ $i ] * $b[ $i ];
		}
		return $sum;
	}

	/**
	 * Coseno entre dos vectores; 0 si alguno es nulo o las dimensiones no casan.
	 *
	 * @param float[] $a Vector.
	 * @param float[] $b Vector.
	 *
	 * @phpstan-param list<float> $a
	 * @phpstan-param list<float> $b
	 */
	public static function cosine( array $a, array $b ): float {
		if ( count( $a ) !== count( $b ) || array() === $a ) {
			return 0.0;
		}
		$norm = sqrt( self::dot( $a, $a ) ) * sqrt( self::dot( $b, $b ) );
		return $norm > 0.0 ? self::dot( $a, $b ) / $norm : 0.0;
	}

	/**
	 * Vector con norma 1 (el nulo se queda como está).
	 *
	 * @param float[] $vector Vector.
	 * @return list<float>
	 *
	 * @phpstan-param list<float> $vector
	 */
	public static function normalize( array $vector ): array {
		$norm = sqrt( self::dot( $vector, $vector ) );
		if ( $norm <= 0.0 ) {
			return array_map( 'floatval', $vector );
		}
		return array_map( static fn( $x ): float => (float) $x / $norm, $vector );
	}

	/**
	 * Reescala los cosenos del lote a 0–1 (mínimo → 0, máximo → 1). El coseno
	 * de un modelo de embeddings vive en una franja estrecha (en el banco, entre
	 * dos entradas cualesquiera, 0,43–0,70 del p5 al p95; la vecina más cercana,
	 * 0,8–0,95): dividir
	 * por el máximo, como la similitud léxica, dejaría a todas las candidatas
	 * cerca de 1 y la relevancia mínima no filtraría nada.
	 *
	 * @param array<int, float> $scores ID → coseno.
	 * @return array<int, float> ID → 0–1. Un lote de uno o sin variación da 1.
	 */
	public static function rescale( array $scores ): array {
		if ( array() === $scores ) {
			return array();
		}
		$min   = min( $scores );
		$range = max( $scores ) - $min;
		$out   = array();
		foreach ( $scores as $id => $score ) {
			$out[ $id ] = $range > 0.0 ? ( $score - $min ) / $range : 1.0;
		}
		return $out;
	}

	/**
	 * Relevancia mezclada: `(1 − peso) · léxica + peso · coseno`, en 0–1.
	 *
	 * @param float $lexical Relevancia léxica normalizada (0–1).
	 * @param float $cosine  Coseno reescalado (0–1).
	 * @param float $weight  Peso del coseno (0 = solo léxico, 1 = solo coseno).
	 */
	public static function blend( float $lexical, float $cosine, float $weight = self::WEIGHT ): float {
		$weight = max( 0.0, min( 1.0, $weight ) );
		return max( 0.0, min( 1.0, ( 1 - $weight ) * $lexical + $weight * $cosine ) );
	}

	/**
	 * Texto que se vectoriza de una entrada: título, encabezados y las primeras
	 * palabras del cuerpo El mismo en el banco y en el plugin.
	 *
	 * @param Document $document Entrada.
	 * @param int      $words    Palabras del cuerpo.
	 * @param int      $chars    Tope de caracteres.
	 */
	public static function text( Document $document, int $words = self::TEXT_WORDS, int $chars = self::TEXT_CHARS ): string {
		$parts    = array( trim( $document->title ) );
		$headings = array_filter( array_map( 'trim', array_slice( $document->headings, 0, self::TEXT_HEADINGS ) ), static fn( string $h ): bool => '' !== $h );
		if ( array() !== $headings ) {
			$parts[] = implode( ' · ', $headings );
		}

		$body = array();
		$left = $words;
		foreach ( $document->paragraphs as $paragraph ) {
			if ( $left <= 0 ) {
				break;
			}
			$tokens = preg_split( '/\s+/u', trim( $paragraph->text ), -1, PREG_SPLIT_NO_EMPTY );
			$tokens = false === $tokens ? array() : $tokens;
			if ( array() === $tokens ) {
				continue;
			}
			$body[] = implode( ' ', array_slice( $tokens, 0, $left ) );
			$left  -= count( $tokens );
		}
		if ( array() !== $body ) {
			$parts[] = implode( "\n", $body );
		}

		$text = implode( "\n\n", array_filter( $parts, static fn( string $p ): bool => '' !== $p ) );
		return mb_strlen( $text, 'UTF-8' ) > $chars ? mb_substr( $text, 0, $chars, 'UTF-8' ) : $text;
	}
}
