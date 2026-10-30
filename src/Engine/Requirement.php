<?php
/**
 * Comparador de los filtros en modo Exigir.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * La única definición en PHP de «cumple los filtros Exigir» (docs/04 §4.3), compartida por {@see Suggester} y
 * {@see MemoryIndex}. El índice en tablas lo expresa en SQL (`TableRepository::require_sql()`); una prueba de
 * integración comprueba que ambos dan lo mismo sobre los mismos casos.
 *
 * Condición: `types` (tipos permitidos) y, por taxonomía, al menos un valor en común con los exigidos.
 */
final class Requirement {

	/**
	 * Si una entrada cumple la condición.
	 *
	 * @param array  $needs      Condición (ver {@see IndexRepository::similar()}).
	 * @param string $type       Tipo de contenido de la entrada.
	 * @param array  $taxonomies Taxonomía → valores de la entrada.
	 *
	 * @phpstan-param array{types?: list<string>, taxonomies?: array<string, list<string>>} $needs
	 * @phpstan-param array<string, list<string>> $taxonomies
	 */
	public static function met( array $needs, string $type, array $taxonomies ): bool {
		if ( isset( $needs['types'] ) && ! in_array( $type, $needs['types'], true ) ) {
			return false;
		}
		foreach ( $needs['taxonomies'] ?? array() as $taxonomy => $values ) {
			if ( array() === array_intersect( $values, $taxonomies[ $taxonomy ] ?? array() ) ) {
				return false;
			}
		}

		return true;
	}
}
