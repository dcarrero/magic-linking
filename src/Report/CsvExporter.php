<?php
/**
 * Exportación a CSV del informe y de los enlaces rotos.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Report;

use Generator;
use MagicLinking\Graph\BrokenRepository;
use MagicLinking\Graph\ReportRepository;

/**
 * Escribe CSV en UTF-8 con cabeceras fijas en inglés (para que no cambien con el idioma del
 * administrador) y neutraliza las celdas que una hoja de cálculo tomaría por una fórmula.
 */
final class CsvExporter {

	/**
	 * Columnas del informe.
	 */
	public const REPORT_COLUMNS = array( 'id', 'title', 'type', 'language', 'inbound', 'outbound', 'external', 'broken', 'status', 'words', 'indexed_at', 'url' );

	/**
	 * Columnas del informe según si el sitio es multilingüe (si no, sin «language»).
	 *
	 * @param bool $multilingual Si se incluye el idioma.
	 *
	 * @return array<int, string>
	 */
	public static function report_columns( bool $multilingual ): array {
		return $multilingual ? self::REPORT_COLUMNS : array_values( array_diff( self::REPORT_COLUMNS, array( 'language' ) ) );
	}

	/**
	 * Columnas de la lista de enlaces rotos.
	 */
	public const BROKEN_COLUMNS = array( 'source_id', 'source_title', 'url', 'anchor', 'reason', 'edit_url' );

	/**
	 * Escribe la cabecera y las filas.
	 *
	 * @param resource                         $stream Destino abierto para escritura.
	 * @param array<int, string>               $header Nombres de columna.
	 * @param iterable<array<int, string|int>> $rows   Filas.
	 * @param bool                             $bom    Añadir la marca UTF-8 para que Excel reconozca los acentos.
	 */
	public static function write( $stream, array $header, iterable $rows, bool $bom = false ): void {
		if ( $bom ) {
			fwrite( $stream, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Flujo de salida propio.
		}

		self::put( $stream, $header );
		foreach ( $rows as $row ) {
			self::put( $stream, array_map( array( self::class, 'cell' ), $row ) );
		}
	}

	/**
	 * Filas del informe completo con los filtros dados.
	 *
	 * @param ReportRepository     $repository Consulta.
	 * @param array<string, mixed> $args       Filtros y orden.
	 * @param bool                 $multilingual Si se incluye la columna de idioma.
	 *
	 * @return Generator<int, array<int, string|int>>
	 */
	public static function report_rows( ReportRepository $repository, array $args, bool $multilingual = true ): Generator {
		foreach ( $repository->each( $args ) as $item ) {
			$row = array(
				$item['id'],
				$item['title'],
				$item['type'],
				$item['lang'],
				$item['inbound'],
				$item['outbound'],
				$item['external'],
				$item['broken'],
				$item['status'],
				$item['words'],
				$item['indexed_at'],
				$item['url'],
			);
			if ( ! $multilingual ) {
				unset( $row[3] );
				$row = array_values( $row );
			}

			yield $row;
		}//end foreach
	}

	/**
	 * Filas de los enlaces rotos con los filtros dados.
	 *
	 * @param BrokenRepository     $repository Consulta.
	 * @param array<string, mixed> $args       Filtros.
	 *
	 * @return Generator<int, array<int, string|int>>
	 */
	public static function broken_rows( BrokenRepository $repository, array $args ): Generator {
		foreach ( $repository->each( $args ) as $item ) {
			yield array( $item['source_id'], $item['source_title'], $item['url'], $item['anchor'], $item['reason'], $item['edit_url'] );
		}
	}

	/**
	 * Neutraliza una celda que empieza como una fórmula (=, +, -, @, tabulador o retorno).
	 *
	 * @param string|int $value Valor.
	 *
	 * @return string|int
	 */
	public static function cell( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}

		$value = (string) $value;

		return '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * Escribe una fila.
	 *
	 * @param resource               $stream Destino.
	 * @param array<int, string|int> $row    Celdas.
	 */
	private static function put( $stream, array $row ): void {
		fputcsv( $stream, $row, ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Flujo de salida propio.
	}
}
