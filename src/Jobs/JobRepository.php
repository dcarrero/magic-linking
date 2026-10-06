<?php
/**
 * Acceso a magiclinking_jobs.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Jobs;

use MagicLinking\Core\Schema;
use wpdb;

/**
 * Estado de los procesos largos.
 */
final class JobRepository {

	public const QUEUED    = 'queued';
	public const RUNNING   = 'running';
	public const PAUSED    = 'paused';
	public const DONE      = 'done';
	public const FAILED    = 'failed';
	public const CANCELLED = 'cancelled';

	/**
	 * Patrón LIKE de los procesos del sistema (`params.system` a true, ver Jobs::start_index()).
	 */
	public const SYSTEM_LIKE = '%"system":true%';

	/**
	 * Estados de un proceso sin terminar.
	 */
	public const ACTIVE = array( self::QUEUED, self::RUNNING, self::PAUSED );

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
		$this->table = Schema::table( $wpdb->prefix, 'jobs' );
	}

	/**
	 * Crea un proceso en cola.
	 *
	 * @param string               $type    Tipo (`index`).
	 * @param int                  $total   Unidades de trabajo previstas.
	 * @param array<string, mixed> $params  Parámetros.
	 * @param int                  $user_id Quien lo lanza (0 = sistema o WP-CLI).
	 *
	 * @return int ID.
	 */
	public function create( string $type, int $total, array $params, int $user_id ): int {
		$wpdb = $this->wpdb;
		$now  = current_time( 'mysql', true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Tabla propia.
			$this->table,
			array(
				'type'       => $type,
				'status'     => self::QUEUED,
				'total'      => $total,
				'done'       => 0,
				'params'     => (string) wp_json_encode( $params ),
				'created_by' => $user_id,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Un proceso por su ID.
	 *
	 * @param int $id ID.
	 *
	 * @return array{id: int, type: string, status: string, total: int, done: int, params: array<string, mixed>, error: string, created_by: int, created_at: string, updated_at: string}|null
	 */
	public function get( int $id ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Último proceso sin terminar de un tipo.
	 *
	 * @param string $type Tipo.
	 *
	 * @return array{id: int, type: string, status: string, total: int, done: int, params: array<string, mixed>, error: string, created_by: int, created_at: string, updated_at: string}|null
	 */
	public function active( string $type ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
			$wpdb->prepare(
				'SELECT * FROM %i WHERE type = %s AND status IN (%s, %s, %s) ORDER BY id DESC LIMIT 1',
				$this->table,
				$type,
				self::ACTIVE[0],
				self::ACTIVE[1],
				self::ACTIVE[2]
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Último proceso de un tipo, esté como esté.
	 *
	 * @param string $type      Tipo.
	 * @param bool   $user_only Sin contar los procesos del sistema (`params.system`, el recálculo nocturno).
	 *
	 * @return array{id: int, type: string, status: string, total: int, done: int, params: array<string, mixed>, error: string, created_by: int, created_at: string, updated_at: string}|null
	 */
	public function latest( string $type, bool $user_only = false ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
			$wpdb->prepare( 'SELECT * FROM %i WHERE type = %s AND params NOT LIKE %s ORDER BY id DESC LIMIT 1', $this->table, $type, $user_only ? self::SYSTEM_LIKE : '' ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Último proceso de un tipo que terminó bien.
	 *
	 * @param string $type      Tipo.
	 * @param bool   $user_only Sin contar los procesos del sistema (`params.system`, el recálculo nocturno).
	 *
	 * @return array{id: int, type: string, status: string, total: int, done: int, params: array<string, mixed>, error: string, created_by: int, created_at: string, updated_at: string}|null
	 */
	public function latest_done( string $type, bool $user_only = false ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
			$wpdb->prepare( 'SELECT * FROM %i WHERE type = %s AND status = %s AND params NOT LIKE %s ORDER BY id DESC LIMIT 1', $this->table, $type, self::DONE, $user_only ? self::SYSTEM_LIKE : '' ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Cambia el estado.
	 *
	 * @param int         $id     ID.
	 * @param string      $status Nuevo estado.
	 * @param string|null $error  Mensaje de error, si lo hay.
	 */
	public function set_status( int $id, string $status, ?string $error = null ): void {
		$wpdb    = $this->wpdb;
		$data    = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s' );

		if ( null !== $error ) {
			$data['error'] = $error;
			$formats[]     = '%s';
		}

		$wpdb->update( $this->table, $data, array( 'id' => $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
	}

	/**
	 * Guarda el avance de un proceso.
	 *
	 * @param int                  $id     ID.
	 * @param int                  $done   Unidades hechas.
	 * @param int                  $total  Unidades previstas.
	 * @param array<string, mixed> $params Parámetros actualizados (cursor, tamaño de lote).
	 */
	public function set_progress( int $id, int $done, int $total, array $params ): void {
		$wpdb = $this->wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
			$this->table,
			array(
				'done'       => $done,
				'total'      => $total,
				'params'     => (string) wp_json_encode( $params ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Pasa las columnas a los tipos de PHP.
	 *
	 * @param array<string, mixed> $row Fila.
	 *
	 * @return array{id: int, type: string, status: string, total: int, done: int, params: array<string, mixed>, error: string, created_by: int, created_at: string, updated_at: string}
	 */
	private function normalize( array $row ): array {
		$params = json_decode( (string) ( $row['params'] ?? '' ), true );

		return array(
			'id'         => (int) $row['id'],
			'type'       => (string) $row['type'],
			'status'     => (string) $row['status'],
			'total'      => (int) $row['total'],
			'done'       => (int) $row['done'],
			'params'     => is_array( $params ) ? $params : array(),
			'error'      => (string) ( $row['error'] ?? '' ),
			'created_by' => (int) $row['created_by'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		);
	}
}
