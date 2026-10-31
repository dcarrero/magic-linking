<?php
/**
 * Sugerencias del motor con la forma que necesita la tarjeta del panel (docs/07 §3).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\PostTitle;
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
	 * @param Language    $language Idioma por entrada.
	 * @param AnchorNotes $notes    Avisos de ancla repetida.
	 */
	public function __construct( private Language $language, private AnchorNotes $notes ) {
	}

	/**
	 * Carga las entradas que se van a presentar en la caché de WordPress de una vez.
	 *
	 * @param Suggestion[] $suggestions Sugerencias.
	 */
	public function prime( array $suggestions ): void {
		$ids   = array();
		$pairs = array();
		foreach ( $suggestions as $suggestion ) {
			$ids[]   = $suggestion->source;
			$ids[]   = $suggestion->target;
			$pairs[] = array( $suggestion->anchor, $suggestion->target, $suggestion->source );
			foreach ( $suggestion->alternatives as $alternative ) {
				$pairs[] = array( $alternative->anchor, $suggestion->target, $suggestion->source );
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( count( $ids ) > 1 ) {
			_prime_post_caches( $ids, false, false );
		}

		// Los avisos de ancla repetida de toda la lista, con dos consultas.
		$this->notes->prime( $pairs );
	}

	/**
	 * Sugerencia lista para la API.
	 *
	 * @param Suggestion $suggestion Sugerencia del motor.
	 * @param bool       $nested     Es una frase alternativa: sin origen, destino, motivos ni más alternativas.
	 * @param int|null   $target     Destino al que lleva la sugerencia de la que cuelga la alternativa.
	 *
	 * @return array<string, mixed>
	 */
	public function present( Suggestion $suggestion, bool $nested = false, ?int $target = null ): array {
		$can = current_user_can( 'edit_post', $suggestion->source );

		$item = array_merge(
			array( 'anchor' => $suggestion->anchor ),
			array(
				'sentence' => $suggestion->sentence,
				'offset'   => $suggestion->offset,
			),
			self::span( $suggestion->sentence, $suggestion->offset, $suggestion->anchor ),
			array(
				'paragraph' => $suggestion->paragraph,
				'score'     => round( $suggestion->score->value, 3 ),
			),
			// Avisos de ancla repetida (docs/07 §3.3): cada frase alternativa trae los de su propia ancla.
			array( 'anchor_notes' => $this->notes->for_anchor( $suggestion->anchor, $target ?? $suggestion->target, $suggestion->source ) )
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
				'alternatives' => array_map( fn( Suggestion $alternative ): array => $this->present( $alternative, true, $suggestion->target ), $suggestion->alternatives ),
			)
		);
	}

	/**
	 * Destinos a los que una entrada ya enlaza, con lo que enseña el bloque «Enlaces actuales» (docs/07 §3.3).
	 *
	 * @param array $links Destino y anclas de cada uno, en orden.
	 *
	 * @phpstan-param list<array{id: int, anchors: list<string>}> $links
	 *
	 * @return list<array<string, mixed>>
	 */
	public function current_links( array $links ): array {
		$ids = array_column( $links, 'id' );
		if ( count( $ids ) > 1 ) {
			_prime_post_caches( $ids, false, false );
		}

		$out = array();
		foreach ( $links as $link ) {
			$post = get_post( $link['id'] );
			// Un destino que ya no existe o está en la papelera no es una página a la que se enlace.
			if ( ! $post instanceof WP_Post || 'trash' === $post->post_status ) {
				continue;
			}

			$out[] = array_merge( $this->post( $link['id'] ), array( 'anchors' => array_values( array_filter( $link['anchors'], static fn( string $anchor ): bool => '' !== trim( $anchor ) ) ) ) );
		}

		return $out;
	}

	/**
	 * El ancla localizada en la frase: lo que la tarjeta necesita para pintarla sin contar bytes.
	 *
	 * @param string $sentence Frase.
	 * @param int    $offset   Byte donde empieza el ancla.
	 * @param string $anchor   Ancla.
	 *
	 * @return array{before: string, after: string, anchor_start: int, anchor_end: int}
	 */
	public static function span( string $sentence, int $offset, string $anchor ): array {
		$before = substr( $sentence, 0, $offset );
		$start  = self::utf16( $before );

		return array(
			'before'       => $before,
			'after'        => substr( $sentence, $offset + strlen( $anchor ) ),
			'anchor_start' => $start,
			'anchor_end'   => $start + self::utf16( $anchor ),
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
			'title'      => PostTitle::of( $post ),
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
