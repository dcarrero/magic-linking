<?php
/**
 * Búsqueda de anclas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Frases objetivo de un destino y sus apariciones válidas en un origen.
 */
final class PhraseFinder {

	/**
	 * Palabras como máximo en un ancla.
	 */
	public const MAX_WORDS = 8;

	/**
	 * N-gramas de más peso del destino que se usan como frases objetivo.
	 */
	public const TOP_TERMS = 10;

	/**
	 * Anclas genéricas que nunca se proponen (se comparan por clave sin tildes).
	 */
	public const GENERIC = array(
		'aqui',
		'aca',
		'click',
		'clic',
		'haz clic',
		'haz click',
		'pincha aqui',
		'pulsa aqui',
		'este articulo',
		'este enlace',
		'esta pagina',
		'mas informacion',
		'mas info',
		'leer mas',
		'ver mas',
		'saber mas',
		'enlace',
		'link',
		'web',
		'pagina',
		'articulo',
		'here',
		'click here',
		'read more',
		'more',
		'more info',
		'this article',
		'this page',
		'this link',
		'learn more',
		'article',
		'page',
		'website',
	);

	/**
	 * Crea el buscador.
	 *
	 * @param int $max_words Palabras como máximo por ancla.
	 */
	public function __construct( private int $max_words = self::MAX_WORDS ) {
	}

	/**
	 * Frases objetivo de un destino: segmentos de su título (sin palabras vacías
	 * en los extremos y de hasta MAX_WORDS palabras), sus frases objetivo, sus
	 * TOP_TERMS términos de más peso y los n-gramas de su título que estén entre
	 * sus términos principales (los títulos largos no caben en un ancla).
	 *
	 * @param DocMeta  $target   Destino.
	 * @param array    $terms    Términos principales del destino, de mayor a menor peso.
	 * @param Analyzer $analyzer Analizador del idioma.
	 * @return list<Phrase> Sin repetir; si una clave sale por dos vías, gana la de más calidad.
	 *
	 * @phpstan-param array<string, float> $terms
	 */
	public function target_phrases( DocMeta $target, array $terms, Analyzer $analyzer ): array {
		$phrases = array();
		$add     = static function ( array $keys, string $kind ) use ( &$phrases ): void {
			$key = implode( ' ', $keys );
			if ( '' !== $key && ( ! isset( $phrases[ $key ] ) || Scorer::ANCHOR_QUALITY[ $kind ] > Scorer::ANCHOR_QUALITY[ $phrases[ $key ]->kind ] ) ) {
				$phrases[ $key ] = new Phrase( array_values( $keys ), $kind );
			}
		};

		$sources = array( array( $target->title, Phrase::TITLE ) );
		foreach ( $target->focus as $focus ) {
			$sources[] = array( $focus, Phrase::FOCUS );
		}
		foreach ( $sources as [ $text, $kind ] ) {
			foreach ( $this->segments( $analyzer->tokenizer()->tokenize( $text ) ) as $segment ) {
				$keys = $analyzer->keys( $segment );
				if ( count( $keys ) > $this->max_words || ! self::has_content( $segment, $analyzer ) ) {
					continue;
				}
				// Un segmento de una palabra solo vale si es un término principal.
				if ( 1 === count( $keys ) && Phrase::TITLE === $kind && ! isset( $terms[ $keys[0] ] ) ) {
					continue;
				}
				$add( $keys, $kind );
			}
		}

		$rank = 0;
		foreach ( $terms as $term => $weight ) {
			if ( ++$rank > self::TOP_TERMS ) {
				break;
			}
			$keys = explode( ' ', (string) $term );
			$add( $keys, count( $keys ) > 1 ? Phrase::NGRAM : Phrase::UNIGRAM );
		}

		foreach ( $analyzer->terms( $target->title ) as $term => $count ) {
			$term = (string) $term;
			if ( isset( $terms[ $term ] ) && str_contains( $term, ' ' ) ) {
				$add( explode( ' ', $term ), Phrase::NGRAM );
			}
		}

		return array_values( $phrases );
	}

