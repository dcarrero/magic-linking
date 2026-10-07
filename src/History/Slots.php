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
}
