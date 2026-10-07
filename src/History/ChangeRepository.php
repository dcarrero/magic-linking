<?php
/**
 * Acceso a magiclinking_changes.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\History;

use MagicLinking\Core\Schema;
use wpdb;

/**
 * Historial de cambios en el contenido (docs/03 §4.6). Aquí solo está lo que exige la regla 5 de
 * CLAUDE.md: guardar el cambio antes de escribir, leerlo por id o por lote y marcarlo como deshecho. La
 * conservación, la purga y la pantalla son de F1-10.
 */
final class ChangeRepository {

	public const INSERT = 'insert';
	public const REMOVE = 'remove';

	/**
	 * Conexión.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Tabla.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = Schema::table( $wpdb->prefix, 'changes' );
	}

	/**
	 * Guarda un cambio.
	 *
	 * @param string      $batch_id           Lote.
	 * @param int         $post_id            Entrada.
	 * @param string      $action             `insert` o `remove`.
	 * @param string|null $block_path         Ruta del bloque (`3.0.1`) o `@` y el byte en el editor clásico.
	 * @param string      $before_html        HTML del tramo antes.
	 * @param string      $after_html         HTML del tramo después.
	 * @param string      $content_hash_after SHA-1 del contenido completo después del cambio.
	 * @param int         $user_id            Quien lo hace (0 = sistema).
	 *
	 * @return int ID de la fila; 0 si no se ha podido guardar (en ese caso no se escribe el contenido).
	 */
	public function record( string $batch_id, int $post_id, string $action, ?string $block_path, string $before_html, string $after_html, string $content_hash_after, int $user_id ): int {
		$wpdb = $this->wpdb;

		$done = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Tabla propia.
			$this->table,
			array(
				'batch_id'           => $batch_id,
				'post_id'            => $post_id,
				'action'             => $action,
				'block_path'         => $block_path,
				'before_html'        => $before_html,
				'after_html'         => $after_html,
				'content_hash_after' => $content_hash_after,
				'user_id'            => $user_id,
				'created_at'         => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return false === $done ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Un cambio por su id.
	 *
	 * @param int $id ID.
	 *
	 * @return array{id: int, batch_id: string, post_id: int, action: string, block_path: string|null, before_html: string, after_html: string, content_hash_after: string, user_id: int, created_at: string, undone_at: string|null}|null
	 */
	public function get( int $id ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla propia.

		return is_array( $row ) ? $this->cast( $row ) : null;
	}

	/**
	 * Cambios de un lote, del primero al último.
	 *
	 * @param string $batch_id Lote.
	 *
	 * @return list<array{id: int, batch_id: string, post_id: int, action: string, block_path: string|null, before_html: string, after_html: string, content_hash_after: string, user_id: int, created_at: string, undone_at: string|null}>
	 */
	public function batch( string $batch_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE batch_id = %s ORDER BY id", $batch_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla propia.

		return array_map( array( $this, 'cast' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Marca un cambio como deshecho.
	 *
	 * @param int $id ID.
	 */
	public function mark_undone( int $id ): void {
		$this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Tabla propia.
			$this->table,
			array( 'undone_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Borra una fila (el cambio no llegó a escribirse).
	 *
	 * @param int $id ID.
	 */
	public function delete( int $id ): void {
		$this->wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Tabla propia.
	}

	/**
	 * Tipos de la fila.
	 *
	 * @param array $row Fila.
	 *
	 * @phpstan-param array<string, mixed> $row
	 *
	 * @return array{id: int, batch_id: string, post_id: int, action: string, block_path: string|null, before_html: string, after_html: string, content_hash_after: string, user_id: int, created_at: string, undone_at: string|null}
	 */
	private function cast( array $row ): array {
		return array(
			'id'                 => (int) $row['id'],
			'batch_id'           => (string) $row['batch_id'],
			'post_id'            => (int) $row['post_id'],
			'action'             => (string) $row['action'],
			'block_path'         => null === $row['block_path'] ? null : (string) $row['block_path'],
			'before_html'        => (string) $row['before_html'],
			'after_html'         => (string) $row['after_html'],
			'content_hash_after' => (string) $row['content_hash_after'],
			'user_id'            => (int) $row['user_id'],
			'created_at'         => (string) $row['created_at'],
			'undone_at'          => null === $row['undone_at'] ? null : (string) $row['undone_at'],
		);
	}
}
