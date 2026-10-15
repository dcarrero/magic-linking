<?php
/**
 * Acceso a magiclinking_jobs.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use MagicLinking\AI\UsageStore;
use MagicLinking\Core\Schema;
use wpdb;

/**
 * Estado de los procesos largos.
 */
final class JobRepository implements UsageStore {

	public const QUEUED    = 'queued';
	public const RUNNING   = 'running';
	public const PAUSED    = 'paused';
	public const DONE      = 'done';
	public const FAILED    = 'failed';
	public const CANCELLED = 'cancelled';

	/**
	 * Patrón LIKE de los apuntes de gasto de IA (`ai_rerank`…): su tipo empieza por `ai_`.
	 */
	public const AI_LIKE = 'ai\\_%';

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
	 * Tokens gastados hoy (día del sitio) en acciones con IA: la suma de `ai_tokens_real` de los apuntes de hoy.
	 *
	 * @return int
	 */
	public function ai_tokens_today(): int {
		$wpdb = $this->wpdb;
		$sum  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia; el tope diario no admite datos viejos.
			$wpdb->prepare( 'SELECT COALESCE(SUM(ai_tokens_real), 0) FROM %i WHERE type LIKE %s AND created_at >= %s', $this->table, self::AI_LIKE, self::day_start() )
		);

		return (int) $sum;
	}

	/**
	 * Apunta el gasto de una petición de IA (docs/05 §5.3). Los datos son solo cifras, proveedor y modelo: nunca
	 * texto de las entradas.
	 *
	 * @param array $row Datos de la petición.
	 *
	 * @phpstan-param array{type: string, status: string, tokens_est: int, tokens_real: int, cost_est: float, cost_real: float, provider: string, model: string, user_id: int, params: array<string, mixed>, error: string} $row
	 *
	 * @return int
	 */
	public function record_ai( array $row ): int {
		$wpdb = $this->wpdb;
		$now  = current_time( 'mysql', true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Tabla propia.
			$this->table,
			array(
				'type'           => substr( $row['type'], 0, 30 ),
				'status'         => self::DONE === $row['status'] ? self::DONE : self::FAILED,
				'total'          => 1,
				'done'           => self::DONE === $row['status'] ? 1 : 0,
				'params'         => (string) wp_json_encode( $row['params'] ),
				'ai_tokens_est'  => max( 0, $row['tokens_est'] ),
				'ai_tokens_real' => max( 0, $row['tokens_real'] ),
				'ai_cost_est'    => number_format( max( 0.0, $row['cost_est'] ), 6, '.', '' ),
				'ai_cost_real'   => number_format( max( 0.0, $row['cost_real'] ), 6, '.', '' ),
				'ai_provider'    => substr( $row['provider'], 0, 80 ),
				'ai_model'       => substr( $row['model'], 0, 80 ),
				'error'          => '' === $row['error'] ? null : $row['error'],
				'created_by'     => $row['user_id'],
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * La última llamada de IA que falló, para que Ajustes enseñe el motivo (solo a quien administra).
	 *
	 * @return array{at: string, model: string, provider: string, error: string}|null
	 */
	public function last_ai_failure(): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
			$wpdb->prepare( 'SELECT created_at, ai_provider, ai_model, params, error FROM %i WHERE type LIKE %s AND status = %s ORDER BY id DESC LIMIT 1', $this->table, self::AI_LIKE, self::FAILED ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		$params = json_decode( (string) $row['params'], true );
		$model  = '' !== (string) $row['ai_model'] ? (string) $row['ai_model'] : (string) ( is_array( $params ) ? ( $params['model_requested'] ?? '' ) : '' );

		return array(
			'at'       => (string) $row['created_at'],
			'model'    => $model,
			'provider' => (string) $row['ai_provider'],
			'error'    => (string) $row['error'],
		);
	}

	/**
	 * Gasto de IA de los últimos días, por proveedor y modelo (docs/05 §5.3).
	 *
	 * @param int $days Días hacia atrás desde ahora.
	 *
	 * @return array{calls: int, failed: int, tokens: int, models: list<array{provider: string, model: string, calls: int, tokens: int, cost: float}>}
	 */
	public function ai_usage( int $days ): array {
		$wpdb  = $this->wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
			$wpdb->prepare(
				'SELECT status, ai_provider, ai_model, COUNT(*) AS calls, COALESCE(SUM(ai_tokens_real), 0) AS tokens, COALESCE(SUM(ai_cost_real), 0) AS cost FROM %i WHERE type LIKE %s AND created_at >= %s GROUP BY status, ai_provider, ai_model',
				$this->table,
				self::AI_LIKE,
				$since
			),
			ARRAY_A
		);

		$out    = array(
			'calls'  => 0,
			'failed' => 0,
			'tokens' => 0,
			'models' => array(),
		);
		$models = array();
		foreach ( $rows as $row ) {
			$calls          = (int) $row['calls'];
			$out['calls']  += $calls;
			$out['tokens'] += (int) $row['tokens'];
			if ( self::FAILED === $row['status'] ) {
				$out['failed'] += $calls;
			}
			if ( '' === (string) $row['ai_model'] ) {
				continue;
			}
			$key                       = $row['ai_provider'] . "\n" . $row['ai_model'];
			$models[ $key ]            = ( $models[ $key ] ?? array(
				'provider' => (string) $row['ai_provider'],
				'model'    => (string) $row['ai_model'],
				'calls'    => 0,
				'tokens'   => 0,
				'cost'     => 0.0,
			) );
			$models[ $key ]['calls']  += $calls;
			$models[ $key ]['tokens'] += (int) $row['tokens'];
			$models[ $key ]['cost']   += (float) $row['cost'];
		}//end foreach

		usort( $models, static fn( array $a, array $b ): int => $b['tokens'] <=> $a['tokens'] );
		$out['models'] = array_slice( $models, 0, 10 );

		return $out;
	}

	/**
	 * Inicio del día del sitio, en UTC, con el formato de `created_at`.
	 */
	public static function day_start(): string {
		$start = new DateTimeImmutable( 'today', wp_timezone() );

		return $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
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
