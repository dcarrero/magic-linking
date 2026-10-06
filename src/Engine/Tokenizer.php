<?php
/**
 * Segmentación en frases, normalización, tokens y n-gramas.
 *
 * Clase pura: no usa WordPress, así que se comporta igual en el banco de pruebas
 * y en el plugin. Necesita mbstring; usa intl (Normalizer) si está disponible y,
 * si no, una tabla propia que da la misma clave de comparación.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Tokenizador de un idioma. Las palabras vacías se inyectan por constructor.
 */
final class Tokenizer {

	/**
	 * Abreviaturas tras las que un punto nunca cierra frase. En minúsculas, sin el
	 * punto final y con los puntos internos que tengan («p.ej», «e.g»).
	 */
	private const ABBREVIATIONS = array(
		// Castellano: tratamientos y cargos.
		'sr',
		'sra',
		'srta',
		'sres',
		'sras',
		'dr',
		'dra',
		'dres',
		'dras',
		'dña',
		'ud',
		'uds',
		'vd',
		'vds',
		'lic',
		'lcdo',
		'lcda',
		'ing',
		'arq',
		'prof',
		'profa',
		'excmo',
		'excma',
		'ilmo',
		'ilma',
		'sto',
		'sta',
		'mons',
		'gral',
		'cnel',
		'tte',
		// Castellano: referencias y direcciones.
		'pág',
		'págs',
		'núm',
		'nº',
		'n.º',
		'art',
		'arts',
		'cap',
		'vol',
		'vols',
		'fig',
		'tel',
		'tlf',
		'telf',
		'aprox',
		'avda',
		'av',
		'pza',
		'apdo',
		'dpto',
		'depto',
		'admón',
		'ej',
		'p.ej',
		'cf',
		'cfr',
		'vid',
		'ibid',
		'ee.uu',
		// Inglés.
		'mr',
		'mrs',
		'ms',
		'messrs',
		'rev',
		'hon',
		'st',
		'mt',
		'sgt',
		'capt',
		'col',
		'gen',
		'lt',
		'gov',
		'sen',
		'rep',
		'vs',
		'e.g',
		'i.e',
		'approx',
		'dept',
		'est',
		'u.s',
		'u.k',
	);

	/**
	 * Abreviaturas que solo lo son delante de un número («No. 5», «pp. 12»); en
	 * otro caso son palabras normales («Dije que no. Luego...»).
	 */
	private const NUMBER_ABBREVIATIONS = array( 'no', 'nos', 'pp', 'pg', 'ch', 'sec', 'n' );

	/**
	 * Signos que cierran una cita o un paréntesis detrás del punto.
	 */
	private const CLOSERS = '»"”’\')\]';

	/**
	 * Letras, números, marcas combinatorias y guion blando.
	 */
	private const WORD_CHARS = '\p{L}\p{N}\p{M}\x{00AD}';

	/**
	 * Apóstrofos, comillas y guiones tipográficos unificados.
	 */
	private const PUNCTUATION_MAP = array(
		'’'        => "'",
		'‘'        => "'",
		'ʼ'        => "'",
		'′'        => "'",
		'´'        => "'",
		'`'        => "'",
		'“'        => '"',
		'”'        => '"',
		'„'        => '"',
		'«'        => '"',
		'»'        => '"',
		'″'        => '"',
		'‐'        => '-',
		'‑'        => '-',
		"\u{00AD}" => '',
		"\u{200B}" => '',
		"\u{FEFF}" => '',
	);

	/**
	 * Letras latinas con diacrítico → letra base, para cuando no hay intl. En
	 * minúsculas: se aplica después de mb_strtolower(). La ñ no está a propósito.
	 */
	private const FOLD_MAP = array(
		'à' => 'a',
		'á' => 'a',
		'â' => 'a',
		'ã' => 'a',
		'ä' => 'a',
		'å' => 'a',
		'ā' => 'a',
		'ç' => 'c',
		'ć' => 'c',
		'č' => 'c',
		'è' => 'e',
		'é' => 'e',
		'ê' => 'e',
		'ë' => 'e',
		'ē' => 'e',
		'ė' => 'e',
		'ę' => 'e',
		'ì' => 'i',
		'í' => 'i',
		'î' => 'i',
		'ï' => 'i',
		'ī' => 'i',
		'ò' => 'o',
		'ó' => 'o',
		'ô' => 'o',
		'õ' => 'o',
		'ö' => 'o',
		'ō' => 'o',
		'ù' => 'u',
		'ú' => 'u',
		'û' => 'u',
		'ü' => 'u',
		'ū' => 'u',
		'ý' => 'y',
		'ÿ' => 'y',
		'š' => 's',
		'ś' => 's',
		'ž' => 'z',
		'ź' => 'z',
		'ż' => 'z',
		'ń' => 'n',
		'ň' => 'n',
		'ř' => 'r',
		'ł' => 'l',
	);

