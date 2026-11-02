<?php
/**
 * Qué entradas puede ver en el informe el usuario actual (en SQL, para que total, páginas y CSV cuadren).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

use wpdb;

/**
 * Fragmento de WHERE sobre `wp_posts` (con el alias que se indique) que deja pasar solo lo que el usuario puede ver.
 *
 * Por tipo, con las capacidades del propio tipo (`get_post_type_object()->cap`): si puede `edit_others_posts`, ve
 * todo; si no, ve lo publicado, lo suyo y, si puede `read_private_posts`, lo privado.
 */
final class Visibility {

	/**
	 * Condición SQL (entre paréntesis) de las entradas visibles para el usuario actual.
	 *
	 * @param wpdb               $wpdb  Base de datos.
	 * @param string             $alias Alias de la tabla de entradas.
	 * @param array<int, string> $types Tipos que se tienen en cuenta; los demás solo ven lo publicado y lo propio.
	 */
	public static function post_sql( wpdb $wpdb, string $alias, array $types ): string {
		$conditions = array(
			"{$alias}.post_status = 'publish'",
			$wpdb->prepare( "{$alias}.post_author = %d", get_current_user_id() ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Alias fijo de quien llama.
		);

		$all     = array();
		$private = array();
		foreach ( array_values( array_unique( $types ) ) as $type ) {
			$object = get_post_type_object( $type );
			if ( null === $object ) {
				continue;
			}
			if ( current_user_can( $object->cap->edit_others_posts ) ) {
				$all[] = $type;
			} elseif ( current_user_can( $object->cap->read_private_posts ) ) {
				$private[] = $type;
			}
		}

		if ( array() !== $all ) {
			$conditions[] = $wpdb->prepare( "{$alias}.post_type IN (" . implode( ',', array_fill( 0, count( $all ), '%s' ) ) . ')', $all ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Marcadores generados.
		}
		if ( array() !== $private ) {
			$conditions[] = $wpdb->prepare( "({$alias}.post_status = 'private' AND {$alias}.post_type IN (" . implode( ',', array_fill( 0, count( $private ), '%s' ) ) . '))', $private ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Marcadores generados.
		}

		return '( ' . implode( ' OR ', $conditions ) . ' )';
	}
}
