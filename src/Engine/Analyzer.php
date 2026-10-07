<?php
/**
 * Análisis léxico de un documento: términos por campo.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

use MagicLinking\Engine\Stemmer\English;
use MagicLinking\Engine\Stemmer\Spanish;
use MagicLinking\Engine\Stemmer\StemmerInterface;

/**
 * Convierte texto en términos: n-gramas de claves de raíz.
 *
 * La clave de raíz de una palabra es la raíz de su forma normalizada, sin
 * tildes (salvo la ñ); las palabras vacías internas de un n-grama se comparan
 * por su clave sin raíz. Los n-gramas siguen la regla de
 * {@see Tokenizer::ngrams()}: no empiezan ni terminan con palabra vacía ni
 * cruzan puntuación o frases.
 */
final class Analyzer {

	public const TITLE   = 1;
	public const HEADING = 2;
	public const BODY    = 4;
	public const FOCUS   = 8;

	/**
	 * Palabras que acompañan a una cifra (magnitudes, unidades, monedas, tiempo,
	 * meses y días): junto a números no forman un término («1 GW», «100.000
	 * millones», «25 de septiembre», «60 años»). Por clave: sin tildes, con ñ.
	 */
	public const NUMERIC_WORDS = array(
		// Magnitudes.
		'mil',
		'miles',
		'millon',
		'millones',
		'billon',
		'billones',
		'millardo',
		'millardos',
		'thousand',
		'thousands',
		'million',
		'millions',
		'billion',
		'billions',
		'trillion',
		'trillions',
		'bn',
		'mn',
		'k',
		'm',
		// Unidades.
		'w',
		'kw',
		'mw',
		'gw',
		'tw',
		'wh',
		'kwh',
		'mwh',
		'gwh',
		'twh',
		'v',
		'kv',
		'mv',
		'a',
		'ma',
		'mah',
		'hz',
		'khz',
		'mhz',
		'ghz',
		'thz',
		'b',
		'kb',
		'mb',
		'gb',
		'tb',
		'pb',
		'eb',
		'bit',
		'bits',
		'kbps',
		'mbps',
		'gbps',
		'tbps',
		'tflops',
		'pflops',
		'nm',
		'um',
		'mm',
		'cm',
		'dm',
		'km',
		'm2',
		'm3',
		'g',
		'mg',
		'kg',
		't',
		'l',
		'ml',
		'cl',
		'dl',
		'pulgada',
		'pulgadas',
		'inch',
		'inches',
		'grado',
		'grados',
		'degrees',
		'c',
		'f',
		'x',
		'por',
		'ciento',
		'percent',
		'porcentaje',
		'pct',
		'veces',
		'times',
		// Monedas.
		'euro',
		'euros',
		'eur',
		'dolar',
		'dolares',
		'dollar',
		'dollars',
		'usd',
		'libra',
		'libras',
		'pound',
		'pounds',
		'gbp',
		'yen',
		'yenes',
		'yuan',
		'yuanes',
		'centimo',
		'centimos',
		'cents',
		// Tiempo.
		'año',
		'años',
		'mes',
		'meses',
		'semana',
		'semanas',
		'dia',
		'dias',
		'hora',
		'horas',
		'minuto',
		'minutos',
		'segundo',
		'segundos',
		'siglo',
		'siglos',
		'trimestre',
		'trimestres',
		'semestre',
		'semestres',
		'year',
		'years',
		'month',
		'months',
		'week',
		'weeks',
		'day',
		'days',
		'hour',
		'hours',
		'minute',
		'minutes',
		'second',
		'seconds',
		'quarter',
		'quarters',
		'century',
		'h',
		'min',
		's',
		'ms',
		// Meses y días.
		'enero',
		'febrero',
		'marzo',
		'abril',
		'mayo',
		'junio',
		'julio',
		'agosto',
		'septiembre',
		'setiembre',
		'octubre',
		'noviembre',
		'diciembre',
		'january',
		'february',
		'march',
		'april',
		'may',
		'june',
		'july',
		'august',
		'september',
		'october',
		'november',
		'december',
		'lunes',
		'martes',
		'miercoles',
		'jueves',
		'viernes',
		'sabado',
		'domingo',
		'monday',
		'tuesday',
		'wednesday',
		'thursday',
		'friday',
		'saturday',
		'sunday',
	);

