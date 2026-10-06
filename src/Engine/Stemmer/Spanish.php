<?php
/**
 * Stemmer Snowball para castellano.
 *
 * Implementación propia a partir de la especificación publicada en
 * https://snowballstem.org/algorithms/spanish/stemmer.html (Snowball 3.x).
 * El algoritmo es de Martin Porter y del proyecto Snowball (BSD-3-Clause);
 * no se reutiliza código de Snowball ni de otras implementaciones.
 *
 * Las regiones RV, R1 y R2 se guardan como desplazamientos en bytes de la
 * cadena UTF-8. Todas las operaciones quitan sufijos completos, así que los
 * desplazamientos siguen siendo válidos durante todo el proceso.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine\Stemmer;

/**
 * Snowball para castellano.
 */
final class Spanish implements StemmerInterface {

	private const VOWELS = array(
		'a' => true,
		'e' => true,
		'i' => true,
		'o' => true,
		'u' => true,
		'á' => true,
		'é' => true,
		'í' => true,
		'ó' => true,
		'ú' => true,
		'ü' => true,
	);

	/**
	 * Paso 0: pronombres enclíticos.
	 */
	private const PRONOUNS = array( 'selas', 'selos', 'sela', 'selo', 'las', 'les', 'los', 'nos', 'me', 'se', 'la', 'le', 'lo' );

	/**
	 * Paso 0: terminación verbal que tiene que preceder al pronombre, y cómo queda.
	 */
	private const PRONOUN_HOSTS = array(
		'iéndo' => 'iendo',
		'ándo'  => 'ando',
		'ár'    => 'ar',
		'ér'    => 'er',
		'ír'    => 'ir',
		'iendo' => 'iendo',
		'ando'  => 'ando',
		'yendo' => 'yendo',
		'ar'    => 'ar',
		'er'    => 'er',
		'ir'    => 'ir',
	);

	/**
	 * Paso 1: sufijo => regla.
	 */
	private const STANDARD = array(
		'anza'     => 'r2',
		'anzas'    => 'r2',
		'ico'      => 'r2',
		'ica'      => 'r2',
		'icos'     => 'r2',
		'icas'     => 'r2',
		'ismo'     => 'r2',
		'ismos'    => 'r2',
		'able'     => 'r2',
		'ables'    => 'r2',
		'ible'     => 'r2',
		'ibles'    => 'r2',
		'ista'     => 'r2',
		'istas'    => 'r2',
		'oso'      => 'r2',
		'osa'      => 'r2',
		'osos'     => 'r2',
		'osas'     => 'r2',
		'amiento'  => 'r2',
		'amientos' => 'r2',
		'imiento'  => 'r2',
		'imientos' => 'r2',
		'adora'    => 'r2_ic',
		'ador'     => 'r2_ic',
		'ación'    => 'r2_ic',
		'adoras'   => 'r2_ic',
		'adores'   => 'r2_ic',
		'aciones'  => 'r2_ic',
		'ante'     => 'r2_ic',
		'antes'    => 'r2_ic',
		'ancia'    => 'r2_ic',
		'ancias'   => 'r2_ic',
		'acion'    => 'r2_ic',
		'logía'    => 'log',
		'logías'   => 'log',
		'ución'    => 'u',
		'uciones'  => 'u',
		'ucion'    => 'u',
		'encia'    => 'ente',
		'encias'   => 'ente',
		'amente'   => 'amente',
		'mente'    => 'mente',
		'idad'     => 'idad',
		'idades'   => 'idad',
		'iva'      => 'ivo',
		'ivo'      => 'ivo',
		'ivas'     => 'ivo',
		'ivos'     => 'ivo',
	);

	/**
	 * Paso 2a: formas verbales que empiezan por y (se quitan tras u).
	 */
	private const Y_VERB = array( 'yeron', 'yendo', 'yamos', 'yais', 'yan', 'yen', 'yas', 'yes', 'ya', 'ye', 'yo', 'yó' );

	/**
	 * Paso 2b: terminaciones tras las que se quita también la u de «gu».
	 */
	private const VERB_GU = array( 'emos', 'éis', 'en', 'es' );

