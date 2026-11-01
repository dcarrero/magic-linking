<?php
/**
 * Pico de memoria por petición REST real (F1-44, presupuesto de `09 §4`).
 *
 * Uso: wp eval-file tests/perf/rest-memory.php [muestras] [strict]   (por defecto 20 entradas al azar, semilla fija)
 * Cada medida es un proceso PHP nuevo (`rest-peak.php`) que carga WordPress como una petición web, sin la base de WP-CLI y
 * sin cachés de otra petición. Imprime la base de WordPress (la ruta `/wp/v2/types`, sin cálculo del plugin), el pico
 * máximo de las sugerencias salientes y entrantes y lo que añade el plugin (pico menos base). Con `strict` sale con error
 * si el pico total pasa de 64 MB o lo que añade el plugin pasa de 24 MB.
 *
 * @package MagicLinking
 */

// phpcs:disable -- Script de banco de pruebas, no forma parte del plugin.

use MagicLinking\Core\Schema;

global $wpdb;

const ML_TOTAL_BUDGET = 64.0;
const ML_OWN_BUDGET   = 24.0;

$samples = isset( $args[0] ) && is_numeric( $args[0] ) ? (int) $args[0] : 20;
$strict  = in_array( 'strict', $args, true );

$docs = Schema::table( $wpdb->prefix, 'docs' );
$ids  = array_map( 'intval', $wpdb->get_col( "SELECT d.post_id FROM {$docs} d JOIN {$wpdb->posts} p ON p.ID = d.post_id WHERE d.doc_len > 0 AND p.post_status = 'publish'" ) );
mt_srand( 20261101 );
shuffle( $ids );
$sample = array_slice( $ids, 0, $samples );

$script = __DIR__ . '/rest-peak.php';
$peak   = static function ( string $route, int $id ) use ( $script ): array {
	// Solo stdout: con WP_DEBUG un aviso (Deprecated, Notice) sale por stderr o delante del JSON. El JSON es la última línea.
	$out   = (string) shell_exec( 'ABSPATH_LOAD=' . escapeshellarg( ABSPATH . 'wp-load.php' ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $route ) . ' ' . $id . ' 2>/dev/null' );
	$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $out ) ), static fn( string $line ): bool => '' !== $line ) );
	$data  = json_decode( (string) end( $lines ), true );
	if ( ! is_array( $data ) || 200 !== ( $data['status'] ?? 0 ) ) {
		fwrite( STDERR, "rest-peak {$route} {$id}: " . substr( $out, -300 ) . "\n" );
		exit( 1 );
	}

	return array( (float) $data['peak'], (int) $data['queries'] );
};

$base = 0.0;
for ( $i = 0; $i < 3; $i++ ) {
	$base = max( $base, $peak( '/wp/v2/types', 0 )[0] );
}
printf( "base de WordPress (petición REST sin cálculo del plugin)=%.1f MB\n", $base );

$worst = 0.0;
foreach ( array( 'outbound', 'inbound' ) as $direction ) {
	$max     = 0.0;
	$queries = array();
	foreach ( $sample as $id ) {
		list( $mb, $n ) = $peak( '/magic-linking/v1/suggestions/' . $direction, $id );
		$max            = max( $max, $mb );
		$queries[]      = $n;
	}
	sort( $queries );
	$worst = max( $worst, $max );
	printf( "REST %-8s pico total=%.1f MB  añadido por el plugin=%.1f MB  consultas(mediana)=%d  (%d peticiones)\n", $direction, $max, $max - $base, $queries[ intdiv( count( $queries ), 2 ) ], count( $sample ) );
}

printf( "memoria por petición: pico total=%.1f MB (límite %.0f), añadido=%.1f MB (límite %.0f)\n", $worst, ML_TOTAL_BUDGET, $worst - $base, ML_OWN_BUDGET );
if ( $strict && ( $worst > ML_TOTAL_BUDGET || $worst - $base > ML_OWN_BUDGET ) ) {
	fwrite( STDERR, "Presupuesto de memoria superado.\n" );
	exit( 1 );
}
