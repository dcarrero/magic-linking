<?php
/**
 * Un enlace del historial, aunque se haya puesto, quitado y vuelto a poner.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\History;

/**
 * Rehacer deja una fila `insert` nueva con el mismo tramo antes y después que la original. Para la
 * pantalla y para los procesos de deshacer en bloque eso es **un solo enlace** («hueco»): vale la última
 * fila de cada hueco, que es la que dice si el enlace está puesto o deshecho ahora.
 */
final class Slots {

	/**
	 * Última fila de cada hueco, en el orden en que se crearon.
	 *
	 * @param list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string}> $rows Inserciones de un lote, de la más antigua a la más reciente.
	 *
	 * @return list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string}>
	 */
	public static function collapse( array $rows ): array {
		$latest = array();
		foreach ( $rows as $row ) {
			// El hueco conserva su sitio (el de la primera fila) y se queda con la fila más reciente.
			$latest[ $row['slot'] ] = $row;
		}

		return array_values( $latest );
	}

	/**
	 * Ids de las filas más recientes de cada hueco, en el orden en que se tratan: de la más nueva a la más
	 * antigua para deshacer (y para «todos»), de la más antigua a la más nueva para rehacer. El orden es por id,
	 * no por posición del hueco, porque un hueco rehecho lleva un id alto y los procesos avanzan con un cursor de id.
	 *
	 * @param array     $rows   Inserciones de un lote (de `ChangeRepository::inserts_of()`).
	 * @param bool|null $undone true = solo los deshechos (rehacer); false = solo los puestos (deshacer); null = todos.
	 *
	 * @phpstan-param list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string}> $rows
	 *
	 * @return list<int>
	 */
	public static function ordered_ids( array $rows, ?bool $undone ): array {
		$ids = array();
		foreach ( self::collapse( $rows ) as $slot ) {
			if ( null === $undone || ( null !== $slot['undone_at'] ) === $undone ) {
				$ids[] = $slot['id'];
			}
		}

		true === $undone ? sort( $ids ) : rsort( $ids );

		return $ids;
	}
}
