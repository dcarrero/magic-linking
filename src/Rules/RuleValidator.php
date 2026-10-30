<?php
/**
 * Validación y saneado de una regla recibida.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rules;

use MagicLinking\Core\Settings;
use MagicLinking\Engine\Analyzer;
use MagicLinking\Engine\PhraseFinder;
use MagicLinking\I18n\Language;
use WP_Error;
use WP_Post;

/**
 * Convierte lo que llega de la API o de WP-CLI en una {@see Rule} válida, o en un error con un mensaje llano.
 *
 * El destino sale del servidor: tiene que ser una entrada publicada de un tipo que se analiza y sin contraseña. El
 * idioma de la regla es el del destino (regla 9): no se acepta uno distinto.
 */
final class RuleValidator {

	/**
	 * Máximo de caracteres de una variante.
	 */
	public const MAX_LENGTH = 120;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes (tipos que se analizan).
	 * @param Language $language Idioma por entrada.
	 */
	public function __construct( private Settings $settings, private Language $language ) {
	}

	/**
	 * Valida una regla.
	 *
	 * @param array<string, mixed> $input    Campos recibidos: `phrases` (matriz o texto con una por línea), `target_id`, `max_per_post`, `first_only`, `word_gap`, `exclude_post_types`, `exclude_categories`, `active`.
	 * @param Rule|null            $existing Regla que se edita: lo que no llegue se conserva.
	 * @param int                  $user_id  Quien la guarda.
	 *
	 * @return Rule|WP_Error
	 */
	public function validate( array $input, ?Rule $existing = null, int $user_id = 0 ): Rule|WP_Error {
		$base      = $existing ?? new Rule( 0, array(), 0, '', '' );
		$target_id = isset( $input['target_id'] ) ? (int) $input['target_id'] : $base->target_id;

		// Pausar, reactivar o cambiar opciones no exige que el destino siga siendo válido (puede haberse despublicado):
		// solo se valida al crear o cuando el destino cambia respecto a lo guardado. Las frases se revalidan siempre que
		// llegan, con el idioma del destino guardado.
		$check = null === $existing || ( isset( $input['target_id'] ) && $target_id !== $base->target_id );
		$lang  = $base->lang;
		$url   = $base->target_url;
		if ( $check ) {
			$target = get_post( $target_id );
			if ( ! $target instanceof WP_Post || 'publish' !== $target->post_status || '' !== $target->post_password || ! in_array( $target->post_type, $this->settings->post_types(), true ) ) {
				return $this->error( __( 'The destination must be a published entry of a content type that Magic Linking analyzes.', 'magic-linking' ) );
			}
			if ( in_array( $target_id, $this->settings->all()['ignored_ids'], true ) ) {
				return $this->error( __( 'That entry is in your list of ignored URLs, so it cannot be a destination.', 'magic-linking' ) );
			}
			$lang = $this->language->for_post( $target_id );
			$url  = (string) get_permalink( $target );
		}

		$phrases = array_key_exists( 'phrases', $input ) ? $this->variants( $input['phrases'], $lang ) : $base->variants;
		if ( $phrases instanceof WP_Error ) {
			return $phrases;
		}
		if ( array() === $phrases ) {
			return $this->error( __( 'Write at least one phrase to link.', 'magic-linking' ) );
		}

		$max = isset( $input['max_per_post'] ) ? (int) $input['max_per_post'] : ( $base->max_per_post );
		if ( $max < 1 || $max > Rule::MAX_PER_POST ) {
			return $this->error( sprintf( /* translators: %d: maximum number of links a rule can add to one entry. */ __( 'Links per entry must be between 1 and %d.', 'magic-linking' ), Rule::MAX_PER_POST ) );
		}

		$types = $base->exclude_post_types;
		if ( array_key_exists( 'exclude_post_types', $input ) ) {
			$types = array_values( array_intersect( array_map( 'strval', (array) $input['exclude_post_types'] ), array_keys( Settings::available_post_types() ) ) );
		}
		$categories = $base->exclude_categories;
		if ( array_key_exists( 'exclude_categories', $input ) ) {
			$categories = array_values( array_unique( array_filter( array_map( 'intval', (array) $input['exclude_categories'] ), static fn( int $id ): bool => $id > 0 ) ) );
		}

		return new Rule(
			$base->id,
			$phrases,
			$target_id,
			$url,
			$lang,
			$max,
			array_key_exists( 'first_only', $input ) ? self::flag( $input['first_only'] ) : $base->first_only,
			array_key_exists( 'word_gap', $input ) ? self::flag( $input['word_gap'] ) : $base->gap,
			$types,
			$categories,
			array_key_exists( 'active', $input ) ? self::flag( $input['active'] ) : $base->active,
			$base->created_at,
			null === $existing ? $user_id : $base->created_by
		);
	}

	/**
	 * Variantes limpias: una por línea, sin etiquetas ni espacios sobrantes, sin repetir y solo las que el motor puede enlazar.
	 *
	 * @param mixed  $value Matriz de frases o texto con una por línea.
	 * @param string $lang  Idioma del destino (decide qué es una palabra vacía).
	 *
	 * @return list<string>|WP_Error
	 */
	private function variants( mixed $value, string $lang ): array|WP_Error {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\R/u', $value );
		}
		$analyzer = Analyzer::for_language( $lang );
		$clean    = array();
		$keys     = array();
		foreach ( is_array( $value ) ? $value : array() as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}
			$line = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $line ) ) );
			if ( '' === $line ) {
				continue;
			}
			if ( mb_strlen( $line, 'UTF-8' ) > self::MAX_LENGTH ) {
				return $this->error( sprintf( /* translators: %d: maximum characters of a phrase. */ __( 'A phrase can have at most %d characters.', 'magic-linking' ), self::MAX_LENGTH ) );
			}
			$tokens = $analyzer->tokenizer()->tokenize( $line );
			if ( array() === $tokens || count( $tokens ) > PhraseFinder::MAX_WORDS ) {
				return $this->error( sprintf( /* translators: 1: the phrase, 2: maximum number of words. */ __( '“%1$s” is not a usable phrase: it needs between 1 and %2$d words.', 'magic-linking' ), $line, PhraseFinder::MAX_WORDS ) );
			}
			$content = false;
			foreach ( $tokens as $token ) {
				$content = $content || $analyzer->is_content( $token );
			}
			if ( ! $content || 1 !== preg_match( '/\p{L}{2}/u', $line ) ) {
				return $this->error( sprintf( /* translators: %s: the phrase. */ __( '“%s” has no meaningful word to link (only articles, prepositions or numbers).', 'magic-linking' ), $line ) );
			}
			$key = $analyzer->phrase_key( $line );
			if ( isset( $keys[ $key ] ) ) {
				continue;
			}
			$keys[ $key ] = true;
			$clean[]      = $line;
		}//end foreach
		if ( count( $clean ) > Rule::MAX_VARIANTS ) {
			return $this->error( sprintf( /* translators: %d: maximum number of phrases in one rule. */ __( 'A rule can have at most %d phrases.', 'magic-linking' ), Rule::MAX_VARIANTS ) );
		}

		return $clean;
	}

	/**
	 * Valor de sí o no recibido.
	 *
	 * @param mixed $value Valor.
	 */
	private static function flag( mixed $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Error 400.
	 *
	 * @param string $message Mensaje.
	 */
	private function error( string $message ): WP_Error {
		return new WP_Error( 'magiclinking_invalid_rule', $message, array( 'status' => 400 ) );
	}
}
