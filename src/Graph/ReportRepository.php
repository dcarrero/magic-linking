<?php
/**
 * Consultas del informe de enlaces internos.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

use MagicLinking\Core\PostTitle;
use Generator;
use MagicLinking\Core\Schema;
use MagicLinking\Core\Settings;
use MagicLinking\I18n\Language;
use MagicLinking\Index\Exclusions;
use wpdb;

/**
 * Lee magiclinking_docs unida a las entradas y calcula estados y recuentos del informe.
 */
final class ReportRepository {

	public const FILTERS = array( 'all', 'orphans', 'low', 'over', 'broken', 'excluded' );

	/**
	 * Estado de las filas del filtro «excluded» (no están en el índice: no tienen cifras).
	 */
	public const EXCLUDED_STATUS = 'excluded';

	/**
	 * Columnas por las que se puede ordenar (nombre público → columna SQL).
	 */
	private const ORDER_COLUMNS = array(
		'title'      => 'p.post_title',
		'type'       => 'd.post_type',
		'lang'       => 'd.lang',
		'inbound'    => 'd.inbound',
		'outbound'   => 'd.outbound',
		'external'   => 'd.external',
		'broken'     => 'd.broken',
		'words'      => 'd.word_count',
		'indexed_at' => 'd.indexed_at',
	);

	/**
	 * Conexión.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Ajustes.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Detección de plugins multilingües.
	 *
	 * @var Language
	 */
	private Language $language;

	/**
	 * Constructor.
	 *
	 * @param wpdb     $wpdb     Conexión.
	 * @param Settings $settings Ajustes.
	 * @param Language $language Idioma (por defecto el real).
	 */
	public function __construct( wpdb $wpdb, Settings $settings, ?Language $language = null ) {
		$this->wpdb     = $wpdb;
		$this->settings = $settings;
		$this->language = $language ?? new Language();
	}

	/**
	 * Si el idioma tiene sentido en el informe: hay WPML o Polylang activos, o el índice tiene más de un idioma.
	 *
	 * @param array<int, string>|null $langs Idiomas distintos del índice, si ya se han leído.
	 */
	public function multilingual( ?array $langs = null ): bool {
		if ( $this->language->plugin_active() ) {
			return true;
		}

		if ( null === $langs ) {
			$wpdb  = $this->wpdb;
			$docs  = Schema::table( $wpdb->prefix, 'docs' );
			$langs = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT lang FROM %i LIMIT 2', $docs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
		}

		return count( $langs ) > 1;
	}

	/**
	 * Columnas por las que se puede ordenar.
	 *
	 * @return array<int, string>
	 */
	public static function order_keys(): array {
		return array_keys( self::ORDER_COLUMNS );
	}

