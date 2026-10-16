<?php
/**
 * Título de una entrada como texto plano.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Core;

use WP_Post;

/**
 * Una sola regla para el título de una entrada como texto: el título **guardado** (`post_title`), sin etiquetas y con
 * las entidades decodificadas. No se usa `get_the_title()`: fuera del administrador (REST, WP-CLI) antepone
 * «Privado: » o «Protegido: » y aplica `wptexturize` (`&#8230;`, comillas curvas), y el título deja de ser el real.
 *
 * Es texto, no HTML: React lo escapa al pintar, y quien lo imprima en HTML tiene que escaparlo, como cualquier texto.
 */
final class PostTitle {

	/**
	 * Título de la entrada, como texto plano.
	 *
	 * @param WP_Post|int $post Entrada o su ID.
	 */
	public static function of( WP_Post|int $post ): string {
		$post = get_post( $post );

		return $post instanceof WP_Post ? self::plain( $post->post_title ) : '';
	}

	/**
	 * Un título guardado (por ejemplo, el de una fila de SQL) como texto plano.
	 *
	 * @param string $raw `post_title` tal como está en la base de datos.
	 */
	public static function plain( string $raw ): string {
		return html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
