<?php
/**
 * Sugerencias del motor con la forma que necesita la tarjeta del panel (docs/07 §3).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Engine\Reason;
use MagicLinking\Engine\Suggestion;
use MagicLinking\I18n\Language;
use WP_Post;

/**
 * Una sugerencia es: origen (donde irá el enlace), destino (a donde lleva), ancla y frase (con el ancla
 * localizada en la frase de dos formas: `before`/`after` para pintarla sin contar bytes y
 * `anchor_start`/`anchor_end` en unidades UTF-16, los índices de `String.prototype.slice`), puntuación,
 * motivos con su texto, frases alternativas y `can_insert` y, solo si el usuario puede editar el origen, `insert`, el cuerpo que `POST /links` espera tal cual.
 *
 * `offset` es el byte de la frase donde empieza el ancla (el del motor); es lo que se devuelve al insertar.
 */
final class SuggestionPresenter {

	/**
	 * Constructor.
	 *
	 * @param Language $language Idioma por entrada.
	 */
	public function __construct( private Language $language ) {
	}

	/**
	 * Carga las entradas que se van a presentar en la caché de WordPress de una vez.
	 *
	 * @param Suggestion[] $suggestions Sugerencias.
	 */
	public function prime( array $suggestions ): void {
		$ids = array();
		foreach ( $suggestions as $suggestion ) {
			$ids[] = $suggestion->source;
			$ids[] = $suggestion->target;
		}
		$ids = array_values( array_unique( $ids ) );
		if ( count( $ids ) > 1 ) {
			_prime_post_caches( $ids, false, false );
		}
	}

	/**
	 * Sugerencia lista para la API.
	 *
	 * @param Suggestion $suggestion Sugerencia del motor.
	 * @param bool       $nested     Es una frase alternativa: sin origen, destino, motivos ni más alternativas.
	 *
	 * @return array<string, mixed>
	 */
	public function present( Suggestion $suggestion, bool $nested = false ): array {
		$before = substr( $suggestion->sentence, 0, $suggestion->offset );
		$start  = self::utf16( $before );
		$can    = current_user_can( 'edit_post', $suggestion->source );

		$item = array(
			'anchor'       => $suggestion->anchor,
			'sentence'     => $suggestion->sentence,
			'offset'       => $suggestion->offset,
			'before'       => $before,
			'after'        => substr( $suggestion->sentence, $suggestion->offset + strlen( $suggestion->anchor ) ),
			'anchor_start' => $start,
			'anchor_end'   => $start + self::utf16( $suggestion->anchor ),
			'paragraph'    => $suggestion->paragraph,
			'score'        => round( $suggestion->score->value, 3 ),
		);

		// Sin permiso sobre el origen no hay nada que enviar: la tarjeta se enseña sin botón de enlazar.
		if ( $can ) {
			$item['insert'] = array(
				'post_id'    => $suggestion->source,
				'target_id'  => $suggestion->target,
				'sentence'   => $suggestion->sentence,
				'offset'     => $suggestion->offset,
				'anchor'     => $suggestion->anchor,
				// El motor numera párrafos del texto extraído, no bloques de `parse_blocks()`: el servidor localiza la frase por su contexto.
				'block_path' => null,
			);
		}

		if ( $nested ) {
			return $item;
		}

		return array_merge(
			array(
				'source' => $this->post( $suggestion->source ),
				'target' => $this->post( $suggestion->target ),
			),
			array( 'can_insert' => $can ),
			$item,
			array(
				'reasons'      => array_map( array( $this, 'reason' ), $suggestion->reasons ),
				'alternatives' => array_map( fn( Suggestion $alternative ): array => $this->present( $alternative, true ), $suggestion->alternatives ),
			)
		);
	}

	/**
	 * Entrada con lo que la tarjeta enseña. La dirección de edición solo se da a quien puede editarla.
	 *
	 * @param int $id ID.
	 *
	 * @return array<string, mixed>
	 */
	public function post( int $id ): array {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return array( 'id' => $id );
		}

		$type = get_post_type_object( $post->post_type );

		return array(
			'id'         => $id,
			'title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'url'        => (string) get_permalink( $post ),
			'type'       => $post->post_type,
			'type_label' => null === $type ? $post->post_type : $type->labels->singular_name,
			'lang'       => $this->language->for_post( $id ),
			'edit_url'   => current_user_can( 'edit_post', $id ) ? (string) get_edit_post_link( $id, 'raw' ) : null,
		);
	}

	/**
	 * Motivo con su texto en el idioma del usuario (docs/04 §10).
	 *
	 * @param Reason $reason Motivo del motor.
	 *
	 * @return array{code: string, text: string}
	 */
	public function reason( Reason $reason ): array {
		switch ( $reason->code ) {
			case Reason::SHARED_TERMS:
				$terms = array_map( 'strval', (array) ( $reason->args['terms'] ?? array() ) );
				/* translators: %s: comma-separated list of shared terms. */
				$text = sprintf( __( 'They share: %s', 'magic-linking' ), implode( ', ', $terms ) );
				break;
			case Reason::ANCHOR_TITLE:
				$text = __( 'The anchor matches the title of the destination', 'magic-linking' );
				break;
			case Reason::ANCHOR_FOCUS:
				$text = __( 'The anchor matches the focus keyword of the destination', 'magic-linking' );
				break;
			case Reason::ORPHAN:
				$text = __( 'The destination is an orphan (0 inbound links)', 'magic-linking' );
				break;
			case Reason::SEMANTIC:
				$text = __( 'Very similar in meaning', 'magic-linking' );
				break;
			default:
				$text = '';
		}//end switch

		return array(
			'code' => $reason->code,
			'text' => $text,
		);
	}

	/**
	 * Longitud en unidades UTF-16, que es como JavaScript cuenta los índices de una cadena.
	 *
	 * @param string $text Texto UTF-8.
	 */
	private static function utf16( string $text ): int {
		return intdiv( strlen( (string) mb_convert_encoding( $text, 'UTF-16LE', 'UTF-8' ) ), 2 );
	}
}
