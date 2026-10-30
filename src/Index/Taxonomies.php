<?php
/**
 * Valores de categoría y etiqueta de una entrada para los filtros de las sugerencias.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

/**
 * Las claves son las taxonomías lógicas del motor (`category`, `post_tag`, ver
 * {@see \MagicLinking\Engine\Suggester::FILTERS}) y los valores, los `term_taxonomy_id` como texto: dos entradas
 * comparten un valor si tienen el mismo término, y el filtro Exigir se resuelve en SQL sobre
 * `term_relationships` sin unir con ninguna otra tabla.
 *
 * En un tipo de contenido propio, «categoría» es su taxonomía jerárquica principal (`product_cat`…) y «etiqueta»
 * la no jerárquica principal; al ser términos distintos de los de `category`, una entrada y un producto nunca
 * comparten categoría (docs/04 §4.3).
 */
final class Taxonomies {

	/**
	 * Taxonomías técnicas de los plugins de idiomas y de formato: no son temas.
	 */
	private const SKIP = array( 'post_format', 'language', 'term_language', 'post_translations', 'term_translations' );

	/**
	 * Taxonomía principal de un tipo para una taxonomía lógica del motor.
	 *
	 * @param string $post_type Tipo de contenido.
	 * @param string $logical   `category` (jerárquica) o `post_tag` (no jerárquica).
	 */
	public static function primary( string $post_type, string $logical ): ?string {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		if ( isset( $taxonomies[ $logical ] ) ) {
			return $logical;
		}

		$hierarchical = 'category' === $logical;
		foreach ( $taxonomies as $name => $taxonomy ) {
			if ( ! in_array( $name, self::SKIP, true ) && $taxonomy->public && $taxonomy->show_ui && $taxonomy->hierarchical === $hierarchical ) {
				return (string) $name;
			}
		}

		return null;
	}

	/**
	 * Valores de una entrada: taxonomía lógica → `term_taxonomy_id` como texto.
	 *
	 * Usa la caché de términos de WordPress, que se carga por lotes con `update_object_term_cache()`.
	 *
	 * @param int    $post_id   ID.
	 * @param string $post_type Tipo de contenido.
	 *
	 * @return array<string, list<string>>
	 */
	public static function of( int $post_id, string $post_type ): array {
		$out = array();
		foreach ( array( 'category', 'post_tag' ) as $logical ) {
			$taxonomy = self::primary( $post_type, $logical );
			$terms    = null === $taxonomy ? false : get_the_terms( $post_id, $taxonomy );
			$ids      = array();
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$ids[] = (string) $term->term_taxonomy_id;
			}
			$out[ $logical ] = $ids;
		}

		return $out;
	}
}
