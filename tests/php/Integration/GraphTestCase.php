<?php
/**
 * Base de las pruebas de integración que necesitan las tablas del plugin.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Schema;
use WP_UnitTestCase;

/**
 * Crea las tablas como temporales (así la suite las deshace) y las vacía antes de cada prueba.
 */
abstract class GraphTestCase extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		flush_rewrite_rules();

		foreach ( Schema::create_statements( $wpdb->prefix, $wpdb->get_charset_collate() ) as $statement ) {
			$wpdb->query( (string) preg_replace( '/^CREATE TABLE/', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $statement ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
		foreach ( Schema::TABLES as $table ) {
			$name = Schema::table( $wpdb->prefix, $table );
			$wpdb->query( "DELETE FROM {$name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// El resolutor guarda en memoria lo resuelto; las entradas de una prueba no son las de la anterior.
		\MagicLinking\Core\Plugin::container()->get( \MagicLinking\Graph\GraphIndexer::class )->flush();

		delete_option( 'magiclinking_settings' );
		update_option( 'magiclinking_db_version', MAGICLINKING_DB_VERSION, false );
	}

	/**
	 * Fila de magiclinking_docs.
	 *
	 * @param int $post_id ID.
	 *
	 * @return array<string, string>|null
	 */
	protected function doc_row( int $post_id ): ?array {
		global $wpdb;
		$name = Schema::table( $wpdb->prefix, 'docs' );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$name} WHERE post_id = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Filas de magiclinking_links de un origen.
	 *
	 * @param int $source_id ID.
	 *
	 * @return list<array<string, string>>
	 */
	protected function link_rows( int $source_id ): array {
		global $wpdb;
		$name = Schema::table( $wpdb->prefix, 'links' );

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$name} WHERE source_id = %d ORDER BY id", $source_id ), ARRAY_A ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
