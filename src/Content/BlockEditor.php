<?php
/**
 * Inserción de un enlace en contenido de bloques (docs/06 §3).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

/**
 * Localiza la frase en el bloque de texto permitido que la contiene y sustituye solo el tramo de ese
 * bloque en el contenido original. El resto del documento (comentarios de bloque, espacios, atributos
 * JSON, otros bloques) no se vuelve a serializar: son los mismos bytes.
 *
 * No escribe en la base de datos ni verifica: eso lo hace {@see Inserter} con {@see Verifier}.
 */
final class BlockEditor {

	/**
	 * Bloques en cuyo texto se enlaza (docs/06 §3.2). `core/heading` se suma cuando el usuario lo permite.
	 */
	public const ALLOWED = array( 'core/paragraph', 'core/list-item' );

	/**
	 * Si un contenido es del editor de bloques (tiene comentarios de bloque).
	 *
	 * @param string $content Contenido guardado.
	 */
	public static function handles( string $content ): bool {
		return str_contains( $content, '<!-- wp:' );
	}

	/**
	 * Calcula el contenido con el enlace insertado.
	 *
	 * @param string        $content Contenido guardado.
	 * @param InsertRequest $request Petición.
	 *
	 * @throws InsertionException Si no se puede insertar con seguridad; no se ha tocado nada.
	 */
	public function insert( string $content, InsertRequest $request ): Edit {
		$map = BlockMap::parse( $content );
		if ( null === $map ) {
			throw new InsertionException( InsertionException::UNSUPPORTED, __( 'The block structure of this post could not be read, so it was left untouched.', 'magic-linking' ) );
		}

		$allowed = $this->allowed( $request );
		$nodes   = $this->candidates( $map, $request );
		$found   = array();
		$blocked = null;
		$refused = null;

		foreach ( $nodes as $node ) {
			if ( ! in_array( $node->name, $allowed, true ) ) {
				// Solo importa si el texto de la sugerencia está de verdad en un bloque que no se toca.
				if ( null !== $request->block_path || $this->mentions( $content, $node, $request ) ) {
					$refused = $this->refusal( $node );
				}
				continue;
			}

			foreach ( $node->segments as [ $from, $to ] ) {
				$html = substr( $content, $from, $to - $from );
				$view = new TextView( $html, false );

				foreach ( Linker::matches( $view, $request ) as [ $a, $b ] ) {
					if ( null !== $view->unsafe() ) {
						throw new InsertionException( InsertionException::UNSUPPORTED, __( 'The block contains markup (script, styles…) that cannot be scanned safely.', 'magic-linking' ) );
					}
					$problem = Linker::blocked( $view, $a, $b, $request->headings );
					if ( null !== $problem ) {
						$blocked ??= $problem;
						continue;
					}
					$found[] = array( $node, $from, $html, $view, $a, $b );
				}
			}
		}//end foreach

		if ( null !== $refused && array() === $found ) {
			throw $refused;
		}

		if ( 1 !== count( $found ) ) {
			if ( array() === $found && null !== $blocked ) {
				throw $blocked;
			}
			throw new InsertionException( InsertionException::TEXT_CHANGED, __( 'The text has changed.', 'magic-linking' ) );
		}

		[ $node, $from, $html, $view, $a, $b ] = $found[0];

		if ( isset( $node->attrs['metadata']['bindings'] ) && is_array( $node->attrs['metadata']['bindings'] ) && array() !== $node->attrs['metadata']['bindings'] ) {
			throw new InsertionException( InsertionException::BOUND_BLOCK, __( 'The text of this block comes from another source (data binding) and cannot be edited here.', 'magic-linking' ) );
		}

		[ $new_html ] = Linker::wrap( $html, $view, $a, $b, $request );
		$new_content  = substr( $content, 0, $from ) . $new_html . substr( $content, $from + strlen( $html ) );

		return new Edit(
			$new_content,
			$node->path,
			$node->start,
			$node->end,
			substr( $content, $node->start, $node->end - $node->start ),
			substr( $new_content, $node->start, $node->end + strlen( $new_content ) - strlen( $content ) - $node->start )
		);
	}

