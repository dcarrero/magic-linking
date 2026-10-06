<?php
/**
 * Huella de una entrada para saltar las que no han cambiado.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use WP_Post;

/**
 * Valor de `magiclinking_docs.content_hash`: SHA-1 de lo que entra en el índice (título,
 * frases objetivo y contenido), compartido por el grafo y el índice léxico.
 */
final class Fingerprint {

	/**
	 * Huella de una entrada.
	 *
	 * @param WP_Post $post Entrada.
	 * @param string  $html Contenido que se analiza (con el filtro `magiclinking_post_html` aplicado).
	 */
	public static function of( WP_Post $post, string $html ): string {
		return sha1( implode( "\0", array( $post->post_title, implode( "\x1f", self::focus( $post->ID ) ), $html ) ) );
	}

	/**
	 * Frases objetivo de una entrada: la palabra clave foco de Rank Math o de Yoast, si existe.
	 *
	 * @param int $post_id ID.
	 *
	 * @return list<string>
	 */
	public static function focus( int $post_id ): array {
		$phrases = array();

		foreach ( array( 'rank_math_focus_keyword', '_yoast_wpseo_focuskw' ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}
			foreach ( explode( ',', $value ) as $phrase ) {
				$phrase = trim( wp_strip_all_tags( $phrase ) );
				if ( '' !== $phrase ) {
					$phrases[ $phrase ] = true;
				}
			}
		}

		return array_map( 'strval', array_keys( $phrases ) );
	}
}