	/**
	 * Apariciones válidas de las frases objetivo en las frases de un origen.
	 *
	 * Descarta las que están en una frase que ya tiene enlace, las de menos de
	 * dos caracteres útiles o más de MAX_WORDS palabras, las genéricas y las
	 * que usan un ancla con la que el origen ya enlaza a otro destino, las que no
	 * tienen ninguna palabra de contenido («1 GW», «100.000 millones»), las que no
	 * empiezan por palabra de contenido («GW de capacidad») o acaban en unidad,
	 * magnitud o mes (sí pueden acabar en cifra: «iPhone 15»), y las que
	 * empiezan o terminan en una forma verbal («Anthropic podría»): así
	 * gana otra aparición del mismo destino si la hay. Antes de esos descartes recorta
	 * el ancla hasta que no empiece ni acabe en palabra vacía (del idioma de la entrada,
	 * de otro idioma que domina la frase o el extremo de una palabra con guion):
	 * «el consumo de» → «consumo»; si no queda nada, se descarta. Amplía el
	 * ancla una palabra a la derecha si esa palabra no es vacía, está en el
	 * título del destino y es uno de sus términos principales («bomba de calor»
	 * → «bomba de calor aerotérmica»).
	 *
	 * @param AnalyzedDocument $source   Origen analizado con frases.
	 * @param Phrase[]         $phrases  Frases objetivo del destino.
	 * @param Analyzer         $analyzer Analizador del idioma.
	 * @param array            $expand   Claves de las palabras del título del destino que son términos principales.
	 * @param array            $blocked  Clave de ancla → destino con el que el origen ya la usa.
	 * @param int              $target   ID del destino.
	 * @return list<AnchorMatch> En orden de aparición.
	 *
	 * @phpstan-param list<Phrase> $phrases
	 * @phpstan-param array<string, true> $expand
	 * @phpstan-param array<string, int> $blocked
	 */
	public function find( AnalyzedDocument $source, array $phrases, Analyzer $analyzer, array $expand = array(), array $blocked = array(), int $target = 0 ): array {
		$positions = $source->positions();
		$total     = count( $source->sentences );
		$last      = array() === $source->sentences ? -1 : $source->sentences[ $total - 1 ]->paragraph;
		$generic   = array_fill_keys( self::GENERIC, true );
		$found     = array();

		foreach ( $phrases as $phrase ) {
			$size = count( $phrase->keys );
			foreach ( $positions[ $phrase->keys[0] ] ?? array() as [ $s, $i ] ) {
				$sentence = $source->sentences[ $s ];
				if ( $sentence->has_link || $i + $size > count( $sentence->keys ) ) {
					continue;
				}
				if ( ! $this->matches( $sentence, $i, $phrase->keys ) ) {
					continue;
				}

				[ $start, $end ] = $this->expand( $sentence, $i, $i + $size - 1, $expand );
				$grown           = array( $start, $end );
				[ $start, $end ] = $this->trim( $sentence, $start, $end, $analyzer, $analyzer->foreign_language( $sentence ) );
				if ( $end < $start ) {
					continue;
				}
				if ( $grown[1] - $grown[0] + 1 > $this->max_words ) {
					continue;
				}
				// «más información» sigue siendo genérica aunque al recortarla quede «información».
				$whole = substr( $sentence->text, $sentence->tokens[ $grown[0] ]->offset, $sentence->tokens[ $grown[1] ]->end() - $sentence->tokens[ $grown[0] ]->offset );
				if ( isset( $generic[ $analyzer->tokenizer()->key( $whole ) ] ) ) {
					continue;
				}
				$words  = $end - $start + 1;
				$offset = $sentence->tokens[ $start ]->offset;
				$anchor = substr( $sentence->text, $offset, $sentence->tokens[ $end ]->end() - $offset );
				$key    = implode( ' ', array_slice( $sentence->keys, $start, $words ) );

				$span = array_slice( $sentence->tokens, $start, $words );
				if ( $words > $this->max_words
					|| preg_match_all( '/[\p{L}\p{N}]/u', $anchor ) < 2
					|| ! self::has_content( $span, $analyzer )
					|| ! $analyzer->is_content( $span[0] )
					|| $analyzer->is_numeric_word( $span[ $words - 1 ] )
					|| $analyzer->is_verb_like( $span[0], $span[1] ?? null )
					|| $analyzer->is_verb_like( $span[ $words - 1 ] )
					|| isset( $generic[ $analyzer->tokenizer()->key( $anchor ) ] )
					|| ( isset( $blocked[ $key ] ) && $blocked[ $key ] !== $target ) ) {
					continue;
				}

				$found[ $s . ':' . $offset . ':' . $key ] = new AnchorMatch( $s, $offset, $anchor, $key, array( $start, $end ) === $grown ? $phrase->kind : ( $words > 1 ? Phrase::NGRAM : Phrase::UNIGRAM ), $words, $total > 0 ? $s / $total : 0.0, $sentence->paragraph === $last );
			}//end foreach
		}//end foreach

		usort( $found, static fn( AnchorMatch $a, AnchorMatch $b ): int => array( $a->sentence, $a->offset, -$a->words ) <=> array( $b->sentence, $b->offset, -$b->words ) );
		return $found;
	}