	/**
	 * Prepara un contenido para comprobar varias peticiones sin volver a leerlo cada vez: la vista de texto de cada
	 * tramo de los bloques en los que se enlaza.
	 *
	 * @param string $content  Contenido.
	 * @param int    $post_id  Entrada (para el filtro `magiclinking_insertable_blocks`).
	 * @param bool   $headings Enlazar también en encabezados.
	 *
	 * @return list<TextView>|null Null si la estructura de bloques no se puede leer.
	 */
	public function prepare( string $content, int $post_id, bool $headings = false ): ?array {
		$map = BlockMap::parse( $content );
		if ( null === $map ) {
			return null;
		}

		$allowed = $this->allowed( new InsertRequest( $post_id, '', '', '', '', null, array(), null, $headings ) );
		$views   = array();
		foreach ( $map->walk() as $node ) {
			if ( $node->freeform || ! in_array( $node->name, $allowed, true ) ) {
				continue;
			}
			foreach ( $node->segments as [ $from, $to ] ) {
				$views[] = new TextView( substr( $content, $from, $to - $from ), false );
			}
		}

		return $views;
	}

	/**
	 * Si la petición se podría insertar: el contexto aparece una sola vez, enlazable, en los tramos preparados.
	 * Lo mismo que comprueba {@see self::insert()} antes de escribir, sin construir el resultado.
	 *
	 * @param array         $views   Tramos de {@see self::prepare()}.
	 * @param InsertRequest $request Petición.
	 *
	 * @phpstan-param list<TextView> $views
	 */
	public function fits( array $views, InsertRequest $request ): bool {
		$found = 0;
		foreach ( $views as $view ) {
			foreach ( Linker::matches( $view, $request ) as [ $a, $b ] ) {
				if ( null !== $view->unsafe() ) {
					return false;
				}
				if ( null === Linker::blocked( $view, $a, $b, $request->headings ) ) {
					++$found;
				}
			}
		}

		return 1 === $found;
	}

	/**
	 * Bloques en los que se busca: el indicado por la ruta o, si no hay, todos.
	 *
	 * @param BlockMap      $map     Mapa.
	 * @param InsertRequest $request Petición.
	 *
	 * @return list<BlockNode>
	 *
	 * @throws InsertionException Si la ruta no existe.
	 */
	private function candidates( BlockMap $map, InsertRequest $request ): array {
		if ( null === $request->block_path ) {
			return array_values( array_filter( $map->walk(), static fn( BlockNode $node ): bool => ! $node->freeform ) );
		}

		$node = $map->find( $request->block_path );
		if ( null === $node ) {
			throw new InsertionException( InsertionException::TEXT_CHANGED, __( 'The text has changed.', 'magic-linking' ) );
		}

		return array( $node );
	}

	/**
	 * Nombres de bloque en los que se enlaza.
	 *
	 * @param InsertRequest $request Petición.
	 *
	 * @return list<string>
	 */
	private function allowed( InsertRequest $request ): array {
		$names = self::ALLOWED;
		if ( $request->headings ) {
			$names[] = 'core/heading';
		}

		/**
		 * Bloques en cuyo texto se puede insertar un enlace.
		 *
		 * Los de la lista (párrafo, elemento de lista y, si el usuario lo permite, encabezado) entran siempre;
		 * un complemento puede añadir bloques de texto propios, que se recorren con las mismas garantías
		 * (verificación byte a byte del resto del documento, historial y deshacer).
		 *
		 * @param array $names   Nombres de bloque (`core/paragraph`).
		 * @param int          $post_id Entrada.
		 *
		 * @phpstan-param list<string> $names
		 */
		$filtered = apply_filters( 'magiclinking_insertable_blocks', $names, $request->post_id );

		return array_values( array_unique( array_merge( $names, array_filter( array_map( 'strval', (array) $filtered ) ) ) ) );
	}

	/**
	 * Por qué no se toca un bloque que no está en la lista.
	 *
	 * @param BlockNode $node Bloque.
	 */
	private function refusal( BlockNode $node ): InsertionException {
		if ( 'core/block' === $node->name ) {
			return new InsertionException( InsertionException::REUSABLE_BLOCK, __( 'This text belongs to a reusable block: edit the original pattern.', 'magic-linking' ) );
		}

		return new InsertionException( InsertionException::BLOCK_NOT_ALLOWED, __( 'This text is in a block where links are not inserted automatically.', 'magic-linking' ) );
	}

	/**
	 * Si el texto de la petición aparece en el HTML propio de un bloque.
	 *
	 * @param string        $content Contenido guardado.
	 * @param BlockNode     $node    Bloque.
	 * @param InsertRequest $request Petición.
	 */
	private function mentions( string $content, BlockNode $node, InsertRequest $request ): bool {
		foreach ( $node->segments as [ $from, $to ] ) {
			$view = new TextView( substr( $content, $from, $to - $from ), false );
			if ( array() !== Linker::matches( $view, $request ) ) {
				return true;
			}
		}

		return false;
	}
}
