<?php
/**
 * Cambio de destino o retirada de un enlace roto en el contenido (docs/06 §9).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

use MagicLinking\Graph\LinkParser;

/**
 * Hermano de {@see BlockEditor} y {@see ClassicEditor} para enlaces que **ya existen**: localiza el `<a>` roto
 * y calcula el contenido con otro `href` (conservando texto y demás atributos byte a byte) o sin el `<a>` (el
 * texto se queda tal cual). No escribe en la base de datos ni verifica: eso lo hace {@see Repairer} con
 * {@see Verifier}.
 *
 * Cuál es el enlace (docs/06 §9): se recorren todos los `<a>` del documento en orden, se clasifican con el mismo
 * {@see LinkParser} que construyó el grafo y se escoge la aparición número `ordinal` entre las que tienen la
 * dirección rota. Se exige que el documento tenga exactamente las apariciones que dice el grafo y que el ancla de
 * la elegida sea la del grafo; si no, el contenido cambió desde que se hizo la lista y no se toca nada.
 */
final class LinkFixer {

	/**
	 * Bloques en los que se puede **quitar** un enlace sin invalidar el bloque: texto que sigue siendo texto.
	 * Quitarlo de un botón, una imagen o un bloque de un complemento dejaría marcas que el editor reescribe.
	 * Cambiar el destino, en cambio, no cambia la forma del bloque y se permite en cualquiera.
	 */
	public const UNLINK_BLOCKS = array( 'core/paragraph', 'core/list-item', 'core/heading', 'core/quote', 'core/pullquote', 'core/verse', 'core/preformatted', 'core/table', 'core/details' );

	/**
	 * Constructor.
	 *
	 * @param LinkParser $parser El mismo analizador que usa el grafo.
	 */
	public function __construct( private LinkParser $parser ) {
	}

