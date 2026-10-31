<?php
/**
 * Textos de la comprobación de un ancla ajustada (docs/07 §3.2).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Engine\AnchorCheck;

/**
 * Cada código de {@see AnchorCheck} con su frase en el idioma del usuario. El motor no conoce WordPress: los
 * textos viven aquí.
 */
final class AnchorMessages {

	/**
	 * Frase de un código.
	 *
	 * @param string $code Código de {@see AnchorCheck}.
	 */
	public static function text( string $code ): string {
		switch ( $code ) {
			case AnchorCheck::NOT_LITERAL:
				return __( 'The anchor has to be a literal part of the sentence.', 'magic-linking' );
			case AnchorCheck::NOT_WORD_BOUNDARY:
				return __( 'The anchor has to start and end at the edge of a word.', 'magic-linking' );
			case AnchorCheck::IGNORED_WORD:
				return __( 'The anchor contains a word or phrase you chose to ignore in the settings.', 'magic-linking' );
			case AnchorCheck::ANCHOR_TAKEN:
				return __( 'This anchor already links to a different entry from this one. Use another anchor.', 'magic-linking' );
			case AnchorCheck::SPACES:
				return __( 'The anchor cannot start or end with a space.', 'magic-linking' );
			case AnchorCheck::TOO_SHORT:
				return __( 'The anchor is too short.', 'magic-linking' );
			case AnchorCheck::TOO_MANY_WORDS:
				/* translators: %d: maximum number of words of an anchor. */
				return sprintf( __( 'The anchor has more than %d words.', 'magic-linking' ), \MagicLinking\Engine\PhraseFinder::MAX_WORDS );
			case AnchorCheck::NO_CONTENT:
				return __( 'The anchor needs at least one meaningful word, not only filler words.', 'magic-linking' );
			case AnchorCheck::STARTS_WITH_FILLER:
				return __( 'The anchor cannot start with a filler word or a number.', 'magic-linking' );
			case AnchorCheck::ENDS_WITH_UNIT:
				return __( 'The anchor cannot end with a unit, a magnitude or a month.', 'magic-linking' );
			case AnchorCheck::STARTS_WITH_VERB:
				return __( 'The anchor starts with a verb form. It usually reads better starting with a noun.', 'magic-linking' );
			case AnchorCheck::ENDS_WITH_VERB:
				return __( 'The anchor ends with a verb form. It usually reads better ending with a noun.', 'magic-linking' );
			case AnchorCheck::ENDS_WITH_FILLER:
				return __( 'The anchor ends with a filler word.', 'magic-linking' );
			case AnchorCheck::GENERIC:
				return __( 'This anchor is too generic: it says nothing about the destination.', 'magic-linking' );
			default:
				return __( 'The anchor is not valid.', 'magic-linking' );
		}//end switch
	}

	/**
	 * Lista de códigos con su texto.
	 *
	 * @param array $codes Códigos.
	 *
	 * @phpstan-param list<string> $codes
	 *
	 * @return list<array{code: string, message: string}>
	 */
	public static function describe( array $codes ): array {
		return array_map(
			static fn( string $code ): array => array(
				'code'    => $code,
				'message' => self::text( $code ),
			),
			$codes
		);
	}
}
