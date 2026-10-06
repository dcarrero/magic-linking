<?php
/**
 * Genera entradas sintéticas para medir el indexado (solo desarrollo, no va en el ZIP).
 *
 * Uso: wp eval-file tests/perf/seed.php [cantidad]   (por defecto 20000)
 *
 * @package MagicLinking
 */

// phpcs:disable -- Script de banco de pruebas, no forma parte del plugin.

$count = isset( $args[0] ) ? (int) $args[0] : 20000;

global $wpdb;
$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_name LIKE 'bench-%'" );
$words    = explode( ' ', 'casa perro camino lluvia ciudad mercado libro verde montaña río viaje cocina jardín escuela música trabajo tiempo historia campo playa coche tren mesa ventana noche mañana amigo familia fiesta comida vino aceite pan queso plaza iglesia castillo puente calle barrio empresa proyecto idea plan precio oferta cliente servicio producto calidad energía salud deporte ciencia arte cine teatro moda color luz sombra viento fuego agua tierra cielo estrella luna sol' );
$nw       = count( $words );
mt_srand( 42 );

wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );
$wpdb->query( 'SET autocommit=0' );

for ( $i = $existing + 1; $i <= $existing + $count; $i++ ) {
	$paras = array();
	for ( $p = 0; $p < mt_rand( 6, 12 ); $p++ ) {
		$s = array();
		for ( $w = 0; $w < mt_rand( 40, 90 ); $w++ ) {
			$s[] = $words[ mt_rand( 0, $nw - 1 ) ];
		}
		if ( mt_rand( 0, 3 ) === 0 && $i > 50 ) {
			$t   = mt_rand( 1, $i - 1 );
			$bad = mt_rand( 0, 30 ) === 0 ? 'x' : '';
			$s[ mt_rand( 0, count( $s ) - 1 ) ] = '<a href="' . home_url( "/bench-{$bad}{$t}/" ) . '">' . $words[ mt_rand( 0, $nw - 1 ) ] . ' ' . $words[ mt_rand( 0, $nw - 1 ) ] . '</a>';
		}
		$paras[] = "<!-- wp:paragraph -->\n<p>" . implode( ' ', $s ) . ".</p>\n<!-- /wp:paragraph -->";
	}
	wp_insert_post(
		array(
			'post_title'   => "Entrada de prueba {$i} " . $words[ $i % $nw ],
			'post_name'    => "bench-{$i}",
			'post_content' => implode( "\n\n", $paras ),
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	if ( 0 === $i % 500 ) {
		$wpdb->query( 'COMMIT' );
		echo "{$i}\n";
	}
}
$wpdb->query( 'COMMIT' );
$wpdb->query( 'SET autocommit=1' );
wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );
echo "Hecho: {$count} entradas nuevas.\n";
