<?php
/**
 * Órdenes de WP-CLI.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Cli;

use MagicLinking\Graph\BrokenRepository;
use MagicLinking\Graph\IndexOutcome;
use MagicLinking\Graph\ReportRepository;
use MagicLinking\Jobs\JobRepository;
use MagicLinking\Jobs\Jobs;
use MagicLinking\Report\CsvExporter;
use WP_CLI;

/**
 * Analiza el sitio y muestra el informe de enlaces internos desde la terminal.
 *
 * Con `--user=<admin>` el informe incluye los enlaces de edición; sin usuario esas columnas van vacías.
 */
final class Command {

	/**
	 * Procesos.
	 *
	 * @var Jobs
	 */
	private Jobs $jobs;

	/**
	 * Informe.
	 *
	 * @var ReportRepository
	 */
	private ReportRepository $report;

	/**
	 * Rotos.
	 *
	 * @var BrokenRepository
	 */
	private BrokenRepository $broken;

	/**
	 * Constructor.
	 *
	 * @param Jobs             $jobs   Procesos.
	 * @param ReportRepository $report Consulta del informe.
	 * @param BrokenRepository $broken Consulta de rotos.
	 */
	public function __construct( Jobs $jobs, ReportRepository $report, BrokenRepository $broken ) {
		$this->jobs   = $jobs;
		$this->report = $report;
		$this->broken = $broken;
	}

	/**
	 * Analiza los enlaces internos del sitio (o de una entrada) y actualiza el informe.
	 *
	 * No modifica ningún contenido. Sin opciones recorre todas las entradas publicadas de los
	 * tipos elegidos en los ajustes, en la propia orden y con barra de progreso, pero salta las
	 * que no han cambiado desde el último análisis. La primera vez construye también el índice léxico (dos pasadas). Si ya hay un proceso en marcha, lo continúa.
	 *
	 * ## OPTIONS
	 *
	 * [--post=<id>]
	 * : Analiza solo esta entrada.
	 *
	 * [--force]
	 * : Vuelve a analizar todas las entradas desde cero, aunque su contenido no haya cambiado, y reconstruye el índice léxico entero (df incluido).
	 *
	 * [--background]
	 * : Deja el proceso en cola de Action Scheduler y termina. Se ejecuta con WP-Cron o con `wp action-scheduler run --group=magic-linking`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp magic-linking index
	 *     wp magic-linking index --force
	 *     wp magic-linking index --post=123 --force
	 *     wp magic-linking index --background
	 *
	 * @param array<int, string>   $args       Argumentos.
	 * @param array<string, mixed> $assoc_args Opciones.
	 */
	public function index( array $args, array $assoc_args ): void {
		unset( $args );

		if ( isset( $assoc_args['post'] ) ) {
			$this->index_one( absint( $assoc_args['post'] ), ! empty( $assoc_args['force'] ) );
			return;
		}

		$background = ! empty( $assoc_args['background'] );
		$job        = $this->jobs->start_index( 0, $background, ! empty( $assoc_args['force'] ), true, ! empty( $assoc_args['force'] ) );

		if ( JobRepository::PAUSED === $job['status'] ) {
			WP_CLI::error(
				sprintf(
					/* translators: %d: process ID. */
					__( 'Process %1$d is paused. Resume it with: wp magic-linking job resume %2$d', 'magic-linking' ),
					$job['id'],
					$job['id']
				)
			);
		}

		if ( $background ) {
			WP_CLI::success(
				sprintf(
					/* translators: %d: process ID. */
					__( 'Process %d queued. It will run in the background with Action Scheduler.', 'magic-linking' ),
					$job['id']
				)
			);
			return;
		}

		$bar  = \WP_CLI\Utils\make_progress_bar( __( 'Analyzing entries', 'magic-linking' ), max( 1, $job['total'] ) );
		$last = $job['done'];
		$this->jobs->run_to_completion(
			$job['id'],
			static function ( int $done ) use ( $bar, &$last ): void {
				$bar->tick( max( 0, $done - $last ) );
				$last = $done;
			}
		);
		$bar->finish();

		$final = $this->jobs->repository()->get( $job['id'] );
		if ( null !== $final && JobRepository::DONE === $final['status'] ) {
			WP_CLI::log(
				sprintf(
					/* translators: 1: new or modified entries, 2: unchanged entries skipped. */
					__( '%1$d new or modified, %2$d unchanged and skipped.', 'magic-linking' ),
					(int) ( $final['params']['changed'] ?? 0 ),
					(int) ( $final['params']['same'] ?? 0 )
				)
			);
			WP_CLI::success(
				sprintf(
					/* translators: %d: number of entries. */
					_n( '%d entry analyzed.', '%d entries analyzed.', $final['done'], 'magic-linking' ),
					$final['done']
				)
			);
			return;
		}

		WP_CLI::error(
			null !== $final && '' !== $final['error']
				? $final['error']
				: __( 'The process did not finish. Check its state with: wp magic-linking status', 'magic-linking' )
		);
	}

