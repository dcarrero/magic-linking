<?php
/**
 * Enlace insertado.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

/**
 * Lo que devuelve {@see Inserter::insert()}: el cambio guardado en el historial, para poder deshacerlo.
 */
final class InsertResult {

	/**
	 * Constructor.
	 *
	 * @param int    $change_id Fila de `magiclinking_changes`.
	 * @param string $batch_id  Lote al que pertenece.
	 * @param int    $post_id   Entrada modificada.
	 * @param string $path      Ruta del bloque o, en el editor clásico, `@` y el byte del tramo.
	 */
	public function __construct(
		public readonly int $change_id,
		public readonly string $batch_id,
		public readonly int $post_id,
		public readonly string $path
	) {
	}
}
