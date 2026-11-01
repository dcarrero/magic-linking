<?php
/**
 * Detección del idioma de un texto por palabras vacías.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Heurística sin dependencias: cuenta, entre las primeras palabras del texto, las palabras vacías que son propias
 * de cada idioma. Las listas son las de {@see Stopwords::detection()} (las del motor para castellano e inglés, y
 * las de reconocimiento para francés, alemán, italiano y portugués). Una palabra que está en la lista de más de un
 * idioma («de», «e», «no», «a», «la»…) reparte su peso entre todos los que la tienen, nunca cuenta para uno solo
 * (portugués y castellano comparten muchas); decide el ganador con margen sobre el segundo. Se comparan sin
 * tildes, como {@see Stopwords::is()}, y las elisiones («all'», «l'», «don'») se quitan antes de contar.
 *
 * No adivina: con pocas palabras, o sin un ganador claro, devuelve el idioma de reserva.
 */
final class LanguageDetector {

	/**
	 * Palabras mínimas para decidir; con menos, se usa el idioma de reserva.
	 */
	public const MIN_WORDS = 30;

	/**
	 * Palabras que se examinan como máximo (el coste no crece con la longitud de la entrada).
	 */
	public const MAX_WORDS = 400;

	/**
	 * Caracteres que se leen como máximo del texto.
	 */
	public const MAX_CHARS = 6000;

	/**
	 * Proporción mínima de palabras propias del ganador sobre el total.
	 */
	public const MIN_SHARE = 0.12;

	/**
	 * El ganador debe tener al menos tantas veces las palabras del segundo.
	 */
	public const MIN_RATIO = 1.6;

	/**
	 * Idiomas de cada palabra, calculados la primera vez que se piden.
	 *
	 * @var array<string, list<string>>|null
	 */
	private static ?array $markers = null;

	/**
	 * Idiomas que se reconocen.
	 *
	 * @return list<string>
	 */
	public static function languages(): array {
		return Stopwords::detection_languages();
	}

	/**
	 * Idioma de un texto, o el de reserva si no está claro.
	 *
	 * @param string $text     Texto plano (sin etiquetas).
	 * @param string $fallback Idioma que se devuelve cuando no hay confianza.
	 */
	public static function detect( string $text, string $fallback ): string {
		$sample = mb_strtolower( mb_substr( Tokenizer::nfc( $text ), 0, self::MAX_CHARS, 'UTF-8' ), 'UTF-8' );
		// «all'innovazione», «l'estate»: el prefijo elidido (francés e italiano) no es una palabra de otro idioma. Solo
		// esos prefijos: «it's», «don't» o una palabra antes de una comilla de cierre conservan la palabra.
		$sample = (string) preg_replace( "/(?<!\p{L})(?:qu|jusqu|lorsqu|puisqu|quest|all|dell|nell|sull|dall|sant|un|[ldjmnstcv])['’](?=\p{L})/u", ' ', $sample );
		if ( 1 > (int) preg_match_all( '/\p{L}+/u', $sample, $found ) ) {
			return $fallback;
		}

		$words = array_slice( $found[0], 0, self::MAX_WORDS );
		$total = count( $words );
		if ( $total < self::MIN_WORDS ) {
			return $fallback;
		}

		$markers = self::markers();
		$hits    = array_fill_keys( self::languages(), 0.0 );
		foreach ( $words as $word ) {
			$owners = $markers[ Stopwords::fold( $word ) ] ?? array();
			foreach ( $owners as $language ) {
				// Una palabra de varios idiomas reparte su peso entre ellos.
				$hits[ $language ] += 1 / count( $owners );
			}
		}

		arsort( $hits );
		$best   = (string) array_key_first( $hits );
		$top    = $hits[ $best ];
		$second = array_values( $hits )[1];

		if ( $top / $total < self::MIN_SHARE || $top < self::MIN_RATIO * $second ) {
			return $fallback;
		}

		return $best;
	}

	/**
	 * Idiomas que tienen cada palabra (sin tildes), calculado la primera vez que se pide.
	 *
	 * @return array<string, list<string>>
	 */
	private static function markers(): array {
		if ( null !== self::$markers ) {
			return self::$markers;
		}

		self::$markers = array();
		foreach ( self::languages() as $language ) {
			foreach ( Stopwords::detection( $language ) as $word ) {
				self::$markers[ Stopwords::fold( $word ) ][ $language ] = $language;
			}
		}
		self::$markers = array_map( 'array_values', self::$markers );

		return self::$markers;
	}
}