	/**
	 * Muestra el estado del índice y del último proceso, con las cifras del informe.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Formato de salida.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp magic-linking status
	 *     wp magic-linking status --format=json
	 *
	 * @param array<int, string>   $args       Argumentos.
	 * @param array<string, mixed> $assoc_args Opciones.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );

		$status  = $this->jobs->status();
		$summary = $this->report->summary();
		$job     = $status['job'] ?? $status['last_job'];

		$rows = array(
			'indexed'        => $status['indexed'],
			'lexical'        => $status['lexical'],
			'eligible'       => $status['eligible'],
			'pending'        => $status['pending'],
			'process'        => null === $job ? 'none' : sprintf( '#%d %s (%d/%d)', $job['id'], $job['status'], $job['done'], $job['total'] ),
			'orphans'        => $summary['orphans'],
			'under_linked'   => $summary['low'],
			'over_linked'    => $summary['over'],
			'broken_links'   => $summary['broken_links'],
			'internal_links' => $summary['internal_links'],
		);

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'table' === $format ) {
			$items = array();
			foreach ( $rows as $key => $value ) {
				$items[] = array(
					'field' => $key,
					'value' => (string) $value,
				);
			}
			\WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, array( $rows ), array_keys( $rows ) );
	}

	/**
	 * Muestra el informe de enlaces internos.
	 *
	 * ## OPTIONS
	 *
	 * [--filter=<filter>]
	 * : Qué entradas listar.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - orphans
	 *   - low
	 *   - over
	 *   - broken
	 * ---
	 *
	 * [--post_type=<type>]
	 * : Solo un tipo de contenido.
	 *
	 * [--lang=<code>]
	 * : Solo un idioma (código corto: es, en...).
	 *
	 * [--search=<text>]
	 * : Solo entradas cuyo título contiene el texto.
	 *
	 * [--orderby=<column>]
	 * : Columna de orden: title, type, lang, inbound, outbound, external, broken, words o indexed_at.
	 * ---
	 * default: inbound
	 * ---
	 *
	 * [--order=<order>]
	 * : Sentido del orden.
	 * ---
	 * default: asc
	 * options:
	 *   - asc
	 *   - desc
	 * ---
	 *
	 * [--format=<format>]
	 * : Formato de salida.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp magic-linking report --filter=orphans
	 *     wp magic-linking report --format=csv > informe.csv
	 *     wp magic-linking report --filter=broken --format=json
	 *
	 * @param array<int, string>   $args       Argumentos.
	 * @param array<string, mixed> $assoc_args Opciones.
	 */
	public function report( array $args, array $assoc_args ): void {
		unset( $args );

		$filter  = (string) ( $assoc_args['filter'] ?? 'all' );
		$orderby = (string) ( $assoc_args['orderby'] ?? 'inbound' );
		$format  = (string) ( $assoc_args['format'] ?? 'table' );

		if ( ! in_array( $filter, ReportRepository::FILTERS, true ) ) {
			WP_CLI::error( __( 'Invalid filter. Use: all, orphans, low, over or broken.', 'magic-linking' ) );
		}
		if ( ! in_array( $orderby, ReportRepository::order_keys(), true ) ) {
			WP_CLI::error( __( 'Invalid column for --orderby.', 'magic-linking' ) );
		}

		$query = array(
			'filter'    => $filter,
			'search'    => sanitize_text_field( (string) ( $assoc_args['search'] ?? '' ) ),
			'post_type' => sanitize_key( (string) ( $assoc_args['post_type'] ?? '' ) ),
			'lang'      => sanitize_key( (string) ( $assoc_args['lang'] ?? '' ) ),
			'orderby'   => $orderby,
			'order'     => 'desc' === ( $assoc_args['order'] ?? '' ) ? 'desc' : 'asc',
		);

		if ( 'count' === $format ) {
			WP_CLI::line( (string) $this->report->query( $query + array( 'per_page' => 1 ) )['total'] );
			return;
		}

		if ( 'csv' === $format ) {
			$multilingual = $this->report->multilingual();
			$this->stream_csv( CsvExporter::report_columns( $multilingual ), CsvExporter::report_rows( $this->report, $query, $multilingual ) );
			return;
		}

		$fields = array( 'id', 'title', 'type', 'lang', 'inbound', 'outbound', 'external', 'broken', 'status' );
		\WP_CLI\Utils\format_items( $format, $this->report->each( $query ), $fields );
	}

