<?php
/**
 * Mide las sugerencias salientes y entrantes sobre el índice persistente (F1-07, presupuesto de `09 §4`).
 *
 * Uso: wp eval-file tests/perf/suggest.php [muestras] [strict]   (por defecto 100 entradas al azar, semilla fija)
 * Hace falta el sitio sintético ya indexado: `wp eval-file tests/perf/seed.php 10000` y `wp magic-linking index`.
 * Imprime p50, p95 y máximo de cada dirección, consultas por petición y memoria (pico total y añadida). Con `strict`
 * (CI) sale con error si el p95 de salientes pasa de 500 ms, el de entrantes de 800 ms o la memoria
 * que añade el cálculo de 32 MB, o la capa REST añade más de 20 ms al p95 (ver el comentario del final).
 *
 * @package MagicLinking
 */

// phpcs:disable -- Script de banco de pruebas, no forma parte del plugin.

use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\Index\Suggestions;
use MagicLinking\Index\TableRepository;

global $wpdb;

$samples = isset( $args[0] ) && is_numeric( $args[0] ) ? (int) $args[0] : 100;
$strict  = in_array( 'strict', $args, true );

$service    = Plugin::container()->get( Suggestions::class );
$repository = Plugin::container()->get( TableRepository::class );
if ( ! $service->ready() ) {
	fwrite( STDERR, "El índice léxico no está construido: ejecuta `wp magic-linking index`.\n" );
	exit( 1 );
}

$docs = Schema::table( $wpdb->prefix, 'docs' );
$ids  = array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$docs} WHERE doc_len > 0" ) );
printf( "entradas indexadas=%d muestras=%d\n", count( $ids ), $samples );

mt_srand( 20261006 );
shuffle( $ids );
$sample = array_slice( $ids, 0, $samples );

// Una pasada sin medir para calentar la caché del servidor de base de datos.
$service->outgoing( $sample[0] );
$service->incoming( $sample[0] );

$report = static function ( string $name, array $times, array $queries, array $counts ): float {
	sort( $times );
	$n   = count( $times );
	$p95 = $times[ (int) min( $n - 1, ceil( 0.95 * $n ) - 1 ) ];
	printf(
		"%-9s p50=%6.1f ms  p95=%6.1f ms  max=%6.1f ms  consultas(mediana)=%d  sugerencias(media)=%.1f\n",
		$name,
		$times[ (int) floor( $n / 2 ) ],
		$p95,
		$times[ $n - 1 ],
		(int) $queries[ (int) floor( count( $queries ) / 2 ) ],
		array_sum( $counts ) / max( 1, count( $counts ) )
	);

	return $p95;
};

$results = array();
$extra   = 0.0;
$total   = 0.0;
foreach ( array( 'outgoing', 'incoming' ) as $direction ) {
	$times   = array();
	$queries = array();
	$counts  = array();
	foreach ( $sample as $id ) {
		// Cada medida es una petición nueva: sin las cachés de entradas de la anterior.
		wp_cache_flush();
		$repository->release();
		memory_reset_peak_usage();
		$base   = memory_get_usage();
		$before = $wpdb->num_queries;
		$t0     = microtime( true );
		$found  = $service->$direction( $id );
		$times[]   = ( microtime( true ) - $t0 ) * 1000;
		$queries[] = $wpdb->num_queries - $before;
		$counts[]  = count( $found );
		$extra     = max( $extra, ( memory_get_peak_usage() - $base ) / 1048576 );
		$total     = max( $total, memory_get_peak_usage() / 1048576 );
	}
	sort( $queries );
	$results[ $direction ] = $report( $direction, $times, $queries, $counts );
}

