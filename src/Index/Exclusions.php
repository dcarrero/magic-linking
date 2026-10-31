<?php
/**
 * Entradas excluidas del análisis.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

/**
 * Una entrada excluida deja de analizarse: no está en el índice, así que no es origen ni destino de ninguna
 * sugerencia, y el informe solo la muestra en su filtro (docs/07 §4). Es la marca de la propia entrada, como la
 * del contenido pilar: un metadato oculto con valor `1` (se borra al volver a incluirla, no se guarda un 0), que
 * no es una opción y por tanto no se carga automáticamente (regla 8), y que sobrevive a reindexar.
 *
 * Quien cambia la marca no reindexa: lo hace {@see \MagicLinking\Jobs\Jobs} al ver el cambio de metadato, de modo
 * que vale igual desde la API REST, WP-CLI o `update_post_meta()`.
 */
final class Exclusions {

	/**
	 * Metadato de la entrada (valor `1`).
	 */
	public const KEY = '_magiclinking_excluded';

	/**
	 * Taxonomía de cada conjunto de categorías excluidas (clave: sus ids unidos por comas), por petición.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $taxonomies = array();

	/**
	 * Si la entrada está excluida del análisis.
	 *
	 * @param int $post_id ID.
	 */
	public static function is_excluded( int $post_id ): bool {
		// Excluida = existe una fila con valor `1` (vale cualquiera si hay varias).
		return in_array( '1', array_map( 'strval', get_post_meta( $post_id, self::KEY, false ) ), true );
	}

	/**
	 * Si la entrada queda fuera del análisis, por su marca o por estar en una categoría excluida en Ajustes (F1-16).
	 * Es la pregunta de la elegibilidad; {@see self::is_excluded()} es solo la marca de la propia entrada.
	 *
	 * @param int $post_id ID.
	 */
	public static function is_out( int $post_id ): bool {
		return self::is_excluded( $post_id ) || self::in_excluded_category( $post_id );
	}

	/**
	 * `term_taxonomy_id` de las categorías excluidas en Ajustes, leídos una vez por petición con los demás datos que
	 * piden los hooks de términos ({@see SuggestionCache::site_settings()}): sin consultas.
	 *
	 * @return list<int>
	 */
	public static function excluded_terms(): array {
		return SuggestionCache::site_settings()['excluded_categories'];
	}

	/**
	 * Si la entrada tiene alguna de las categorías excluidas. Los términos salen de la caché de objetos de WordPress
	 * (que {@see _prime_post_caches()} rellena para un lote), así que no hay consulta por entrada.
	 *
	 * @param int $post_id ID.
	 */
	public static function in_excluded_category( int $post_id ): bool {
		$excluded = self::excluded_terms();
		if ( array() === $excluded ) {
			return false;
		}

		$type = (string) get_post_type( $post_id );
		if ( '' === $type ) {
			return false;
		}

		// Solo las taxonomías de las categorías excluidas, que casi siempre es una (`category`).
		foreach ( array_intersect( self::taxonomies_of( $excluded ), get_object_taxonomies( $type ) ) as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( in_array( (int) $term->term_taxonomy_id, $excluded, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Si alguno de los `term_taxonomy_id` dados es una categoría excluida (p. ej. las que el editor tiene elegidas
	 * ahora, sin guardar).
	 *
	 * @param array $tt_ids `term_taxonomy_id`.
	 *
	 * @phpstan-param list<int|string> $tt_ids
	 */
	public static function any_excluded( array $tt_ids ): bool {
		return array() !== array_intersect( array_map( 'intval', $tt_ids ), self::excluded_terms() );
	}

	/**
	 * Si hay categorías excluidas: quien carga entradas en lote tiene que cargar también sus términos
	 * (`_prime_post_caches( $ids, true, … )`) para que la elegibilidad no haga una consulta por entrada.
	 */
	public static function needs_terms(): bool {
		return array() !== self::excluded_terms();
	}

	/**
	 * Taxonomías de las categorías excluidas: se buscan una vez por conjunto y petición, no por entrada.
	 *
	 * @param array $tt_ids `term_taxonomy_id`.
	 *
	 * @phpstan-param list<int> $tt_ids
	 *
	 * @return list<string>
	 */
	private static function taxonomies_of( array $tt_ids ): array {
		$key = implode( ',', $tt_ids );
		if ( isset( self::$taxonomies[ $key ] ) ) {
			return self::$taxonomies[ $key ];
		}

		$names = array();
		foreach ( $tt_ids as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', $tt_id );
			if ( $term instanceof \WP_Term ) {
				$names[ $term->taxonomy ] = true;
			}
		}
		self::$taxonomies[ $key ] = array_map( 'strval', array_keys( $names ) );

		return self::$taxonomies[ $key ];
	}

	/**
	 * Olvida las taxonomías recordadas (cambian los ajustes o los términos).
	 */
	public static function forget(): void {
		self::$taxonomies = array();
	}

	/**
	 * Excluye la entrada o vuelve a incluirla.
	 *
	 * @param int  $post_id  ID.
	 * @param bool $excluded Excluida.
	 */
	public static function set( int $post_id, bool $excluded ): void {
		if ( $excluded ) {
			update_post_meta( $post_id, self::KEY, '1' );
		} else {
			delete_post_meta( $post_id, self::KEY );
		}
	}

	/**
	 * Fragmento SQL para dejar fuera las entradas excluidas (por su marca o por una categoría excluida) de una
	 * consulta sobre `wp_posts` (condición que empieza por « AND »), preparado y sin una consulta por fila.
	 *
	 * @param string $posts_column Columna con el ID de la entrada (p. ej. `ID`).
	 */
	public static function not_excluded_sql( string $posts_column = 'ID' ): string {
		global $wpdb;

		$sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- La columna llega de una constante de quien llama.
			" AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ex WHERE ex.post_id = {$posts_column} AND ex.meta_key = %s AND ex.meta_value = %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::KEY,
			'1'
		);

		$terms = self::excluded_terms();
		if ( array() !== $terms ) {
			// Son enteros ya saneados (`excluded_terms()`).
			$sql .= " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} ec WHERE ec.object_id = {$posts_column} AND ec.term_taxonomy_id IN (" . implode( ',', $terms ) . ') )'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $sql;
	}
}