	/**
	 * Formas verbales frecuentes que no pueden abrir ni cerrar un ancla
	 * («Anthropic podría»). Por clave. Las de ser, estar, haber y tener ya son
	 * palabras vacías y nunca quedan en un extremo.
	 */
	public const VERB_FORMS = array(
		'puede',
		'pueden',
		'podia',
		'podian',
		'podra',
		'podran',
		'podria',
		'podrian',
		'pudo',
		'pudieron',
		'poder',
		'debe',
		'deben',
		'debia',
		'debian',
		'debera',
		'deberan',
		'deberia',
		'deberian',
		'hace',
		'hacen',
		'hacia',
		'hacian',
		'hizo',
		'hicieron',
		'hara',
		'haran',
		'haria',
		'harian',
		'va',
		'van',
		'iba',
		'iban',
		'ira',
		'iran',
		'iria',
		'irian',
		'quiere',
		'quieren',
		'queria',
		'querian',
		'querra',
		'querria',
		'sigue',
		'siguen',
		'seguira',
		'seguiran',
		'permite',
		'permiten',
		'permitira',
		'permitiria',
		'busca',
		'buscan',
		'llega',
		'llegan',
		'parece',
		'parecen',
		'dice',
		'dicen',
		'dijo',
		'afirma',
		'asegura',
		'explica',
		'señala',
		'anuncia',
		'presenta',
		'lanza',
		'ofrece',
		'ofrecen',
		'incluye',
		'incluyen',
		'convierte',
		'supone',
		'necesita',
		'necesitan',
		'cuentan',
		'can',
		'could',
		'may',
		'might',
		'must',
		'shall',
		'should',
		'will',
		'would',
		'says',
		'said',
		'makes',
		'made',
		'gets',
		'got',
		'goes',
		'went',
		'wants',
		'seems',
		'announced',
		'announces',
		'launches',
		'launched',
		'offers',
		'includes',
		'allows',
		'plans',
	);

	/**
	 * Terminaciones verbales del castellano (sobre la forma con tildes) que casi
	 * nunca son sustantivo: condicional plural, pretérito y pretérito imperfecto.
	 */
	public const VERB_SUFFIXES_ES = '/(?:rían|ríamos|aron|ieron|aban|ábamos|ió|ó)$/u';

	/**
	 * Participio del castellano que, seguido de una palabra vacía, abre una
	 * perífrasis verbal («reafirmado el apoyo»).
	 */
	public const PARTICIPLE_ES = '/(?:ado|ido|ada|ida|ados|idos|adas|idas)$/u';

	/**
	 * Tamaño máximo de la memoria de raíces.
	 */
	private const CACHE_SIZE = 200000;

	/**
	 * Idioma dominante de cada frase si no es el de la entrada ('' si lo es).
	 *
	 * @var \WeakMap<Sentence, string>|null
	 */
	private ?\WeakMap $foreign = null;

	/**
	 * Analizadores por idioma.
	 *
	 * @var array<string, self>
	 */
	private static array $languages = array();

	/**
	 * Raíces ya calculadas: forma normalizada → clave de raíz.
	 *
	 * @var array<string, string>
	 */
	private array $cache = array();

	/**
	 * NUMERIC_WORDS como conjunto.
	 *
	 * @var array<string, int>
	 */
	private array $numeric;

	/**
	 * VERB_FORMS como conjunto.
	 *
	 * @var array<string, int>
	 */
	private array $verbs;

	/**
	 * Crea el analizador.
	 *
	 * @param Tokenizer             $tokenizer Tokenizador del idioma.
	 * @param StemmerInterface|null $stemmer   Stemmer del idioma; sin él, la raíz es la palabra.
	 * @param int                   $max       Palabras como máximo por n-grama.
	 * @param string                $language  Código de idioma de dos letras (para las reglas propias del castellano).
	 */
	public function __construct(
		private Tokenizer $tokenizer,
		private ?StemmerInterface $stemmer = null,
		private int $max = 3,
		private string $language = ''
	) {
		$this->numeric = array_flip( self::NUMERIC_WORDS );
		$this->verbs   = array_flip( self::VERB_FORMS );
	}

	/**
	 * Analizador de un idioma, compartido.
	 *
	 * @param string $language Código de idioma o locale.
	 */
	public static function for_language( string $language ): self {
		$code = strtolower( substr( $language, 0, 2 ) );
		if ( ! isset( self::$languages[ $code ] ) ) {
			$stemmer = match ( $code ) {
				'es'    => new Spanish(),
				'en'    => new English(),
				default => null,
			};
			self::$languages[ $code ] = new self( Tokenizer::for_language( $code ), $stemmer, 3, $code );
		}
		return self::$languages[ $code ];
	}

