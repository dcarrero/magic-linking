<?php
/**
 * Plugin Name:       Magic Linking – Internal Links
 * Plugin URI:        https://magiclinking.com
 * Description:       Internal link suggestions in the editor. Link in one click, check that nothing else changes, undo from History. Plus orphan and broken link reports.
 * Version:           0.10.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Color Vivo
 * Author URI:        https://colorvivo.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       magic-linking
 * Domain Path:       /languages
 *
 * @package MagicLinking
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Otra copia del plugin (p. ej. una carpeta antigua) ya está cargada: no se redeclara nada.
if ( defined( 'MAGICLINKING_VERSION' ) ) {
	return;
}

// Rutas y versiones en un solo sitio; el resto del código no repite valores literales.
define( 'MAGICLINKING_VERSION', '0.10.0' );
define( 'MAGICLINKING_FILE', __FILE__ );
define( 'MAGICLINKING_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAGICLINKING_URL', plugin_dir_url( __FILE__ ) );
define( 'MAGICLINKING_PREFIX', 'magiclinking_' );
define( 'MAGICLINKING_DB_VERSION', 3 );
define( 'MAGICLINKING_MIN_WP', '6.9' );
define( 'MAGICLINKING_MIN_PHP', '8.1' );

/**
 * Requisitos que no se cumplen, como lista de textos legibles; vacía si está todo.
 *
 * Solo usa funciones del núcleo de PHP y de WordPress presentes en cualquier versión soportada,
 * para poder ejecutarse antes de cargar nada más del plugin.
 *
 * @return list<string>
 */
function magiclinking_unmet_requirements(): array {
	$unmet = array();

	// PHPStan analiza con PHP >= 8.1, pero el sitio puede bajar de versión con el plugin ya activo.
	/* @phpstan-ignore if.alwaysFalse */
	if ( version_compare( PHP_VERSION, MAGICLINKING_MIN_PHP, '<' ) ) {
		/* translators: 1: minimum PHP version, 2: PHP version in use. */
		$unmet[] = sprintf( __( 'PHP %1$s or later (this site runs PHP %2$s)', 'magic-linking' ), MAGICLINKING_MIN_PHP, PHP_VERSION );
	}

	if ( version_compare( get_bloginfo( 'version' ), MAGICLINKING_MIN_WP, '<' ) ) {
		/* translators: 1: minimum WordPress version, 2: WordPress version in use. */
		$unmet[] = sprintf( __( 'WordPress %1$s or later (this site runs WordPress %2$s)', 'magic-linking' ), MAGICLINKING_MIN_WP, get_bloginfo( 'version' ) );
	}

	if ( ! extension_loaded( 'mbstring' ) ) {
		$unmet[] = __( 'the PHP mbstring extension', 'magic-linking' );
	}

	return $unmet;
}

/**
 * Texto explicativo de los requisitos que faltan.
 *
 * @param array<int, string> $unmet Requisitos que no se cumplen.
 */
function magiclinking_requirements_message( array $unmet ): string {
	/* translators: %s: list of missing requirements, separated by commas. */
	return sprintf( __( 'Magic Linking needs %s. It has not been loaded.', 'magic-linking' ), implode( ', ', $unmet ) );
}

/**
 * Aviso en la pantalla de Plugins cuando no se cumplen los requisitos.
 */
function magiclinking_requirements_notice(): void {
	$screen = get_current_screen();
	if ( null === $screen || 'plugins' !== $screen->id ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( magiclinking_requirements_message( magiclinking_unmet_requirements() ) )
	);
}

/**
 * Activación con requisitos sin cumplir: se desactiva el plugin y se explica por qué, sin fatales.
 */
function magiclinking_requirements_on_activation(): void {
	$unmet = magiclinking_unmet_requirements();
	if ( array() === $unmet ) {
		return;
	}

	deactivate_plugins( plugin_basename( MAGICLINKING_FILE ) );
	wp_die(
		esc_html( magiclinking_requirements_message( $unmet ) ),
		esc_html__( 'Plugin not activated', 'magic-linking' ),
		array( 'back_link' => true )
	);
}

if ( array() !== magiclinking_unmet_requirements() ) {
	register_activation_hook( MAGICLINKING_FILE, 'magiclinking_requirements_on_activation' );
	add_action( 'admin_notices', 'magiclinking_requirements_notice' );
	return;
}

/**
 * Aviso en la pantalla de Plugins cuando falta vendor/ (copia de desarrollo sin `composer install`).
 */
function magiclinking_missing_vendor_notice(): void {
	$screen = get_current_screen();
	if ( null === $screen || 'plugins' !== $screen->id ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Magic Linking is missing its bundled libraries (the vendor folder). Reinstall the plugin from wordpress.org. It has not been loaded.', 'magic-linking' )
	);
}

// Autoloader de Composer: en el ZIP es un classmap optimizado sin dependencias de desarrollo.
if ( ! is_readable( MAGICLINKING_DIR . 'vendor/autoload.php' ) ) {
	add_action( 'admin_notices', 'magiclinking_missing_vendor_notice' );
	return;
}
require_once MAGICLINKING_DIR . 'vendor/autoload.php';

// Action Scheduler empaquetado, solo si no hay otra copia ya inicializada. Si otro plugin
// trae su propia copia, el registro de versiones de Action Scheduler se queda con la más
// reciente de todas en plugins_loaded (prioridad 1), antes de que arranquemos.
if ( ! class_exists( 'ActionScheduler', false ) && is_readable( MAGICLINKING_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once MAGICLINKING_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

register_activation_hook( MAGICLINKING_FILE, array( MagicLinking\Core\Installer::class, 'activate' ) );
register_deactivation_hook( MAGICLINKING_FILE, array( MagicLinking\Core\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( MagicLinking\Core\Plugin::class, 'boot' ) );
