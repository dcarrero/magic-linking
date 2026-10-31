<?php
/**
 * Frases objetivo que escribe el usuario para una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use MagicLinking\Engine\Analyzer;
use MagicLinking\Engine\PhraseFinder;

/**
 * Frases por las que el usuario quiere que enlacen a *esta* entrada, cada una con sus variantes y, si lo pide, el
 * hueco de una palabra (docs/04 §4.2, docs/07 §3.4, D-30). Son un extra: sin ellas el motor funciona igual.
 *
 * Se guardan en una meta de la entrada (`_magiclinking_target_phrases`, JSON; los metadatos de entrada no se cargan
 * automáticamente, regla 8; se borra al vaciar la lista). Cada frase y cada variante entran en el índice como la
 * palabra clave foco ({@see Fingerprint::focus()}), de modo que cambiar la lista cambia la huella y se reindexa la entrada
 * ({@see \MagicLinking\Jobs\Jobs::on_exclusion_meta()}); el hueco no entra en el índice, solo en el buscador de
 * anclas ({@see TargetPhrases::indexed()}).
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
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		// Lo guardado ya salió de clean(): aquí solo se descarta lo que no tenga la forma (una meta editada a mano).
		$list = array();
		foreach ( $decoded as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['phrase'] ?? null ) && '' !== $entry['phrase'] ) {
				$list[] = array(
					'phrase'   => $entry['phrase'],
					'variants' => array_values( array_filter( is_array( $entry['variants'] ?? null ) ? $entry['variants'] : array(), 'is_string' ) ),
					'gap'      => true === ( $entry['gap'] ?? false ),
				);
			}
		}

		return $list;
	}

	/**
	 * Guarda las frases (limpias) y devuelve lo guardado y lo que se ha quitado por repetido. Una lista vacía borra la meta.
	 *
	 * @param int   $post_id ID.
	 * @param array $phrases Lista de `phrase`, `variants` y `gap`.
	 *
	 * @phpstan-param array<mixed> $phrases
	 * @return array{phrases: list<array{phrase: string, variants: list<string>, gap: bool}>, removed: list<string>}
	 */
	public static function set( int $post_id, array $phrases ): array {
		$report = self::clean( $phrases );
		if ( array() === $report['phrases'] ) {
			delete_post_meta( $post_id, self::KEY );
		} else {
			update_post_meta( $post_id, self::KEY, wp_slash( (string) wp_json_encode( $report['phrases'] ) ) );
		}

		return $report;
	}

	/**
	 * Lo que va al índice de una entrada, con una sola lectura de la meta: todos los textos (frases y variantes) y los
	 * que admiten una palabra en medio.
	 *
	 * @param int $post_id ID.
	 *
	 * @return array{texts: list<string>, gapped: list<string>}
	 */
	public static function indexed( int $post_id ): array {
		$texts  = array();
		$gapped = array();
		foreach ( self::get( $post_id ) as $entry ) {
			$all = array_merge( array( $entry['phrase'] ), $entry['variants'] );
			foreach ( $all as $text ) {
				$texts[] = $text;
				if ( $entry['gap'] ) {
					$gapped[] = $text;
				}
			}
		}

		return array(
			'texts'  => $texts,
			'gapped' => $gapped,
		);
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
	 * Palabras de un texto contadas como las cuenta el motor (una por clave de raíz): lo que se valida es lo que luego
	 * puede encajar en un ancla.
	 *
	 * @param string   $text     Texto ya limpio.
	 * @param Analyzer $analyzer Analizador del idioma de la entrada.
	 */
	public static function words( string $text, Analyzer $analyzer ): int {
		return count( $analyzer->keys( $analyzer->tokenizer()->tokenize( $text ) ) );
	}

	/**
	 * Limpia una lista: quita lo vacío y lo repetido (sin mirar mayúsculas; la primera aparición manda), conserva el
	 * orden y, con `$capped`, respeta los máximos. Un texto repetido se quita solo a sí mismo: la frase conserva sus
	 * otras variantes y su hueco. Si la frase está vacía o repetida, la primera variante que quede pasa a ser la frase;
	 * sin variantes, la entrada desaparece. Lo repetido se devuelve en `removed` para avisar al usuario.
	 *
	 * @param array $phrases Lista de `phrase`, `variants` y `gap`.
	 * @param bool  $capped  Aplicar los máximos de frases y de variantes (la ruta los valida antes y los rechaza).
	 *
	 * @phpstan-param array<mixed> $phrases
	 * @return array{phrases: list<array{phrase: string, variants: list<string>, gap: bool}>, removed: list<string>}
	 */
	public static function clean( array $phrases, bool $capped = true ): array {
		$seen    = array();
		$clean   = array();
		$removed = array();

		foreach ( $phrases as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			// Frase y variantes en una sola lista; la primera que queda es la frase.
			$texts = array_merge( array( $entry['phrase'] ?? '' ), is_array( $entry['variants'] ?? null ) ? $entry['variants'] : array() );
			$kept  = array();
			foreach ( $texts as $text ) {
				$text = self::tidy( $text );
				if ( '' === $text ) {
					continue;
				}
				$low = mb_strtolower( $text );
				if ( isset( $seen[ $low ] ) ) {
					$removed[ $low ] = $text;
					continue;
				}
				$seen[ $low ] = true;
				$kept[]       = $text;
			}
			if ( array() === $kept ) {
				continue;
			}

			$phrase = array_shift( $kept );
			if ( $capped ) {
				$kept = array_slice( $kept, 0, self::MAX_VARIANTS );
			}
			$clean[] = array(
				'phrase'   => $phrase,
				'variants' => $kept,
				'gap'      => filter_var( $entry['gap'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			);
		}//end foreach

		return array(
			'phrases' => $capped ? array_slice( $clean, 0, self::MAX_PHRASES ) : $clean,
			'removed' => array_values( $removed ),
		);
	}
}
