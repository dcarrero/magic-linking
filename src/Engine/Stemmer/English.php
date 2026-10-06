<?php
/**
 * Stemmer Snowball para inglés (Porter2).
 *
 * Implementación propia a partir de la especificación publicada en
 * https://snowballstem.org/algorithms/english/stemmer.html (Snowball 3.x),
 * incluidas sus formas excepcionales. El algoritmo es de Martin Porter y del
 * proyecto Snowball (BSD-3-Clause);
 * no se reutiliza código de Snowball ni de otras implementaciones.
 *
 * El algoritmo trabaja sobre letras ASCII; cualquier otro byte se trata como
 * consonante. La «y» que actúa como consonante se marca con «Y» durante el
 * proceso y se devuelve a minúscula al final.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine\Stemmer;

/**
 * Porter2.
 */
final class English implements StemmerInterface {

	private const VOWELS = 'aeiouy';

	private const DOUBLES = array( 'bb', 'dd', 'ff', 'gg', 'mm', 'nn', 'pp', 'rr', 'tt' );

	private const LI_ENDINGS = 'cdeghkmnrt';

	/**
	 * Palabras completas con raíz fija (null: se dejan igual).
	 */
	private const EXCEPTIONS = array(
		'skis'   => 'ski',
		'skies'  => 'sky',
		'idly'   => 'idl',
		'gently' => 'gentl',
		'ugly'   => 'ugli',
		'early'  => 'earli',
		'only'   => 'onli',
		'singly' => 'singl',
		'sky'    => null,
		'news'   => null,
		'howe'   => null,
		'atlas'  => null,
		'cosmos' => null,
		'bias'   => null,
		'andes'  => null,
	);

	/**
	 * Prefijos tras los que empieza R1.
	 */
	private const R1_PREFIXES = array( 'univers', 'commun', 'gener', 'arsen', 'later', 'emerg', 'organ', 'inter', 'past' );

	/**
	 * Paso 1b: lo que queda antes de «eed» en palabras que no son participios.
	 */
	private const EED_KEEP = array( 'proc', 'exc', 'succ' );

	/**
	 * Paso 1b: lo que queda antes de «ing» en palabras que no son gerundios.
	 */
	private const ING_KEEP = array( 'inn', 'out', 'cann', 'herr', 'earr', 'even' );

	/**
	 * Paso 2: sufijo => sustitución (null: regla especial).
	 */
	private const STEP2 = array(
		'ization' => 'ize',
		'ational' => 'ate',
		'fulness' => 'ful',
		'ousness' => 'ous',
		'iveness' => 'ive',
		'tional'  => 'tion',
		'biliti'  => 'ble',
		'lessli'  => 'less',
		'entli'   => 'ent',
		'ation'   => 'ate',
		'alism'   => 'al',
		'aliti'   => 'al',
		'ousli'   => 'ous',
		'iviti'   => 'ive',
		'fulli'   => 'ful',
		'ogist'   => 'og',
		'enci'    => 'ence',
		'anci'    => 'ance',
		'abli'    => 'able',
		'izer'    => 'ize',
		'ator'    => 'ate',
		'alli'    => 'al',
		'bli'     => 'ble',
		'ogi'     => null,
		'li'      => null,
	);

	/**
	 * Paso 3: sufijo => sustitución (null: «ative», solo en R2).
	 */
	private const STEP3 = array(
		'ational' => 'ate',
		'tional'  => 'tion',
		'alize'   => 'al',
		'icate'   => 'ic',
		'iciti'   => 'ic',
		'ative'   => null,
		'ical'    => 'ic',
		'ness'    => '',
		'ful'     => '',
	);

	/**
	 * Paso 4: sufijos que se quitan en R2 (ordenados de mayor a menor).
	 */
	private const STEP4 = array( 'ement', 'ance', 'ence', 'able', 'ible', 'ment', 'ant', 'ent', 'ism', 'ate', 'iti', 'ous', 'ive', 'ize', 'ion', 'al', 'er', 'ic' );

	/**
	 * Inicio de R1 en bytes.
	 *
	 * @var int
	 */
	private int $r1 = 0;

	/**
	 * Inicio de R2 en bytes.
	 *
	 * @var int
	 */
	private int $r2 = 0;

	/**
	 * Devuelve la raíz de una palabra.
	 *
	 * @param string $word Palabra en minúsculas.
	 * @return string Raíz.
	 */
	public function stem( string $word ): string {
		if ( array_key_exists( $word, self::EXCEPTIONS ) ) {
			return self::EXCEPTIONS[ $word ] ?? $word;
		}
		if ( strlen( $word ) < 3 ) {
			return $word;
		}

		if ( "'" === $word[0] ) {
			$word = substr( $word, 1 );
		}

		$word = $this->mark_y( $word );
		$this->regions( $word );

		$word = $this->step0( $word );
		$word = $this->step1a( $word );
		$word = $this->step1b( $word );
		$word = $this->step1c( $word );
		$word = $this->step2( $word );
		$word = $this->step3( $word );
		$word = $this->step4( $word );
		$word = $this->step5( $word );

		return str_replace( 'Y', 'y', $word );
	}

