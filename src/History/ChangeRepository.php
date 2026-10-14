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

	public const INSERT   = 'insert';
	public const REMOVE   = 'remove';
	public const RETARGET = 'retarget';
	public const UNLINK   = 'unlink';

	/**
	 * Acciones que el usuario ve en el Historial y puede deshacer y rehacer: poner un enlace, cambiar su destino
	 * o quitarlo (F1-33). `remove` es el rastro de haber deshecho una de ellas.
	 */
	public const PRIMARY = array( self::INSERT, self::RETARGET, self::UNLINK );

	/**
	 * Origen de un cambio: lo escribió Magic Linking en el servidor (lo normal).
	 */
	public const ORIGIN_PLUGIN = 'plugin';

	/**
	 * Origen de un cambio: el usuario puso el enlace en el editor y el servidor solo lo registró (F1-34, D-58).
	 */
	public const ORIGIN_EDITOR = 'editor';

	/**
	 * Inserciones activas de una entrada que se miran como máximo al comprobar duplicados (las más recientes).
	 */
	public const ACTIVE_LIMIT = 500;

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
	 * @var array<string, array<string, list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string, action: string, origin?: string}>>>
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
	 * @param string      $action             `insert`, `retarget`, `unlink` o `remove`.
	 * @param string|null $block_path         Ruta del bloque (`3.0.1`) o `@` y el byte en el editor clásico.
	 * @param string      $before_html        HTML del tramo antes.
	 * @param string      $after_html         HTML del tramo después.
	 * @param string      $content_hash_after SHA-1 del contenido completo después del cambio.
	 * @param int         $user_id            Quien lo hace (0 = sistema).
	 * @param string      $origin             `plugin` (lo escribió el servidor) o `editor` (solo registrado, F1-34).
	 * @param string|null $origin_key         Clave idempotente de un registro del editor (SHA-1); null en el resto.
	 *
	 * @return int ID de la fila; 0 si no se ha podido guardar (en ese caso no se escribe el contenido).
	 */
	public function record( string $batch_id, int $post_id, string $action, ?string $block_path, string $before_html, string $after_html, string $content_hash_after, int $user_id, string $origin = self::ORIGIN_PLUGIN, ?string $origin_key = null ): int {
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
				'origin'             => $origin,
				'origin_key'         => $origin_key,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return false === $done ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Filas registradas con claves idempotentes del editor, en una sola consulta (F1-34).
	 *
	 * @param array $keys Claves (SHA-1).
	 *
	 * @phpstan-param list<string> $keys
	 *
	 * @return array<string, int> ID de la fila por clave; solo las que existen.
	 */
	public function ids_for_origin_keys( array $keys ): array {
		$wpdb = $this->wpdb;
		$keys = array_values( array_unique( $keys ) );
		if ( array() === $keys ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Lista de marcadores generada con array_fill().
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT id, origin_key FROM %i WHERE origin_key IN ({$placeholders})", $this->table, ...$keys ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$found = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$found[ (string) $row['origin_key'] ] = (int) $row['id'];
		}

		return $found;
	}

	/**
	 * Inserciones de una entrada que siguen puestas (no deshechas), con su HTML, las más recientes primero. Sirve para
	 * no registrar dos veces el mismo tramo. Con más de {@see self::ACTIVE_LIMIT} solo se miran las más recientes.
	 *
	 * @param int $post_id Entrada.
	 *
	 * @return list<array{id: int, before_html: string, after_html: string}>
	 */
	public function active_inserts_of_post( int $post_id ): array {
		return $this->active_rows( $post_id, null, self::ACTIVE_LIMIT );
	}

	/**
	 * Inserciones puestas de origen `editor` de una entrada, las más recientes primero, con las columnas mínimas (F1-34).
	 *
	 * @param int $post_id Entrada.
	 * @param int $limit   Máximo de filas (las más recientes).
	 *
	 * @return list<array{id: int, before_html: string, after_html: string}>
	 */
	public function active_editor_rows( int $post_id, int $limit ): array {
		return $this->active_rows( $post_id, self::ORIGIN_EDITOR, $limit );
	}

	/**
	 * Inserciones puestas de una entrada, de la más reciente a la más antigua.
	 *
	 * @param int         $post_id Entrada.
	 * @param string|null $origin  Solo de este origen, o todos.
	 * @param int         $limit   Máximo de filas.
	 *
	 * @return list<array{id: int, before_html: string, after_html: string}>
	 */
	private function active_rows( int $post_id, ?string $origin, int $limit ): array {
		$wpdb  = $this->wpdb;
		$where = null === $origin
			? $wpdb->prepare( 'post_id = %d AND action = %s AND undone_at IS NULL', $post_id, self::INSERT )
			: $wpdb->prepare( 'post_id = %d AND action = %s AND undone_at IS NULL AND origin = %s', $post_id, self::INSERT, $origin );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, before_html, after_html FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d", $this->table, max( 1, $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla propia; $where ya está preparado.

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'          => (int) $row['id'],
				'before_html' => (string) $row['before_html'],
				'after_html'  => (string) $row['after_html'],
			);
		}

		return $out;
	}

	/**
	 * Un cambio por su id.
	 *
	 * @param int $id ID.
	 *
	 * @return array{id: int, batch_id: string, post_id: int, action: string, block_path: string|null, before_html: string, after_html: string, content_hash_after: string, user_id: int, created_at: string, undone_at: string|null, origin: string}|null
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
	 * @return list<array{id: int, batch_id: string, post_id: int, action: string, block_path: string|null, before_html: string, after_html: string, content_hash_after: string, user_id: int, created_at: string, undone_at: string|null, origin: string}>
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
	 * @return array<int, array{id: int, batch_id: string, post_id: int, action: string, block_path: string|null, before_html: string, after_html: string, content_hash_after: string, user_id: int, created_at: string, undone_at: string|null, origin: string}>
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
			$query = $wpdb->prepare( 'SELECT batch_id FROM %i WHERE action IN ( %s, %s, %s ) AND batch_id < %s GROUP BY batch_id ORDER BY batch_id DESC LIMIT %d', $this->table, ...array_merge( self::PRIMARY, array( $before, $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Tres acciones, más el cursor y el límite.
		} else {
			$query = $wpdb->prepare( 'SELECT batch_id FROM %i WHERE action IN ( %s, %s, %s ) GROUP BY batch_id ORDER BY batch_id DESC LIMIT %d', $this->table, ...array_merge( self::PRIMARY, array( $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Tres acciones y el límite.
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
	 * @return array<string, list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string, action: string, origin?: string}>> Por lote.
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
				"SELECT id, batch_id, post_id, action, user_id, created_at, undone_at, origin, MD5( CONCAT_WS( '|', post_id, COALESCE( block_path, '' ), before_html, after_html ) ) AS slot FROM %i WHERE action IN ( %s, %s, %s ) AND batch_id IN ({$placeholders}) ORDER BY id",
				$this->table,
				...array_merge( self::PRIMARY, $batch_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$by_batch = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$by_batch[ (string) $row['batch_id'] ][] = array(
				'id'         => (int) $row['id'],
				'post_id'    => (int) $row['post_id'],
				'action'     => (string) $row['action'],
				'user_id'    => (int) $row['user_id'],
				'created_at' => (string) $row['created_at'],
				'undone_at'  => null === $row['undone_at'] ? null : (string) $row['undone_at'],
				'slot'       => (string) $row['slot'],
				'origin'     => (string) $row['origin'],
			);
		}

		if ( 1 === count( $batch_ids ) ) {
			$this->memo[ $batch_ids[0] ] = $by_batch;
		}

		return $by_batch;
	}

	/**
	 * Cambios por entrada, paginados, todo en SQL (F1-36, D-59): una fila por entrada con sus recuentos, ordenadas por
	 * su última actividad (poner o deshacer un cambio). Cuenta un enlace una sola vez aunque se haya rehecho (solo
	 * vale la fila más reciente de cada tramo, como {@see Slots}) y no trae HTML.
	 *
	 * @param array $filters  Filtros: `status` (`active`, `undone` o vacío = todos), `origin` (`plugin`, `editor` o vacío),
	 *                        `search` (texto del título de la entrada), `post_id` (solo esa entrada) y `restrict` (tipos de entrada que el usuario edita sean de quien sean, su id como autor para el resto y si ve las entradas borradas; ausente = sin límite).
	 * @param int   $page     Página (desde 1).
	 * @param int   $per_page Entradas por página.
	 *
	 * @phpstan-param array{status?: string, origin?: string, search?: string, restrict?: array{types: list<string>, author: int, deleted: bool}|null, post_id?: int} $filters
	 *
	 * @return array{items: list<array{post_id: int, total: int, active: int, last_at: string}>, total: int}
	 */
	public function post_groups( array $filters, int $page, int $per_page ): array {
		$wpdb     = $this->wpdb;
		$per_page = max( 1, $per_page );
		$offset   = max( 0, $page - 1 ) * $per_page;
		$status   = $filters['status'] ?? '';
		$restrict = $filters['restrict'] ?? null;
		$search   = trim( (string) ( $filters['search'] ?? '' ) );

		$join  = '';
		$where = $this->live_rows_sql( 'c' ) . $wpdb->prepare( ' AND c.action IN ( %s, %s, %s )', ...self::PRIMARY ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Tres acciones.
		if ( in_array( $filters['origin'] ?? '', array( self::ORIGIN_PLUGIN, self::ORIGIN_EDITOR ), true ) ) {
			$where .= $wpdb->prepare( ' AND c.origin = %s', $filters['origin'] );
		}
		if ( null !== $restrict || '' !== $search ) {
			$join = $wpdb->prepare( ( '' !== $search ? ' INNER' : ' LEFT' ) . ' JOIN %i p ON p.ID = c.post_id', $wpdb->posts ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Palabra fija.
		}
		if ( (int) ( $filters['post_id'] ?? 0 ) > 0 ) {
			$where .= $wpdb->prepare( ' AND c.post_id = %d', (int) $filters['post_id'] );
		}
		if ( null !== $restrict ) {
			$any = array( $wpdb->prepare( 'p.post_author = %d', $restrict['author'] ) );
			if ( array() !== $restrict['types'] ) {
				$any[] = 'p.post_type IN ( ' . implode( ',', array_map( static fn( string $type ): string => "'" . esc_sql( $type ) . "'", $restrict['types'] ) ) . ' )';
			}
			if ( $restrict['deleted'] ) {
				$any[] = 'p.ID IS NULL';
			}
			$where .= ' AND ( ' . implode( ' OR ', $any ) . ' )';
		}
		if ( '' !== $search ) {
			$where .= $wpdb->prepare( ' AND p.post_title LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}

		$having = '';
		if ( 'active' === $status ) {
			$having = ' HAVING active > 0';
		} elseif ( 'undone' === $status ) {
			$having = ' HAVING active < total';
		}

		$grouped = $wpdb->prepare( 'SELECT c.post_id, COUNT(*) AS total, SUM( c.undone_at IS NULL ) AS active, MAX( GREATEST( c.created_at, COALESCE( c.undone_at, c.created_at ) ) ) AS last_at FROM %i c', $this->table ) . $join . ' WHERE ' . $where . ' GROUP BY c.post_id' . $having; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Cada trozo está preparado.

		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ( ' . $grouped . ' ) g' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia; consulta preparada por trozos.
		$rows  = $wpdb->get_results( $grouped . $wpdb->prepare( ' ORDER BY last_at DESC, MAX( c.id ) DESC LIMIT %d OFFSET %d', $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia; consulta preparada por trozos.

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'post_id' => (int) $row['post_id'],
				'total'   => (int) $row['total'],
				'active'  => (int) $row['active'],
				'last_at' => (string) $row['last_at'],
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Los primeros cambios de varias entradas en una sola consulta (`UNION ALL` con límite por entrada, sin
	 * funciones de ventana), del más reciente al más antiguo. Sin HTML; para eso están {@see self::get_many()}.
	 *
	 * @param array  $post_ids Entradas.
	 * @param int    $limit    Cambios por entrada.
	 * @param string $origin   Solo de este origen (`plugin`, `editor`) o vacío.
	 *
	 * @phpstan-param list<int> $post_ids
	 *
	 * @return array<int, list<int>> IDs de cambio por entrada.
	 */
	public function recent_ids_of_posts( array $post_ids, int $limit, string $origin = '' ): array {
		$wpdb  = $this->wpdb;
		$parts = array();
		foreach ( array_values( array_unique( $post_ids ) ) as $post_id ) {
			$parts[] = '(' . $this->post_rows_sql( $post_id, $origin, 'c.id, c.post_id' ) . $wpdb->prepare( ' ORDER BY c.id DESC LIMIT %d', max( 1, $limit ) ) . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Cada trozo está preparado.
		}
		if ( array() === $parts ) {
			return array();
		}

		$rows = $wpdb->get_results( implode( ' UNION ALL ', $parts ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia; consulta preparada por trozos.

		$by_post = array();
		foreach ( $rows as $row ) {
			$by_post[ (int) $row[1] ][] = (int) $row[0];
		}

		return $by_post;
	}

	/**
	 * Una página de los cambios de una entrada, del más reciente al más antiguo, con cursor, y cuántos hay.
	 *
	 * @param int    $post_id  Entrada.
	 * @param int    $before   Cursor: solo los cambios con id menor que este (0 = desde el principio).
	 * @param int    $per_page Cambios por página.
	 * @param string $origin   Solo de este origen o vacío.
	 *
	 * @return array{ids: list<int>, total: int}
	 */
	public function post_ids_page( int $post_id, int $before, int $per_page, string $origin = '' ): array {
		$wpdb = $this->wpdb;
		$cond = $before > 0 ? $wpdb->prepare( ' AND c.id < %d', $before ) : '';
		$ids  = $wpdb->get_col( $this->post_rows_sql( $post_id, $origin, 'c.id' ) . $cond . $wpdb->prepare( ' ORDER BY c.id DESC LIMIT %d', max( 1, $per_page ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia; consulta preparada por trozos.
		$all  = (int) $wpdb->get_var( $this->post_rows_sql( $post_id, $origin, 'COUNT(*)' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia; consulta preparada por trozos.

		return array(
			'ids'   => array_map( 'intval', $ids ),
			'total' => $all,
		);
	}

	/**
	 * Ids de los cambios activos o deshechos de una entrada (un tramo cuenta una vez), del más reciente al más antiguo.
	 *
	 * @param int    $post_id Entrada.
	 * @param bool   $undone  true = los deshechos (para rehacer); false = los puestos (para deshacer).
	 * @param string $origin  Solo de este origen o vacío.
	 *
	 * @return list<int>
	 */
	public function pending_ids_of_post( int $post_id, bool $undone, string $origin = '' ): array {
		$wpdb = $this->wpdb;
		$cond = $undone ? ' AND c.undone_at IS NOT NULL' : ' AND c.undone_at IS NULL';
		$ids  = $wpdb->get_col( $this->post_rows_sql( $post_id, $origin, 'c.id' ) . $cond . ' ORDER BY c.id DESC' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia; consulta preparada por trozos.

		return array_map( 'intval', $ids );
	}

	/**
	 * Entrada, lote y acción de cada cambio, en una consulta y sin HTML.
	 *
	 * @param array $ids IDs de cambio.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @return array<int, array{post_id: int, batch_id: string, action: string}> Por id; solo los que existen.
	 */
	public function light_of( array $ids ): array {
		$wpdb = $this->wpdb;
		$ids  = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Lista de marcadores generada con array_fill().
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT id, post_id, batch_id, action FROM %i WHERE id IN ({$placeholders})", $this->table, ...$ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['id'] ] = array(
				'post_id'  => (int) $row['post_id'],
				'batch_id' => (string) $row['batch_id'],
				'action'   => (string) $row['action'],
			);
		}

		return $out;
	}

	/**
	 * De cuántas entradas distintas es cada lote.
	 *
	 * @param array $batch_ids Lotes.
	 *
	 * @phpstan-param list<string> $batch_ids
	 *
	 * @return array<string, int>
	 */
	public function batch_post_counts( array $batch_ids ): array {
		$wpdb      = $this->wpdb;
		$batch_ids = array_values( array_unique( $batch_ids ) );
		if ( array() === $batch_ids ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Lista de marcadores generada con array_fill().
		$placeholders = implode( ',', array_fill( 0, count( $batch_ids ), '%s' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT batch_id, COUNT( DISTINCT post_id ) AS posts FROM %i WHERE batch_id IN ({$placeholders}) GROUP BY batch_id", $this->table, ...$batch_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['batch_id'] ] = (int) $row['posts'];
		}

		return $out;
	}

	/**
	 * `SELECT` de las filas visibles de una entrada (alias `c`).
	 *
	 * @param int    $post_id Entrada.
	 * @param string $origin  Origen o vacío.
	 * @param string $select  Columna o expresión.
	 */
	private function post_rows_sql( int $post_id, string $origin, string $select ): string {
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare( 'SELECT ' . $select . ' FROM %i c WHERE c.post_id = %d AND c.action IN ( %s, %s, %s )', $this->table, $post_id, ...self::PRIMARY ) . ' AND ' . $this->live_rows_sql( 'c' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $select es una constante interna; tres acciones.
		if ( in_array( $origin, array( self::ORIGIN_PLUGIN, self::ORIGIN_EDITOR ), true ) ) {
			$sql .= $wpdb->prepare( ' AND c.origin = %s', $origin );
		}

		return $sql;
	}

	/**
	 * Condición SQL de las filas que cuentan: la más reciente de cada tramo. Una fila puesta (sin deshacer) siempre lo
	 * es; una deshecha solo si nadie volvió a poner después el mismo tramo en el mismo lote (rehacer, `put_back_after`).
	 *
	 * @param string $alias Alias de la tabla.
	 */
	private function live_rows_sql( string $alias ): string {
		return $this->wpdb->prepare(
			"( {$alias}.undone_at IS NULL OR NOT EXISTS ( SELECT 1 FROM %i n WHERE n.post_id = {$alias}.post_id AND n.batch_id = {$alias}.batch_id AND n.id > {$alias}.id AND n.action = {$alias}.action AND COALESCE( n.block_path, '' ) = COALESCE( {$alias}.block_path, '' ) AND n.before_html = {$alias}.before_html AND n.after_html = {$alias}.after_html ) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Alias interno.
			$this->table
		);
	}

	/**
	 * Si el mismo cambio (mismo lote, entrada, acción y tramo antes y después) se volvió a hacer después de este.
	 *
	 * @param array $change Fila `insert`, `retarget` o `unlink` del historial.
	 *
	 * @phpstan-param array{id: int, batch_id: string, post_id: int, action: string, block_path: string|null, before_html: string, after_html: string} $change
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
				$change['action'],
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
	 * @return array{id: int, batch_id: string, post_id: int, action: string, block_path: string|null, before_html: string, after_html: string, content_hash_after: string, user_id: int, created_at: string, undone_at: string|null, origin: string}
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
			'origin'             => (string) ( $row['origin'] ?? self::ORIGIN_PLUGIN ),
		);
	}
}