	/**
	 * Palabras vacías indexadas por su clave.
	 *
	 * @var array<string, true>
	 */
	private array $stopwords = array();

	/**
	 * Abreviaturas indexadas por su forma en minúsculas.
	 *
	 * @var array<string, true>
	 */
	private array $abbreviations;

	/**
	 * Crea un tokenizador para un idioma.
	 *
	 * @param iterable $stopwords     Palabras vacías del idioma (con o sin tildes: se comparan por clave).
	 * @param iterable $abbreviations Abreviaturas adicionales, sin el punto final.
	 *
	 * @phpstan-param iterable<string> $stopwords
	 * @phpstan-param iterable<string> $abbreviations
	 */
	public function __construct( iterable $stopwords = array(), iterable $abbreviations = array() ) {
		foreach ( $stopwords as $word ) {
			$key = $this->key( $word );
			if ( '' !== $key ) {
				$this->stopwords[ $key ] = true;
			}
		}

		$this->abbreviations = array_fill_keys( self::ABBREVIATIONS, true );
		foreach ( $abbreviations as $abbreviation ) {
			$abbreviation = mb_strtolower( rtrim( trim( $abbreviation ), '.' ), 'UTF-8' );
			if ( '' !== $abbreviation ) {
				$this->abbreviations[ $abbreviation ] = true;
			}
		}
	}

	/**
	 * Tokenizador de un idioma con sus palabras vacías de {@see Stopwords}.
	 *
	 * Las listas se escriben con tildes («más», «él»); el constructor las pasa a
	 * clave, así que se reconocen también sin ellas («mas», «el»). Un idioma sin
	 * lista da un tokenizador sin palabras vacías.
	 *
	 * @param string   $language      Código de idioma o locale («es», «es_ES», «en-US»…).
	 * @param iterable $abbreviations Abreviaturas adicionales, sin el punto final.
	 *
	 * @phpstan-param iterable<string> $abbreviations
	 */
	public static function for_language( string $language, iterable $abbreviations = array() ): self {
		return new self( Stopwords::for( $language ), $abbreviations );
	}

	/**
	 * Parte un texto en frases, con el texto original (en NFC) y sin espacios en
	 * los extremos. Un salto de línea siempre cierra frase; los fragmentos sin
	 * letras ni números (separadores, emojis sueltos) se descartan.
	 *
	 * @param string $text Texto plano ya extraído del contenido.
	 * @return list<string>
	 */
	public function sentences( string $text ): array {
		$sentences = array();
		$lines     = preg_split( '/\R/u', $this->prepare( $text ) );

		foreach ( false === $lines ? array() : $lines as $line ) {
			foreach ( $this->split_line( $line ) as $sentence ) {
				if ( 1 === preg_match( '/[\p{L}\p{N}]/u', $sentence ) ) {
					$sentences[] = $sentence;
				}
			}
		}

		return $sentences;
	}

	/**
	 * Forma normalizada para mostrar y agrupar: NFC, minúsculas, apóstrofos,
	 * comillas y guiones unificados, espacios colapsados. Conserva las tildes.
	 *
	 * @param string $text Texto de entrada.
	 */
	public function normalize( string $text ): string {
		return $this->lower( $this->prepare( $text ) );
	}

	/**
	 * Clave de búsqueda: la forma normalizada sin diacríticos, salvo la ñ, que es
	 * otra letra («año» y «ano» no son la misma palabra).
	 *
	 * @param string $text Texto de entrada.
	 */
	public function key( string $text ): string {
		return $this->fold( $this->normalize( $text ) );
	}

