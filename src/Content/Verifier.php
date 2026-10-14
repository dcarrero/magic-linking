<?php
/**
 * Verificador de cambios: lo único que ha cambiado es el enlace (docs/06 §6).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

use WP_HTML_Tag_Processor;

/**
 * Compara el contenido antes y después de insertar (o quitar) un enlace. Nada se escribe si falla una
 * sola comprobación:
 *
 * 1. `parse_blocks()` da los mismos bloques, en el mismo orden, sin bloques «clásicos» nuevos.
 * 2. Todo lo que queda fuera del rango del bloque modificado es idéntico byte a byte.
 * 3. Dentro del rango, el texto plano es idéntico: solo cambian etiquetas.
 * 4. El número de `<a>` del rango ha cambiado exactamente en el sentido pedido (+1 o −1).
 * 5. Las etiquetas del rango son las mismas salvo el par `<a>`…`</a>`, y ese par está bien anidado.
 *
 * Cambiar el destino de un enlace (`check_retarget()`) pasa las mismas comprobaciones salvo la 4 y la 5: el número
 * de enlaces y la secuencia de etiquetas no varían, y los atributos de todos los `<a>` son idénticos salvo el
 * `href` de uno solo.
 */
final class Verifier {

	/**
	 * Elementos vacíos, que no tienen etiqueta de cierre.
	 */
	private const VOID = array( 'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR' );

	/**
	 * Comprueba un cambio.
	 *
	 * @param string $before Contenido antes.
	 * @param string $after  Contenido después.
	 * @param array  $range  Bytes [inicio, fin) del bloque modificado en $before.
	 * @param int    $links  Variación esperada del número de enlaces del rango: 1 al insertar, -1 al quitar.
	 *
	 * @phpstan-param array{0: int, 1: int} $range
	 */
	public static function check( string $before, string $after, array $range, int $links = 1 ): VerifyResult {
		[ $start, $end ] = $range;
		$delta           = strlen( $after ) - strlen( $before );

		if ( $start < 0 || $end < $start || $end > strlen( $before ) || $end + $delta < $start ) {
			return VerifyResult::fail( 'range' );
		}

		// 2. Fuera del rango, byte a byte.
		if ( substr( $after, 0, $start ) !== substr( $before, 0, $start ) || substr( $after, $end + $delta ) !== substr( $before, $end ) ) {
			return VerifyResult::fail( 'outside_changed' );
		}

		// 1. Estructura de bloques.
		if ( self::block_names( $before ) !== self::block_names( $after ) ) {
			return VerifyResult::fail( 'blocks' );
		}

		$inner_before = substr( $before, $start, $end - $start );
		$inner_after  = substr( $after, $start, $end + $delta - $start );
		$tags_before  = self::scan( $inner_before );
		$tags_after   = self::scan( $inner_after );

		// 3. Mismo texto.
		if ( $tags_before['text'] !== $tags_after['text'] ) {
			return VerifyResult::fail( 'text' );
		}

		// 4. Un enlace más o uno menos.
		if ( $tags_after['links'] - $tags_before['links'] !== $links ) {
			return VerifyResult::fail( 'link_count' );
		}

		// 5. Mismas etiquetas salvo el par de enlace, bien anidado y sin enlaces dentro de enlaces.
		if ( $tags_after['nested'] ) {
			return VerifyResult::fail( 'nested_links' );
		}

		$longer  = $links > 0 ? $tags_after : $tags_before;
		$shorter = $links > 0 ? $tags_before : $tags_after;

		if ( ! self::differs_by_one_link( $longer['sequence'], $shorter['sequence'] ) ) {
			return VerifyResult::fail( 'tags' );
		}

		if ( $longer['crossed'] ) {
			return VerifyResult::fail( 'link_crosses_tags' );
		}

		return VerifyResult::pass();
	}

