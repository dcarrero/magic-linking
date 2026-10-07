<?php
/**
 * Almacén que carga por lotes lo que el motor consultará entrada a entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Evita una consulta por candidata: el motor avisa de las entradas que va a puntuar con
 * {@see self::preload()} y, al terminar, suelta lo cargado con {@see self::release()}. Mientras tanto,
 * `meta()`, `terms()`, `linking_to()` y `anchor_uses()` contestan desde memoria para esas entradas.
 */
interface Preloads {

	/**
	 * Carga los datos de unas entradas (sustituye lo cargado antes).
	 *
	 * @param int[] $ids IDs.
	 *
	 * @phpstan-param list<int> $ids
	 */
	public function preload( array $ids ): void;

	/**
	 * Suelta lo cargado con {@see self::preload()}.
	 */
	public function release(): void;
}
