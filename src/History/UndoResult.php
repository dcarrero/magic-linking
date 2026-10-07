<?php
/**
 * Resultado de deshacer un cambio.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\History;

/**
 * Qué pasó al deshacer un cambio del historial.
 */
final class UndoResult {

	/** El contenido ha vuelto a ser exactamente el de antes del enlace. */
	public const RESTORED = 'restored';

	/** La entrada se había editado después: se ha quitado solo el enlace. */
	public const LINK_REMOVED = 'link_removed';

	/** No se ha tocado nada: hay que quitar el enlace a mano. */
	public const MANUAL = 'manual';

	/** El enlace ya no estaba en la entrada (borrado a mano o recuperado de una revisión). */
	public const GONE = 'already_gone';

	/** El cambio ya estaba deshecho. */
	public const ALREADY = 'already_undone';

	/** Se ha vuelto a poner el enlace (rehacer) y el contenido es el que había antes de deshacer. */
	public const REDONE = 'redone';

	/** No se ha tocado nada por otro motivo (bloqueo, permisos, verificación…). */
	public const FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param int         $change_id Cambio.
	 * @param int         $post_id   Entrada.
	 * @param string      $status    Una de las constantes.
	 * @param string      $message   Mensaje para el usuario.
	 * @param string      $reason    Código de {@see \MagicLinking\Content\InsertionException} si falló.
	 * @param string|null $edit_url  Enlace directo al editor cuando hay que quitar el enlace a mano.
	 */
	public function __construct(
		public readonly int $change_id,
		public readonly int $post_id,
		public readonly string $status,
		public readonly string $message = '',
		public readonly string $reason = '',
		public readonly ?string $edit_url = null
	) {
	}

	/**
	 * Si el contenido quedó sin el enlace (ahora o antes).
	 */
	public function done(): bool {
		return in_array( $this->status, array( self::RESTORED, self::LINK_REMOVED, self::GONE, self::ALREADY ), true );
	}
}