	/**
	 * Una página del informe.
	 *
	 * @param array{filter?: string, search?: string, post_type?: string, lang?: string, orderby?: string, order?: string, page?: int, per_page?: int} $args Filtros, orden y página.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function query( array $args ): array {
		if ( 'excluded' === ( $args['filter'] ?? '' ) ) {
			return $this->excluded( $args );
		}

		$wpdb     = $this->wpdb;
		$docs     = Schema::table( $wpdb->prefix, 'docs' );
		$where    = $this->where( $args );
		$order    = $this->order( $args['orderby'] ?? 'inbound', $args['order'] ?? 'asc' );
		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Consulta propia; los fragmentos de WHERE están preparados.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i d INNER JOIN %i p ON p.ID = d.post_id WHERE 1=1' . $where, $docs, $wpdb->posts ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fragmentos preparados.
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Consulta propia; los fragmentos de WHERE están preparados.
			$wpdb->prepare( 'SELECT d.post_id, p.post_title, d.post_type, d.lang, d.inbound, d.outbound, d.external, d.broken, d.word_count, d.indexed_at FROM %i d INNER JOIN %i p ON p.ID = d.post_id WHERE 1=1' . $where . $order . ' LIMIT %d OFFSET %d', $docs, $wpdb->posts, $per_page, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fragmentos preparados y ORDER BY de una lista cerrada.
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = $this->item( $row );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Las entradas excluidas del análisis: publicadas, de un tipo que se analiza y con la marca. No están en el índice,
	 * así que solo tienen título, tipo y enlaces para verlas o editarlas (sin cifras ni idioma), y solo se ordenan por
	 * título o tipo.
	 *
	 * @param array<string, mixed> $args Los de {@see self::query()}.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	private function excluded( array $args ): array {
		$types = $this->settings->post_types();
		if ( array() === $types || ( ! empty( $args['lang'] ) ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$wpdb  = $this->wpdb;
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$where = $wpdb->prepare( "p.post_status = 'publish' AND p.post_type IN ({$in})", $types ) . $wpdb->prepare( ' AND m.meta_key = %s AND m.meta_value = %s', Exclusions::KEY, '1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Marcadores generados.
		if ( ! empty( $args['search'] ) ) {
			$where .= $wpdb->prepare( ' AND p.post_title LIKE %s', '%' . $wpdb->esc_like( (string) $args['search'] ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Preparado.
		}
		if ( ! empty( $args['post_type'] ) ) {
			$where .= $wpdb->prepare( ' AND p.post_type = %s', (string) $args['post_type'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Preparado.
		}

		$column    = 'type' === ( $args['orderby'] ?? '' ) ? 'p.post_type' : 'p.post_title';
		$direction = 'desc' === strtolower( (string) ( $args['order'] ?? '' ) ) ? 'DESC' : 'ASC';
		$per_page  = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset    = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fragmentos preparados.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i p INNER JOIN %i m ON m.post_id = p.ID WHERE ' . $where, $wpdb->posts, $wpdb->postmeta ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fragmentos preparados.
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fragmentos preparados y ORDER BY de una lista cerrada.
			$wpdb->prepare( "SELECT p.ID AS post_id, p.post_title, p.post_type FROM %i p INNER JOIN %i m ON m.post_id = p.ID WHERE {$where} ORDER BY {$column} {$direction}, p.ID ASC LIMIT %d OFFSET %d", $wpdb->posts, $wpdb->postmeta, $per_page, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fragmentos preparados.
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = $this->excluded_item( $row );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Cuántas entradas hay excluidas del análisis (las que se verían en el filtro).
	 */
	private function excluded_count(): int {
		$types = $this->settings->post_types();
		if ( array() === $types ) {
			return 0;
		}

		$wpdb = $this->wpdb;
		$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Marcadores generados.
			$wpdb->prepare( "SELECT COUNT(*) FROM %i p INNER JOIN %i m ON m.post_id = p.ID WHERE p.post_status = 'publish' AND p.post_type IN ({$in}) AND m.meta_key = %s AND m.meta_value = %s", array_merge( array( $wpdb->posts, $wpdb->postmeta ), $types, array( Exclusions::KEY, '1' ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
		);
	}

	/**
	 * Fila de una entrada excluida: sin cifras (no está en el índice).
	 *
	 * @param array<string, mixed> $row Fila de la consulta.
	 *
	 * @return array<string, mixed>
	 */
	private function excluded_item( array $row ): array {
		$id       = (int) $row['post_id'];
		$type     = (string) $row['post_type'];
		$object   = get_post_type_object( $type );
		$title    = PostTitle::plain( (string) $row['post_title'] );
		$can_edit = current_user_can( 'edit_post', $id );

		return array(
			'id'         => $id,
			'title'      => '' !== trim( $title ) ? $title : __( '(no title)', 'magic-linking' ),
			'type'       => $type,
			'type_label' => null !== $object ? (string) $object->labels->singular_name : $type,
			'lang'       => '',
			'inbound'    => 0,
			'outbound'   => 0,
			'external'   => 0,
			'broken'     => 0,
			'words'      => 0,
			'status'     => self::EXCLUDED_STATUS,
			'indexed_at' => '',
			'url'        => (string) get_permalink( $id ),
			'edit_url'   => $can_edit ? (string) get_edit_post_link( $id, 'raw' ) : '',
		);
	}

	/**
	 * Filas del informe de varias entradas, con las cifras de ahora (para refrescarlas tras añadir o deshacer un
	 * enlace), en una sola consulta y en el orden pedido. Las que no están en el análisis se omiten.
	 *
	 * @param array<int, int> $post_ids Entradas.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function rows( array $post_ids ): array {
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		if ( array() === $post_ids ) {
			return array();
		}

		$wpdb         = $this->wpdb;
		$docs         = Schema::table( $wpdb->prefix, 'docs' );
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$found = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tabla propia; los marcadores son %d.
			$wpdb->prepare( "SELECT d.post_id, p.post_title, d.post_type, d.lang, d.inbound, d.outbound, d.external, d.broken, d.word_count, d.indexed_at FROM %i d INNER JOIN %i p ON p.ID = d.post_id WHERE d.post_id IN ({$placeholders})", array_merge( array( $docs, $wpdb->posts ), $post_ids ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
			ARRAY_A
		);

		$by_id = array();
		foreach ( (array) $found as $row ) {
			$by_id[ (int) $row['post_id'] ] = $this->item( $row );
		}

		$items = array();
		foreach ( $post_ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$items[] = $by_id[ $id ];
			}
		}

		return $items;
	}

	/**
	 * Todas las filas del informe con los filtros dados, de 500 en 500.
	 *
	 * @param array<string, mixed> $args Filtros y orden (sin página).
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
	 * Recuentos de la cabecera y de las pestañas de filtro.
	 *
	 * @return array{analyzed: int, orphans: int, low: int, over: int, broken_posts: int, internal_links: int, broken_links: int, excluded: int, types: array<int, array{name: string, label: string, count: int}>, langs: array<int, string>, multilingual: bool}
	 */
	public function summary(): array {
		$wpdb = $this->wpdb;
		$docs = Schema::table( $wpdb->prefix, 'docs' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
			$wpdb->prepare(
				'SELECT COUNT(*) AS analyzed,
					COALESCE(SUM(inbound = 0), 0) AS orphans,
					COALESCE(SUM(inbound > 0 AND inbound < %d), 0) AS low,
					COALESCE(SUM(outbound > GREATEST(1, FLOOR(word_count / %d))), 0) AS over_linked,
					COALESCE(SUM(broken > 0), 0) AS broken_posts,
					COALESCE(SUM(outbound), 0) AS internal_links,
					COALESCE(SUM(broken), 0) AS broken_links
				FROM %i',
				$this->settings->low_inbound_threshold(),
				$this->settings->words_per_link(),
				$docs
			),
			ARRAY_A
		);
		$row = is_array( $row ) ? $row : array();

		$types = array();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
			$wpdb->prepare( 'SELECT post_type, COUNT(*) AS n FROM %i GROUP BY post_type ORDER BY n DESC, post_type ASC', $docs ),
			ARRAY_A
		);
		foreach ( (array) $rows as $type_row ) {
			$object  = get_post_type_object( (string) $type_row['post_type'] );
			$types[] = array(
				'name'  => (string) $type_row['post_type'],
				'label' => null !== $object ? (string) $object->labels->name : (string) $type_row['post_type'],
				'count' => (int) $type_row['n'],
			);
		}

		$langs = array_map(
			'strval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT lang FROM %i ORDER BY lang ASC', $docs ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
		);

		return array(
			'analyzed'       => (int) ( $row['analyzed'] ?? 0 ),
			'orphans'        => (int) ( $row['orphans'] ?? 0 ),
			'low'            => (int) ( $row['low'] ?? 0 ),
			'over'           => (int) ( $row['over_linked'] ?? 0 ),
			'broken_posts'   => (int) ( $row['broken_posts'] ?? 0 ),
			'internal_links' => (int) ( $row['internal_links'] ?? 0 ),
			'broken_links'   => (int) ( $row['broken_links'] ?? 0 ),
			'excluded'       => $this->excluded_count(),
			'types'          => $types,
			'langs'          => $langs,
			'multilingual'   => $this->multilingual( $langs ),
		);
	}

	/**
	 * Fragmento WHERE ya preparado (empieza por « AND »).
	 *
	 * @param array<string, mixed> $args Filtros.
	 */
	private function where( array $args ): string {
		$wpdb  = $this->wpdb;
		$where = '';

		switch ( $args['filter'] ?? 'all' ) {
			case 'orphans':
				$where .= ' AND d.inbound = 0';
				break;
			case 'low':
				$where .= $wpdb->prepare( ' AND d.inbound > 0 AND d.inbound < %d', $this->settings->low_inbound_threshold() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Preparado.
				break;
			case 'over':
				$where .= $wpdb->prepare( ' AND d.outbound > GREATEST(1, FLOOR(d.word_count / %d))', $this->settings->words_per_link() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Preparado.
				break;
			case 'broken':
				$where .= ' AND d.broken > 0';
				break;
		}

		if ( ! empty( $args['search'] ) ) {
			$where .= $wpdb->prepare( ' AND p.post_title LIKE %s', '%' . $wpdb->esc_like( (string) $args['search'] ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Preparado.
		}
		if ( ! empty( $args['post_type'] ) ) {
			$where .= $wpdb->prepare( ' AND d.post_type = %s', (string) $args['post_type'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Preparado.
		}
		if ( ! empty( $args['lang'] ) ) {
			$where .= $wpdb->prepare( ' AND d.lang = %s', (string) $args['lang'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Preparado.
		}

		return $where;
	}

	/**
	 * Cláusula ORDER BY desde una lista cerrada, con la entrada como desempate para paginar sin saltos.
	 *
	 * @param string $orderby Clave pública.
	 * @param string $order   asc o desc.
	 */
	private function order( string $orderby, string $order ): string {
		$column    = self::ORDER_COLUMNS[ $orderby ] ?? self::ORDER_COLUMNS['inbound'];
		$direction = 'desc' === strtolower( $order ) ? 'DESC' : 'ASC';

		return " ORDER BY {$column} {$direction}, d.post_id ASC";
	}

	/**
	 * Una fila del informe lista para la API y el CSV.
	 *
	 * @param array<string, mixed> $row Fila de la consulta.
	 *
	 * @return array<string, mixed>
	 */
	private function item( array $row ): array {
		$id       = (int) $row['post_id'];
		$inbound  = (int) $row['inbound'];
		$outbound = (int) $row['outbound'];
		$words    = (int) $row['word_count'];
		$type     = (string) $row['post_type'];
		$object   = get_post_type_object( $type );
		$title    = PostTitle::plain( (string) $row['post_title'] );
		$can_edit = current_user_can( 'edit_post', $id );

		return array(
			'id'         => $id,
			'title'      => '' !== trim( $title ) ? $title : __( '(no title)', 'magic-linking' ),
			'type'       => $type,
			'type_label' => null !== $object ? (string) $object->labels->singular_name : $type,
			'lang'       => (string) $row['lang'],
			'inbound'    => $inbound,
			'outbound'   => $outbound,
			'external'   => (int) $row['external'],
			'broken'     => (int) $row['broken'],
			'words'      => $words,
			'status'     => LinkStatus::of( $inbound, $outbound, $words, $this->settings->low_inbound_threshold(), $this->settings->words_per_link() ),
			'indexed_at' => (string) mysql_to_rfc3339( (string) $row['indexed_at'] . '' ),
			'url'        => (string) get_permalink( $id ),
			'edit_url'   => $can_edit ? (string) get_edit_post_link( $id, 'raw' ) : '',
		);
	}
}