	/**
	 * Normalización sobre texto ya preparado (UTF-8 válido y NFC).
	 *
	 * @param string $text Texto preparado.
	 */
	private function lower( string $text ): string {
		$text = strtr( $text, self::PUNCTUATION_MAP );
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = (string) preg_replace( '/[\s\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Quita los diacríticos de un texto ya normalizado, salvo la tilde de la ñ.
	 *
	 * @param string $text Texto normalizado.
	 */
	private function fold( string $text ): string {
		// La ñ se aparta para que la descomposición no le quite la tilde.
		$text = str_replace( 'ñ', "\u{E000}", $text );

		if ( class_exists( \Normalizer::class ) ) {
			$decomposed = \Normalizer::normalize( $text, \Normalizer::FORM_D );
			if ( false !== $decomposed ) {
				$text = $decomposed;
			}
		} else {
			$text = strtr( $text, self::FOLD_MAP );
		}

		$text = (string) preg_replace( '/\p{Mn}+/u', '', $text );

		return str_replace( "\u{E000}", 'ñ', $text );
	}

	/**
	 * Tokens de un texto: secuencias de letras y números, con guion o apóstrofo
	 * internos («wi-fi», «don't») y decimales («3,5», «1.500»). Las URL y los
	 * correos no producen tokens. Las posiciones son bytes del texto en NFC (el
	 * mismo texto si ya venía en NFC, que es lo habitual).
	 *
	 * @param string $text Texto, normalmente una frase.
	 * @return list<Token>
	 */
	public function tokenize( string $text ): array {
		$text   = $this->prepare( $text );
		$masked = (string) preg_replace_callback(
			'~(?:https?://|www\.)\S+|[\w.+-]+@[\w-]+(?:\.[\w-]+)+~u',
			static fn( array $m ): string => str_repeat( "\x01", strlen( $m[0] ) ),
			$text
		);

		$word    = '[' . self::WORD_CHARS . ']+';
		$pattern = '/' . $word . '(?:(?:[\'’\-\x{2010}\x{2011}]|(?<=\p{N})[.,](?=\p{N}))' . $word . ')*/u';

		if ( false === preg_match_all( $pattern, $masked, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$tokens   = array();
		$prev_end = null;
		foreach ( $matches[0] as $match ) {
			$surface = $match[0];
			$offset  = $match[1];
			$normal  = $this->lower( $surface );
			$key     = $this->fold( $normal );
			$gap     = null === $prev_end ? '' : substr( $masked, $prev_end, $offset - $prev_end );

			$tokens[] = new Token(
				$surface,
				$normal,
				$key,
				$offset,
				isset( $this->stopwords[ $key ] ),
				1 === preg_match( '/[^\s\x{00A0}]/u', $gap )
			);

			$prev_end = $offset + strlen( $surface );
		}

		return $tokens;
	}

	/**
	 * N-gramas de hasta $max palabras de una lista de tokens de una sola frase.
	 * Ninguno empieza ni termina con palabra vacía (pueden llevarlas dentro:
	 * «centro de datos») ni cruza puntuación. En orden de aparición y, en la
	 * misma posición, de menor a mayor.
	 *
	 * @param Token[] $tokens Tokens de una frase.
	 * @param int     $max    Palabras como máximo (por defecto 3).
	 * @return list<NGram>
	 *
	 * @phpstan-param list<Token> $tokens
	 */
	public function ngrams( array $tokens, int $max = 3 ): array {
		$max    = max( 1, $max );
		$count  = count( $tokens );
		$ngrams = array();

		for ( $i = 0; $i < $count; $i++ ) {
			if ( $tokens[ $i ]->is_stopword ) {
				continue;
			}
			for ( $size = 1; $size <= $max && $i + $size <= $count; $size++ ) {
				$last = $tokens[ $i + $size - 1 ];
				if ( $size > 1 && $last->break_before ) {
					break;
				}
				if ( ! $last->is_stopword ) {
					$ngrams[] = new NGram( array_slice( $tokens, $i, $size ) );
				}
			}
		}

		return $ngrams;
	}

	/**
	 * Todos los n-gramas de un texto, frase a frase: nunca cruzan una frontera
	 * de frase.
	 *
	 * @param string $text Texto plano.
	 * @param int    $max  Palabras como máximo por n-grama.
	 * @return list<NGram>
	 */
	public function terms( string $text, int $max = 3 ): array {
		$terms = array();
		foreach ( $this->sentences( $text ) as $sentence ) {
			array_push( $terms, ...$this->ngrams( $this->tokenize( $sentence ), $max ) );
		}

		return $terms;
	}

	/**
	 * Si una palabra es vacía en este tokenizador (se compara por clave).
	 *
	 * @param string $word Palabra.
	 */
	public function is_stopword( string $word ): bool {
		return isset( $this->stopwords[ $this->key( $word ) ] );
	}

	/**
	 * UTF-8 válido y en NFC. Sin intl, al menos recompone la ñ descompuesta.
	 *
	 * @param string $text Texto de entrada.
	 */
	private function prepare( string $text ): string {
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}

		if ( class_exists( \Normalizer::class ) ) {
			$nfc = \Normalizer::normalize( $text, \Normalizer::FORM_C );

			return false === $nfc ? $text : $nfc;
		}

		return str_replace( array( "n\u{0303}", "N\u{0303}" ), array( 'ñ', 'Ñ' ), $text );
	}

	/**
	 * Parte una línea en frases.
	 *
	 * @param string $line Una línea sin saltos.
	 * @return list<string>
	 */
	private function split_line( string $line ): array {
		$pattern = '/[.!?…]+[' . self::CLOSERS . ']*(?=\s|$)/u';
		if ( ! preg_match_all( $pattern, $line, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array( trim( $line ) );
		}

		$sentences = array();
		$start     = 0;
		foreach ( $matches[0] as $match ) {
			$end = $match[1] + strlen( $match[0] );
			if ( $this->ends_sentence( $line, $match[0], $match[1], $end ) ) {
				$sentences[] = trim( substr( $line, $start, $end - $start ) );
				$start       = $end;
			}
		}
		$sentences[] = trim( substr( $line, $start ) );

		return array_values( array_filter( $sentences, static fn( string $s ): bool => '' !== $s ) );
	}

	/**
	 * Decide si un signo de cierre termina la frase.
	 *
	 * @param string $line   Línea completa.
	 * @param string $mark   Signos encontrados («.», «?!», «.»»…).
	 * @param int    $offset Posición del primer signo.
	 * @param int    $end    Posición justo después de los signos.
	 */
	private function ends_sentence( string $line, string $mark, int $offset, int $end ): bool {
		$rest = ltrim( substr( $line, $end ), " \t\u{00A0}" );
		if ( '' === $rest ) {
			return true;
		}

		// Tras el cierre, una minúscula indica que la frase sigue.
		$next_is_lower = 1 === preg_match( '/^\p{Ll}/u', $rest );

		// Solo un punto simple sin comillas detrás puede ser de abreviatura.
		if ( '.' !== $mark ) {
			return ! $next_is_lower;
		}

		if ( 1 !== preg_match( '/(\S+)$/u', substr( $line, 0, $offset ), $m ) ) {
			return ! $next_is_lower;
		}

		$word  = (string) preg_replace( '/^[«"“‘\'(\[¿¡]+/u', '', $m[1] );
		$lower = mb_strtolower( $word, 'UTF-8' );

		if ( isset( $this->abbreviations[ $lower ] ) ) {
			return false;
		}

		// «No. 5», «pp. 12».
		if ( in_array( $lower, self::NUMBER_ABBREVIATIONS, true ) && 1 === preg_match( '/^\p{N}/u', $rest ) ) {
			return false;
		}

		// Inicial suelta: «J. K. Rowling», «p. ej.». Caso conocido: «vitamina C.
		// Es buena» no se parte, porque la letra suelta no se distingue de una
		// inicial sin mirar el significado (prueba incompleta en TokenizerTest).
		// Solo junta dos frases en una; los n-gramas siguen sin cruzar el punto.
		if ( 1 === preg_match( '/^\p{L}$/u', $word ) ) {
			return false;
		}

		// Abreviatura en varias partes: «EE. UU.», «FF. AA.», «CC. OO.».
		if ( 1 === preg_match( '/^\p{L}{1,2}$/u', $word ) && 1 === preg_match( '/^\p{L}{1,2}\.(?:\s|$)/u', $rest ) ) {
			return false;
		}

		return ! $next_is_lower;
	}
}
