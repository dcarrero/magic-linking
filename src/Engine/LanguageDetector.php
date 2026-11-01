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
 * de cada idioma. Castellano e inglés usan las listas del motor ({@see Stopwords}); francés, alemán, italiano y
 * portugués llevan aquí una lista corta de palabras funcionales. De cada lista se quitan las palabras que también
 * existen en otro idioma («de», «no», «a», «la»…), para que lo que cuente discrimine.
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
	 * Francés.
	 */
	private const FR = array( 'le', 'la', 'les', 'des', 'du', 'un', 'une', 'et', 'est', 'que', 'qui', 'dans', 'pour', 'pas', 'sur', 'avec', 'ce', 'cette', 'ces', 'son', 'sa', 'ses', 'au', 'aux', 'ne', 'il', 'elle', 'nous', 'vous', 'ils', 'elles', 'mais', 'ou', 'où', 'comme', 'plus', 'par', 'sont', 'été', 'être', 'avoir', 'fait', 'peut', 'aussi', 'très', 'cela', 'leur', 'leurs', 'entre', 'sans', 'sous', 'chez', 'dont', 'donc', 'alors', 'tout', 'tous', 'ont', 'était', 'ça', 'qu', 'l' );

	/**
	 * Alemán.
	 */
	private const DE = array( 'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'eines', 'einem', 'einen', 'und', 'ist', 'nicht', 'mit', 'für', 'auf', 'von', 'zu', 'im', 'auch', 'sich', 'es', 'wird', 'werden', 'wurde', 'sind', 'aber', 'wie', 'oder', 'dass', 'bei', 'nach', 'aus', 'noch', 'nur', 'wenn', 'kann', 'hat', 'haben', 'über', 'ich', 'wir', 'sie', 'er', 'ihr', 'sein', 'war', 'dieser', 'diese', 'dieses', 'als', 'zum', 'zur', 'vom', 'am', 'um', 'durch', 'mehr', 'sehr', 'schon', 'unter', 'bis' );

	/**
	 * Italiano.
	 */
	private const IT = array( 'il', 'lo', 'la', 'gli', 'le', 'di', 'del', 'della', 'dei', 'delle', 'degli', 'che', 'non', 'per', 'con', 'una', 'uno', 'sono', 'è', 'come', 'più', 'anche', 'nel', 'nella', 'nei', 'nelle', 'alla', 'alle', 'al', 'ai', 'sul', 'sulla', 'questo', 'questa', 'questi', 'quello', 'quella', 'essere', 'ha', 'hanno', 'ma', 'se', 'tra', 'fra', 'molto', 'dove', 'quando', 'però', 'perché', 'così', 'suo', 'sua', 'loro', 'può', 'dal', 'dalla', 'dai', 'cui', 'ogni', 'ci' );

	/**
	 * Portugués.
	 */
	private const PT = array( 'o', 'os', 'as', 'um', 'uma', 'uns', 'umas', 'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'nos', 'nas', 'que', 'não', 'para', 'com', 'por', 'mais', 'como', 'mas', 'foi', 'são', 'ser', 'está', 'também', 'muito', 'seu', 'sua', 'seus', 'suas', 'ao', 'aos', 'pelo', 'pela', 'pelos', 'pelas', 'entre', 'sobre', 'já', 'ou', 'isso', 'isto', 'esta', 'este', 'esse', 'essa', 'ele', 'ela', 'eles', 'elas', 'nós', 'você', 'tem', 'têm', 'há', 'só', 'então', 'quando', 'onde', 'ainda', 'depois', 'sem', 'nem', 'até', 'apenas', 'seja', 'sempre', 'mesmo', 'todos', 'todas', 'muitos', 'faz', 'fazer', 'pode', 'podem', 'temos', 'estão', 'estava', 'foram', 'será', 'nosso', 'nossa', 'deste', 'desta', 'neste', 'nesta', 'dessa', 'nas', 'à', 'às', 'cada', 'quem', 'qual', 'quais', 'porque', 'pois', 'sobre' );

	/**
	 * Palabras exclusivas de cada idioma, calculadas la primera vez que se piden.
	 *
	 * @var array<string, array<string, true>>|null
	 */
	private static ?array $markers = null;

	/**
	 * Idiomas que se reconocen.
	 *
	 * @return list<string>
	 */
	public static function languages(): array {
		return array( 'es', 'en', 'fr', 'de', 'it', 'pt' );
	}

	/**
	 * Idioma de un texto, o el de reserva si no está claro.
	 *
	 * @param string $text     Texto plano (sin etiquetas).
	 * @param string $fallback Idioma que se devuelve cuando no hay confianza.
	 */
	public static function detect( string $text, string $fallback ): string {
		$sample = mb_strtolower( mb_substr( $text, 0, self::MAX_CHARS, 'UTF-8' ), 'UTF-8' );
		if ( 1 > (int) preg_match_all( '/\p{L}+/u', $sample, $found ) ) {
			return $fallback;
		}

		$words = array_slice( $found[0], 0, self::MAX_WORDS );
		$total = count( $words );
		if ( $total < self::MIN_WORDS ) {
			return $fallback;
		}

		$markers = self::markers();
		$hits    = array_fill_keys( self::languages(), 0 );
		foreach ( $words as $word ) {
			foreach ( $markers as $language => $set ) {
				if ( isset( $set[ $word ] ) ) {
					++$hits[ $language ];
				}
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
	 * Palabras exclusivas de cada idioma: su lista sin las que aparecen en otra.
	 *
	 * @return array<string, array<string, true>>
	 */
	private static function markers(): array {
		if ( null !== self::$markers ) {
			return self::$markers;
		}

		$lists = array(
			'es' => Stopwords::for( 'es' ),
			'en' => Stopwords::for( 'en' ),
			'fr' => self::FR,
			'de' => self::DE,
			'it' => self::IT,
			'pt' => self::PT,
		);

		$count = array();
		foreach ( $lists as $words ) {
			foreach ( array_unique( $words ) as $word ) {
				$count[ $word ] = ( $count[ $word ] ?? 0 ) + 1;
			}
		}

		self::$markers = array();
		foreach ( $lists as $language => $words ) {
			self::$markers[ $language ] = array();
			foreach ( $words as $word ) {
				if ( 1 === $count[ $word ] ) {
					self::$markers[ $language ][ $word ] = true;
				}
			}
		}

		return self::$markers;
	}
}
