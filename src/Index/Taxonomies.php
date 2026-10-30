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
		// Memo por petición: se llama dos veces por entrada y por metadato.
		static $memo = array();
		$key         = $post_type . "\0" . $logical;
		if ( array_key_exists( $key, $memo ) ) {
			return $memo[ $key ];
		}

		$memo[ $key ] = self::find_primary( $post_type, $logical );

		return $memo[ $key ];
	}

	/**
	 * Busca la taxonomía principal (sin memo).
	 *
	 * @param string $post_type Tipo de contenido.
	 * @param string $logical   `category` o `post_tag`.
	 */
	private static function find_primary( string $post_type, string $logical ): ?string {
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
	 * Los IDs (Gutenberg y la caja de categorías) y los nombres (el cuadro de etiquetas del editor clásico) viajan
	 * por separado y nunca se adivina cuál es cuál: una etiqueta llamada «2026» es un nombre, no el término 2026.
	 * Solo cuentan los términos que existen en la taxonomía principal del tipo; lo demás se descarta. Una taxonomía
	 * lógica que no viene se deja fuera, para que mande lo guardado.
	 *
	 * @param string $post_type Tipo de contenido.
	 * @param array  $ids       Taxonomía lógica → IDs de término.
	 * @param array  $names     Taxonomía lógica → nombres de término.
	 *
	 * @phpstan-param array<string, mixed> $ids
	 * @phpstan-param array<string, mixed> $names
	 *
	 * @return array<string, list<string>>
	 */
	public static function resolve( string $post_type, array $ids, array $names = array() ): array {
		$out = array();
		foreach ( array( 'category', 'post_tag' ) as $logical ) {
			$given_ids   = is_array( $ids[ $logical ] ?? null ) ? $ids[ $logical ] : null;
			$given_names = is_array( $names[ $logical ] ?? null ) ? $names[ $logical ] : null;
			if ( null === $given_ids && null === $given_names ) {
				continue;
			}
			$taxonomy = self::primary( $post_type, $logical );
			$found    = array();
			foreach ( array_slice( $given_ids ?? array(), 0, 100 ) as $value ) {
				$term = null !== $taxonomy && is_int( $value ) ? get_term( $value, $taxonomy ) : null;
				if ( $term instanceof \WP_Term ) {
					$found[ (string) $term->term_taxonomy_id ] = true;
				}
			}
			foreach ( array_slice( $given_names ?? array(), 0, 100 ) as $value ) {
				$term = null !== $taxonomy && is_string( $value ) ? get_term_by( 'name', wp_strip_all_tags( $value ), $taxonomy ) : null;
				if ( $term instanceof \WP_Term ) {
					$found[ (string) $term->term_taxonomy_id ] = true;
				}
			}
			$out[ $logical ] = array_map( 'strval', array_keys( $found ) );
		}//end foreach

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
