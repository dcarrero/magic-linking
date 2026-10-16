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
 * `get_the_title()` devuelve HTML: el título pasa por `wptexturize` y deja entidades (`&#8230;`, `&amp;`,
 * `&#8217;`). Las pantallas del plugin pintan el título como texto (React escapa), así que lo que se les entrega es el
 * texto ya decodificado, sin etiquetas; si no, el usuario ve «Ver Brujas en un día&#8230; desde las alturas».
 *
 * Es texto, no HTML: quien lo imprima en HTML tiene que escaparlo, igual que cualquier otro texto.
 */
final class PostTitle {

	/**
	 * Título de la entrada, como texto plano.
	 *
	 * @param WP_Post|int $post Entrada o su ID.
	 */
	public static function of( WP_Post|int $post ): string {
		return html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
