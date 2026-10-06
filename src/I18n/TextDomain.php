<?php
/**
 * Carga de las traducciones incluidas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\I18n;

use MagicLinking\Core\Module;

/**
 * Carga el dominio de texto `magic-linking` desde la carpeta languages/ del plugin.
 *
 * El plugin trae castellano (es_ES) escrito a mano; el resto de idiomas los sirve
 * translate.wordpress.org. La carga espera a `init`, como pide WordPress 6.7 en adelante.
 */
final class TextDomain implements Module {

	public const DOMAIN = 'magic-linking';

	/**
	 * Engancha la carga.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'load' ) );
	}

	/**
	 * Carga las traducciones del plugin si hay un fichero para el idioma actual.
	 */
	public function load(): void {
		$mofile = MAGICLINKING_DIR . 'languages/' . self::DOMAIN . '-' . determine_locale() . '.mo';

		if ( is_readable( $mofile ) ) {
			load_textdomain( self::DOMAIN, $mofile );
		}
	}
}
