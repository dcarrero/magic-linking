<?php
/**
 * Memoria acotada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Memoria acotada de los resultados que se repiten palabra tras palabra (raíces, formas, palabras de contenido).
 */
final class Memo {

	/**
	 * Guarda un valor; al llegar al tope la memoria se vacía entera (más barato que llevar un orden de uso).
	 *
	 * @param array      $map   Memoria (por referencia).
	 * @param string|int $key   Clave.
	 * @param mixed      $value Valor.
	 * @param int        $limit Entradas como máximo.
	 *
	 * @phpstan-param array<mixed> $map
	 */
	public static function put( array &$map, string|int $key, mixed $value, int $limit ): void {
		if ( count( $map ) >= $limit ) {
			$map = array();
		}
		$map[ $key ] = $value;
	}
}
