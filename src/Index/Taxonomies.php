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
	 * Taxonomía principal ya encontrada por (tipo, taxonomía lógica), por petición.
	 *
	 * @var array<string, string>
	 */
	private static array $memo = array();

	/**
	 * Categorías que se listan como máximo por taxonomía en Ajustes (más las ya excluidas). Pasado el tope no hay
	 * lista manejable: la pantalla ofrece un filtro por nombre y solo pinta unas cuantas a la vez.
	 */
	public const LISTED_MAX = 5000;

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
		$key = $post_type . "\0" . $logical;
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		// Un null no se recuerda: la taxonomía de un tipo puede registrarse más tarde.
		$found = self::find_primary( $post_type, $logical );
		if ( null !== $found ) {
			self::$memo[ $key ] = $found;
		}

		return $found;
	}

	/**
	 * Engancha el olvido del memo cuando se registra o se quita una taxonomía.
	 */
	public static function register(): void {
		add_action( 'registered_taxonomy', array( self::class, 'forget' ) );
		add_action( 'unregistered_taxonomy', array( self::class, 'forget' ) );
	}

	/**
	 * Olvida las taxonomías principales recordadas.
	 */
	public static function forget(): void {
		self::$memo = array();
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
			$terms    = null === $taxonomy ? false : self::terms_of( $post_id, $taxonomy );
			$ids      = array();
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$ids[] = (string) $term->term_taxonomy_id;
			}
			$out[ $logical ] = $ids;
		}

		return $out;
	}

	/**
	 * Los términos de una entrada. Primero la caché de términos (la que llena `update_object_term_cache()`), con el mismo
	 * filtro `get_the_terms` que aplicaría el núcleo: `get_the_terms()` carga la entrada entera (con su contenido) para
	 * validarla, y con 200 candidatas pesa. Si los términos no están en la caché, `get_the_terms()` los pide como siempre.
	 *
	 * @param int    $post_id  ID.
	 * @param string $taxonomy Taxonomía.
	 *
	 * @return array<int, \WP_Term>|false|\WP_Error
	 */
	private static function terms_of( int $post_id, string $taxonomy ) {
		$terms = get_object_term_cache( $post_id, $taxonomy );
		if ( false === $terms ) {
			return get_the_terms( $post_id, $taxonomy );
		}

		return apply_filters( 'get_the_terms', $terms, $post_id, $taxonomy ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro del núcleo.
	}

	/**
	 * Taxonomías de categoría (la jerárquica principal) de los tipos indicados, sin repetir.
	 *
	 * @param array $post_types Tipos de contenido.
	 *
	 * @phpstan-param list<string> $post_types
	 *
	 * @return list<string> Nombres de taxonomía.
	 */
	public static function category_taxonomies( array $post_types ): array {
		$names = array();
		foreach ( $post_types as $type ) {
			$name = self::primary( $type, 'category' );
			if ( null !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * De los `term_taxonomy_id` recibidos, los que son de la categoría principal de algún tipo analizado (una consulta).
	 *
	 * @param array $tt_ids     `term_taxonomy_id`.
	 * @param array $post_types Tipos analizados.
	 *
	 * @phpstan-param list<int>    $tt_ids
	 * @phpstan-param list<string> $post_types
	 *
	 * @return list<int> Los válidos, en el mismo orden.
	 */
	public static function filter_category_terms( array $tt_ids, array $post_types ): array {
		global $wpdb;

		$names = self::category_taxonomies( $post_types );
		if ( array() === $tt_ids || array() === $names ) {
			return array();
		}

		$ids   = implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) );
		$in    = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$valid = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Marcadores generados.
			$wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$ids}) AND taxonomy IN ({$in})", array_merge( $tt_ids, $names ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
		);
		$valid = array_map( 'intval', $valid );

		return array_values( array_filter( $tt_ids, static fn( int $id ): bool => in_array( $id, $valid, true ) ) );
	}

	/**
	 * Categorías entre las que elegir las que se excluyen del análisis (Ajustes), por taxonomía y en orden de jerarquía.
	 *
	 * @param array $post_types Tipos analizados.
	 * @param int   $max        Máximo de categorías por taxonomía, además de las ya excluidas.
	 *
	 * @phpstan-param list<string> $post_types
	 *
	 * @return list<array{taxonomy: string, label: string, terms: list<array{id: int, name: string, depth: int}>}>
	 */
	public static function category_groups( array $post_types, int $max = self::LISTED_MAX ): array {
		$groups = array();
		foreach ( self::category_taxonomies( $post_types ) as $name ) {
			$taxonomy = get_taxonomy( $name );
			$terms    = get_terms(
				array(
					'taxonomy'   => $name,
					'hide_empty' => false,
					'orderby'    => 'name',
					'number'     => $max,
				)
			);
			if ( ! $taxonomy || ! is_array( $terms ) ) {
				continue;
			}

			// Las ya excluidas se listan siempre, aunque el sitio pase del tope: si no, quedarían sin control.
			$listed  = array_map( static fn( $term ): int => (int) $term->term_taxonomy_id, $terms );
			$missing = array_values( array_diff( Exclusions::excluded_terms(), $listed ) );
			if ( array() !== $missing ) {
				$extra = get_terms(
					array(
						'taxonomy'         => $name,
						'hide_empty'       => false,
						'term_taxonomy_id' => $missing,
					)
				);
				$terms = array_merge( $terms, is_array( $extra ) ? $extra : array() );
			}

			$by_parent = array();
			$known     = array();
			foreach ( $terms as $term ) {
				$known[ $term->term_id ] = true;
			}
			foreach ( $terms as $term ) {
				$by_parent[ isset( $known[ $term->parent ] ) ? $term->parent : 0 ][] = $term;
			}

			$flat  = array();
			$visit = static function ( int $parent_id, int $depth ) use ( &$visit, &$flat, $by_parent ): void {
				foreach ( $by_parent[ $parent_id ] ?? array() as $term ) {
					$flat[] = array(
						'id'    => (int) $term->term_taxonomy_id,
						'name'  => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
						'depth' => $depth,
					);
					$visit( $term->term_id, $depth + 1 );
				}
			};
			$visit( 0, 0 );

			$groups[] = array(
				'taxonomy' => $name,
				'label'    => (string) ( $taxonomy->labels->name ?? $name ),
				'terms'    => $flat,
			);
		}//end foreach

		return $groups;
	}
}