	/**
	 * Si algún token es palabra de contenido.
	 *
	 * @param Token[]  $tokens   Tokens.
	 * @param Analyzer $analyzer Analizador.
	 *
	 * @phpstan-param list<Token> $tokens
	 */
	private static function has_content( array $tokens, Analyzer $analyzer ): bool {
		foreach ( $tokens as $token ) {
			if ( $analyzer->is_content( $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tramos de un texto corto entre signos de puntuación, sin palabras vacías
	 * en los extremos.
	 *
	 * @param Token[] $tokens Tokens.
	 * @return list<list<Token>>
	 *
	 * @phpstan-param list<Token> $tokens
	 */
	private function segments( array $tokens ): array {
		$segments = array();
		$current  = array();
		foreach ( $tokens as $token ) {
			if ( $token->break_before && array() !== $current ) {
				$segments[] = $current;
				$current    = array();
			}
			$current[] = $token;
		}
		$segments[] = $current;

		$trimmed = array();
		foreach ( $segments as $segment ) {
			while ( array() !== $segment && $segment[0]->is_stopword ) {
				array_shift( $segment );
			}
			while ( array() !== $segment && end( $segment )->is_stopword ) {
				array_pop( $segment );
			}
			if ( array() !== $segment ) {
				$trimmed[] = $segment;
			}
		}
		return $trimmed;
	}

	/**
	 * Si la frase objetivo aparece en la posición $i sin cruzar puntuación.
	 *
	 * @param Sentence $sentence Frase.
	 * @param int      $i        Posición del primer token.
	 * @param string[] $keys     Claves de la frase objetivo.
	 *
	 * @phpstan-param list<string> $keys
	 */
	private function matches( Sentence $sentence, int $i, array $keys ): bool {
		foreach ( $keys as $n => $key ) {
			if ( $sentence->keys[ $i + $n ] !== $key || ( $n > 0 && $sentence->tokens[ $i + $n ]->break_before ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Recorta el tramo [$start, $end] hasta que no empiece ni acabe en palabra
	 * vacía (D-52): del idioma de la entrada, de otro idioma que domina la frase
	 * o el extremo vacío de una palabra con guion. Si no queda nada, `$end < $start`.
	 *
	 * @param Sentence    $sentence Frase.
	 * @param int         $start    Primer token.
	 * @param int         $end      Último token.
	 * @param Analyzer    $analyzer Analizador del idioma.
	 * @param string|null $foreign  Idioma que domina la frase si no es el de la entrada.
	 * @return array{0: int, 1: int}
	 */
	private function trim( Sentence $sentence, int $start, int $end, Analyzer $analyzer, ?string $foreign ): array {
		while ( $start <= $end && $analyzer->is_empty_edge( $sentence->tokens[ $start ], true, $foreign ) ) {
			++$start;
		}
		while ( $end >= $start && $analyzer->is_empty_edge( $sentence->tokens[ $end ], false, $foreign ) ) {
			--$end;
		}
		return array( $start, $end );
	}

	/**
	 * Amplía el tramo [$start, $end] una palabra a la derecha si es una palabra
	 * del título del destino y no hay puntuación en medio.
	 *
	 * @param Sentence            $sentence Frase.
	 * @param int                 $start    Primer token.
	 * @param int                 $end      Último token.
	 * @param array<string, true> $expand   Claves con las que se puede ampliar.
	 * @return array{0: int, 1: int}
	 */
	private function expand( Sentence $sentence, int $start, int $end, array $expand ): array {
		$next = $end + 1;
		if ( isset( $sentence->tokens[ $next ] ) && ! $sentence->tokens[ $next ]->break_before && ! $sentence->tokens[ $next ]->is_stopword && isset( $expand[ $sentence->keys[ $next ] ] ) ) {
			$end = $next;
		}
		return array( $start, $end );
	}
}
