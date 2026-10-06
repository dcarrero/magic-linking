<?php
/**
 * Lista de enlaces internos rotos.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

use Generator;
use MagicLinking\Core\Schema;
use wpdb;

/**
 * Lee magiclinking_links con is_broken > 0, unida a la entrada de origen.
 */
final class BrokenRepository {

	/**
	 * Conexión.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Todos los enlaces rotos con los filtros dados, de 500 en 500.
	 *
	 * @param array<string, mixed> $args Filtros (sin página).
	 *
	 * @return Generator<int, array<string, mixed>>
	 */
	public function each( array $args ): Generator {
		$page  = 1;
		$count = 0;
		do {
			$result = $this->query(
				array_merge(
					$args,
					array(
						'page'     => $page,
						'per_page' => 500,
					)
				)
			); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

			yield from $result['items'];

			$count = count( $result['items'] );
			++$page;
		} while ( 500 === $count );
	}

	/**
	 * Una página de enlaces rotos.
	 *
	 * @param array{post_id?: int, search?: string, page?: int, per_page?: int} $args Filtros y página.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function query( array $args ): array {
		$wpdb     = $this->wpdb;
		$links    = Schema::table( $wpdb->prefix, 'links' );
		$where    = '';
		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );

		if ( ! empty( $args['post_id'] ) ) {
			$where .= $wpdb->prepare( ' AND l.source_id = %d', (int) $args['post_id'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Preparado.
		}
		if ( ! empty( $args['search'] ) ) {
			$like   = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where .= $wpdb->prepare( ' AND (l.target_url LIKE %s OR l.anchor LIKE %s OR p.post_title LIKE %s)', $like, $like, $like ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Preparado.
		}

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Consulta propia; WHERE preparado.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i l INNER JOIN %i p ON p.ID = l.source_id WHERE l.is_broken > 0' . $where, $links, $wpdb->posts ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fragmentos preparados.
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Consulta propia; WHERE preparado.
			$wpdb->prepare( 'SELECT l.id, l.source_id, l.target_id, l.target_url, l.anchor, l.is_broken, p.post_title FROM %i l INNER JOIN %i p ON p.ID = l.source_id WHERE l.is_broken > 0' . $where . ' ORDER BY p.post_title ASC, l.id ASC LIMIT %d OFFSET %d', $links, $wpdb->posts, $per_page, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fragmentos preparados.
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$source = (int) $row['source_id'];
			$title  = html_entity_decode( wp_strip_all_tags( (string) $row['post_title'] ), ENT_QUOTES, 'UTF-8' );
			$code   = (int) $row['is_broken'];

			$items[] = array(
				'id'           => (int) $row['id'],
				'source_id'    => $source,
				'source_title' => '' !== trim( $title ) ? $title : __( '(no title)', 'magic-linking' ),
				'target_id'    => null === $row['target_id'] ? null : (int) $row['target_id'],
				'url'          => (string) $row['target_url'],
				'anchor'       => (string) $row['anchor'],
				'reason_code'  => $code,
				'reason'       => BrokenReason::label( $code ),
				'edit_url'     => current_user_can( 'edit_post', $source ) ? (string) get_edit_post_link( $source, 'raw' ) : '',
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