	/**
	 * Tokenizador del analizador.
	 */
	public function tokenizer(): Tokenizer {
		return $this->tokenizer;
	}

	/**
	 * Si un token es palabra de contenido: no vacía, no es una cifra
	 * («2025», «100.000», «3,5») y no es una palabra que acompaña a cifras
	 * (NUMERIC_WORDS). Un término o un ancla necesita al menos una.
	 *
	 * @param Token $token Token.
	 */
	public function is_content( Token $token ): bool {
		return ! $token->is_stopword
			&& 1 !== preg_match( '/^[\p{N}.,]+$/u', $token->key )
			&& ! isset( $this->numeric[ $token->key ] );
	}

	/**
	 * Si un token es una palabra que acompaña a cifras (NUMERIC_WORDS: unidades,
	 * magnitudes, monedas, tiempo, meses).
	 *
	 * @param Token $token Token.
	 */
	public function is_numeric_word( Token $token ): bool {
		return isset( $this->numeric[ $token->key ] );
	}

	/**
	 * Si un token es una forma verbal que no puede quedar en el extremo de un
	 * ancla: está en VERB_FORMS o, en castellano, termina como un verbo
	 * conjugado (VERB_SUFFIXES_ES) o es un participio seguido de palabra vacía.
	 * Heurística sin análisis gramatical.
	 *
	 * @param Token      $token Token.
	 * @param Token|null $next  Token siguiente dentro del ancla, si lo hay.
	 */
	public function is_verb_like( Token $token, ?Token $next = null ): bool {
		if ( isset( $this->verbs[ $token->key ] ) ) {
			return true;
		}
		if ( 'es' !== $this->language || mb_strlen( $token->normal ) < 4 ) {
			return false;
		}
		if ( 1 === preg_match( self::VERB_SUFFIXES_ES, $token->normal ) ) {
			return true;
		}
		return null !== $next && $next->is_stopword && 1 === preg_match( self::PARTICIPLE_ES, $token->normal );
	}

	/**
	 * Idioma de la frase si no es el de la entrada: otro idioma con lista de
	 * palabras vacías cuyas palabras vacías aparecen al menos dos veces y más del
	 * doble que las del idioma de la entrada. Cubre contenido en castellano en un
	 * sitio o una entrada declarados en inglés (o al revés), donde las palabras
	 * vacías del texto no se reconocen y quedarían en los extremos de un ancla.
	 * No cambia el idioma de la entrada (regla 9); solo se usa para recortar.
	 *
	 * @param Sentence $sentence Frase (el resultado se recuerda mientras exista el objeto).
	 * @return string|null Código del otro idioma, o null si la frase es del idioma de la entrada.
	 */
	public function foreign_language( Sentence $sentence ): ?string {
		$this->foreign ??= new \WeakMap();
		if ( isset( $this->foreign[ $sentence ] ) ) {
			return '' === $this->foreign[ $sentence ] ? null : $this->foreign[ $sentence ];
		}
		$own     = 0;
		$foreign = array();
		foreach ( $sentence->tokens as $token ) {
			if ( $token->is_stopword ) {
				++$own;
			}
			foreach ( Stopwords::languages() as $code ) {
				if ( $code !== $this->language && Stopwords::is( $token->normal, $code ) ) {
					$foreign[ $code ] = ( $foreign[ $code ] ?? 0 ) + 1;
				}
			}
		}
		arsort( $foreign );
		$code  = (string) array_key_first( $foreign );
		$found = '' !== $code && $foreign[ $code ] >= 2 && $foreign[ $code ] > 2 * $own ? $code : null;

		$this->foreign[ $sentence ] = $found ?? '';
		return $found;
	}

	/**
	 * Si un token no puede quedar en el extremo de un ancla ni de un término que
	 * se enseña: es palabra vacía del idioma de la entrada, de otro idioma que
	 * domina la frase ($foreign) o el extremo vacío de una palabra con guion
	 * («Castilla-La», «Smith-de»).
	 *
	 * @param Token       $token   Token.
	 * @param bool        $leading Si está al principio del ancla (false: al final).
	 * @param string|null $foreign Idioma que domina la frase, de {@see foreign_language()}.
	 */
	public function is_empty_edge( Token $token, bool $leading, ?string $foreign = null ): bool {
		if ( $token->is_stopword || ( null !== $foreign && Stopwords::is( $token->normal, $foreign ) ) ) {
			return true;
		}
		if ( ! str_contains( $token->normal, '-' ) ) {
			return false;
		}
		$parts = explode( '-', $token->normal );
		$edge  = $leading ? $parts[0] : $parts[ count( $parts ) - 1 ];
		return '' === $edge || Stopwords::is( $edge, $this->language ) || ( null !== $foreign && Stopwords::is( $edge, $foreign ) );
	}

