<?php
/**
 * Desinstalación.
 *
 * Borra tablas, opciones y metadatos propios solo si el usuario marcó «borrar
 * datos al desinstalar» (por defecto, no). Los enlaces insertados se quedan:
 * están escritos en el contenido.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

MagicLinking\Core\Installer::uninstall_everywhere();