	/**
	 * Lista los enlaces internos rotos.
	 *
	 * ## OPTIONS
	 *
	 * [--post=<id>]
	 * : Solo los de esta entrada.
	 *
	 * [--format=<format>]
	 * : Formato de salida.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp magic-linking broken
	 *     wp magic-linking broken --post=123 --format=csv
	 *
	 * @param array<int, string>   $args       Argumentos.
	 * @param array<string, mixed> $assoc_args Opciones.
	 */
	public function broken( array $args, array $assoc_args ): void {
		unset( $args );

		$query  = array( 'post_id' => absint( $assoc_args['post'] ?? 0 ) );
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( 'count' === $format ) {
			WP_CLI::line( (string) $this->broken->query( $query + array( 'per_page' => 1 ) )['total'] );
			return;
		}

		if ( 'csv' === $format ) {
			$this->stream_csv( CsvExporter::BROKEN_COLUMNS, CsvExporter::broken_rows( $this->broken, $query ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $this->broken->each( $query ), array( 'source_id', 'source_title', 'url', 'anchor', 'reason' ) );
	}

	/**
	 * Gestiona el proceso de indexado: lista, pausa, reanuda o cancela.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Qué hacer.
	 * ---
	 * options:
	 *   - list
	 *   - pause
	 *   - resume
	 *   - cancel
	 * ---
	 *
	 * [<id>]
	 * : ID del proceso (por defecto, el que está en marcha).
	 *
	 * ## EXAMPLES
	 *
	 *     wp magic-linking job list
	 *     wp magic-linking job pause
	 *     wp magic-linking job resume 4
	 *
	 * @param array<int, string>   $args       Argumentos.
	 * @param array<string, mixed> $assoc_args Opciones.
	 */
	public function job( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		$action = $args[0] ?? 'list';

		if ( 'list' === $action ) {
			$status = $this->jobs->status();
			$items  = array();
			foreach ( array_filter( array( $status['job'], $status['last_job'] ) ) as $job ) {
				$items[ $job['id'] ] = array(
					'id'         => $job['id'],
					'status'     => $job['status'],
					'done'       => $job['done'],
					'total'      => $job['total'],
					'updated_at' => $job['updated_at'],
				);
			}
			\WP_CLI\Utils\format_items( 'table', array_values( $items ), array( 'id', 'status', 'done', 'total', 'updated_at' ) );
			return;
		}

		if ( ! in_array( $action, array( 'pause', 'resume', 'cancel' ), true ) ) {
			WP_CLI::error( __( 'Unknown action. Use: list, pause, resume or cancel.', 'magic-linking' ) );
		}

		$id = isset( $args[1] ) ? absint( $args[1] ) : (int) ( $this->jobs->status()['job']['id'] ?? 0 );
		if ( 0 === $id || null === $this->jobs->repository()->get( $id ) ) {
			WP_CLI::error( __( 'There is no such process.', 'magic-linking' ) );
		}

		switch ( $action ) {
			case 'pause':
				$ok = $this->jobs->pause( $id );
				break;
			case 'resume':
				$ok = $this->jobs->resume( $id );
				break;
			default:
				$ok = $this->jobs->cancel( $id );
		}

		if ( ! $ok ) {
			WP_CLI::error( __( 'That process cannot be changed in its current state.', 'magic-linking' ) );
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: process ID, 2: new state. */
				__( 'Process %1$d is now %2$s.', 'magic-linking' ),
				$id,
				(string) $this->jobs->repository()->get( $id )['status']
			)
		);
	}

	/**
	 * Analiza una entrada.
	 *
	 * @param int  $post_id ID.
	 * @param bool $force   Volver a analizar aunque no haya cambiado.
	 */
	private function index_one( int $post_id, bool $force ): void {
		if ( 0 === $post_id || null === get_post( $post_id ) ) {
			WP_CLI::error( __( 'That entry does not exist.', 'magic-linking' ) );
		}

		$outcome = $this->jobs->index_one( $post_id, $force );

		switch ( $outcome->status ) {
			case IndexOutcome::INDEXED:
				WP_CLI::success( __( 'Entry analyzed.', 'magic-linking' ) );
				break;
			case IndexOutcome::UNCHANGED:
				WP_CLI::success( __( 'Nothing changed since the last analysis. Use --force to analyze it again.', 'magic-linking' ) );
				break;
			case IndexOutcome::REMOVED:
				WP_CLI::success( __( 'The entry is not published or is not of a chosen content type: it was taken out of the report.', 'magic-linking' ) );
				break;
			default:
				WP_CLI::warning( __( 'The entry is not published or is not of a chosen content type, so it is not analyzed.', 'magic-linking' ) );
		}
	}

	/**
	 * Escribe CSV en la salida estándar.
	 *
	 * @param array<int, string>               $header Columnas.
	 * @param iterable<array<int, string|int>> $rows   Filas.
	 */
	private function stream_csv( array $header, iterable $rows ): void {
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Salida estándar.
		if ( false === $out ) {
			WP_CLI::error( __( 'Could not open the output.', 'magic-linking' ) );
		}

		CsvExporter::write( $out, $header, $rows );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Salida estándar.
	}
}
