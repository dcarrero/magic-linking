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
	 * Guarda un valor; al llegar al tope se descarta la mitad más antigua (en orden de inserción), así las palabras que siguen
	 * apareciendo se vuelven a guardar enseguida y el tamaño nunca pasa del tope.
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
			$map = array_slice( $map, intdiv( $limit, 2 ), null, true );
		}
		$map[ $key ] = $value;
	}
}