	/**
	 * Término listo para enseñar en un motivo: la forma de superficie sin
	 * palabras vacías (de cualquier idioma con lista) en los extremos. Es solo
	 * presentación; no es una decisión de idioma.
	 *
	 * @param string $surface Forma de superficie en minúsculas («de calefacción»).
	 * @return string|null Null si no queda ninguna palabra de contenido o es una forma verbal suelta.
	 */
	public function display_term( string $surface ): ?string {
		$tokens = $this->tokenizer->tokenize( $surface );
		$from   = 0;
		$to     = count( $tokens ) - 1;
		while ( $from <= $to && $this->is_listed_stopword( $tokens[ $from ] ) ) {
			++$from;
		}
		while ( $to >= $from && $this->is_listed_stopword( $tokens[ $to ] ) ) {
			--$to;
		}
		$span = array_slice( $tokens, $from, $to - $from + 1 );
		if ( array() === $span || ( 1 === count( $span ) && $this->is_verb_like( $span[0] ) ) ) {
			return null;
		}
		foreach ( $span as $token ) {
			if ( $this->is_content( $token ) ) {
				return implode( ' ', array_map( static fn( Token $t ): string => $t->surface, $span ) );
			}
		}
		return null;
	}

	/**
	 * Si un token es palabra vacía de algún idioma con lista.
	 *
	 * @param Token $token Token.
	 */
	private function is_listed_stopword( Token $token ): bool {
		if ( $token->is_stopword ) {
			return true;
		}
		foreach ( Stopwords::languages() as $code ) {
			if ( Stopwords::is( $token->normal, $code ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Clave de raíz de un token.
	 *
	 * @param Token $token Token.
	 */
	public function key( Token $token ): string {
		if ( $token->is_stopword ) {
			return $token->key;
		}
		if ( isset( $this->cache[ $token->normal ] ) ) {
			return $this->cache[ $token->normal ];
		}
		$stem = null === $this->stemmer ? $token->normal : $this->stemmer->stem( $token->normal );
		$key  = $this->tokenizer->key( $stem );
		if ( count( $this->cache ) >= self::CACHE_SIZE ) {
			$this->cache = array();
		}
		$this->cache[ $token->normal ] = $key;
		return $key;
	}

	/**
	 * Claves de raíz de una lista de tokens.
	 *
	 * @param Token[] $tokens Tokens.
	 * @return list<string>
	 *
	 * @phpstan-param list<Token> $tokens
	 */
	public function keys( array $tokens ): array {
		$keys = array();
		foreach ( $tokens as $token ) {
			$keys[] = $this->key( $token );
		}
		return $keys;
	}

	/**
	 * Clave de un texto corto entero (un ancla): claves de raíz de todas sus
	 * palabras separadas por un espacio.
	 *
	 * @param string $text Texto.
	 */
	public function phrase_key( string $text ): string {
		return implode( ' ', $this->keys( $this->tokenizer->tokenize( $text ) ) );
	}

	/**
	 * Términos de un texto sin frases (título, encabezado, frase objetivo).
	 *
	 * @param string $text Texto.
	 * @return array<string, int> Término → frecuencia.
	 */
	public function terms( string $text ): array {
		$counts = array();
		foreach ( $this->tokenizer->sentences( $text ) as $sentence ) {
			$tokens = $this->tokenizer->tokenize( $sentence );
			$none   = array();
			$this->count( $tokens, $this->keys( $tokens ), $counts, $none, false );
		}
		return $counts;
	}

	/**
	 * Analiza un documento.
	 *
	 * @param Document $document  Documento.
	 * @param bool     $sentences Si se guardan las frases y las formas para mostrar
	 *                            (para buscar anclas; el indexado no las necesita).
	 */
	public function analyze( Document $document, bool $sentences = false ): AnalyzedDocument {
		$counts   = array(
			self::TITLE   => array(),
			self::HEADING => array(),
			self::BODY    => array(),
			self::FOCUS   => array(),
		);
		$length   = 0;
		$words    = 0;
		$list     = array();
		$surfaces = array();

		$fields = array( array( self::TITLE, array( $document->title ) ), array( self::HEADING, $document->headings ), array( self::FOCUS, $document->focus ) );
		foreach ( $fields as [ $field, $texts ] ) {
			foreach ( $texts as $text ) {
				foreach ( $this->tokenizer->sentences( $text ) as $sentence ) {
					$tokens  = $this->tokenizer->tokenize( $sentence );
					$length += $this->count( $tokens, $this->keys( $tokens ), $counts[ $field ], $surfaces, $sentences );
				}
			}
		}

		foreach ( $document->paragraphs as $p => $paragraph ) {
			$cursor = 0;
			foreach ( $this->tokenizer->sentences( $paragraph->text ) as $sentence ) {
				$tokens  = $this->tokenizer->tokenize( $sentence );
				$keys    = $this->keys( $tokens );
				$words  += count( $tokens );
				$length += $this->count( $tokens, $keys, $counts[ self::BODY ], $surfaces, $sentences );

				if ( $sentences && array() !== $tokens ) {
					$list[] = new Sentence( $sentence, $p, $tokens, $keys, $this->sentence_has_link( $paragraph, $sentence, $cursor ) );
				}
			}
		}

		$shown = array();
		foreach ( $surfaces as $term => $forms ) {
			arsort( $forms );
			$shown[ $term ] = (string) array_key_first( $forms );
		}

		return new AnalyzedDocument( $document, $counts, $length, $words, $list, $shown );
	}

	/**
	 * Cuenta los n-gramas de una frase.
	 *
	 * @param Token[]  $tokens   Tokens de la frase.
	 * @param string[] $keys     Claves de raíz de los tokens.
	 * @param array    $counts   Acumulador término → frecuencia.
	 * @param array    $surfaces Acumulador término → forma → frecuencia.
	 * @param bool     $shown    Si se acumulan las formas.
	 * @return int Palabras no vacías de la frase.
	 *
	 * @phpstan-param list<Token> $tokens
	 * @phpstan-param list<string> $keys
	 * @phpstan-param array<string, int> $counts
	 * @phpstan-param array<string, array<string, int>> $surfaces
	 */
	private function count( array $tokens, array $keys, array &$counts, array &$surfaces, bool $shown ): int {
		$total   = count( $tokens );
		$words   = 0;
		$content = array();
		foreach ( $tokens as $n => $token ) {
			$content[ $n ] = $this->is_content( $token );
		}
		for ( $i = 0; $i < $total; $i++ ) {
			if ( $tokens[ $i ]->is_stopword ) {
				continue;
			}
			++$words;
			$term   = '';
			$normal = '';
			$has    = false;
			for ( $size = 1; $size <= $this->max && $i + $size <= $total; $size++ ) {
				$last = $tokens[ $i + $size - 1 ];
				if ( $size > 1 && $last->break_before ) {
					break;
				}
				$term   .= ( 1 === $size ? '' : ' ' ) . $keys[ $i + $size - 1 ];
				$normal .= ( 1 === $size ? '' : ' ' ) . $last->normal;
				$has     = $has || $content[ $i + $size - 1 ];
				// Sin palabra de contenido («1 GW», «2025») no es un término.
				if ( $last->is_stopword || ! $has ) {
					continue;
				}
				$counts[ $term ] = ( $counts[ $term ] ?? 0 ) + 1;
				if ( $shown ) {
					$surfaces[ $term ][ $normal ] = ( $surfaces[ $term ][ $normal ] ?? 0 ) + 1;
				}
			}
		}//end for
		return $words;
	}

	/**
	 * Si la frase toca un enlace existente del párrafo. Localiza la frase en el
	 * párrafo desde la posición $cursor; si no la encuentra (el texto cambió al
	 * normalizar), la da por enlazada si el párrafo tiene algún enlace.
	 *
	 * @param Paragraph $paragraph Párrafo.
	 * @param string    $sentence  Frase.
	 * @param int       $cursor    Posición desde la que buscar; avanza.
	 */
	private function sentence_has_link( Paragraph $paragraph, string $sentence, int &$cursor ): bool {
		if ( array() === $paragraph->links ) {
			return false;
		}
		$start = strpos( $paragraph->text, $sentence, $cursor );
		if ( false === $start ) {
			return true;
		}
		$cursor = $start + strlen( $sentence );
		return $paragraph->has_link_in( $start, $cursor );
	}
}
