<?php
/**
 * Acceso a magiclinking_docs y magiclinking_links.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

use MagicLinking\Core\Schema;
use wpdb;

/**
 * Guarda el grafo de enlaces internos y los recuentos desnormalizados de cada entrada.
 *
 * Definiciones (se dicen igual en la ficha y en la pantalla):
 * - entrantes: entradas distintas que enlazan a esta, contando solo enlaces que funcionan;
 * - salientes: enlaces internos a otras entradas o páginas del sitio, cada aparición cuenta;
 * - externas: enlaces a otros sitios;
 * - rotos: enlaces internos de esta entrada que no llevan a ninguna parte.
 */
final class GraphRepository {

	/**
	 * Conexión.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Tabla de entradas.
	 *
	 * @var string
	 */
	private string $docs;

	/**
	 * Tabla de enlaces.
	 *
	 * @var string
	 */
	private string $links;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->docs  = Schema::table( $wpdb->prefix, 'docs' );
		$this->links = Schema::table( $wpdb->prefix, 'links' );
	}

	/**
	 * Fila de una entrada indexada.
	 *
	 * @param int $post_id ID.
	 *
	 * @return array{post_id: int, post_type: string, lang: string, content_hash: string, word_count: int}|null
	 */
	public function doc( int $post_id ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
			$wpdb->prepare( 'SELECT post_id, post_type, lang, content_hash, word_count FROM %i WHERE post_id = %d', $this->docs, $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla propio.
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'post_id'      => (int) $row['post_id'],
			'post_type'    => (string) $row['post_type'],
			'lang'         => (string) $row['lang'],
			'content_hash' => (string) $row['content_hash'],
			'word_count'   => (int) $row['word_count'],
		);
	}

	/**
	 * Crea o actualiza la fila de una entrada sin tocar sus entrantes.
	 *
	 * @param array{post_id: int, post_type: string, lang: string, content_hash: string, word_count: int, outbound: int, external: int, broken: int} $doc Datos.
	 */
	public function upsert_doc( array $doc ): void {
		$wpdb = $this->wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Tabla propia.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (post_id, post_type, lang, status, content_hash, word_count, outbound, external, broken, indexed_at)
				VALUES (%d, %s, %s, 1, %s, %d, %d, %d, %d, %s)
				ON DUPLICATE KEY UPDATE post_type = VALUES(post_type), lang = VALUES(lang), status = 1, content_hash = VALUES(content_hash),
					word_count = VALUES(word_count), outbound = VALUES(outbound), external = VALUES(external), broken = VALUES(broken), indexed_at = VALUES(indexed_at)',
				$this->docs,
				$doc['post_id'],
				$doc['post_type'],
				$doc['lang'],
				$doc['content_hash'],
				$doc['word_count'],
				$doc['outbound'],
				$doc['external'],
				$doc['broken'],
				current_time( 'mysql', true )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Filas de varias entradas indexadas, con una sola consulta.
	 *
	 * @param array<int, int> $post_ids IDs.
	 *
	 * @return array<int, array{post_id: int, post_type: string, lang: string, content_hash: string, word_count: int}> Por ID; faltan las que no están indexadas.
	 */
	public function docs_by_ids( array $post_ids ): array {
		$wpdb = $this->wpdb;
		$out  = array();

		foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $post_ids ) ) ), 500 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia.
				$wpdb->prepare( "SELECT post_id, post_type, lang, content_hash, word_count FROM %i WHERE post_id IN ({$in})", array_merge( array( $this->docs ), $chunk ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['post_id'] ] = array(
					'post_id'      => (int) $row['post_id'],
					'post_type'    => (string) $row['post_type'],
					'lang'         => (string) $row['lang'],
					'content_hash' => (string) $row['content_hash'],
					'word_count'   => (int) $row['word_count'],
				);
			}
		}

		return $out;
	}

	/**
	 * Guarda de una vez el resultado de analizar varias entradas: sustituye sus enlaces
	 * (borrado previo y INSERT múltiple por trozos) y actualiza sus filas.
	 *
	 * @param array<int, array{post_id: int, post_type: string, lang: string, content_hash: string, word_count: int, outbound: int, external: int, broken: int}> $docs  Filas de magiclinking_docs.
	 * @param array<int, array<int, array{target_id: int|null, url: string, anchor: string, broken: int}>>                                                       $links Enlaces internos nuevos por entrada de origen (una entrada sin enlaces lleva una lista vacía).
	 *
	 * @return list<int> IDs de destino afectados (los antiguos y los nuevos), para recalcular sus entrantes.
	 */
	public function write_batch( array $docs, array $links ): array {
		$wpdb     = $this->wpdb;
		$affected = array();
		$sources  = array_map( 'intval', array_keys( $links ) );

		foreach ( array_chunk( $sources, 500 ) as $chunk ) {
			$in       = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$old      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia.
				$wpdb->prepare( "SELECT DISTINCT target_id FROM %i WHERE source_id IN ({$in}) AND target_id IS NOT NULL", array_merge( array( $this->links ), $chunk ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
			);
			$affected = array_merge( $affected, array_map( 'intval', $old ) );

			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia.
				$wpdb->prepare( "DELETE FROM %i WHERE source_id IN ({$in})", array_merge( array( $this->links ), $chunk ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
			);
		}

		$rows = array();
		foreach ( $links as $source_id => $list ) {
			foreach ( $list as $link ) {
				$rows[] = array( (int) $source_id, $link );
				if ( null !== $link['target_id'] ) {
					$affected[] = $link['target_id'];
				}
			}
		}

		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$args = array( $this->links );
			foreach ( $chunk as list( $source_id, $link ) ) {
				// NULLIF: un destino sin entrada (0) se guarda como NULL, con el mismo formato para todas las filas.
				array_push( $args, $source_id, (int) $link['target_id'], mb_substr( $link['url'], 0, 2048 ), $link['anchor'], $link['broken'] );
			}
			$values = implode( ', ', array_fill( 0, count( $chunk ), '(%d, NULLIF(%d, 0), %s, %s, 0, 1, %d)' ) );
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia.
				$wpdb->prepare( "INSERT INTO %i (source_id, target_id, target_url, anchor, kind, is_internal, is_broken) VALUES {$values}", $args ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
			);
		}

		$now = current_time( 'mysql', true );
		foreach ( array_chunk( array_values( $docs ), 100 ) as $chunk ) {
			$args = array( $this->docs );
			foreach ( $chunk as $doc ) {
				array_push( $args, $doc['post_id'], $doc['post_type'], $doc['lang'], $doc['content_hash'], $doc['word_count'], $doc['outbound'], $doc['external'], $doc['broken'], $now );
			}
			$values = implode( ', ', array_fill( 0, count( $chunk ), '(%d, %s, %s, 1, %s, %d, %d, %d, %d, %s)' ) );
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia.
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Marcadores generados.
					"INSERT INTO %i (post_id, post_type, lang, status, content_hash, word_count, outbound, external, broken, indexed_at) VALUES {$values} ON DUPLICATE KEY UPDATE post_type = VALUES(post_type), lang = VALUES(lang), status = 1, content_hash = VALUES(content_hash), word_count = VALUES(word_count), outbound = VALUES(outbound), external = VALUES(external), broken = VALUES(broken), indexed_at = VALUES(indexed_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
					$args
				)
			);
		}

		return array_values( array_unique( $affected ) );
	}

	/**
	 * Sustituye los enlaces internos de una entrada.
	 *
	 * @param int                                                                              $source_id Entrada de origen.
	 * @param array<int, array{target_id: int|null, url: string, anchor: string, broken: int}> $links     Enlaces internos nuevos.
	 *
	 * @return list<int> IDs de destino afectados (los antiguos y los nuevos), para recalcular sus entrantes.
	 */
	public function replace_links( int $source_id, array $links ): array {
		$wpdb = $this->wpdb;

		$old = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
			$wpdb->prepare( 'SELECT DISTINCT target_id FROM %i WHERE source_id = %d AND target_id IS NOT NULL', $this->links, $source_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla propio.
		);

		$wpdb->delete( $this->links, array( 'source_id' => $source_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.

		$affected = array_map( 'intval', $old );

		foreach ( $links as $link ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Tabla propia.
				$this->links,
				array(
					'source_id'   => $source_id,
					'target_id'   => $link['target_id'],
					'target_url'  => mb_substr( $link['url'], 0, 2048 ),
					'anchor'      => $link['anchor'],
					'kind'        => 0,
					'is_internal' => 1,
					'is_broken'   => $link['broken'],
				),
				array( '%d', '%d', '%s', '%s', '%d', '%d', '%d' )
			);

			if ( null !== $link['target_id'] ) {
				$affected[] = $link['target_id'];
			}
		}

		return array_values( array_unique( $affected ) );
	}

	/**
	 * Borra una entrada del índice (fila, términos principales y enlaces salientes).
	 *
	 * @param int $post_id ID.
	 *
	 * @return list<int> Destinos afectados.
	 */
	public function delete_doc( int $post_id ): array {
		$wpdb     = $this->wpdb;
		$affected = $this->replace_links( $post_id, array() );

		$wpdb->delete( Schema::table( $wpdb->prefix, 'postings' ), array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia: sus términos principales salen del índice léxico.
		$wpdb->delete( $this->docs, array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.

		return $affected;
	}

	/**
	 * Entradas de origen cuyos enlaces apuntan a una entrada.
	 *
	 * @param int $post_id ID de destino.
	 *
	 * @return list<int>
	 */
	public function sources_linking_to( int $post_id ): array {
		$wpdb = $this->wpdb;

		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
				$wpdb->prepare( 'SELECT DISTINCT source_id FROM %i WHERE target_id = %d', $this->links, $post_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla propio.
			)
		);
	}

	/**
	 * Entradas de origen con enlaces rotos cuya URL contiene un fragmento (normalmente el slug de una entrada recién publicada).
	 *
	 * @param string $fragment Fragmento de URL.
	 *
	 * @return list<int>
	 */
	public function sources_with_broken_url_like( string $fragment ): array {
		$wpdb = $this->wpdb;

		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
				$wpdb->prepare(
					'SELECT DISTINCT source_id FROM %i WHERE is_broken > 0 AND target_url LIKE %s',
					$this->links,
					'%' . $wpdb->esc_like( $fragment ) . '%'
				)
			)
		);
	}

	/**
	 * Entradas indexadas con al menos un enlace roto.
	 *
	 * @return list<int>
	 */
	public function sources_with_broken_links(): array {
		$wpdb = $this->wpdb;

		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE broken > 0', $this->docs ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
		);
	}

	/**
	 * Recalcula los entrantes de unas entradas (o de todas si no se indica ninguna).
	 *
	 * Solo hay enlaces de entradas indexadas: delete_doc() borra los de las que salen del índice.
	 *
	 * @param array<int, int>|null $post_ids IDs, o null para todas.
	 */
	public function refresh_inbound( ?array $post_ids = null ): void {
		$wpdb = $this->wpdb;

		$sql = 'UPDATE %i d SET d.inbound = (
			SELECT COUNT(DISTINCT l.source_id) FROM %i l
			WHERE l.target_id = d.post_id AND l.is_broken = 0 AND l.source_id <> d.post_id
		)';

		if ( null === $post_ids ) {
			$wpdb->query( $wpdb->prepare( $sql, $this->docs, $this->links ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia.
			return;
		}

		foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $post_ids ) ) ), 200 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$wpdb->query( $wpdb->prepare( $sql . " WHERE d.post_id IN ({$in})", array_merge( array( $this->docs, $this->links ), $chunk ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Tabla propia; marcadores generados.
		}
	}

	/**
	 * Borra las filas de entradas que ya no deben estar en el índice: borradas,
	 * sin publicar o de un tipo que ya no se analiza.
	 *
	 * @param array<int, string> $post_types Tipos que se analizan ahora.
	 *
	 * @return int Filas borradas.
	 */
	public function purge_out_of_scope( array $post_types ): int {
		$wpdb = $this->wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
			$wpdb->prepare(
				"SELECT d.post_id FROM %i d LEFT JOIN %i p ON p.ID = d.post_id WHERE p.ID IS NULL OR p.post_status <> 'publish'",
				$this->docs,
				$wpdb->posts
			)
		);

		if ( array() !== $post_types ) {
			$in    = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			$other = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia.
				$wpdb->prepare( "SELECT post_id FROM %i WHERE post_type NOT IN ({$in})", array_merge( array( $this->docs ), $post_types ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
			);
		} else {
			$other = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i', $this->docs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
		}
		$ids = array_merge( $ids, $other );

		$affected = array();
		$ids      = array_values( array_unique( array_map( 'intval', $ids ) ) );
		foreach ( $ids as $id ) {
			$affected = array_merge( $affected, $this->delete_doc( $id ) );
		}

		if ( array() !== $affected ) {
			$this->refresh_inbound( array_values( array_unique( $affected ) ) );
		}

		return count( $ids );
	}

	/**
	 * IDs de las entradas publicadas de los tipos indicados, en orden, a partir de un cursor.
	 *
	 * @param array<int, string> $post_types Tipos.
	 * @param int                $after_id   Solo IDs mayores que este.
	 * @param int                $limit      Máximo de IDs.
	 *
	 * @return array<int, int>
	 */
	public function eligible_ids( array $post_types, int $after_id, int $limit ): array {
		if ( array() === $post_types ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$in   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Consulta de análisis sobre el núcleo.
			$wpdb->prepare( "SELECT ID FROM %i WHERE post_status = 'publish' AND ID > %d AND post_type IN ({$in}) ORDER BY ID ASC LIMIT %d", array_merge( array( $wpdb->posts, $after_id ), $post_types, array( $limit ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Cuántas entradas publicadas hay de los tipos indicados.
	 *
	 * @param array<int, string> $post_types Tipos.
	 */
	public function count_eligible( array $post_types ): int {
		if ( array() === $post_types ) {
			return 0;
		}

		$wpdb = $this->wpdb;
		$in   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Consulta de análisis sobre el núcleo.
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_status = 'publish' AND post_type IN ({$in})", array_merge( array( $wpdb->posts ), $post_types ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
		);
	}

	/**
	 * Número de entradas indexadas.
	 */
	public function count_docs(): int {
		$wpdb = $this->wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->docs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
	}
}
