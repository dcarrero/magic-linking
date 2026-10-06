<?php
/**
 * Índice léxico sobre las tablas del plugin.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use MagicLinking\Core\Schema;
use MagicLinking\Engine\Analyzer;
use MagicLinking\Engine\DocMeta;
use MagicLinking\Engine\IndexedDoc;
use MagicLinking\Engine\IndexRepository;
use MagicLinking\Engine\IndexStats;
use wpdb;
use WP_Post;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Tablas propias; los marcadores de las consultas múltiples se generan.

/**
 * {@see IndexRepository} sobre `magiclinking_docs`, `magiclinking_terms` y `magiclinking_postings`
 * (y `magiclinking_links` para los enlaces). Todas las escrituras son en bloque: INSERT múltiple
 * con `$wpdb->prepare()` por trozos, nunca una consulta por término.
 *
 * Los términos de `magiclinking_terms` se identifican por (idioma, raíz); el tamaño del n-grama sale
 * de los espacios de la raíz. Un término de más de 64 caracteres no se guarda (cabe mal en la
 * columna y es casi siempre un n-grama sin valor).
 */
final class TableRepository implements IndexRepository {

	/**
	 * Longitud máxima de una raíz (columna `stem`).
	 */
	public const MAX_STEM = 64;

	/**
	 * Filas por INSERT múltiple de términos y de postings.
	 */
	private const ROWS = 500;

	/**
	 * Términos por consulta de búsqueda.
	 */
	private const LOOKUP = 1000;

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
	 * Tabla de términos.
	 *
	 * @var string
	 */
	private string $terms;

	/**
	 * Tabla de postings.
	 *
	 * @var string
	 */
	private string $postings;

	/**
	 * Tabla de enlaces.
	 *
	 * @var string
	 */
	private string $links;

