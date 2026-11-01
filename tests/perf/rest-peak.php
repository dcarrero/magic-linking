<?php
/**
 * Pico de memoria de UNA petición REST en un proceso PHP nuevo (F1-44, presupuesto de `09 §4`).
 *
 * Uso (dentro del contenedor, sin WP-CLI): php tests/perf/rest-peak.php <ruta> <post_id>
 * Carga WordPress como lo hace una petición web (wp-load.php), con el plugin y sin las cachés de ningún proceso anterior,
 * ejecuta la ruta REST con el usuario administrador y escribe una línea `ML_PEAK_JSON:{…}` (con avisos de WP_DEBUG por stdout puede haber otras líneas) con el pico absoluto de memoria (MB) y las consultas de la petición. Con la
 * ruta `/wp/v2/types` mide la base de WordPress sin cálculo del plugin. Lo llama `tests/perf/rest-memory.php`.
 *
 * @package MagicLinking
 */

// phpcs:disable -- Script de banco de pruebas, no forma parte del plugin.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
$ml_route = $argv[1] ?? '/wp/v2/types';
$ml_post  = (int) ( $argv[2] ?? 0 );
$load     = getenv( 'ABSPATH_LOAD' ) ?: '/var/www/html/wp-load.php';

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/wp-json' . $ml_route;
define( 'WP_USE_THEMES', false );
require $load;

$base_boot = memory_get_peak_usage();
$admin     = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 );
wp_set_current_user( $admin );
$queries_before = get_num_queries();
$request = new WP_REST_Request( 'GET', $ml_route );
if ( $ml_post ) {
	$request->set_param( 'post_id', $ml_post );
}
$response = rest_do_request( $request );
$json     = wp_json_encode( rest_get_server()->response_to_data( $response, false ) );

echo 'ML_PEAK_JSON:' . wp_json_encode(
	array(
		'status' => $response->get_status(),
		'queries' => get_num_queries() - $queries_before,
		'boot'   => round( $base_boot / 1048576, 2 ),
		'peak'   => round( memory_get_peak_usage() / 1048576, 2 ),
		'bytes'  => strlen( (string) $json ),
	)
) . "\n";