	/**
	 * Calcula el contenido con el enlace arreglado.
	 *
	 * @param string     $content Contenido guardado.
	 * @param FixRequest $request Petición.
	 *
	 * @throws InsertionException Si no se puede arreglar con seguridad; no se ha tocado nada.
	 */
	public function fix( string $content, FixRequest $request ): Edit {
		if ( FixRequest::RETARGET === $request->mode && ( null === $request->new_url || '' === $request->new_url ) ) {
			throw new InsertionException( InsertionException::BAD_REQUEST, __( 'The link address is not valid.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
		}

		if ( BlockEditor::handles( $content ) ) {
			$map = BlockMap::parse( $content );
			if ( null === $map ) {
				throw new InsertionException( InsertionException::UNSUPPORTED, __( 'The block structure of this post could not be read, so it was left untouched.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
			}

			$segments = array();
			foreach ( $map->walk() as $node ) {
				foreach ( $node->segments as [ $from, $to ] ) {
					$segments[] = array( $from, $to, $node );
				}
			}
			usort( $segments, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );

			return $this->apply( $content, $request, $segments, true );
		}

		return $this->apply( $content, $request, array( array( 0, strlen( $content ), null ) ), false );
	}

	/**
	 * Localiza el enlace y construye el cambio.
	 *
	 * @param string     $content  Contenido.
	 * @param FixRequest $request  Petición.
	 * @param array      $segments Tramos de HTML en orden de documento: [inicio, fin, bloque propietario o null].
	 * @param bool       $blocks   Contenido de bloques.
	 *
	 * @phpstan-param list<array{0: int, 1: int, 2: BlockNode|null}> $segments
	 *
	 * @throws InsertionException Si no se puede arreglar con seguridad.
	 */
	private function apply( string $content, FixRequest $request, array $segments, bool $blocks ): Edit {
		$candidates = array();
		foreach ( $segments as [ $from, $to, $node ] ) {
			$html  = substr( $content, $from, $to - $from );
			$links = AnchorScanner::scan( $html );
			if ( null === $links ) {
				throw new InsertionException( InsertionException::UNSUPPORTED, __( 'The post contains markup (nested links, scripts, styles…) that cannot be scanned safely, so it was left untouched.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
			}

			foreach ( $links as $link ) {
				$parsed = $this->parser->parse( substr( $html, $link['open_start'], $link['close_end'] - $link['open_start'] ) );
				if ( 1 === count( $parsed ) && $parsed[0]->internal && $parsed[0]->url === $request->url ) {
					$candidates[] = array(
						'link'   => $link,
						'from'   => $from,
						'node'   => $node,
						'anchor' => $parsed[0]->anchor,
					);
				}
			}
		}

		if ( count( $candidates ) !== $request->count || ! isset( $candidates[ $request->ordinal ] ) || $candidates[ $request->ordinal ]['anchor'] !== $request->anchor ) {
			throw new InsertionException( InsertionException::LINK_GONE, __( 'The post no longer matches the list (the link was moved, edited or is not stored in its content). Nothing was changed; reload the list.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
		}

		$pick  = $candidates[ $request->ordinal ];
		$node  = $pick['node'];
		$open  = $pick['from'] + $pick['link']['open_start'];
		$after = $pick['from'] + $pick['link']['open_end'];
		$close = $pick['from'] + $pick['link']['close_start'];
		$end   = $pick['from'] + $pick['link']['close_end'];
		$tag   = substr( $content, $open, $after - $open );

		if ( $blocks && $node instanceof BlockNode ) {
			$this->assert_block( $node, $request, AnchorScanner::href( $tag ) );
		}

		if ( FixRequest::RETARGET === $request->mode ) {
			$new_tag = AnchorScanner::with_href( $tag, (string) $request->new_url );
			if ( null === $new_tag || $new_tag === $tag ) {
				throw new InsertionException( InsertionException::UNSUPPORTED, __( 'The link address could not be changed safely, so the post was left untouched.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
			}
			$new_content = substr( $content, 0, $open ) . $new_tag . substr( $content, $after );
		} else {
			$new_content = substr( $content, 0, $open ) . substr( $content, $after, $close - $after ) . substr( $content, $end );
		}

		if ( $blocks && $node instanceof BlockNode ) {
			$start = $node->start;
			$stop  = $node->end;
			$path  = $node->path;
		} else {
			[ $start, $stop ] = ( new ClassicEditor() )->chunk( $content, $open, $end );
			$path             = '@' . $start;
		}

		$delta = strlen( $new_content ) - strlen( $content );

		return new Edit(
			$new_content,
			$path,
			$start,
			$stop,
			substr( $content, $start, $stop - $start ),
			substr( $new_content, $start, $stop + $delta - $start )
		);
	}

	/**
	 * Comprueba que el bloque admite el cambio.
	 *
	 * @param BlockNode  $node    Bloque que contiene el enlace.
	 * @param FixRequest $request Petición.
	 * @param string     $href    `href` actual del enlace.
	 *
	 * @throws InsertionException Si el bloque no se debe tocar.
	 */
	private function assert_block( BlockNode $node, FixRequest $request, string $href ): void {
		if ( isset( $node->attrs['metadata']['bindings'] ) && is_array( $node->attrs['metadata']['bindings'] ) && array() !== $node->attrs['metadata']['bindings'] ) {
			throw new InsertionException( InsertionException::BOUND_BLOCK, __( 'The text of this block comes from another source (data binding) and cannot be edited here.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
		}

		if ( FixRequest::UNLINK === $request->mode ) {
			// El HTML suelto (bloque clásico) no tiene nombre y se admite: lo que queda es texto.
			if ( null !== $node->name && ! in_array( $node->name, $this->unlink_blocks( $request->post_id ), true ) ) {
				throw new InsertionException( InsertionException::BLOCK_NOT_ALLOWED, __( 'Removing the link would break this block (a button, an image…). Change its destination instead, or edit the entry.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
			}
			return;
		}

		// La dirección también está en los ajustes del bloque (p. ej. un archivo): cambiar solo el HTML lo dejaría incoherente.
		if ( '' !== $href && array() !== $node->attrs ) {
			foreach ( array( JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 0 ) as $flags ) {
				if ( str_contains( (string) wp_json_encode( $node->attrs, $flags ), (string) wp_json_encode( $href, $flags ) ) ) {
					throw new InsertionException( InsertionException::UNSUPPORTED, __( 'The address of this link is also stored in the settings of its block, so it is not changed here. Edit the entry instead.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
				}
			}
		}
	}

	/**
	 * Bloques en los que se puede quitar un enlace (los de la lista más los que añada un complemento).
	 *
	 * @param int $post_id Entrada.
	 *
	 * @return list<string>
	 */
	private function unlink_blocks( int $post_id ): array {
		// El mismo filtro que decide en qué bloques de texto se inserta (ver BlockEditor): lo que un complemento declare ahí es texto.
		$filtered = apply_filters( 'magiclinking_insertable_blocks', BlockEditor::ALLOWED, $post_id );

		return array_values( array_unique( array_merge( self::UNLINK_BLOCKS, array_filter( array_map( 'strval', (array) $filtered ) ) ) ) );
	}
}
