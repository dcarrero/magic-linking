<?php
/**
 * Consultas del informe de enlaces internos.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

use Generator;
use MagicLinking\Core\Schema;
use MagicLinking\Core\Settings;
use MagicLinking\I18n\Language;
use wpdb;

/**
 * Lee magiclinking_docs unida a las entradas y calcula estados y recuentos del informe.
 */
final class ReportRepository {

	public const FILTERS = array( 'all', 'orphans', 'low', 'over', 'broken' );

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
	 * @return array{analyzed: int, orphans: int, low: int, over: int, broken_posts: int, internal_links: int, broken_links: int, types: array<int, array{name: string, label: string, count: int}>, langs: array<int, string>, multilingual: bool}
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
		$title    = html_entity_decode( wp_strip_all_tags( (string) $row['post_title'] ), ENT_QUOTES, 'UTF-8' );
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
