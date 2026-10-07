<?php
/**
 * Genera entradas sintéticas para medir el indexado (solo desarrollo, no va en el ZIP).
 *
 * Uso: wp eval-file tests/perf/seed.php [cantidad] [zipf]   (por defecto 20000)
 *
 * Sin `zipf`, el texto sale de un vocabulario de 75 palabras: cada entrada comparte casi todos sus términos
 * con las demás, el caso más denso posible para la recuperación. Con `zipf`, el vocabulario es de 6.000
 * palabras inventadas con frecuencias de Zipf (como un texto natural: unas pocas palabras en todas partes y
 * una cola larga), los títulos son de dos o tres palabras y algunas entradas citan el título de otra.
 *
 * @package MagicLinking
 */

// phpcs:disable -- Script de banco de pruebas, no forma parte del plugin.

$count = isset( $args[0] ) ? (int) $args[0] : 20000;
$zipf  = in_array( 'zipf', $args, true );

global $wpdb;
$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_name LIKE 'bench-%'" );
$words    = explode( ' ', 'casa perro camino lluvia ciudad mercado libro verde montaña río viaje cocina jardín escuela música trabajo tiempo historia campo playa coche tren mesa ventana noche mañana amigo familia fiesta comida vino aceite pan queso plaza iglesia castillo puente calle barrio empresa proyecto idea plan precio oferta cliente servicio producto calidad energía salud deporte ciencia arte cine teatro moda color luz sombra viento fuego agua tierra cielo estrella luna sol' );
$nw       = count( $words );
mt_srand( 42 );

if ( $zipf ) {
	$syllables = array( 'ca', 'se', 'ro', 'lu', 'mi', 'to', 'ba', 'ne', 'ri', 'do', 'ga', 'pe', 'fi', 'zu', 'la', 'ma', 'so', 'ti', 've', 'co', 'pa', 're', 'di', 'no', 'su', 'te', 'va', 'li', 'mo', 'ra' );
	$words     = array();
	while ( count( $words ) < 6000 ) {
		$w = '';
		for ( $k = mt_rand( 2, 4 ); $k > 0; $k-- ) {
			$w .= $syllables[ mt_rand( 0, count( $syllables ) - 1 ) ];
		}
		$words[ $w ] = true;
	}
	$words = array_keys( $words );
	$nw    = count( $words );
	$cdf   = array();
	$acc   = 0.0;
	foreach ( $words as $rank => $w ) {
		$acc    += 1 / ( $rank + 1 );
		$cdf[]   = $acc;
	}
	$pick = static function () use ( $cdf, $acc, $nw ): int {
		$x  = mt_rand() / mt_getrandmax() * $acc;
		$lo = 0;
		$hi = $nw - 1;
		while ( $lo < $hi ) {
			$mid = intdiv( $lo + $hi, 2 );
			if ( $cdf[ $mid ] < $x ) {
				$lo = $mid + 1;
			} else {
				$hi = $mid;
			}
		}
		return $lo;
	};
	$titles = array();
}

wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );
$wpdb->query( 'SET autocommit=0' );

for ( $i = $existing + 1; $i <= $existing + $count; $i++ ) {
	$paras = array();
	for ( $p = 0; $p < mt_rand( 6, 12 ); $p++ ) {
		$s = array();
		for ( $w = 0; $w < mt_rand( 40, 90 ); $w++ ) {
			$s[] = $words[ $zipf ? $pick() : mt_rand( 0, $nw - 1 ) ];
		}
		if ( $zipf && $titles && 0 === mt_rand( 0, 2 ) ) {
			$s[ mt_rand( 0, count( $s ) - 1 ) ] = $titles[ mt_rand( 0, count( $titles ) - 1 ) ];
		}
		if ( mt_rand( 0, 3 ) === 0 && $i > 50 ) {
			$t   = mt_rand( 1, $i - 1 );
			$bad = mt_rand( 0, 30 ) === 0 ? 'x' : '';
			$s[ mt_rand( 0, count( $s ) - 1 ) ] = '<a href="' . home_url( "/bench-{$bad}{$t}/" ) . '">' . $words[ mt_rand( 0, $nw - 1 ) ] . ' ' . $words[ mt_rand( 0, $nw - 1 ) ] . '</a>';
		}
		$paras[] = "<!-- wp:paragraph -->\n<p>" . implode( ' ', $s ) . ".</p>\n<!-- /wp:paragraph -->";
	}
	if ( $zipf ) {
		$title    = implode( ' ', array( $words[ mt_rand( 50, 2000 ) ], $words[ mt_rand( 50, 2000 ) ], $words[ mt_rand( 50, 2000 ) ] ) );
		$titles[] = $title;
	} else {
		$title = "Entrada de prueba {$i} " . $words[ $i % $nw ];
	}
	wp_insert_post(
		array(
			'post_title'   => $title,
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
