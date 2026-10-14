<?php
/**
 * Comprobación del destino de un enlace que se escribe en una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

use MagicLinking\Core\Settings;
use MagicLinking\I18n\Language;
use WP_Post;

/**
 * Una dirección de destino solo sale del servidor, nunca del cliente: tiene que ser una entrada publicada, de un
 * tipo que se analiza, sin contraseña, distinta del origen y en el mismo idioma (regla 9). Lo comparten insertar
 * sugerencias y arreglar enlaces rotos.
 */
final class Destination {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes.
	 * @param Language $language Idioma por entrada.
	 */
	public function __construct( private Settings $settings, private Language $language ) {
	}

	/**
	 * Dirección del destino, tras comprobar que se puede enlazar desde el origen.
	 *
	 * @param int $post_id   Origen.
	 * @param int $target_id Destino.
	 *
	 * @return string|InsertionException La dirección, o el motivo por el que no se puede enlazar a ese destino.
	 */
	public function resolve( int $post_id, int $target_id ): string|InsertionException {
		$target = get_post( $target_id );
		if ( ! $target instanceof WP_Post || 'publish' !== $target->post_status || ! in_array( $target->post_type, $this->settings->post_types(), true ) || '' !== $target->post_password || $target_id === $post_id ) {
			return new InsertionException( InsertionException::BAD_TARGET, __( 'The destination is not a published entry that can be linked.', 'magic-linking' ) );
		}
		if ( $this->language->for_post( $post_id ) !== $this->language->for_post( $target_id ) ) {
			return new InsertionException( InsertionException::LANGUAGE, __( 'The destination is in a different language, so no link is added.', 'magic-linking' ) );
		}

		$url = (string) get_permalink( $target );
		if ( '' === $url ) {
			return new InsertionException( InsertionException::BAD_TARGET, __( 'The destination is not a published entry that can be linked.', 'magic-linking' ) );
		}

		return $url;
	}
}
