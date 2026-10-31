<?php
/**
 * Frases objetivo que escribe el usuario para una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use MagicLinking\Engine\PhraseFinder;

/**
 * Frases por las que el usuario quiere que enlacen a *esta* entrada, cada una con sus variantes y, si lo pide, el
 * hueco de una palabra (docs/04 §4.2, docs/07 §3.4, D-30). Son un extra: sin ellas el motor funciona igual.
 *
 * Se guardan en una meta de la entrada (`_magiclinking_target_phrases`, JSON; los metadatos de entrada no se cargan
 * automáticamente, regla 8; se borra al vaciar la lista). Cada frase y cada variante entran en el índice como la
 * palabra clave foco ({@see Fingerprint::focus()}), de modo que cambiar la lista cambia la huella y se reindexa la entrada
 * ({@see \MagicLinking\Jobs\Jobs::on_exclusion_meta()}); el hueco no entra en el índice, solo en el buscador de
 * anclas ({@see Fingerprint::gapped()}).
 */
final class TargetPhrases {

	/**
	 * Meta de la entrada.
	 */
	public const KEY = '_magiclinking_target_phrases';

	/**
	 * Frases como máximo por entrada.
	 */
	public const MAX_PHRASES = 10;

	/**
	 * Variantes como máximo por frase.
	 */
	public const MAX_VARIANTS = 5;

	/**
	 * Palabras como máximo por frase o variante: las de un ancla.
	 */
	public const MAX_WORDS = PhraseFinder::MAX_WORDS;

	/**
	 * Caracteres como máximo por frase o variante.
	 */
	public const MAX_CHARS = 120;

	/**
	 * Las frases guardadas de una entrada.
	 *
	 * @param int $post_id ID.
	 *
	 * @return list<array{phrase: string, variants: list<string>, gap: bool}>
	 */
	public static function get( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::KEY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? self::clean( $decoded ) : array();
	}

	/**
	 * Guarda las frases (limpias) y devuelve lo guardado. Una lista vacía borra la meta.
	 *
	 * @param int   $post_id ID.
	 * @param array $phrases Lista de `phrase`, `variants` y `gap`.
	 *
	 * @phpstan-param array<mixed> $phrases
	 * @return list<array{phrase: string, variants: list<string>, gap: bool}>
	 */
	public static function set( int $post_id, array $phrases ): array {
		$clean = self::clean( $phrases );
		if ( array() === $clean ) {
			delete_post_meta( $post_id, self::KEY );
		} else {
			update_post_meta( $post_id, self::KEY, wp_slash( (string) wp_json_encode( $clean ) ) );
		}

		return $clean;
	}

	/**
	 * Todos los textos (frases y variantes) de una entrada: lo que se indexa como frase objetivo.
	 *
	 * @param int $post_id ID.
	 *
	 * @return list<string>
	 */
	public static function texts( int $post_id ): array {
		$texts = array();
		foreach ( self::get( $post_id ) as $entry ) {
			$texts[] = $entry['phrase'];
			foreach ( $entry['variants'] as $variant ) {
				$texts[] = $variant;
			}
		}

		return $texts;
	}

	/**
	 * Los textos de las frases que admiten una palabra en medio (con sus variantes).
	 *
	 * @param int $post_id ID.
	 *
	 * @return list<string>
	 */
	public static function gapped( int $post_id ): array {
		$texts = array();
		foreach ( self::get( $post_id ) as $entry ) {
			if ( $entry['gap'] ) {
				$texts[] = $entry['phrase'];
				foreach ( $entry['variants'] as $variant ) {
					$texts[] = $variant;
				}
			}
		}

		return $texts;
	}

	/**
	 * Un texto como lo guarda el motor: sin etiquetas, con los espacios colapsados y recortado.
	 *
	 * @param mixed $text Texto.
	 */
	public static function tidy( mixed $text ): string {
		if ( ! is_string( $text ) ) {
			return '';
		}

		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
	}

	/**
	 * Palabras de un texto (separadas por espacios).
	 *
	 * @param string $text Texto ya limpio.
	 */
	public static function words( string $text ): int {
		return '' === $text ? 0 : count( explode( ' ', $text ) );
	}

	/**
	 * Limpia una lista: quita lo vacío y lo repetido (sin mirar mayúsculas; la primera aparición manda), conserva
	 * el orden y respeta los máximos.
	 *
	 * @param array $phrases Lista de `phrase`, `variants` y `gap`.
	 *
	 * @phpstan-param array<mixed> $phrases
	 * @return list<array{phrase: string, variants: list<string>, gap: bool}>
	 */
	public static function clean( array $phrases ): array {
		$seen  = array();
		$clean = array();

		foreach ( $phrases as $entry ) {
			if ( ! is_array( $entry ) || count( $clean ) >= self::MAX_PHRASES ) {
				continue;
			}
			$phrase = self::tidy( $entry['phrase'] ?? '' );
			$key    = mb_strtolower( $phrase );
			if ( '' === $phrase || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$variants = array();
			foreach ( is_array( $entry['variants'] ?? null ) ? $entry['variants'] : array() as $variant ) {
				$variant = self::tidy( $variant );
				$low     = mb_strtolower( $variant );
				if ( '' === $variant || isset( $seen[ $low ] ) || count( $variants ) >= self::MAX_VARIANTS ) {
					continue;
				}
				$seen[ $low ] = true;
				$variants[]   = $variant;
			}

			$clean[] = array(
				'phrase'   => $phrase,
				'variants' => $variants,
				'gap'      => filter_var( $entry['gap'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			);
		}//end foreach

		return $clean;
	}
}
