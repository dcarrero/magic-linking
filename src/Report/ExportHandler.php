<?php
/**
 * Descarga del CSV desde el administrador (admin-post.php).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Report;

use MagicLinking\Core\Module;
use MagicLinking\Graph\BrokenRepository;
use MagicLinking\Graph\ReportRepository;
use MagicLinking\History\Reader;

/**
 * Sirve el CSV de la vista actual del informe o de los enlaces rotos.
 *
 * Solo para quien puede editar entradas y con nonce; solo en admin-post.php, nunca en el front-end.
 */
final class ExportHandler implements Module {

	public const ACTION = 'magiclinking_export';

	/**
	 * Informe.
	 *
	 * @var ReportRepository
	 */
	private ReportRepository $report;

	/**
	 * Enlaces rotos.
	 *
	 * @var BrokenRepository
	 */
	private BrokenRepository $broken;

	/**
	 * Historial.
	 *
	 * @var Reader
	 */
	private Reader $reader;

	/**
	 * Constructor.
	 *
	 * @param ReportRepository $report Consulta del informe.
	 * @param BrokenRepository $broken Consulta de rotos.
	 * @param Reader           $reader Lectura del Historial.
	 */
	public function __construct( ReportRepository $report, BrokenRepository $broken, Reader $reader ) {
		$this->report = $report;
		$this->broken = $broken;
		$this->reader = $reader;
	}

	/**
	 * Engancha admin-post.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * URL de descarga con su nonce, sin filtros (la pantalla los añade).
	 */
	public static function url(): string {
		// Sin escapar (wp_nonce_url() devuelve «&amp;»): la dirección la usa JavaScript, no el HTML.
		return add_query_arg( '_wpnonce', wp_create_nonce( self::ACTION ), admin_url( 'admin-post.php?action=' . self::ACTION ) );
	}

	/**
	 * Manejador de admin-post: comprueba permisos y nonce y sirve el fichero.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to export this data.', 'magic-linking' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		$get      = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce comprobado arriba.
		$dataset  = in_array( $get['dataset'] ?? '', array( 'broken', 'history' ), true ) ? (string) $get['dataset'] : 'report';
		$names    = array(
			'broken'  => 'broken-links',
			'history' => 'history',
			'report'  => 'report',
		);
		$filename = sprintf( 'magic-linking-%s-%s.csv', $names[ $dataset ], gmdate( 'Y-m-d' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Salida estándar.
		if ( false !== $out ) {
			$this->write( $out, $dataset, $get );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Salida estándar.
		}

		exit;
	}

	/**
	 * Escribe el CSV en un flujo.
	 *
	 * @param resource             $stream  Destino.
	 * @param string               $dataset `report`, `broken` o `history`.
	 * @param array<string, mixed> $query   Filtros (los mismos parámetros que la API).
	 */
	public function write( $stream, string $dataset, array $query ): void {
		if ( 'history' === $dataset ) {
			$status = (string) ( $query['status'] ?? '' );
			$origin = (string) ( $query['origin'] ?? '' );

			CsvExporter::write(
				$stream,
				CsvExporter::HISTORY_COLUMNS,
				$this->reader->export_rows(
					get_current_user_id(),
					array(
						'status' => in_array( $status, array( 'active', 'undone' ), true ) ? $status : '',
						'origin' => in_array( $origin, array( 'plugin', 'editor' ), true ) ? $origin : '',
						'ai'     => rest_sanitize_boolean( (string) ( $query['ai'] ?? '' ) ),
						'search' => sanitize_text_field( (string) ( $query['search'] ?? '' ) ),
					)
				),
				true
			);
			return;
		}

		if ( 'broken' === $dataset ) {
			CsvExporter::write(
				$stream,
				CsvExporter::BROKEN_COLUMNS,
				CsvExporter::broken_rows(
					$this->broken,
					array(
						'post_id' => absint( $query['post_id'] ?? 0 ),
						'search'  => sanitize_text_field( (string) ( $query['search'] ?? '' ) ),
					)
				),
				true
			);
			return;
		}

		$filter  = (string) ( $query['filter'] ?? 'all' );
		$orderby = (string) ( $query['orderby'] ?? 'inbound' );

		$multilingual = $this->report->multilingual();

		CsvExporter::write(
			$stream,
			CsvExporter::report_columns( $multilingual ),
			CsvExporter::report_rows(
				$this->report,
				array(
					'filter'    => in_array( $filter, ReportRepository::FILTERS, true ) ? $filter : 'all',
					'search'    => sanitize_text_field( (string) ( $query['search'] ?? '' ) ),
					'post_type' => sanitize_key( (string) ( $query['post_type'] ?? '' ) ),
					'lang'      => sanitize_key( (string) ( $query['lang'] ?? '' ) ),
					'orderby'   => in_array( $orderby, ReportRepository::order_keys(), true ) ? $orderby : 'inbound',
					'order'     => 'desc' === ( $query['order'] ?? '' ) ? 'desc' : 'asc',
				),
				$multilingual
			),
			true
		);
	}
}
