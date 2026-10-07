<?php
/**
 * Sitio de demostración para las capturas de la ficha de wordpress.org.
 *
 * Contenido propio e inventado (hogar y climatización), sin marcas ni dominios reales. Se ejecuta con
 * `wp eval-file` dentro de wp-env; deja el sitio en castellano y sin índice (lo construye capture.mjs).
 *
 * @package MagicLinking
 */

// phpcs:ignoreFile -- Utilidad de desarrollo, fuera del ZIP.

global $wpdb;

foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status <> 'auto-draft'" ) as $id ) {
	wp_delete_post( (int) $id, true );
}
foreach ( array( 'docs', 'terms', 'postings', 'links', 'changes', 'jobs' ) as $t ) {
	$wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" );
}
delete_option( 'magiclinking_settings' );
// Entradas cortas de demostración: un enlace cada 30 palabras y «poco enlazada» por debajo de 3 entrantes.
update_option( 'magiclinking_settings', array( 'words_per_link' => 30, 'low_inbound_threshold' => 3 ) );
update_option( 'blogname', 'Casa Confortable' );
update_option( 'blogdescription', 'Climatización y eficiencia para el hogar' );
update_option( 'show_on_front', 'posts' );

// «[[slug|texto]]» se convierte en un enlace interno.
$posts = array(
	'bomba-de-calor-aerotermica' => array(
		'Bomba de calor aerotérmica: guía completa',
		array(
			'La bomba de calor aerotérmica extrae la energía del aire exterior y la lleva al interior de la casa. Por cada kilovatio de electricidad que consume, entrega entre tres y cinco de calor, y por eso se ha convertido en la alternativa más buscada a la caldera.',
			'Antes de decidirte conviene revisar el aislamiento térmico de la vivienda, porque una casa que pierde calor obliga a una máquina más potente y más cara. Lo ideal es combinarla con suelo radiante, que trabaja a baja temperatura, o con radiadores de baja temperatura que con los de hierro fundido de toda la vida.',
			'Un termostato inteligente permite ajustar la temperatura por franjas horarias y aprovechar las horas en las que la electricidad es más barata. Si además instalas placas solares para autoconsumo, parte de la energía que mueve la máquina sale de tu propio tejado.',
			'Para pedir ayudas necesitarás el certificado de eficiencia energética de la vivienda, antes y después de la reforma. Pide siempre varios presupuestos y compara la potencia, el rendimiento estacional y la garantía.',
		),
	),
	'suelo-radiante' => array(
		'Suelo radiante: ventajas e inconvenientes',
		array(
			'El suelo radiante reparte el calor desde toda la superficie del suelo, con agua a unos 35 grados. El resultado es una temperatura muy uniforme, sin corrientes de aire ni zonas frías, y sin radiadores que ocupen pared.',
			'Su gran virtud es que trabaja a baja temperatura, lo que lo hace el compañero ideal de la [[bomba-de-calor-aerotermica|bomba de calor aerotérmica]]. Su mayor inconveniente es la inercia: tarda en calentarse y en enfriarse, así que no es práctico en habitaciones que se usan pocas horas.',
			'En una reforma hay que sumar el espesor del sistema, de cuatro a ocho centímetros, y comprobar que las puertas siguen abriendo. En obra nueva casi no se nota.',
		),
	),
	'aislamiento-termico' => array(
		'Aislamiento térmico de la vivienda',
		array(
			'El aislamiento térmico es la inversión que antes se amortiza: reduce la demanda de calefacción en invierno y de aire acondicionado en verano. Antes de cambiar de sistema de calefacción, revisa por dónde se escapa el calor.',
			'Las pérdidas suelen repartirse entre la cubierta, las paredes, las ventanas y el suelo. Aislar la cubierta o el falso techo es lo más barato, y las ventanas de doble acristalamiento suelen ser el segundo paso. La envolvente se mejora por fases, empezando por lo más rentable.',
			'Un buen aislamiento también ayuda contra las humedades por condensación, porque mantiene las paredes interiores por encima de la temperatura de rocío.',
		),
	),
	'aire-acondicionado-como-elegir' => array(
		'Cómo elegir un aire acondicionado',
		array(
			'Elegir un aire acondicionado no es cuestión de frigorías a ojo. Hay que calcular la potencia según la superficie, la orientación, el aislamiento y el número de personas que usan la estancia.',
			'Un equipo inverter ajusta la potencia en lugar de arrancar y parar, y consume bastante menos. Fíjate en la clasificación energética estacional y en el nivel sonoro de la unidad interior, sobre todo si es para un dormitorio.',
			'Muchos equipos modernos son en realidad una bomba de calor reversible: enfrían en verano y calientan en invierno. Antes de comprar, mira cuánto es el consumo del aire acondicionado en un uso normal.',
		),
	),
	'mantenimiento-caldera-gas' => array(
		'Mantenimiento de la caldera de gas',
		array(
			'Una caldera de gas bien mantenida consume menos, dura más y es mucho más segura. La revisión periódica por un instalador autorizado es obligatoria y debería hacerse cada dos años.',
			'Entre las tareas básicas están comprobar la presión del circuito, limpiar el quemador y verificar la evacuación de humos. Si notas ruidos o radiadores fríos por arriba, antes de llamar al técnico prueba a purgar los radiadores.',
			'Si la caldera tiene más de quince años, compara su coste de mantenimiento con el de pasarte a la aerotermia o a una caldera de condensación.',
		),
	),
	'ahorrar-calefaccion' => array(
		'Ahorrar en la factura de la calefacción',
		array(
			'Bajar un grado el termostato supone alrededor de un siete por ciento menos de consumo. Pero hay medidas más eficaces que pasar frío: sellar rendijas, cerrar las persianas por la noche y no tapar los radiadores con muebles.',
			'Un termostato inteligente programa la temperatura según tu rutina y evita calentar la casa vacía. Si tienes radiadores, purgarlos una vez al año mejora su rendimiento de forma inmediata.',
			'A medio plazo, el mayor ahorro llega con el aislamiento térmico y con un generador más eficiente.',
		),
	),
	'termostato-inteligente' => array(
		'Termostato inteligente: qué es y cómo se instala',
		array(
			'Un termostato inteligente se conecta a tu red wifi y permite controlar la calefacción desde el móvil. Aprende cuándo estás en casa y ajusta la temperatura para no gastar de más.',
			'La instalación suele ser sencilla si el termostato antiguo tiene dos cables, pero hay que comprobar la compatibilidad con la caldera o con la bomba de calor. Los modelos más completos regulan zona por zona con cabezales en cada radiador.',
			'Bien configurado, es una de las formas más baratas de ahorrar en la factura de la calefacción.',
		),
	),
	'placas-solares-autoconsumo' => array(
		'Placas solares para autoconsumo',
		array(
			'Las placas solares fotovoltaicas producen electricidad durante el día. Con autoconsumo, la que no gastas se vierte a la red y se compensa en la factura.',
			'El dimensionado depende de tu consumo, de la orientación del tejado y de las sombras. Combinadas con una bomba de calor, cubren buena parte de la calefacción y del agua caliente sanitaria.',
			'Hay deducciones en el impuesto sobre la renta y en el de bienes inmuebles. Consulta las subvenciones para reformas de eficiencia de tu comunidad.',
		),
	),
	'ventilacion-calidad-aire' => array(
		'Ventilación y calidad del aire interior',
		array(
			'Una casa bien aislada es también una casa que se ventila mal. Sin renovación de aire se acumulan la humedad, el dióxido de carbono y los compuestos que desprenden muebles y productos de limpieza.',
			'Ventilar cinco minutos con las ventanas abiertas de par en par es mejor que dejarlas entreabiertas durante horas. En viviendas muy estancas, la ventilación mecánica con recuperador de calor renueva el aire sin perder la energía.',
			'Si el ambiente es húmedo, valora un deshumidificador mientras resuelves el origen del problema.',
		),
	),
	'deshumidificador' => array(
		'Deshumidificador: cuándo hace falta',
		array(
			'Un deshumidificador baja la humedad relativa del aire cuando la ventilación no basta. Es útil en sótanos, baños sin ventana y viviendas cercanas a la costa.',
			'Los de condensación van bien a temperaturas templadas; los de adsorción funcionan en lugares fríos. Elige la capacidad en litros al día según la superficie, y vacía o conecta el depósito a un desagüe.',
			'Recuerda que el aparato trata el síntoma. Para acabar con las humedades por condensación hay que mejorar el aislamiento y la ventilación.',
		),
	),
	'radiadores-aluminio-hierro' => array(
		'Radiadores de aluminio frente a hierro fundido',
		array(
			'Los radiadores de aluminio se calientan deprisa y se enfrían igual de rápido; los de hierro fundido tardan, pero conservan el calor durante horas. La elección depende de cómo uses la casa.',
			'Con una caldera de condensación o una bomba de calor, que trabajan a menor temperatura, conviene un radiador de mayor superficie. El suelo radiante evita el problema, aunque exige obra.',
			'Sea cual sea el tipo, hay que saber cómo purgar los radiadores para que se calienten por completo.',
		),
	),
	'ventanas-doble-acristalamiento' => array(
		'Ventanas de doble acristalamiento',
		array(
			'Las ventanas con doble acristalamiento y cámara de aire reducen a la mitad las pérdidas de calor de un cristal sencillo. Si además llevan rotura de puente térmico en el marco, el confort mejora todavía más.',
			'Revisa el valor de transmitancia térmica y el aislamiento acústico. Una buena instalación, con sellado perimetral, importa tanto como el vidrio.',
			'Forman parte de cualquier plan serio de aislamiento térmico y suelen entrar en las ayudas a la rehabilitación.',
		),
	),
	'certificado-eficiencia-energetica' => array(
		'Certificado de eficiencia energética',
		array(
			'El certificado de eficiencia energética clasifica la vivienda de la A a la G según su consumo y sus emisiones. Es obligatorio para vender o alquilar, y lo piden las subvenciones para reformas de eficiencia.',
			'Lo emite un técnico competente tras visitar la casa. Tiene una validez de diez años y se registra en la comunidad autónoma.',
			'Mejorar la etiqueta con aislamiento, ventanas nuevas o una bomba de calor aumenta el valor de la vivienda.',
		),
	),
	'subvenciones-reformas-eficiencia' => array(
		'Subvenciones para reformas de eficiencia',
		array(
			'Las ayudas para rehabilitación energética cubren desde un porcentaje de la instalación de una bomba de calor hasta la mejora de la envolvente, con deducciones fiscales según el ahorro conseguido.',
			'Casi siempre piden el certificado de eficiencia energética antes y después de la obra, y que la solicitud se presente antes de empezar. Guarda las facturas y los informes técnicos.',
			'Consulta qué convocatorias hay abiertas en tu comunidad; cambian cada año y se agotan rápido.',
		),
	),
	'aerotermia-o-caldera-condensacion' => array(
		'Aerotermia o caldera de condensación',
		array(
			'La caldera de condensación aprovecha el vapor de los gases de combustión y rinde un diez por ciento más que una convencional. La aerotermia va más allá: calienta con electricidad y con la energía del aire.',
			'Si la casa está bien aislada y tiene suelo radiante o radiadores grandes, la aerotermia suele ganar a largo plazo. En una vivienda antigua con radiadores de hierro, la condensación puede ser una opción razonable.',
			'Antes de decidir, compara el coste de la instalación, el de mantenimiento y lo que pagarías cada mes.',
		),
	),
	'humedades-condensacion' => array(
		'Humedades por condensación en paredes',
		array(
			'La condensación aparece cuando el vapor de agua del interior encuentra una superficie fría. Se nota en esquinas, detrás de los armarios y en los marcos de las ventanas, con manchas oscuras de moho.',
			'La solución pasa por ventilar bien, controlar la humedad y mejorar el aislamiento de la pared afectada. Pintar encima no sirve de nada.',
			'Si el problema es persistente, un deshumidificador ayuda mientras se acomete la reforma.',
		),
	),
	'calefaccion-casa-antigua' => array(
		'Calefacción en una casa antigua',
		array(
			'Calentar una casa antigua es un reto: muros gruesos pero sin aislamiento, ventanas viejas y techos altos. Antes de instalar nada, mide dónde se pierde el calor.',
			'Suele compensar empezar por las ventanas y por la cubierta, y después elegir un generador adecuado. En casas con radiadores de hierro fundido, una caldera de condensación se adapta mejor que una bomba de calor.',
			'Una estufa de pellets puede ser un complemento en el salón.',
		),
	),
	'purgar-radiadores' => array(
		'Cómo purgar los radiadores',
		array(
			'Si el radiador está frío por la parte de arriba y caliente por abajo, tiene aire. Purgarlo lleva dos minutos y mejora el rendimiento de toda la instalación.',
			'Con la calefacción apagada, abre la válvula de purga con una llave o un destornillador hasta que salga agua, y ciérrala. Después comprueba la presión de la caldera y repón agua si hace falta.',
			'Haz esta revisión al empezar el invierno, junto al mantenimiento de la caldera.',
		),
	),
	'consumo-aire-acondicionado' => array(
		'Consumo del aire acondicionado',
		array(
			'El consumo de un aire acondicionado depende de su potencia, de la eficiencia estacional y de las horas de uso. Un equipo inverter de tamaño adecuado gasta mucho menos que uno antiguo sobredimensionado.',
			'Programar la temperatura a 24 o 25 grados y cerrar las persianas durante el día marca más diferencia que cualquier truco. Una buena instalación de aislamiento térmico reduce las horas de funcionamiento.',
			'Lee también nuestra guía para elegir un aire acondicionado.',
		),
	),
	'estufa-de-pellets' => array(
		'Estufa de pellets: pros y contras',
		array(
			'La estufa de pellets quema serrín prensado y es una forma económica de calentar una estancia. Se enciende y se regula con un mando, y su rendimiento es alto.',
			'A cambio hay que almacenar los sacos, limpiar el cajón de cenizas con frecuencia y aceptar un ruido suave del tornillo sin fin. La ventilación del local es imprescindible.',
			'Como complemento de la calefacción central funciona muy bien en casas de campo.',
		),
	),
	'persianas-toldos-calor' => array(
		'Persianas y toldos contra el calor',
		array(
			'Impedir que el sol entre por la ventana es más barato que enfriar la casa después. Las persianas exteriores y los toldos bloquean hasta el ochenta por ciento de la radiación.',
			'Las cortinas interiores ayudan menos, porque el calor ya ha atravesado el cristal. Las lamas orientables permiten dejar pasar la luz sin el calor.',
			'Una buena protección solar reduce las horas en que necesitas encender el aire.',
		),
	),
	'mantenimiento-aire-acondicionado' => array(
		'Mantenimiento del aire acondicionado',
		array(
			'Limpiar los filtros cada mes de uso, revisar la unidad exterior y comprobar la carga de gas mantiene el equipo en forma. Un filtro sucio puede subir el consumo hasta un quince por ciento.',
			'Al empezar la temporada, haz una puesta a punto: desagüe de condensados despejado y limpieza de las lamas. Un técnico debería revisarlo cada pocos años.',
			'Si el equipo es una bomba de calor reversible, la revisión sirve también para el invierno.',
		),
	),
	'precio-bomba-de-calor' => array(
		'Bomba de calor: precios y presupuestos',
		array(
			'El precio de una bomba de calor varía mucho según la potencia, la marca, la instalación y los trabajos complementarios. Una instalación completa en una vivienda de tamaño medio suele moverse entre seis mil y doce mil euros antes de ayudas.',
			'Pide al menos tres presupuestos detallados, con la potencia, el rendimiento estacional y la garantía. Pregunta si incluyen el depósito de agua caliente y la adaptación de los radiadores.',
			'Las subvenciones para reformas de eficiencia pueden cubrir una parte relevante del coste.',
		),
	),
);