	/**
	 * Paso 2b: resto de terminaciones verbales.
	 */
	private const VERB = array(
		'arían',
		'arías',
		'arán',
		'arás',
		'aríais',
		'aría',
		'aréis',
		'aríamos',
		'aremos',
		'ará',
		'aré',
		'erían',
		'erías',
		'erán',
		'erás',
		'eríais',
		'ería',
		'eréis',
		'eríamos',
		'eremos',
		'erá',
		'eré',
		'irían',
		'irías',
		'irán',
		'irás',
		'iríais',
		'iría',
		'iréis',
		'iríamos',
		'iremos',
		'irá',
		'iré',
		'aba',
		'ada',
		'ida',
		'ía',
		'ara',
		'iera',
		'ad',
		'ed',
		'id',
		'ase',
		'iese',
		'aste',
		'iste',
		'an',
		'aban',
		'ían',
		'aran',
		'ieran',
		'asen',
		'iesen',
		'aron',
		'ieron',
		'ado',
		'ido',
		'ando',
		'iendo',
		'ió',
		'ar',
		'er',
		'ir',
		'as',
		'abas',
		'adas',
		'idas',
		'ías',
		'aras',
		'ieras',
		'ases',
		'ieses',
		'ís',
		'áis',
		'abais',
		'íais',
		'arais',
		'ierais',
		'aseis',
		'ieseis',
		'asteis',
		'isteis',
		'ados',
		'idos',
		'amos',
		'ábamos',
		'íamos',
		'imos',
		'áramos',
		'iéramos',
		'iésemos',
		'ásemos',
	);

	/**
	 * Paso 3: vocales residuales.
	 */
	private const RESIDUAL = array( 'os', 'a', 'o', 'á', 'í', 'ó' );

	private const ACCENTS = array(
		'á' => 'a',
		'é' => 'e',
		'í' => 'i',
		'ó' => 'o',
		'ú' => 'u',
	);

	/**
	 * Listas ordenadas de mayor a menor longitud, calculadas una vez.
	 *
	 * @var array<string, list<string>>
	 */
	private array $sorted = array();

	/**
	 * Inicio de RV en bytes.
	 *
	 * @var int
	 */
	private int $rv = 0;

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
	 * Prepara las listas de sufijos ordenadas por longitud.
	 */
	public function __construct() {
		$this->sorted = array(
			'pronouns' => self::by_length( self::PRONOUNS ),
			'hosts'    => self::by_length( array_keys( self::PRONOUN_HOSTS ) ),
			'standard' => self::by_length( array_keys( self::STANDARD ) ),
			'y_verb'   => self::by_length( self::Y_VERB ),
			'verb'     => self::by_length( array_merge( self::VERB_GU, self::VERB ) ),
			'residual' => self::by_length( self::RESIDUAL ),
		);
	}

	/**
	 * Devuelve la raíz de una palabra.
	 *
	 * @param string $word Palabra en minúsculas, UTF-8.
	 * @return string Raíz.
	 */
	public function stem( string $word ): string {
		$this->regions( $word );

		$word = $this->attached_pronoun( $word );

		$before = $word;
		$word   = $this->standard_suffix( $word );
		if ( $word === $before ) {
			$before = $word;
			$word   = $this->y_verb_suffix( $word );
			if ( $word === $before ) {
				$word = $this->verb_suffix( $word );
			}
		}

		$word = $this->residual_suffix( $word );

		return strtr( $word, self::ACCENTS );
	}

	/**
	 * Calcula RV, R1 y R2.
	 *
	 * @param string $word Palabra.
	 */
	private function regions( string $word ): void {
		$chars = self::chars( $word );
		$count = count( $chars );
		$end   = strlen( $word );

		$this->rv = $end;
		if ( $count >= 2 ) {
			if ( ! $this->is_vowel( $chars[1][0] ) ) {
				$this->rv = $this->after_first( $chars, 2, true, $end );
			} elseif ( $this->is_vowel( $chars[0][0] ) ) {
				$this->rv = $this->after_first( $chars, 2, false, $end );
			} elseif ( $count >= 3 ) {
				$this->rv = $chars[2][1] + strlen( $chars[2][0] );
			}
		}

		$this->r1 = $this->region_start( $chars, 0, $end );
		$this->r2 = $this->region_start( $chars, $this->r1, $end );
	}

	/**
	 * Posición tras la primera vocal (o consonante) desde el carácter indicado.
	 *
	 * @param list<array{0: string, 1: int}> $chars Caracteres con su desplazamiento.
	 * @param int                            $from  Índice del primer carácter a mirar.
	 * @param bool                           $vowel Buscar vocal (true) o consonante (false).
	 * @param int                            $end   Longitud de la palabra en bytes.
	 * @return int Desplazamiento en bytes.
	 */
	private function after_first( array $chars, int $from, bool $vowel, int $end ): int {
		$count = count( $chars );
		for ( $i = $from; $i < $count; $i++ ) {
			if ( $this->is_vowel( $chars[ $i ][0] ) === $vowel ) {
				return $chars[ $i ][1] + strlen( $chars[ $i ][0] );
			}
		}

		return $end;
	}