	/**
	 * Comprueba que un cambio de destino ha tocado solo el `href` de un enlace.
	 *
	 * @param string $before Contenido antes.
	 * @param string $after  Contenido después.
	 * @param array  $range  Bytes [inicio, fin) del tramo modificado en $before.
	 *
	 * @phpstan-param array{0: int, 1: int} $range
	 */
	public static function check_retarget( string $before, string $after, array $range ): VerifyResult {
		[ $start, $end ] = $range;
		$delta           = strlen( $after ) - strlen( $before );

		if ( $start < 0 || $end < $start || $end > strlen( $before ) || $end + $delta < $start ) {
			return VerifyResult::fail( 'range' );
		}

		if ( substr( $after, 0, $start ) !== substr( $before, 0, $start ) || substr( $after, $end + $delta ) !== substr( $before, $end ) ) {
			return VerifyResult::fail( 'outside_changed' );
		}

		if ( self::block_names( $before ) !== self::block_names( $after ) ) {
			return VerifyResult::fail( 'blocks' );
		}

		$tags_before = self::scan( substr( $before, $start, $end - $start ) );
		$tags_after  = self::scan( substr( $after, $start, $end + $delta - $start ) );

		if ( $tags_before['text'] !== $tags_after['text'] ) {
			return VerifyResult::fail( 'text' );
		}
		if ( $tags_before['links'] !== $tags_after['links'] || $tags_before['sequence'] !== $tags_after['sequence'] ) {
			return VerifyResult::fail( 'tags' );
		}
		if ( $tags_after['nested'] || $tags_after['crossed'] ) {
			return VerifyResult::fail( 'link_crosses_tags' );
		}

		// Todos los enlaces con los mismos atributos salvo el `href` de uno.
		$changed = 0;
		foreach ( $tags_before['attributes'] as $index => $old ) {
			$new = $tags_after['attributes'][ $index ] ?? null;
			if ( $old === $new ) {
				continue;
			}
			++$changed;

			if ( null === $new || ( $old['href'] ?? null ) === ( $new['href'] ?? null ) ) {
				return VerifyResult::fail( 'attributes' );
			}
			unset( $old['href'], $new['href'] );
			if ( $old !== $new ) {
				return VerifyResult::fail( 'attributes' );
			}
		}

		return 1 === $changed ? VerifyResult::pass() : VerifyResult::fail( 'link_count' );
	}

	/**
	 * Nombres de todos los bloques (también los hijos) en orden de documento; el HTML suelto es `''`.
	 *
	 * @param string $content Contenido.
	 *
	 * @return list<string>
	 */
	public static function block_names( string $content ): array {
		$names = array();
		$add   = static function ( array $blocks ) use ( &$add, &$names ): void {
			foreach ( $blocks as $block ) {
				$names[] = (string) ( $block['blockName'] ?? '' );
				$add( (array) ( $block['innerBlocks'] ?? array() ) );
			}
		};
		$add( parse_blocks( $content ) );

		return $names;
	}

	/**
	 * Recorre un tramo con el procesador de etiquetas de WordPress.
	 *
	 * @param string $html Tramo.
	 *
	 * @return array{text: string, links: int, sequence: list<string>, nested: bool, crossed: bool, attributes: list<array<string, string|true>>}
	 */
	private static function scan( string $html ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$text      = '';
		$links     = 0;
		$sequence  = array();
		$open      = array();
		$depth     = 0;
		$nested    = false;
		$crossed   = false;
		$attrs     = array();

		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			if ( '#text' === $type ) {
				$text .= $processor->get_modifiable_text();
				continue;
			}
			if ( '#tag' !== $type ) {
				continue;
			}

			$name = (string) $processor->get_tag();
			if ( in_array( $name, self::VOID, true ) ) {
				$sequence[] = $name;
				continue;
			}

			if ( ! $processor->is_tag_closer() ) {
				$sequence[] = $name;
				$open[]     = $name;
				if ( 'A' === $name ) {
					$attributes = array();
					foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $attribute ) {
						$value                             = $processor->get_attribute( (string) $attribute );
						$attributes[ (string) $attribute ] = is_string( $value ) ? $value : true;
					}
					ksort( $attributes );
					$attrs[] = $attributes;
					++$links;
					++$depth;
					$nested = $nested || $depth > 1;
				}
				continue;
			}

			$sequence[] = '/' . $name;
			if ( 'A' === $name ) {
				// El cierre del enlace tiene que cerrar justo el último elemento abierto.
				$crossed = $crossed || array() === $open || 'A' !== end( $open );
				--$depth;
			}
			$position = array_search( $name, array_reverse( $open, true ), true );
			if ( false !== $position ) {
				$open = array_slice( $open, 0, (int) $position );
			}
		}//end while

		return array(
			'text'       => $text,
			'links'      => $links,
			'sequence'   => $sequence,
			'nested'     => $nested,
			'crossed'    => $crossed,
			'attributes' => $attrs,
		);
	}

	/**
	 * Si la secuencia larga es la corta con un `A` y su `/A` añadidos.
	 *
	 * @param array $longer  Secuencia con el enlace.
	 * @param array $shorter Secuencia sin él.
	 *
	 * @phpstan-param list<string> $longer
	 * @phpstan-param list<string> $shorter
	 */
	private static function differs_by_one_link( array $longer, array $shorter ): bool {
		if ( count( $longer ) !== count( $shorter ) + 2 ) {
			return false;
		}

		$k      = 0;
		$opened = false;
		$closed = false;

		foreach ( $longer as $tag ) {
			if ( $k < count( $shorter ) && $tag === $shorter[ $k ] ) {
				++$k;
				continue;
			}
			if ( 'A' === $tag && ! $opened ) {
				$opened = true;
				continue;
			}
			if ( '/A' === $tag && $opened && ! $closed ) {
				$closed = true;
				continue;
			}

			return false;
		}

		return $opened && $closed && count( $shorter ) === $k;
	}
}
