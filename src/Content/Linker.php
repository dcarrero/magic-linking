<?php
/**
 * Coloca el enlace en un tramo de HTML ya localizado.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

/**
 * Lo que comparten el editor de bloques y el clásico: buscar el ancla con su contexto en la vista de
 * texto, comprobar que se puede enlazar y construir el HTML nuevo con la etiqueta `<a>` en su sitio.
 */
final class Linker {

	/**
	 * Elementos en línea que pueden quedar dentro del enlace.
	 */
	public const INLINE = array( 'strong', 'em', 'b', 'i', 'u', 's', 'strike', 'mark', 'sub', 'sup', 'span', 'abbr', 'cite', 'del', 'ins', 'small', 'bdi', 'bdo', 'q', 'dfn', 'var', 'time', 'data', 'font' );

	/**
	 * Elementos dentro de los cuales no se enlaza nunca.
	 */
	private const NEVER = array( 'button', 'label', 'figcaption', 'caption', 'summary', 'option' );

	/**
	 * Encabezados.
	 */
	private const HEADINGS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/**
	 * Apariciones del ancla con su contexto en una vista de texto.
	 *
	 * @param TextView      $view    Vista del tramo.
	 * @param InsertRequest $request Petición.
	 *
	 * @return list<array{0: int, 1: int}> Tramos [primer carácter, siguiente al último] del ancla.
	 *
	 * @throws InsertionException Si el ancla está vacía o empieza o acaba en espacio.
	 */
	public static function matches( TextView $view, InsertRequest $request ): array {
		[ $before, $anchor, $after ] = $request->needle();
		if ( '' === $anchor || trim( $anchor ) !== $anchor ) {
			throw new InsertionException( InsertionException::BAD_REQUEST, __( 'The anchor cannot be empty or start or end with a space.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
		}
		$lead   = mb_strlen( $before, 'UTF-8' );
		$length = mb_strlen( $anchor, 'UTF-8' );
		$found  = array();

		foreach ( $view->find_all( $before . $anchor . $after ) as $at ) {
			$found[] = array( $at + $lead, $at + $lead + $length );
		}

		return $found;
	}

	/**
	 * Motivo por el que no se puede enlazar un tramo del texto, o null si se puede.
	 *
	 * @param TextView $view     Vista.
	 * @param int      $from     Primer carácter del ancla.
	 * @param int      $to       Carácter siguiente al último.
	 * @param bool     $headings Permitir encabezados.
	 */
	public static function blocked( TextView $view, int $from, int $to, bool $headings ): ?InsertionException {
		$forbidden = $headings ? self::NEVER : array_merge( self::NEVER, self::HEADINGS );
		$reason    = $view->blocker( $from, $to, $forbidden );

		if ( null === $reason ) {
			return null;
		}
		if ( 'link' === $reason ) {
			return new InsertionException( InsertionException::ALREADY_LINKED, __( 'That text is already linked.', 'magic-linking' ) );
		}
		if ( str_starts_with( $reason, 'element:h' ) ) {
			return new InsertionException( InsertionException::BLOCK_NOT_ALLOWED, __( 'This text is a heading and links in headings are not allowed.', 'magic-linking' ) );
		}

		return new InsertionException( InsertionException::UNSAFE_ANCHOR, __( 'The anchor includes a shortcode, code or a line break and cannot be linked safely.', 'magic-linking' ) );
	}

	/**
	 * Inserta el enlace en el HTML.
	 *
	 * @param string        $html    HTML del tramo.
	 * @param TextView      $view    Su vista de texto.
	 * @param int           $from    Primer carácter del ancla.
	 * @param int           $to      Carácter siguiente al último.
	 * @param InsertRequest $request Petición.
	 *
	 * @return array{0: string, 1: int, 2: int} HTML nuevo y bytes [inicio, fin) donde quedó el ancla (en el HTML original).
	 *
	 * @throws InsertionException Si el ancla cruza etiquetas de forma que el enlace quedaría mal anidado.
	 */
	public static function wrap( string $html, TextView $view, int $from, int $to, InsertRequest $request ): array {
		[ $start, $end ] = $view->bytes( $from, $to );
		$range           = $view->balanced( $start, $end, self::INLINE );

		if ( null === $range ) {
			throw new InsertionException( InsertionException::CROSSES_TAGS, __( 'The anchor crosses text formatting (bold, italics…) and cannot be linked without breaking it.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
		}

		[ $start, $end ] = $range;

		return array(
			substr( $html, 0, $start ) . self::open_tag( $request ) . substr( $html, $start, $end - $start ) . '</a>' . substr( $html, $end ),
			$start,
			$end,
		);
	}

	/**
	 * Etiqueta de apertura del enlace: `href` y, solo si están configurados, `target` y `rel`.
	 *
	 * @param InsertRequest $request Petición.
	 *
	 * @throws InsertionException Si la dirección no es válida.
	 */
	public static function open_tag( InsertRequest $request ): string {
		$url = esc_url( $request->url );
		if ( '' === $url ) {
			throw new InsertionException( InsertionException::BAD_REQUEST, __( 'The link address is not valid.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
		}

		$tag = '<a href="' . $url . '"';
		if ( isset( $request->attributes['target'] ) && '' !== $request->attributes['target'] ) {
			$tag .= ' target="' . esc_attr( $request->attributes['target'] ) . '"';
		}
		if ( isset( $request->attributes['rel'] ) && '' !== $request->attributes['rel'] ) {
			$tag .= ' rel="' . esc_attr( $request->attributes['rel'] ) . '"';
		}

		return $tag . '>';
	}
}
