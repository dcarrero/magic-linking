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
 * Historial de cambios en el contenido (docs/03 §4.6): guardar el cambio antes de escribir (regla 5 de
 * CLAUDE.md), leerlo por id o por lote, marcarlo como deshecho, listar los grupos para la pantalla
 * *Historial* y purgar los antiguos según la conservación elegida (F1-10).
 *
 * Los listados no traen el HTML de los cambios (un lote puede tener miles): solo columnas ligeras y una
 * huella MD5 del tramo («hueco»), que sirve para saber que dos filas son el mismo enlace puesto, quitado y
 * vuelto a poner.
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
	 * Inserciones ya leídas de un solo lote en esta petición (la huella MD5 recorre el HTML de cada fila, y una
	 * petición pregunta varias veces por el mismo lote). Cualquier escritura la vacía.
	 *
	 * @var array<string, array<string, list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string}>>>
	 */
	private array $memo = array();

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
		$wpdb       = $this->wpdb;
		$this->memo = array();

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
		$this->memo = array();
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
		$this->memo = array();
		$this->wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Tabla propia.
	}

	/**
	 * Cambios por sus ids, con su HTML, indexados por id.
	 *
	 * @param array $ids IDs.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @return array<int, array{id: int, batch_id: string, post_id: int, action: string, block_path: string|null, before_html: string, after_html: string, content_hash_after: string, user_id: int, created_at: string, undone_at: string|null}>
	 */
	public function get_many( array $ids ): array {
		$wpdb = $this->wpdb;
		$ids  = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Lista de marcadores generada con array_fill().
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE id IN ({$placeholders})", $this->table, ...$ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$by_id = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$change                 = $this->cast( $row );
			$by_id[ $change['id'] ] = $change;
		}

		return $by_id;
	}

	/**
	 * Lotes con alguna inserción, del más reciente al más antiguo (los ULID se ordenan por fecha).
	 *
	 * @param string|null $before Solo los anteriores a este lote (cursor de paginación).
	 * @param int         $limit  Máximo de lotes.
	 *
	 * @return list<string>
	 */
	public function batch_ids( ?string $before, int $limit ): array {
		$wpdb  = $this->wpdb;
		$limit = max( 1, $limit );

		if ( null !== $before && '' !== $before ) {
			$query = $wpdb->prepare( 'SELECT batch_id FROM %i WHERE action = %s AND batch_id < %s GROUP BY batch_id ORDER BY batch_id DESC LIMIT %d', $this->table, self::INSERT, $before, $limit );
		} else {
			$query = $wpdb->prepare( 'SELECT batch_id FROM %i WHERE action = %s GROUP BY batch_id ORDER BY batch_id DESC LIMIT %d', $this->table, self::INSERT, $limit );
		}

		$ids = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia; consulta ya preparada.

		return array_map( 'strval', $ids );
	}

	/**
	 * Inserciones de varios lotes en columnas ligeras (sin HTML), en el orden en que se hicieron.
	 *
	 * @param array $batch_ids Lotes.
	 *
	 * @phpstan-param list<string> $batch_ids
	 *
	 * @return array<string, list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string}>> Por lote.
	 */
	public function inserts_of( array $batch_ids ): array {
		$wpdb = $this->wpdb;
		if ( array() === $batch_ids ) {
			return array();
		}
		if ( 1 === count( $batch_ids ) && isset( $this->memo[ $batch_ids[0] ] ) ) {
			return $this->memo[ $batch_ids[0] ];
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Lista de marcadores generada con array_fill().
		$placeholders = implode( ',', array_fill( 0, count( $batch_ids ), '%s' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
			$wpdb->prepare(
				"SELECT id, batch_id, post_id, user_id, created_at, undone_at, MD5( CONCAT_WS( '|', post_id, COALESCE( block_path, '' ), before_html, after_html ) ) AS slot FROM %i WHERE action = %s AND batch_id IN ({$placeholders}) ORDER BY id",
				$this->table,
				self::INSERT,
				...$batch_ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$by_batch = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$by_batch[ (string) $row['batch_id'] ][] = array(
				'id'         => (int) $row['id'],
				'post_id'    => (int) $row['post_id'],
				'user_id'    => (int) $row['user_id'],
				'created_at' => (string) $row['created_at'],
				'undone_at'  => null === $row['undone_at'] ? null : (string) $row['undone_at'],
				'slot'       => (string) $row['slot'],
			);
		}

		if ( 1 === count( $batch_ids ) ) {
			$this->memo[ $batch_ids[0] ] = $by_batch;
		}

		return $by_batch;
	}

	/**
	 * Si el mismo enlace (mismo lote, entrada y tramo antes y después) se volvió a poner después de este cambio.
	 *
	 * @param array $change Fila `insert` del historial.
	 *
	 * @phpstan-param array{id: int, batch_id: string, post_id: int, block_path: string|null, before_html: string, after_html: string} $change
	 */
	public function put_back_after( array $change ): bool {
		$wpdb = $this->wpdb;
		$path = $change['block_path'] ?? '';

		return null !== $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
			$wpdb->prepare(
				'SELECT id FROM %i WHERE post_id = %d AND batch_id = %s AND id > %d AND action = %s AND COALESCE( block_path, %s ) = %s AND before_html = %s AND after_html = %s LIMIT 1',
				$this->table,
				$change['post_id'],
				$change['batch_id'],
				$change['id'],
				self::INSERT,
				'',
				$path,
				$change['before_html'],
				$change['after_html']
			)
		);
	}

	/**
	 * Cuántas filas del historial hay en total.
	 */
	public function count(): int {
		$wpdb = $this->wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
	}

	/**
	 * Cuántas filas caen en grupos caducados (ver {@see self::purge()}).
	 *
	 * @param string $cutoff Fecha límite, `Y-m-d H:i:s` en UTC.
	 */
	public function count_expired( string $cutoff ): int {
		$wpdb = $this->wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE batch_id IN ( SELECT batch_id FROM ( SELECT batch_id FROM %i GROUP BY batch_id HAVING GREATEST( MAX( created_at ), COALESCE( MAX( undone_at ), MAX( created_at ) ) ) < %s ) AS expired )',
				$this->table,
				$this->table,
				$cutoff
			)
		);
	}

	/**
	 * Borra grupos enteros cuya última actividad (insertar, o deshacer aunque no escriba nada) es anterior a la fecha límite.
	 * Un grupo con actividad reciente se conserva completo. Trabaja por lotes: llámala hasta que
	 * devuelva 0.
	 *
	 * @param string $cutoff Fecha límite, `Y-m-d H:i:s` en UTC.
	 * @param int    $groups Máximo de grupos por llamada.
	 *
	 * @return int Filas borradas.
	 */
	public function purge( string $cutoff, int $groups = 100 ): int {
		$wpdb       = $this->wpdb;
		$this->memo = array();

		// Grupos con alguna fila antigua (índice created_at) y ninguna reciente.
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
			$wpdb->prepare(
				'SELECT DISTINCT c.batch_id FROM %i c WHERE c.created_at < %s AND NOT EXISTS ( SELECT 1 FROM %i n WHERE n.batch_id = c.batch_id AND ( n.created_at >= %s OR n.undone_at >= %s ) ) LIMIT %d',
				$this->table,
				$cutoff,
				$this->table,
				$cutoff,
				$cutoff,
				max( 1, $groups )
			)
		);
		if ( array() === $ids ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Lista de marcadores generada con array_fill().
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$deleted      = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
			$wpdb->prepare( "DELETE FROM %i WHERE batch_id IN ({$placeholders})", $this->table, ...array_map( 'strval', $ids ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return is_int( $deleted ) ? $deleted : 0;
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
