<?php
/**
 * Almacén que da la frecuencia documental de unos términos concretos.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Un índice con un vocabulario que no cabe en memoria (las tablas del plugin) no puede devolver el
 * `df` de todos los términos en {@see IndexRepository::stats()}: lo da solo de los que se piden.
 * El motor lo usa, si el almacén lo implementa, para pesar la entrada abierta.
 */
interface TermStats {

	/**
	 * Estadísticas del idioma con la frecuencia de unos términos, y sus identificadores.
	 *
	 * @param string   $lang  Idioma.
	 * @param string[] $terms Términos.
	 * @param bool     $keep  El motor va a buscar candidatas enseguida: el almacén puede guardar los identificadores hasta que lo suelte ({@see Preloads::release()}).
	 *
	 * @return array{0: IndexStats, 1: array<string, int>} Estadísticas e ID de término por raíz.
	 */
	public function stats_for( string $lang, array $terms, bool $keep = false ): array;
}
