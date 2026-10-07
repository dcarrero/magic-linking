<?php
/**
 * Mapa de bloques de un documento con sus posiciones en bytes.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

/**
 * `parse_blocks()` devuelve la estructura pero no dónde está cada bloque en el texto guardado, y la
 * inserción necesita esas posiciones para sustituir solo el tramo del bloque (docs/06 §3.6) sin volver a
 * serializar nada. Este mapa recorre los comentarios de bloque con la misma expresión que el analizador de
 * WordPress y, antes de entregarse, se comprueba contra `parse_blocks()`: mismos bloques, mismo orden,
 * mismos índices y mismo `innerHTML`. Si no coinciden por cualquier motivo, no hay mapa y no se toca nada.
 */
final class BlockMap {

	/**
	 * Comentario de bloque: la expresión de `WP_Block_Parser::next_token()`.
	 */
	private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Constructor.
	 *
	 * @param string $content Contenido guardado.
	 * @param array  $roots   Bloques de primer nivel, con los índices de `parse_blocks()`.
	 *
	 * @phpstan-param list<BlockNode> $roots
	 */
	private function __construct( private string $content, private array $roots ) {
	}

	/**
	 * Construye el mapa de un documento con bloques.
	 *
	 * @param string $content Contenido guardado.
	 *
	 * @return BlockMap|null Null si el documento no se puede mapear con seguridad (comentarios mal cerrados, discrepancia con `parse_blocks()`).
	 */
	public static function parse( string $content ): ?BlockMap {
		preg_match_all( self::DELIMITER, $content, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );

		$roots  = array();
		$stack  = array();
		$cursor = 0;

		foreach ( $found as $match ) {
			$token = $match[0][0];
			$at    = (int) $match[0][1];
			$name  = ( '' !== $match['namespace'][0] ? $match['namespace'][0] : 'core/' ) . $match['name'][0];
			$void  = '/' === ( $match['void'][0] ?? '' );
			$close = '/' === $match['closer'][0];
			$end   = $at + strlen( $token );

			// HTML propio entre el comentario anterior y este.
			if ( $at > $cursor ) {
				if ( array() === $stack ) {
					$roots[] = new BlockNode( null, (string) count( $roots ), $cursor, $at, array(), array( array( $cursor, $at ) ), array(), true );
				} else {
					$stack[ count( $stack ) - 1 ]->segments[] = array( $cursor, $at );
				}
			}
			$cursor = $end;

			if ( $close ) {
				if ( array() === $stack || $stack[ count( $stack ) - 1 ]->name !== $name ) {
					return null;
				}
				$node      = array_pop( $stack );
				$node->end = $end;
				continue;
			}

			$attrs = array();
			if ( isset( $match['attrs'] ) && '' !== trim( $match['attrs'][0] ) ) {
				$decoded = json_decode( trim( $match['attrs'][0] ), true );
				$attrs   = is_array( $decoded ) ? $decoded : array();
			}

			$parent = array() === $stack ? null : $stack[ count( $stack ) - 1 ];
			$index  = null === $parent ? count( $roots ) : count( $parent->children );
			$path   = null === $parent ? (string) $index : $parent->path . '.' . $index;
			$node   = new BlockNode( $name, $path, $at, $end, $attrs );

			if ( null === $parent ) {
				$roots[] = $node;
			} else {
				$parent->children[] = $node;
			}

			if ( ! $void ) {
				$stack[] = $node;
			}
		}//end foreach

		if ( array() !== $stack ) {
			return null;
		}

		if ( $cursor < strlen( $content ) ) {
			$roots[] = new BlockNode( null, (string) count( $roots ), $cursor, strlen( $content ), array(), array( array( $cursor, strlen( $content ) ) ), array(), true );
		}

		$map = new self( $content, $roots );

		return $map->agrees_with_parser() ? $map : null;
	}

	/**
	 * Bloques de primer nivel.
	 *
	 * @return list<BlockNode>
	 */
	public function roots(): array {
		return $this->roots;
	}

	/**
	 * Busca un bloque por su ruta.
	 *
	 * @param string $path Ruta (`3.0.1`).
	 */
	public function find( string $path ): ?BlockNode {
		$indexes = explode( '.', $path );
		$level   = $this->roots;
		$node    = null;

		foreach ( $indexes as $index ) {
			if ( 1 !== preg_match( '/^(0|[1-9][0-9]*)$/', $index ) || ! isset( $level[ (int) $index ] ) ) {
				return null;
			}
			$node  = $level[ (int) $index ];
			$level = $node->children;
		}

		return $node;
	}

	/**
	 * Todos los bloques en orden de documento.
	 *
	 * @return list<BlockNode>
	 */
	public function walk(): array {
		$all = array();
		$add = static function ( array $nodes ) use ( &$add, &$all ): void {
			foreach ( $nodes as $node ) {
				$all[] = $node;
				$add( $node->children );
			}
		};
		$add( $this->roots );

		return $all;
	}

	/**
	 * HTML propio de un bloque (sin el de sus hijos), igual que `innerHTML` de `parse_blocks()`.
	 *
	 * @param BlockNode $node Bloque.
	 */
	public function inner_html( BlockNode $node ): string {
		$html = '';
		foreach ( $node->segments as [ $from, $to ] ) {
			$html .= substr( $this->content, $from, $to - $from );
		}

		return $html;
	}

	/**
	 * Si el mapa describe exactamente lo que `parse_blocks()` entiende del documento.
	 */
	private function agrees_with_parser(): bool {
		return $this->same( parse_blocks( $this->content ), $this->roots );
	}

	/**
	 * Compara un nivel de `parse_blocks()` con el mapa.
	 *
	 * @param array $parsed Bloques de WordPress.
	 * @param array $nodes  Bloques del mapa.
	 *
	 * @phpstan-param array<int, array<string, mixed>> $parsed
	 * @phpstan-param list<BlockNode>                  $nodes
	 */
	private function same( array $parsed, array $nodes ): bool {
		if ( count( $parsed ) !== count( $nodes ) ) {
			return false;
		}

		foreach ( array_values( $parsed ) as $i => $block ) {
			$node = $nodes[ $i ];
			if ( ( $block['blockName'] ?? null ) !== $node->name || (string) ( $block['innerHTML'] ?? '' ) !== $this->inner_html( $node ) ) {
				return false;
			}
			if ( ! $this->same( (array) ( $block['innerBlocks'] ?? array() ), $node->children ) ) {
				return false;
			}
		}

		return true;
	}
}
