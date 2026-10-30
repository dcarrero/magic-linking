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
	 * De dónde lee el editor las categorías y etiquetas de un tipo: taxonomía lógica → taxonomía real, atributo de
	 * la API REST y si es jerárquica. Solo las que el tipo tiene.
	 *
	 * @param string $post_type Tipo de contenido.
	 *
	 * @return array<string, array{taxonomy: string, field: string, hierarchical: bool}>
	 */
	public static function sources( string $post_type ): array {
		$out = array();
		foreach ( array( 'category', 'post_tag' ) as $logical ) {
			$name     = self::primary( $post_type, $logical );
			$taxonomy = null === $name ? null : get_taxonomy( $name );
			if ( $taxonomy ) {
				$out[ $logical ] = array(
					'taxonomy'     => $name,
					'field'        => is_string( $taxonomy->rest_base ) && '' !== $taxonomy->rest_base ? $taxonomy->rest_base : $name,
					'hierarchical' => (bool) $taxonomy->hierarchical,
				);
			}
		}

		return $out;
	}

	/**
	 * Valores a partir de lo que el editor tiene ahora (sin guardar): taxonomía lógica → términos elegidos.
	 *
	 * Cada término es un ID (categorías) o un nombre (etiquetas del editor clásico). Solo cuentan los términos que
	 * existen en la taxonomía principal del tipo: lo demás se descarta. Una taxonomía lógica que no viene se
	 * deja fuera, para que mande lo guardado.
	 *
	 * @param string $post_type Tipo de contenido.
	 * @param array  $terms     Lo que envía el editor.
	 *
	 * @phpstan-param array<string, mixed> $terms
	 *
	 * @return array<string, list<string>>
	 */
	public static function resolve( string $post_type, array $terms ): array {
		$out = array();
		foreach ( array( 'category', 'post_tag' ) as $logical ) {
			if ( ! isset( $terms[ $logical ] ) || ! is_array( $terms[ $logical ] ) ) {
				continue;
			}
			$taxonomy = self::primary( $post_type, $logical );
			$ids      = array();
			foreach ( array_slice( $terms[ $logical ], 0, 100 ) as $value ) {
				if ( null === $taxonomy || ! is_scalar( $value ) ) {
					continue;
				}
				$term = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) )
					? get_term( (int) $value, $taxonomy )
					: get_term_by( 'name', wp_strip_all_tags( (string) $value ), $taxonomy );
				if ( $term instanceof \WP_Term ) {
					$ids[ (string) $term->term_taxonomy_id ] = true;
				}
			}
			$out[ $logical ] = array_map( 'strval', array_keys( $ids ) );
		}

		return $out;
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