$tipos = array(
	'sobre-nosotros' => array( 'Sobre nosotros', array( 'Casa Confortable es un blog independiente sobre climatización y eficiencia en el hogar.', 'Escribimos guías prácticas para decidir con calma y sin letra pequeña.' ) ),
);

$make = static function ( string $type, string $slug, string $title, array $paras, int $age ) use ( $posts ): int {
	$html = '';
	foreach ( $paras as $p ) {
		$p     = preg_replace_callback(
			'/\[\[([a-z0-9-]+)\|([^\]]+)\]\]/',
			static fn( $m ) => '<a href="' . home_url( '/' . $m[1] . '/' ) . '">' . $m[2] . '</a>',
			$p
		);
		$html .= "<!-- wp:paragraph -->\n<p>" . $p . "</p>\n<!-- /wp:paragraph -->\n\n";
	}
	$author = ( $age % 3 ) ? 1 : (int) get_user_by( 'login', 'redactora' )->ID;
	$date   = gmdate( 'Y-m-d H:i:s', time() - $age * DAY_IN_SECONDS );

	return (int) wp_insert_post(
		array(
			'post_type'     => $type,
			'post_name'     => $slug,
			'post_title'    => $title,
			'post_content'  => trim( $html ),
			'post_status'   => 'publish',
			'post_author'   => $author,
			'post_date_gmt' => $date,
			'post_date'     => get_date_from_gmt( $date ),
		)
	);
};

