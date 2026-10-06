<?php
/**
 * Mide el indexado por lotes (grafo + índice léxico, F1-04): un lote y el proceso completo, en la propia petición.
 *
 * Uso: wp eval-file tests/perf/measure.php [limite_entradas]   (por defecto 2000)
 * El índice se vacía antes. Imprime entradas/s (de las dos fases: el recorrido con conteo y el pesado) y memoria pico.
 * Sin límite (el defecto, 2000, solo es para pruebas rápidas) se miden las 10.000 entradas del criterio de F1-04: `wp eval-file tests/perf/seed.php 10000` y `wp eval-file tests/perf/measure.php 10000`.
 *
 * @package MagicLinking
 */

// phpcs:disable -- Script de banco de pruebas, no forma parte del plugin.

use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\Jobs\Jobs;

global $wpdb;
$limit = isset( $args[0] ) ? (int) $args[0] : 2000;
foreach ( array( 'docs', 'links', 'jobs', 'terms', 'postings' ) as $t ) {
	$wpdb->query( 'TRUNCATE ' . Schema::table( $wpdb->prefix, $t ) );
}

$jobs = Plugin::container()->get( Jobs::class );
$job  = $jobs->start_index( 0, false );
$id   = $job['id'];

printf( "  memoria antes de empezar: %.1f MB\n", memory_get_usage( true ) / 1048576 );
$t0    = microtime( true );
$steps = 0;
do {
	$more = $jobs->step( $id );
	++$steps;
	$j = $jobs->repository()->get( $id );
	printf( "  paso %d: hechas=%d memoria=%.1f MB pico=%.1f MB\n", $steps, $j['done'], memory_get_usage( true ) / 1048576, memory_get_peak_usage( true ) / 1048576 );
} while ( $more && ( $j['done'] < $limit || 'weigh' === ( $j['params']['phase'] ?? '' ) ) );

$dt = microtime( true ) - $t0;
$lex = Plugin::container()->get( \MagicLinking\Index\TableRepository::class );
printf( "léxico: entradas=%d términos=%d postings=%d\n", $lex->count_lexical(), $lex->count_rows( 'terms' ), $lex->count_rows( 'postings' ) );
printf( "entradas=%d pasos=%d tiempo=%.1fs entradas/s=%.1f pico=%.1f MB consultas=%d\n", $j['done'], $steps, $dt, $j['done'] / $dt, memory_get_peak_usage( true ) / 1048576, $wpdb->num_queries );
