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

use MagicLinking\Core\Schema;

global $wpdb;

require_once __DIR__ . '/budget.php';

$samples = isset( $args[0] ) && is_numeric( $args[0] ) ? (int) $args[0] : 20;
$strict  = in_array( 'strict', $args, true );

$ids = array_map(
	'intval',
	$wpdb->get_col( $wpdb->prepare( "SELECT d.post_id FROM %i d JOIN %i p ON p.ID = d.post_id WHERE d.doc_len > 0 AND p.post_status = 'publish'", Schema::table( $wpdb->prefix, 'docs' ), $wpdb->posts ) )
);
mt_srand( 20261101 );
shuffle( $ids );
$sample = array_slice( $ids, 0, $samples );
if ( array() === $sample ) {
	fwrite( STDERR, "No hay entradas publicadas e indexadas que medir: genera el sitio (`seed.php`) y ejecuta `wp magic-linking index`.\n" );
	exit( 1 );
}

$script = __DIR__ . '/rest-peak.php';
$peak   = static function ( string $route, int $id ) use ( $script ): array {
	// Con WP_DEBUG los avisos salen por stdout, delante o detrás del resultado: se busca la línea marcada.
	$out  = (string) shell_exec( 'ABSPATH_LOAD=' . escapeshellarg( ABSPATH . 'wp-load.php' ) . ' ' . escapeshellarg( PHP_BINARY ) . ' -d display_errors=0 ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $route ) . ' ' . $id . ' 2>/dev/null' );
	$data = null;
	foreach ( explode( "\n", $out ) as $line ) {
		if ( str_starts_with( $line, 'ML_PEAK_JSON:' ) ) {
			$data = json_decode( substr( $line, strlen( 'ML_PEAK_JSON:' ) ), true );
		}
	}
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

printf( "memoria por petición: pico total=%.1f MB (límite %.0f), añadido=%.1f MB (límite %.0f)\n", $worst, MAGICLINKING_PERF_TOTAL_MB, $worst - $base, MAGICLINKING_PERF_OWN_MB );
if ( $strict && ( $worst > MAGICLINKING_PERF_TOTAL_MB || $worst - $base > MAGICLINKING_PERF_OWN_MB ) ) {
	fwrite( STDERR, "Presupuesto de memoria superado.\n" );
	exit( 1 );
}
