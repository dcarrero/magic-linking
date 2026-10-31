<?php
/**
 * Comprobaciones comunes de las rutas que actúan sobre una entrada que se analiza.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\Settings;
use WP_Error;
use WP_Post;
use WP_REST_Request;

/**
 * Permiso (poder editar la entrada de la ruta; una que no existe pasa y da 404 después) y búsqueda de la entrada
 * si es de un tipo que se analiza. Lo usan `/pillar` y `/exclusions`.
 */
final class AnalyzedEntry {

	/**
	 * Permiso: `edit_posts` y, si la entrada existe, `edit_post` sobre ella.
	 *
	 * @param WP_REST_Request $request Petición con `post_id`.
	 */
	public static function can_edit( WP_REST_Request $request ): bool {
		$post_id = (int) $request['post_id'];

		return current_user_can( 'edit_posts' ) && ( ! get_post( $post_id ) instanceof WP_Post || current_user_can( 'edit_post', $post_id ) );
	}

	/**
	 * La entrada, o un 404 si no existe o no es de un tipo que se analiza.
	 *
	 * @param Settings $settings Ajustes.
	 * @param int      $post_id  ID.
	 *
	 * @return WP_Post|WP_Error
	 */
	public static function find( Settings $settings, int $post_id ) {
		return $settings->analyzed_post( $post_id ) ?? new WP_Error( 'magiclinking_not_found', __( 'That entry does not exist.', 'magic-linking' ), array( 'status' => 404 ) );
	}
}