	/**
	 * Región tras la primera consonante que sigue a una vocal, a partir de $from.
	 *
	 * @param list<array{0: string, 1: int}> $chars Caracteres con su desplazamiento.
	 * @param int                            $from  Desplazamiento en bytes donde empieza la búsqueda.
	 * @param int                            $end   Longitud de la palabra en bytes.
	 * @return int Desplazamiento en bytes.
	 */
	private function region_start( array $chars, int $from, int $end ): int {
		$count = count( $chars );
		for ( $i = 1; $i < $count; $i++ ) {
			if ( $chars[ $i - 1 ][1] < $from ) {
				continue;
			}
			if ( $this->is_vowel( $chars[ $i - 1 ][0] ) && ! $this->is_vowel( $chars[ $i ][0] ) ) {
				return $chars[ $i ][1] + strlen( $chars[ $i ][0] );
			}
		}

		return $end;
	}

	/**
	 * Paso 0: pronombre enclítico tras gerundio o infinitivo.
	 *
	 * @param string $word Palabra.
	 * @return string Palabra sin el pronombre.
	 */
	private function attached_pronoun( string $word ): string {
		$pronoun = self::longest( $word, $this->sorted['pronouns'] );
		if ( '' === $pronoun ) {
			return $word;
		}

		$rest = substr( $word, 0, -strlen( $pronoun ) );
		$host = self::longest( $rest, $this->sorted['hosts'] );
		if ( '' === $host ) {
			return $word;
		}

		$start = strlen( $rest ) - strlen( $host );
		if ( $start < $this->rv ) {
			return $word;
		}
		if ( 'yendo' === $host && ! str_ends_with( substr( $rest, 0, $start ), 'u' ) ) {
			return $word;
		}

		return substr( $rest, 0, $start ) . self::PRONOUN_HOSTS[ $host ];
	}

	/**
	 * Paso 1: sufijos derivativos.
	 *
	 * @param string $word Palabra.
	 * @return string Palabra con el sufijo tratado.
	 */
	private function standard_suffix( string $word ): string {
		$suffix = self::longest( $word, $this->sorted['standard'] );
		if ( '' === $suffix ) {
			return $word;
		}

		$start = strlen( $word ) - strlen( $suffix );
		$stem  = substr( $word, 0, $start );
		$rule  = self::STANDARD[ $suffix ];

		if ( 'amente' === $rule ) {
			if ( $start < $this->r1 ) {
				return $word;
			}
			if ( $this->ends_in( $stem, 'iv', $this->r2 ) ) {
				$stem = substr( $stem, 0, -2 );
				if ( $this->ends_in( $stem, 'at', $this->r2 ) ) {
					$stem = substr( $stem, 0, -2 );
				}
			} else {
				foreach ( array( 'os', 'ic', 'ad' ) as $prev ) {
					if ( $this->ends_in( $stem, $prev, $this->r2 ) ) {
						$stem = substr( $stem, 0, -2 );
						break;
					}
				}
			}

			return $stem;
		}

		if ( $start < $this->r2 ) {
			return $word;
		}

		switch ( $rule ) {
			case 'log':
				return $stem . 'log';
			case 'u':
				return $stem . 'u';
			case 'ente':
				return $stem . 'ente';
			case 'r2_ic':
				return $this->ends_in( $stem, 'ic', $this->r2 ) ? substr( $stem, 0, -2 ) : $stem;
			case 'mente':
				foreach ( array( 'ante', 'able', 'ible' ) as $prev ) {
					if ( $this->ends_in( $stem, $prev, $this->r2 ) ) {
						return substr( $stem, 0, -4 );
					}
				}
				return $stem;
			case 'idad':
				foreach ( array( 'abil', 'ic', 'iv' ) as $prev ) {
					if ( $this->ends_in( $stem, $prev, $this->r2 ) ) {
						return substr( $stem, 0, -strlen( $prev ) );
					}
				}
				return $stem;
			case 'ivo':
				return $this->ends_in( $stem, 'at', $this->r2 ) ? substr( $stem, 0, -2 ) : $stem;
			default:
				return $stem;
		}//end switch
	}