if ( ! get_user_by( 'login', 'redactora' ) ) {
	wp_insert_user( array( 'user_login' => 'redactora', 'user_pass' => 'password', 'role' => 'editor', 'display_name' => 'Marta Redactora', 'user_email' => 'redactora@example.org' ) );
}
wp_update_user( array( 'ID' => 1, 'display_name' => 'Laura Editora' ) );

$age = 60;
foreach ( $posts as $slug => $data ) {
	$make( 'post', $slug, $data[0], $data[1], $age-- );
}
foreach ( $tipos as $slug => $data ) {
	$make( 'page', $slug, $data[0], $data[1], 90 );
}

// Enlaces entre guías («Te puede interesar»), salvo hacia unas cuantas huérfanas a propósito; la bomba de calor
// (la entrada de las capturas) no enlaza hacia fuera, para que el panel tenga qué proponer.
$orphans = array( 'placas-solares-autoconsumo', 'certificado-eficiencia-energetica', 'precio-bomba-de-calor', 'persianas-toldos-calor', 'mantenimiento-aire-acondicionado' );
$pool    = array_values( array_diff( array_keys( $posts ), $orphans, array( 'bomba-de-calor-aerotermica' ) ) );
$n       = count( $pool );
$i       = 0;
foreach ( array_keys( $posts ) as $from ) {
	++$i;
	if ( 'bomba-de-calor-aerotermica' === $from || 'estufa-de-pellets' === $from ) {
		continue;
	}
	$p = get_page_by_path( $from, OBJECT, 'post' );
	$to = array();
	foreach ( array( ( $i * 3 ) % $n, ( $i * 3 + 4 ) % $n, ( $i * 5 + 1 ) % $n ) as $k ) {
		if ( $pool[ $k ] !== $from && ! in_array( $pool[ $k ], $to, true ) && count( $to ) < ( 0 === $i % 4 ? 1 : 2 ) ) {
			$to[] = $pool[ $k ];
		}
	}
	$links = array();
	foreach ( $to as $slug ) {
		$t       = get_page_by_path( $slug, OBJECT, 'post' );
		$links[] = '<a href="' . home_url( '/' . $slug . '/' ) . '">' . esc_html( $t->post_title ) . '</a>';
	}
	$block = "<!-- wp:paragraph -->\n<p>Te puede interesar: " . implode( ' y ', $links ) . ".</p>\n<!-- /wp:paragraph -->";
	wp_update_post( array( 'ID' => $p->ID, 'post_content' => $p->post_content . "\n\n" . $block ) );
}

// Un enlace roto a propósito (a una entrada que ya no existe) para el informe.
$p = get_page_by_path( 'estufa-de-pellets', OBJECT, 'post' );
wp_update_post( array( 'ID' => $p->ID, 'post_content' => $p->post_content . "\n\n<!-- wp:paragraph -->\n<p>Más información en <a href=\"" . home_url( '/guia-de-pellets-retirada/' ) . "\">la guía de pellets</a>.</p>\n<!-- /wp:paragraph -->" ) );

echo count( $posts ) . " entradas creadas\n";
