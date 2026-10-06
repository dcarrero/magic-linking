<?php
/**
 * Registro de las órdenes de WP-CLI.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Cli;

use MagicLinking\Core\Module;
use WP_CLI;

/**
 * Registra `wp magic-linking` solo cuando WordPress corre dentro de WP-CLI.
 */
final class CliModule implements Module {

	/**
	 * Fábrica de la orden (se crea al usarla).
	 *
	 * @var callable
	 */
	private $factory;

	/**
	 * Constructor.
	 *
	 * @param callable $factory Devuelve la orden.
	 */
	public function __construct( callable $factory ) {
		$this->factory = $factory;
	}

	/**
	 * Registra la orden si estamos en WP-CLI.
	 */
	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'magic-linking', ( $this->factory )() );
		}
	}
}