	/**
	 * Paso 2a: formas verbales que empiezan por y, tras u.
	 *
	 * @param string $word Palabra.
	 * @return string Palabra con el sufijo tratado.
	 */
	private function y_verb_suffix( string $word ): string {
		$suffix = $this->longest_in( $word, $this->sorted['y_verb'], $this->rv );
		if ( '' === $suffix ) {
			return $word;
		}

		$stem = substr( $word, 0, -strlen( $suffix ) );

		return str_ends_with( $stem, 'u' ) ? $stem : $word;
	}

	/**
	 * Paso 2b: resto de formas verbales.
	 *
	 * @param string $word Palabra.
	 * @return string Palabra con el sufijo tratado.
	 */
	private function verb_suffix( string $word ): string {
		$suffix = $this->longest_in( $word, $this->sorted['verb'], $this->rv );
		if ( '' === $suffix ) {
			return $word;
		}

		$stem = substr( $word, 0, -strlen( $suffix ) );
		if ( in_array( $suffix, self::VERB_GU, true ) && str_ends_with( $stem, 'gu' ) ) {
			$stem = substr( $stem, 0, -1 );
		}

		return $stem;
	}

	/**
	 * Paso 3: vocal residual.
	 *
	 * @param string $word Palabra.
	 * @return string Palabra con el sufijo tratado.
	 */
	private function residual_suffix( string $word ): string {
		$suffix = $this->longest_in( $word, $this->sorted['residual'], $this->rv );
		if ( '' !== $suffix ) {
			return substr( $word, 0, -strlen( $suffix ) );
		}

		foreach ( array( 'e', 'é' ) as $vowel ) {
			if ( $this->ends_in( $word, $vowel, $this->rv ) ) {
				$stem = substr( $word, 0, -strlen( $vowel ) );
				if ( str_ends_with( $stem, 'gu' ) && strlen( $stem ) - 1 >= $this->rv ) {
					$stem = substr( $stem, 0, -1 );
				}
				return $stem;
			}
		}

		return $word;
	}

	/**
	 * ¿Termina la palabra en el sufijo y este empieza dentro de la región?
	 *
	 * @param string $word   Palabra.
	 * @param string $suffix Sufijo.
	 * @param int    $region Inicio de la región en bytes.
	 * @return bool
	 */
	private function ends_in( string $word, string $suffix, int $region ): bool {
		return str_ends_with( $word, $suffix ) && strlen( $word ) - strlen( $suffix ) >= $region;
	}

	/**
	 * Sufijo más largo de la lista que termina la palabra y cabe en la región.
	 *
	 * @param string   $word     Palabra.
	 * @param string[] $suffixes Sufijos ordenados de mayor a menor longitud.
	 * @param int      $region   Inicio de la región en bytes.
	 * @return string Sufijo, o cadena vacía.
	 */
	private function longest_in( string $word, array $suffixes, int $region ): string {
		foreach ( $suffixes as $suffix ) {
			if ( $this->ends_in( $word, $suffix, $region ) ) {
				return $suffix;
			}
		}

		return '';
	}

	/**
	 * ¿Es vocal?
	 *
	 * @param string $char Carácter UTF-8.
	 * @return bool
	 */
	private function is_vowel( string $char ): bool {
		return isset( self::VOWELS[ $char ] );
	}

	/**
	 * Sufijo más largo de la lista que termina la palabra.
	 *
	 * @param string   $word     Palabra.
	 * @param string[] $suffixes Sufijos ordenados de mayor a menor longitud.
	 * @return string Sufijo, o cadena vacía.
	 */
	private static function longest( string $word, array $suffixes ): string {
		foreach ( $suffixes as $suffix ) {
			if ( str_ends_with( $word, $suffix ) ) {
				return $suffix;
			}
		}

		return '';
	}

	/**
	 * Ordena sufijos de mayor a menor longitud en bytes.
	 *
	 * @param string[] $suffixes Sufijos.
	 * @return list<string>
	 */
	private static function by_length( array $suffixes ): array {
		usort(
			$suffixes,
			static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a )
		);

		return $suffixes;
	}

	/**
	 * Caracteres UTF-8 con su desplazamiento en bytes.
	 *
	 * @param string $word Palabra.
	 * @return list<array{0: string, 1: int}>
	 */
	private static function chars( string $word ): array {
		$chars = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE );

		return false === $chars ? array() : $chars;
	}
}
