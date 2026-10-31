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
	 * Si la entrada está excluida del análisis.
	 *
	 * @param int $post_id ID.
	 */
	public static function is_excluded( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, self::KEY, true );
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
	 * Fragmento SQL para dejar fuera las entradas excluidas de una consulta sobre `wp_posts` (condición que empieza
	 * por « AND »), preparado y sin una consulta por fila.
	 *
	 * @param string $posts_column Columna con el ID de la entrada (p. ej. `ID`).
	 */
	public static function not_excluded_sql( string $posts_column = 'ID' ): string {
		global $wpdb;

		return $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- La columna llega de una constante de quien llama.
			" AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ex WHERE ex.post_id = {$posts_column} AND ex.meta_key = %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::KEY
		);
	}
}
