<?php
/**
 * Comprobación de un ancla que el usuario ha ajustado dentro de una frase.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Resultado de pasar un ancla ajustada por los descartes de docs/04 §5.3: los **errores** impiden enlazar y los
 * **avisos** (forma verbal en un extremo, ancla genérica, palabra vacía al final) solo se muestran, porque el usuario manda.
 *
 * Es puro (sin WordPress). Lo que depende del documento (que el tramo no cruce otro enlace, ni una etiqueta de
 * formato ni un bloque donde no se enlaza) lo decide la inserción segura al escribir ({@see \MagicLinking\Content\Linker}).
 */
final class AnchorCheck {

	public const NOT_LITERAL        = 'not_literal';
	public const NOT_WORD_BOUNDARY  = 'not_word_boundary';
	public const IGNORED_WORD       = 'ignored_word';
	public const ANCHOR_TAKEN       = 'anchor_taken';
	public const SPACES             = 'spaces';
	public const TOO_SHORT          = 'too_short';
	public const TOO_MANY_WORDS     = 'too_many_words';
	public const NO_CONTENT         = 'no_content';
	public const STARTS_WITH_FILLER = 'starts_with_filler';
	public const ENDS_WITH_UNIT     = 'ends_with_unit';

	public const STARTS_WITH_VERB = 'starts_with_verb';
	public const ENDS_WITH_VERB   = 'ends_with_verb';
	public const GENERIC          = 'generic';
	public const ENDS_WITH_FILLER = 'ends_with_filler';

	/**
	 * Constructor.
	 *
	 * @param array $errors   Códigos de lo que impide enlazar.
	 * @param array $warnings Códigos de lo dudoso.
	 *
	 * @phpstan-param list<string> $errors
	 * @phpstan-param list<string> $warnings
	 */
	public function __construct(
		public readonly array $errors,
		public readonly array $warnings
	) {
	}

	/**
	 * Si se puede enlazar (puede tener avisos).
	 */
	public function valid(): bool {
		return array() === $this->errors;
	}

	/**
	 * Si el ancla es un tramo literal de la frase en ese desplazamiento.
	 *
	 * @param string $sentence Frase.
	 * @param int    $offset   Byte donde empieza el ancla.
	 * @param string $anchor   Ancla.
	 */
	public static function literal( string $sentence, int $offset, string $anchor ): bool {
		return '' !== $anchor && $offset >= 0 && substr( $sentence, $offset, strlen( $anchor ) ) === $anchor;
	}

	/**
	 * Si un tramo literal de la frase corta una palabra por la mitad. Uno que no es literal no es asunto de esta
	 * comprobación: lo rechaza la inserción.
	 *
	 * @param string $sentence Frase.
	 * @param int    $offset   Byte donde empieza el ancla.
	 * @param string $anchor   Ancla.
	 */
	public static function cuts_word( string $sentence, int $offset, string $anchor ): bool {
		return self::literal( $sentence, $offset, $anchor ) && ! self::boundaries( $sentence, $offset, $anchor );
	}

	/**
	 * Si el tramo empieza y acaba en límite de palabra (Unicode): no corta una palabra por la mitad ni un carácter de
	 * varios bytes. Es la comprobación barata que se hace siempre, también con anclas del motor o de la IA.
	 *
	 * @param string $sentence Frase.
	 * @param int    $offset   Byte donde empieza el ancla.
	 * @param string $anchor   Ancla.
	 */
	public static function boundaries( string $sentence, int $offset, string $anchor ): bool {
		$before = substr( $sentence, 0, $offset );
		$after  = substr( $sentence, $offset + strlen( $anchor ) );
		if ( ! mb_check_encoding( $before, 'UTF-8' ) || ! mb_check_encoding( $anchor, 'UTF-8' ) || ! mb_check_encoding( $after, 'UTF-8' ) ) {
			return false;
		}

		$word = '[\p{L}\p{N}\p{M}]';
		if ( 1 === preg_match( "/{$word}\$/u", $before ) && 1 === preg_match( "/^{$word}/u", $anchor ) ) {
			return false;
		}

		return ! ( 1 === preg_match( "/{$word}\$/u", $anchor ) && 1 === preg_match( "/^{$word}/u", $after ) );
	}

	/**
	 * Comprueba un ancla.
	 *
	 * @param Analyzer $analyzer Analizador del idioma de la entrada.
	 * @param string   $sentence Frase guardada (la que devolvió el motor).
	 * @param int      $offset   Byte de la frase donde empieza el ancla.
	 * @param string   $anchor   Ancla propuesta.
	 */
	public static function of( Analyzer $analyzer, string $sentence, int $offset, string $anchor ): self {
		// Tramo literal de la frase guardada: lo único que se puede enlazar, sin editar nada.
		if ( ! self::literal( $sentence, $offset, $anchor ) ) {
			return new self( array( self::NOT_LITERAL ), array() );
		}
		if ( trim( $anchor ) !== $anchor ) {
			return new self( array( self::SPACES ), array() );
		}
		if ( ! self::boundaries( $sentence, $offset, $anchor ) ) {
			return new self( array( self::NOT_WORD_BOUNDARY ), array() );
		}

		$tokens = $analyzer->tokenizer()->tokenize( $anchor );
		$count  = count( $tokens );
		if ( preg_match_all( '/[\p{L}\p{N}]/u', $anchor ) < 2 ) {
			return new self( array( self::TOO_SHORT ), array() );
		}
		if ( $count > PhraseFinder::MAX_WORDS ) {
			return new self( array( self::TOO_MANY_WORDS ), array() );
		}

		// Palabras vacías con el mismo criterio que el motor: las del idioma de la entrada y las del idioma que domina la frase.
		$whole   = $analyzer->tokenizer()->tokenize( $sentence );
		$foreign = $analyzer->foreign_language( new Sentence( $sentence, 0, $whole, $analyzer->keys( $whole ), false ) );

		[ $first, $last ] = $analyzer->trim_edges( $tokens, 0, $count - 1, $foreign );
		$content          = false;
		for ( $i = max( 0, $first ); $i <= $last; $i++ ) {
			$content = $content || $analyzer->is_content( $tokens[ $i ] );
		}
		if ( $last < $first || ! $content ) {
			return new self( array( self::NO_CONTENT ), array() );
		}

		$error = array();
		$warn  = array();
		// Empieza por una palabra vacía (o por una cifra) o acaba en una unidad o un mes: se rechaza como en el motor.
		if ( $first > 0 || ! $analyzer->is_content( $tokens[ $first ] ) ) {
			$error[] = self::STARTS_WITH_FILLER;
		}
		if ( $analyzer->is_numeric_word( $tokens[ $count - 1 ] ) || $analyzer->is_numeric_word( $tokens[ $last ] ) ) {
			$error[] = self::ENDS_WITH_UNIT;
		}

		if ( $analyzer->is_verb_like( $tokens[ $first ], $tokens[ $first + 1 ] ?? null ) ) {
			$warn[] = self::STARTS_WITH_VERB;
		}
		if ( $last > $first && $analyzer->is_verb_like( $tokens[ $last ] ) ) {
			$warn[] = self::ENDS_WITH_VERB;
		}
		if ( $last < $count - 1 ) {
			$warn[] = self::ENDS_WITH_FILLER;
		}
		$generic = array_fill_keys( PhraseFinder::GENERIC, true );
		if ( isset( $generic[ $analyzer->tokenizer()->key( $anchor ) ] ) ) {
			$warn[] = self::GENERIC;
		}

		return new self( $error, $warn );
	}
}
