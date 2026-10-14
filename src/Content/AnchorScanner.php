<?php
/**
 * Localiza los elementos `<a>` de un tramo de HTML con sus posiciones en bytes.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

use WP_HTML_Tag_Processor;

/**
 * `WP_HTML_Tag_Processor` no da posiciones, y arreglar un enlace (docs/06 §9) necesita saber dónde empieza y
 * acaba cada `<a>` para tocar solo ese. Este recorrido usa la misma expresión que {@see TextView} para partir el
 * HTML en etiquetas y empareja cada apertura con su cierre.
 *
 * Es deliberadamente estricto: si el tramo tiene un `<a>` dentro de otro, un `<a>` sin cerrar o un elemento cuyo
 * contenido no se recorre con seguridad (`<script>`, `<style>`, `<svg>`…) junto a un `<a>`, devuelve null y quien
 * lo pide se niega a tocar nada.
 */
final class AnchorScanner {

	/**
	 * Elementos con contenido que este recorrido no entiende (puede esconder etiquetas `<a>` falsas).
	 */
	private const UNSAFE = array( 'script', 'style', 'textarea', 'svg', 'math', 'template', 'title', 'iframe', 'xmp', 'plaintext', 'noscript' );

	/**
	 * Enlaces del tramo, en orden de aparición.
	 *
	 * @param string $html HTML.
	 *
	 * @return list<array{open_start: int, open_end: int, close_start: int, close_end: int}>|null Null si el tramo no se puede recorrer con seguridad.
	 */
	public static function scan( string $html ): ?array {
		if ( false === stripos( $html, '<a' ) ) {
			return array();
		}

		preg_match_all( '~<!--.*?-->|<\?.*?>|<![^>]*>|</?[a-zA-Z][^\s/>]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>~s', $html, $matches, PREG_OFFSET_CAPTURE );

		$found  = array();
		$open   = null;
		$unsafe = false;

		foreach ( $matches[0] as [ $token, $at ] ) {
			if ( 1 !== preg_match( '~^<(/?)([a-zA-Z][^\s/>]*)~', $token, $parts ) ) {
				continue;
			}

			$name   = strtolower( $parts[2] );
			$closer = '/' === $parts[1];
			$at     = (int) $at;

			if ( in_array( $name, self::UNSAFE, true ) ) {
				$unsafe = true;
			}
			if ( 'a' !== $name ) {
				continue;
			}

			if ( ! $closer ) {
				if ( null !== $open ) {
					return null;
				}
				$open = array( $at, $at + strlen( $token ) );
				continue;
			}

			if ( null === $open ) {
				continue;
			}
			$found[] = array(
				'open_start'  => $open[0],
				'open_end'    => $open[1],
				'close_start' => $at,
				'close_end'   => $at + strlen( $token ),
			);
			$open    = null;
		}//end foreach

		if ( null !== $open || ( $unsafe && array() !== $found ) ) {
			return null;
		}

		return $found;
	}

	/**
	 * Valor de `href` de una etiqueta de apertura `<a …>`, decodificado como lo ve el navegador.
	 *
	 * @param string $open Etiqueta.
	 */
	public static function href( string $open ): string {
		$tags = new WP_HTML_Tag_Processor( $open );
		if ( ! $tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
			return '';
		}

		$href = $tags->get_attribute( 'href' );

		return is_string( $href ) ? $href : '';
	}

	/**
	 * La etiqueta de apertura con otro `href` y todo lo demás intacto.
	 *
	 * @param string $open Etiqueta `<a …>`.
	 * @param string $href Dirección nueva.
	 *
	 * @return string|null Null si la etiqueta no se puede reescribir.
	 */
	public static function with_href( string $open, string $href ): ?string {
		$tags = new WP_HTML_Tag_Processor( $open );
		if ( ! $tags->next_tag( array( 'tag_name' => 'a' ) ) || ! is_string( $tags->get_attribute( 'href' ) ) || ! $tags->set_attribute( 'href', $href ) ) {
			return null;
		}

		$next = $tags->get_updated_html();

		return '' === $next ? null : $next;
	}

	/**
	 * Dos etiquetas de apertura que difieren solo en el `href`.
	 *
	 * @param string $before Etiqueta antes.
	 * @param string $after  Etiqueta después.
	 */
	public static function differ_only_in_href( string $before, string $after ): bool {
		$old = self::attributes( $before );
		$new = self::attributes( $after );
		if ( null === $old || null === $new || ! isset( $old['href'], $new['href'] ) || $old['href'] === $new['href'] ) {
			return false;
		}

		unset( $old['href'], $new['href'] );

		return $old === $new;
	}

	/**
	 * Atributos de una etiqueta de apertura, por nombre.
	 *
	 * @param string $open Etiqueta.
	 *
	 * @return array<string, string|true>|null
	 */
	public static function attributes( string $open ): ?array {
		$tags = new WP_HTML_Tag_Processor( $open );
		if ( ! $tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
			return null;
		}

		$out = array();
		foreach ( (array) $tags->get_attribute_names_with_prefix( '' ) as $name ) {
			$value        = $tags->get_attribute( (string) $name );
			$out[ $name ] = is_string( $value ) ? $value : true;
		}
		ksort( $out );

		return $out;
	}
}