	/**
	 * Marca como consonante la «y» inicial y la que sigue a vocal.
	 *
	 * @param string $word Palabra.
	 * @return string Palabra con «Y».
	 */
	private function mark_y( string $word ): string {
		$length = strlen( $word );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( 'y' === $word[ $i ] && ( 0 === $i || $this->is_vowel( $word[ $i - 1 ] ) ) ) {
				$word[ $i ] = 'Y';
			}
		}

		return $word;
	}

	/**
	 * Calcula R1 y R2.
	 *
	 * @param string $word Palabra.
	 */
	private function regions( string $word ): void {
		$this->r1 = -1;
		foreach ( self::R1_PREFIXES as $prefix ) {
			if ( str_starts_with( $word, $prefix ) ) {
				$this->r1 = strlen( $prefix );
				break;
			}
		}
		if ( $this->r1 < 0 ) {
			$this->r1 = $this->region_start( $word, 0 );
		}
		$this->r2 = $this->region_start( $word, $this->r1 );
	}

	/**
	 * Región tras la primera consonante que sigue a una vocal, a partir de $from.
	 *
	 * @param string $word Palabra.
	 * @param int    $from Posición donde empieza la búsqueda.
	 * @return int Posición.
	 */
	private function region_start( string $word, int $from ): int {
		$length = strlen( $word );
		for ( $i = $from + 1; $i < $length; $i++ ) {
			if ( $this->is_vowel( $word[ $i - 1 ] ) && ! $this->is_vowel( $word[ $i ] ) ) {
				return $i + 1;
			}
		}

		return $length;
	}

	/**
	 * Paso 0: apóstrofo final y posesivo.
	 *
	 * @param string $word Palabra.
	 * @return string
	 */
	private function step0( string $word ): string {
		foreach ( array( "'s'", "'s", "'" ) as $suffix ) {
			if ( str_ends_with( $word, $suffix ) ) {
				return substr( $word, 0, -strlen( $suffix ) );
			}
		}

		return $word;
	}

	/**
	 * Paso 1a: plurales.
	 *
	 * @param string $word Palabra.
	 * @return string
	 */
	private function step1a( string $word ): string {
		if ( str_ends_with( $word, 'sses' ) ) {
			return substr( $word, 0, -2 );
		}
		if ( str_ends_with( $word, 'ied' ) || str_ends_with( $word, 'ies' ) ) {
			return substr( $word, 0, -3 ) . ( strlen( $word ) > 4 ? 'i' : 'ie' );
		}
		if ( str_ends_with( $word, 'us' ) || str_ends_with( $word, 'ss' ) ) {
			return $word;
		}
		if ( str_ends_with( $word, 's' ) && $this->has_vowel( substr( $word, 0, -2 ) ) ) {
			return substr( $word, 0, -1 );
		}

		return $word;
	}

	/**
	 * Paso 1b: -eed, -ed, -ing y sus formas en -ly.
	 *
	 * @param string $word Palabra.
	 * @return string
	 */
	private function step1b( string $word ): string {
		foreach ( array( 'eedly', 'eed' ) as $suffix ) {
			if ( str_ends_with( $word, $suffix ) ) {
				$stem = substr( $word, 0, -strlen( $suffix ) );
				if ( in_array( $stem, self::EED_KEEP, true ) || ! $this->in_region( $word, $suffix, $this->r1 ) ) {
					return $word;
				}
				return $stem . 'ee';
			}
		}

		foreach ( array( 'ingly', 'edly', 'ing', 'ed' ) as $suffix ) {
			if ( ! str_ends_with( $word, $suffix ) ) {
				continue;
			}
			$stem = substr( $word, 0, -strlen( $suffix ) );

			if ( 'ing' === $suffix ) {
				if ( 2 === strlen( $stem ) && 'y' === $stem[1] && ! $this->is_vowel( $stem[0] ) ) {
					return $stem[0] . 'ie';
				}
				if ( in_array( $stem, self::ING_KEEP, true ) ) {
					return $word;
				}
			}

			if ( ! $this->has_vowel( $stem ) ) {
				return $word;
			}

			if ( str_ends_with( $stem, 'at' ) || str_ends_with( $stem, 'bl' ) || str_ends_with( $stem, 'iz' ) ) {
				return $stem . 'e';
			}
			if ( in_array( substr( $stem, -2 ), self::DOUBLES, true ) ) {
				$before = substr( $stem, 0, -2 );
				if ( ! in_array( $before, array( 'a', 'e', 'o' ), true ) ) {
					return substr( $stem, 0, -1 );
				}
				return $stem;
			}
			if ( $this->is_short( $stem ) ) {
				return $stem . 'e';
			}

			return $stem;
		}//end foreach

		return $word;
	}

	/**
	 * Paso 1c: -y final tras consonante.
	 *
	 * @param string $word Palabra.
	 * @return string
	 */
	private function step1c( string $word ): string {
		$length = strlen( $word );
		if ( $length > 2 ) {
			$last = $word[ $length - 1 ];
			if ( ( 'y' === $last || 'Y' === $last ) && ! $this->is_vowel( $word[ $length - 2 ] ) ) {
				return substr( $word, 0, -1 ) . 'i';
			}
		}

		return $word;
	}

	/**
	 * Paso 2: sufijos dobles, en R1.
	 *
	 * @param string $word Palabra.
	 * @return string
	 */
	private function step2( string $word ): string {
		foreach ( self::STEP2 as $suffix => $replacement ) {
			if ( ! str_ends_with( $word, $suffix ) ) {
				continue;
			}
			if ( ! $this->in_region( $word, $suffix, $this->r1 ) ) {
				return $word;
			}

			$stem = substr( $word, 0, -strlen( $suffix ) );
			if ( null !== $replacement ) {
				return $stem . $replacement;
			}
			if ( 'ogi' === $suffix ) {
				return str_ends_with( $stem, 'l' ) ? $stem . 'og' : $word;
			}

			// «li» tras una terminación válida.
			return ( '' !== $stem && str_contains( self::LI_ENDINGS, $stem[ strlen( $stem ) - 1 ] ) ) ? $stem : $word;
		}

		return $word;
	}

	/**
	 * Paso 3: más sufijos derivativos, en R1.
	 *
	 * @param string $word Palabra.
	 * @return string
	 */
	private function step3( string $word ): string {
		foreach ( self::STEP3 as $suffix => $replacement ) {
			if ( ! str_ends_with( $word, $suffix ) ) {
				continue;
			}
			if ( ! $this->in_region( $word, $suffix, $this->r1 ) ) {
				return $word;
			}
			if ( null === $replacement && ! $this->in_region( $word, $suffix, $this->r2 ) ) {
				return $word;
			}

			return substr( $word, 0, -strlen( $suffix ) ) . (string) $replacement;
		}

		return $word;
	}

	/**
	 * Paso 4: sufijos en R2.
	 *
	 * @param string $word Palabra.
	 * @return string
	 */
	private function step4( string $word ): string {
		foreach ( self::STEP4 as $suffix ) {
			if ( ! str_ends_with( $word, $suffix ) ) {
				continue;
			}
			if ( ! $this->in_region( $word, $suffix, $this->r2 ) ) {
				return $word;
			}

			$stem = substr( $word, 0, -strlen( $suffix ) );
			if ( 'ion' === $suffix && ! ( str_ends_with( $stem, 's' ) || str_ends_with( $stem, 't' ) ) ) {
				return $word;
			}

			return $stem;
		}

		return $word;
	}

	/**
	 * Paso 5: -e y -ll finales.
	 *
	 * @param string $word Palabra.
	 * @return string
	 */
	private function step5( string $word ): string {
		if ( str_ends_with( $word, 'e' ) ) {
			$stem = substr( $word, 0, -1 );
			if ( $this->in_region( $word, 'e', $this->r2 ) || ( $this->in_region( $word, 'e', $this->r1 ) && ! $this->ends_short_syllable( $stem ) ) ) {
				return $stem;
			}
			return $word;
		}
		if ( str_ends_with( $word, 'll' ) && $this->in_region( $word, 'l', $this->r2 ) ) {
			return substr( $word, 0, -1 );
		}

		return $word;
	}

	/**
	 * ¿Termina en sílaba corta?
	 *
	 * @param string $word Palabra.
	 * @return bool
	 */
	private function ends_short_syllable( string $word ): bool {
		if ( 'past' === $word ) {
			return true;
		}

		$length = strlen( $word );
		if ( 2 === $length ) {
			return $this->is_vowel( $word[0] ) && ! $this->is_vowel( $word[1] );
		}
		if ( $length < 3 ) {
			return false;
		}

		$last = $word[ $length - 1 ];

		return ! $this->is_vowel( $word[ $length - 3 ] )
			&& $this->is_vowel( $word[ $length - 2 ] )
			&& ! $this->is_vowel( $last )
			&& ! str_contains( 'wxY', $last );
	}

	/**
	 * ¿Es una palabra corta? (termina en sílaba corta y R1 está vacía).
	 *
	 * @param string $word Palabra.
	 * @return bool
	 */
	private function is_short( string $word ): bool {
		return $this->r1 >= strlen( $word ) && $this->ends_short_syllable( $word );
	}

	/**
	 * ¿Empieza el sufijo dentro de la región?
	 *
	 * @param string $word   Palabra que termina en el sufijo.
	 * @param string $suffix Sufijo.
	 * @param int    $region Inicio de la región.
	 * @return bool
	 */
	private function in_region( string $word, string $suffix, int $region ): bool {
		return strlen( $word ) - strlen( $suffix ) >= $region;
	}

	/**
	 * ¿Contiene alguna vocal?
	 *
	 * @param string $part Fragmento.
	 * @return bool
	 */
	private function has_vowel( string $part ): bool {
		return strpbrk( $part, self::VOWELS ) !== false;
	}

	/**
	 * ¿Es vocal? («Y» marcada no lo es).
	 *
	 * @param string $char Byte.
	 * @return bool
	 */
	private function is_vowel( string $char ): bool {
		return str_contains( self::VOWELS, $char );
	}
}