// La capa REST (F1-08): la misma petición de la pantalla, con permisos, presentación de la tarjeta y JSON.
// 1) Petición completa sin caché de objetos (lo habitual): motor y capa; debe seguir dentro de 500 y 800 ms.
// 2) Lo que añade la capa: con una caché de objetos en memoria (el resultado del motor ya guardado en el
//    transient de objeto) y las entradas, metadatos y términos fuera de la caché de WordPress, de modo que
//    pague también la carga de las entradas que enseña. Tope: 20 ms al p95.
$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 );
wp_set_current_user( $admin );
$rest = static function ( string $route, int $id ) {
	$request = new WP_REST_Request( 'GET', '/magic-linking/v1/suggestions/' . $route );
	$request->set_param( 'post_id', $id );
	$t0       = microtime( true );
	$response = rest_do_request( $request );
	$json     = wp_json_encode( rest_get_server()->response_to_data( $response, false ) );
	$ms       = ( microtime( true ) - $t0 ) * 1000;
	if ( 200 !== $response->get_status() ) {
		fwrite( STDERR, "REST {$route} {$id}: estado " . $response->get_status() . "\n" );
		exit( 1 );
	}
	$data = $response->get_data();

	return array( $ms, is_array( $data['items'] ?? null ) ? count( $data['items'] ) : 0, strlen( (string) $json ) );
};

$rest_total = array();
foreach ( array( 'outbound', 'inbound' ) as $route ) {
	$times = array();
	$items = array();
	$peak  = 0.0;
	foreach ( $sample as $id ) {
		wp_cache_flush();
		$repository->release();
		memory_reset_peak_usage();
		list( $ms, $count ) = $rest( $route, $id );
		$times[]            = $ms;
		$items[]            = $count;
		$peak               = max( $peak, memory_get_peak_usage() / 1048576 );
	}
	$rest_total[ $route ] = $report( 'REST ' . $route, $times, array( 0 ), $items );
	printf( "REST %s: petición completa sin caché de objetos, pico total=%.1f MB\n", $route, $peak );
}

$rest_over = 0.0;
wp_using_ext_object_cache( true );
foreach ( array( 'outbound', 'inbound' ) as $route ) {
	$times = array();
	$items = array();
	$bytes = 0;
	foreach ( $sample as $id ) {
		wp_cache_flush();
		$repository->release();
		$rest( $route, $id );
		foreach ( array( 'posts', 'post_meta', 'terms', 'term_relationships', 'users', 'user_meta' ) as $group ) {
			wp_cache_flush_group( $group );
		}
		list( $ms, $count, $size ) = $rest( $route, $id );
		$times[]                   = $ms;
		$items[]                   = $count;
		$bytes                     = max( $bytes, $size );
	}
	$over      = $report( 'capa ' . $route, $times, array( 0 ), $items );
	$rest_over = max( $rest_over, $over );
	printf( "capa %s: JSON máximo=%d B\n", $route, $bytes );
}
wp_using_ext_object_cache( false );

// `09 §4` habla del pico total de la petición: se mide el pico absoluto de cada cálculo (con la caché de
// objetos vacía al empezar), que aquí incluye la base de WP-CLI. Se imprime también lo que añade el cálculo.
// En una petición web la base es la de WordPress (unos 25–35 MB con WP_DEBUG); aquí la de WP-CLI, que es mayor,
// así que el pico total no es comparable con el límite. El presupuesto se comprueba sobre lo que añade el
// cálculo: 32 MB, la mitad del límite, que deja el resto a la base de WordPress.
$peak = $extra;
printf( "memoria: pico total en WP-CLI=%.1f MB (base incluida), añadida por el cálculo=%.1f MB (límite 32 MB; el total de la petición web no debe pasar de 64 MB)\n", $total, $extra );

if ( $strict && ( $results['outgoing'] > 500 || $results['incoming'] > 800 || $peak > 32 || $rest_over > 20 || $rest_total['outbound'] > 500 || $rest_total['inbound'] > 800 ) ) {
	fwrite( STDERR, "Presupuesto de rendimiento superado.\n" );
	exit( 1 );
}