	/**
	 * Frecuencias de documento por guardar: idioma → término → incremento.
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $pending = array();

	/**
	 * Totales por idioma (entradas y longitud media), calculados al primer uso.
	 *
	 * @var array<string, array{0: int, 1: float}>
	 */
	private array $totals = array();

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb     = $wpdb;
		$this->docs     = Schema::table( $wpdb->prefix, 'docs' );
		$this->terms    = Schema::table( $wpdb->prefix, 'terms' );
		$this->postings = Schema::table( $wpdb->prefix, 'postings' );
		$this->links    = Schema::table( $wpdb->prefix, 'links' );
	}

	// ------------------------------------------------------------------ Frecuencia de documento.

	/**
	 * {@inheritDoc}
	 *
	 * Acumula en memoria; {@see self::flush_counts()} lo escribe con INSERT múltiples. La longitud
	 * no se usa aquí: `doc_len` se guarda con {@see self::save_docs()}.
	 *
	 * @param string   $lang   Idioma.
	 * @param string[] $terms  Términos distintos de la entrada.
	 * @param int      $length Longitud.
	 */
	public function count_terms( string $lang, array $terms, int $length ): void {
		unset( $length );

		foreach ( $terms as $term ) {
			$term = (string) $term;
			if ( mb_strlen( $term ) <= self::MAX_STEM ) {
				$this->pending[ $lang ][ $term ] = ( $this->pending[ $lang ][ $term ] ?? 0 ) + 1;
			}
		}
	}

	/**
	 * Descarta las frecuencias acumuladas sin escribirlas (el proceso que las contaba se canceló).
	 */
	public function discard_counts(): void {
		$this->pending = array();
	}

	/**
	 * Escribe las frecuencias acumuladas: una consulta por cada 500 términos distintos.
	 */
	public function flush_counts(): void {
		$wpdb = $this->wpdb;

		foreach ( $this->pending as $lang => $counts ) {
			$rows = array();
			foreach ( $counts as $term => $df ) {
				$rows[] = array( (string) $term, $df );
			}

			foreach ( array_chunk( $rows, self::ROWS ) as $chunk ) {
				$args = array( $this->terms );
				foreach ( $chunk as list( $term, $df ) ) {
					array_push( $args, (string) $lang, $term, $df, min( 3, substr_count( $term, ' ' ) + 1 ) );
				}
				$values = implode( ', ', array_fill( 0, count( $chunk ), "(%s, %s, '', %d, %d)" ) );
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO %i (lang, stem, surface, df, n) VALUES {$values} ON DUPLICATE KEY UPDATE df = df + VALUES(df)",
						$args
					)
				);
			}
		}

		$this->pending = array();
	}

	/**
	 * Suma una entrada nueva a la frecuencia de los términos que ya están en el vocabulario.
	 * Los que no están (df de 0 o de 1: no entran en el índice) no se crean.
	 *
	 * @param string   $lang  Idioma.
	 * @param string[] $terms Términos distintos de la entrada.
	 */
	public function bump_df( string $lang, array $terms ): void {
		$wpdb = $this->wpdb;

		foreach ( array_chunk( $this->storable( $terms ), self::LOOKUP ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET df = df + 1 WHERE lang = %s AND stem IN ({$in})", array_merge( array( $this->terms, $lang ), $chunk ) ) );
		}
	}

	/**
	 * Pone a cero todas las frecuencias antes de volver a contarlas. Los identificadores de
	 * término se conservan, así que los postings antiguos siguen siendo válidos hasta que se reescriban.
	 */
	public function reset_df(): void {
		$this->wpdb->query( $this->wpdb->prepare( 'UPDATE %i SET df = 0', $this->terms ) );
		$this->pending = array();
	}

	/**
	 * Borra los términos con df por debajo del mínimo: no pueden unir dos entradas.
	 *
	 * @param int $min_df df mínimo que se conserva.
	 *
	 * @return int Términos borrados.
	 */
	public function prune_terms( int $min_df ): int {
		$wpdb = $this->wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE df < %d', $this->terms, $min_df ) );

		return (int) $wpdb->rows_affected;
	}

	// ------------------------------------------------------------------ Estadísticas.

	/**
	 * {@inheritDoc}
	 *
	 * Sin la frecuencia de cada término (el vocabulario no cabe en memoria): para pesar una entrada
	 * se usa {@see self::stats_for()}.
	 *
	 * @param string $lang Idioma.
	 */
	public function stats( string $lang ): IndexStats {
		[ $count, $average ] = $this->totals( $lang );

		return new IndexStats( $count, $average, array() );
	}

	/**
	 * Estadísticas del idioma con la frecuencia de unos términos, y sus identificadores.
	 *
	 * @param string   $lang  Idioma.
	 * @param string[] $terms Términos.
	 *
	 * @return array{0: IndexStats, 1: array<string, int>} Estadísticas e ID de término por raíz.
	 */
	public function stats_for( string $lang, array $terms ): array {
		[ $count, $average ] = $this->totals( $lang );
		$df                  = array();
		$ids                 = array();

		foreach ( $this->lookup( $lang, $terms ) as $stem => list( $id, $frequency ) ) {
			$df[ $stem ]  = $frequency;
			$ids[ $stem ] = $id;
		}

		return array( new IndexStats( $count, $average, $df ), $ids );
	}

	/**
	 * Entradas y longitud media del idioma.
	 *
	 * @param string $lang Idioma.
	 *
	 * @return array{0: int, 1: float}
	 */
	private function totals( string $lang ): array {
		if ( ! isset( $this->totals[ $lang ] ) ) {
			$row = $this->wpdb->get_row(
				$this->wpdb->prepare( 'SELECT COUNT(*) AS n, AVG(doc_len) AS avg_len FROM %i WHERE lang = %s AND status = 1', $this->docs, $lang ),
				ARRAY_A
			);

			$this->totals[ $lang ] = is_array( $row ) ? array( (int) $row['n'], (float) $row['avg_len'] ) : array( 0, 0.0 );
		}

		return $this->totals[ $lang ];
	}

	/**
	 * Términos del vocabulario: ID y df por raíz. Los que no están, no aparecen.
	 *
	 * @param string   $lang  Idioma.
	 * @param string[] $terms Términos.
	 *
	 * @return array<string, array{0: int, 1: int}>
	 */
	private function lookup( string $lang, array $terms ): array {
		$wpdb  = $this->wpdb;
		$found = array();

		foreach ( array_chunk( $this->storable( $terms ), self::LOOKUP ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT term_id, stem, df FROM %i WHERE lang = %s AND stem IN ({$in})", array_merge( array( $this->terms, $lang ), $chunk ) ),
				ARRAY_N
			);

			foreach ( (array) $rows as $row ) {
				$found[ (string) $row[1] ] = array( (int) $row[0], (int) $row[2] );
			}
		}

		return $found;
	}

	/**
	 * Términos que caben en el vocabulario, como cadenas.
	 *
	 * @param array<int|string, mixed> $terms Términos.
	 *
	 * @return list<string>
	 */
	private function storable( array $terms ): array {
		$out = array();
		foreach ( $terms as $term ) {
			$term = (string) $term;
			if ( mb_strlen( $term ) <= self::MAX_STEM ) {
				$out[] = $term;
			}
		}

		return $out;
	}

	// ------------------------------------------------------------------ Entradas.

	/**
	 * Filas de varias entradas: idioma, huella del índice léxico y longitud. Faltan las que no están indexadas.
	 *
	 * La huella es la del propio índice léxico (`lex_hash`), no la del grafo (`content_hash`): solo se
	 * escribe cuando las postings de la entrada ya están guardadas, así que si el índice léxico falla
	 * a mitad la entrada sigue pareciendo desfasada y se reintenta.
	 *
	 * @param array<int, int> $ids IDs.
	 *
	 * @return array<int, array{lang: string, lex_hash: string, doc_len: int}>
	 */
	public function rows( array $ids ): array {
		$wpdb = $this->wpdb;
		$out  = array();

		foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $ids ) ) ), 500 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT post_id, lang, lex_hash, doc_len FROM %i WHERE post_id IN ({$in})", array_merge( array( $this->docs ), $chunk ) ),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['post_id'] ] = array(
					'lang'     => (string) $row['lang'],
					'lex_hash' => (string) $row['lex_hash'],
					'doc_len'  => (int) $row['doc_len'],
				);
			}
		}

		return $out;
	}

	/**
	 * Crea o actualiza las filas de `magiclinking_docs` con lo que calcula el índice léxico (tipo, idioma
	 * y longitud). No toca la huella, los recuentos ni los enlaces: son del grafo.
	 *
	 * @param list<array{post_id: int, post_type: string, lang: string, doc_len: int}> $docs Filas.
	 */
	public function save_docs( array $docs ): void {
		$wpdb = $this->wpdb;
		$now  = current_time( 'mysql', true );

		foreach ( array_chunk( $docs, 100 ) as $chunk ) {
			$args = array( $this->docs );
			foreach ( $chunk as $doc ) {
				array_push( $args, $doc['post_id'], $doc['post_type'], $doc['lang'], $doc['doc_len'], $now );
			}
			$values = implode( ', ', array_fill( 0, count( $chunk ), "(%d, %s, %s, 1, '', 0, %d, %s)" ) );
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i (post_id, post_type, lang, status, content_hash, word_count, doc_len, indexed_at) VALUES {$values} ON DUPLICATE KEY UPDATE post_type = VALUES(post_type), lang = VALUES(lang), status = 1, doc_len = VALUES(doc_len), indexed_at = VALUES(indexed_at)",
					$args
				)
			);
		}

		$this->totals = array();
	}

	/**
	 * Anota que el índice léxico de unas entradas está al día con una huella de contenido.
	 *
	 * @param array<int, string> $hashes Huella por ID de entrada.
	 */
	public function mark_lexical( array $hashes ): void {
		$wpdb = $this->wpdb;
		$now  = current_time( 'mysql', true );

		foreach ( array_chunk( $hashes, 100, true ) as $chunk ) {
			$args = array( $this->docs );
			foreach ( $chunk as $id => $hash ) {
				array_push( $args, (int) $id, $hash, $now, $now );
			}
			$values = implode( ', ', array_fill( 0, count( $chunk ), '(%d, %s, %s, %s)' ) );
			// Las filas ya existen (las crea save_docs); indexed_at solo cumple el NOT NULL de una inserción que no llega a producirse.
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (post_id, lex_hash, lex_at, indexed_at) VALUES {$values} ON DUPLICATE KEY UPDATE lex_hash = VALUES(lex_hash), lex_at = VALUES(lex_at)", $args ) );
		}
	}

	/**
	 * Entradas cuyo índice léxico va por detrás del grafo (su huella léxica no es la del contenido actual).
	 *
	 * @param int $limit Máximo.
	 *
	 * @return array<int, int>
	 */
	public function lexical_behind( int $limit ): array {
		$ids = $this->wpdb->get_col( $this->wpdb->prepare( "SELECT post_id FROM %i WHERE doc_len > 0 AND content_hash <> '' AND lex_hash <> content_hash ORDER BY post_id LIMIT %d", $this->docs, $limit ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Si hay alguna entrada con índice léxico.
	 */
	public function has_lexical(): bool {
		return null !== $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT post_id FROM %i WHERE doc_len > 0 LIMIT 1', $this->docs ) );
	}

	/**
	 * Entradas con índice léxico.
	 */
	public function count_lexical(): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE doc_len > 0', $this->docs ) );
	}

	/**
	 * Entradas cuyo índice léxico se ha escrito desde un instante (contenido nuevo o modificado; un
	 * reanálisis del grafo sin cambios de contenido no cuenta).
	 *
	 * @param string $since Fecha y hora UTC (`Y-m-d H:i:s`).
	 */
	public function count_changed_since( string $since ): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE lex_at > %s', $this->docs, $since ) );
	}

	/**
	 * Cuántas filas hay en una de las tablas del índice (para el estado y las pruebas).
	 *
	 * @param string $table `terms` o `postings`.
	 */
	public function count_rows( string $table ): int {
		$name = 'terms' === $table ? $this->terms : $this->postings;

		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', $name ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param IndexedDoc $doc Entrada.
	 */
	public function put( IndexedDoc $doc ): void {
		$this->put_many( array( $doc ) );
	}

	/**
	 * Guarda varias entradas indexadas: sus filas de `magiclinking_docs` y sus términos principales
	 * (borrado previo de los antiguos e INSERT múltiple de los nuevos).
	 *
	 * @param IndexedDoc[] $docs Entradas.
	 *
	 * @phpstan-param list<IndexedDoc> $docs
	 */
	public function put_many( array $docs ): void {
		if ( array() === $docs ) {
			return;
		}

		$wpdb   = $this->wpdb;
		$rows   = array();
		$wanted = array();
		$ids    = array();

		foreach ( $docs as $doc ) {
			$ids[]  = $doc->meta->id;
			$rows[] = array(
				'post_id'   => $doc->meta->id,
				'post_type' => $doc->meta->type,
				'lang'      => $doc->meta->lang,
				'doc_len'   => $doc->meta->length,
			);
			foreach ( array_keys( $doc->terms ) as $term ) {
				$wanted[ $doc->meta->lang ][] = (string) $term;
			}
		}

		$this->save_docs( $rows );

		$found = array();
		foreach ( $wanted as $lang => $terms ) {
			$found[ $lang ] = $this->lookup( $lang, array_values( array_unique( $terms ) ) );
		}

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE post_id IN ({$in})", array_merge( array( $this->postings ), $chunk ) ) );
		}

		$postings = array();
		foreach ( $docs as $doc ) {
			foreach ( $doc->terms as $term => $weight ) {
				$term = (string) $term;
				$id   = $found[ $doc->meta->lang ][ $term ][0] ?? null;
				if ( null !== $id ) {
					$postings[] = array( $id, $doc->meta->id, $weight, $doc->fields[ $term ] ?? 0 );
				}
			}
		}

		foreach ( array_chunk( $postings, self::ROWS ) as $chunk ) {
			$args = array( $this->postings );
			foreach ( $chunk as $row ) {
				array_push( $args, $row[0], $row[1], $row[2], $row[3] );
			}
			$values = implode( ', ', array_fill( 0, count( $chunk ), '(%d, %d, %f, %d)' ) );
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (term_id, post_id, weight, field) VALUES {$values}", $args ) );
		}
	}

	/**
	 * Borra los términos principales de unas entradas.
	 *
	 * @param array<int, int> $ids IDs.
	 */
	public function delete_postings( array $ids ): void {
		$wpdb = $this->wpdb;

		foreach ( array_chunk( array_map( 'intval', array_values( $ids ) ), 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE post_id IN ({$in})", array_merge( array( $this->postings ), $chunk ) ) );
		}
	}

	// ------------------------------------------------------------------ Lectura (IndexRepository).

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id ID.
	 */
	public function meta( int $id ): ?DocMeta {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT post_type, lang, word_count, doc_len, outbound FROM %i WHERE post_id = %d', $this->docs, $id ), ARRAY_A );
		$post = get_post( $id );

		if ( ! is_array( $row ) || ! $post instanceof WP_Post ) {
			return null;
		}

		$date = strtotime( $post->post_modified_gmt . ' UTC' );

		return new DocMeta(
			$id,
			(string) $row['post_type'],
			(string) $row['lang'],
			html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			$post->post_name,
			false === $date ? 0 : $date,
			(int) $row['word_count'],
			(int) $row['doc_len'],
			(int) $row['outbound'],
			Fingerprint::focus( $id )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id ID.
	 */
	public function terms( int $id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT t.stem, p.weight FROM %i p JOIN %i t ON t.term_id = p.term_id WHERE p.post_id = %d ORDER BY p.weight DESC, t.stem ASC', $this->postings, $this->terms, $id ),
			ARRAY_N
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row[0] ] = (float) $row[1];
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, float> $weights Pesos.
	 * @param string               $lang    Idioma.
	 * @param int                  $limit   Máximo.
	 * @param int[]                $exclude Excluidos.
	 */
	public function similar( array $weights, string $lang, int $limit, array $exclude = array() ): array {
		$found = $this->lookup( $lang, array_map( 'strval', array_keys( $weights ) ) );
		$by_id = array();
		foreach ( $weights as $term => $weight ) {
			if ( isset( $found[ (string) $term ] ) ) {
				$by_id[ $found[ (string) $term ][0] ] = $weight;
			}
		}
		if ( array() === $by_id ) {
			return array();
		}

		$wpdb   = $this->wpdb;
		$in     = implode( ',', array_fill( 0, count( $by_id ), '%d' ) );
		$rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT p.term_id, p.post_id, p.weight FROM %i p JOIN %i d ON d.post_id = p.post_id WHERE d.lang = %s AND d.status = 1 AND p.term_id IN ({$in})", array_merge( array( $this->postings, $this->docs, $lang ), array_keys( $by_id ) ) ),
			ARRAY_N
		);
		$scores = array();
		foreach ( (array) $rows as $row ) {
			$id            = (int) $row[1];
			$scores[ $id ] = ( $scores[ $id ] ?? 0.0 ) + $by_id[ (int) $row[0] ] * (float) $row[2];
		}

		return self::best( $scores, $limit, $exclude );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string[] $terms   Términos.
	 * @param string   $lang    Idioma.
	 * @param int      $limit   Máximo.
	 * @param int[]    $exclude Excluidos.
	 */
	public function containing( array $terms, string $lang, int $limit, array $exclude = array() ): array {
		$found = $this->lookup( $lang, array_values( array_unique( array_map( 'strval', $terms ) ) ) );
		if ( array() === $found ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$ids  = array_map( static fn( array $term ): int => $term[0], array_values( $found ) );
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT p.post_id, SUM(p.weight) FROM %i p JOIN %i d ON d.post_id = p.post_id WHERE d.lang = %s AND d.status = 1 AND p.term_id IN ({$in}) GROUP BY p.post_id", array_merge( array( $this->postings, $this->docs, $lang ), $ids ) ),
			ARRAY_N
		);

		$scores = array();
		foreach ( (array) $rows as $row ) {
			$scores[ (int) $row[0] ] = (float) $row[1];
		}

		return self::best( $scores, $limit, $exclude );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $a ID.
	 * @param int $b ID.
	 */
	public function similarity( int $a, int $b ): float {
		$wpdb = $this->wpdb;

		return (float) $wpdb->get_var(
			$wpdb->prepare( 'SELECT SUM(x.weight * y.weight) FROM %i x JOIN %i y ON y.term_id = x.term_id WHERE x.post_id = %d AND y.post_id = %d', $this->postings, $this->postings, $a, $b )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $target ID.
	 */
	public function linking_to( int $target ): array {
		$wpdb = $this->wpdb;

		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT source_id FROM %i WHERE target_id = %d AND is_broken = 0 AND source_id <> %d ORDER BY source_id', $this->links, $target, $target ) )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $anchor Clave del ancla.
	 * @param int    $target ID del destino.
	 */
	public function anchor_uses( string $anchor, int $target ): int {
		$wpdb = $this->wpdb;
		$lang = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT lang FROM %i WHERE post_id = %d', $this->docs, $target ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT DISTINCT source_id, anchor FROM %i WHERE target_id = %d AND is_broken = 0', $this->links, $target ),
			ARRAY_N
		);

		$analyzer = Analyzer::for_language( $lang );
		$sources  = array();
		foreach ( (array) $rows as $row ) {
			if ( $analyzer->phrase_key( (string) $row[1] ) === $anchor ) {
				$sources[ (int) $row[0] ] = true;
			}
		}

		return count( $sources );
	}

	/**
	 * Los mejores resultados sin los excluidos, con los empates por ID ascendente.
	 *
	 * @param array<int, float> $scores  ID → puntuación.
	 * @param int               $limit   Máximo.
	 * @param int[]             $exclude Excluidos.
	 *
	 * @return array<int, float>
	 */
	private static function best( array $scores, int $limit, array $exclude ): array {
		foreach ( $exclude as $id ) {
			unset( $scores[ $id ] );
		}
		uksort(
			$scores,
			static fn( int $a, int $b ): int => array( $scores[ $b ], $a ) <=> array( $scores[ $a ], $b )
		);

		return array_slice( $scores, 0, max( 0, $limit ), true );
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
