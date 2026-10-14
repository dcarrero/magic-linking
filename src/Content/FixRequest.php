<?php
/**
 * Petición de arreglar un enlace roto de una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

use MagicLinking\History\ChangeRepository;

/**
 * Qué enlace es y qué hacer con él. El enlace se identifica **por su aparición** (docs/06 §9): la dirección
 * normalizada que guarda el grafo, cuántas veces aparece esa dirección en la entrada y cuál de ellas es (la
 * posición, desde 0, entre las apariciones de esa dirección en el orden del documento). Con el texto del ancla
 * como comprobación: si el contenido ya no encaja con el grafo, no se toca nada.
 */
final class FixRequest {

	/**
	 * Cambiar el destino del enlace.
	 */
	public const RETARGET = 'retarget';

	/**
	 * Quitar el enlace y dejar su texto.
	 */
	public const UNLINK = 'unlink';

	/**
	 * Constructor.
	 *
	 * @param int         $post_id    Entrada donde está el enlace (el origen).
	 * @param string      $mode       `retarget` o `unlink`.
	 * @param string      $url        Dirección interna normalizada del enlace roto, tal como la guarda el grafo.
	 * @param int         $ordinal    Qué aparición de esa dirección es, desde 0, en el orden del documento.
	 * @param int         $count      Cuántas veces aparece esa dirección según el grafo.
	 * @param string      $anchor     Texto del ancla según el grafo (comprobación).
	 * @param string|null $new_url    Dirección nueva (solo `retarget`).
	 * @param int|null    $user_id    Quien lo pide (null = el usuario actual; 0 = el sistema).
	 */
	public function __construct(
		public readonly int $post_id,
		public readonly string $mode,
		public readonly string $url,
		public readonly int $ordinal,
		public readonly int $count,
		public readonly string $anchor,
		public readonly ?string $new_url = null,
		public readonly ?int $user_id = null
	) {
	}

	/**
	 * Acción del historial que deja esta petición.
	 */
	public function action(): string {
		return self::RETARGET === $this->mode ? ChangeRepository::RETARGET : ChangeRepository::UNLINK;
	}
}
