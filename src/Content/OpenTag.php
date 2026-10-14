<?php
/**
 * Lectura de atributos de una etiqueta de apertura.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

use WP_HTML_Tag_Processor;

/**
 * Valor decodificado de un atributo de una etiqueta suelta (`<a href="…">`), con el procesador de etiquetas de WordPress.
 */
final class OpenTag {

	/**
	 * Valor de un atributo de la primera etiqueta `<a>`.
	 *
	 * @param string $open Etiqueta de apertura.
	 * @param string $name Atributo.
	 */
	public static function attribute( string $open, string $name ): string {
		$tags = new WP_HTML_Tag_Processor( $open );
		if ( ! $tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
			return '';
		}

		$value = $tags->get_attribute( $name );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Valor de `href` de una etiqueta `<a>`.
	 *
	 * @param string $open Etiqueta de apertura.
	 */
	public static function href( string $open ): string {
		return self::attribute( $open, 'href' );
	}
}
