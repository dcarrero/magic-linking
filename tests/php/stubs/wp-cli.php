<?php
/**
 * Sustituto mínimo de WP-CLI, solo con lo que usa Magic Linking.
 *
 * Lo cargan PHPStan y las pruebas de integración (que no corren dentro de WP-CLI); en WP-CLI de
 * verdad no se incluye nunca. Registra lo que se «imprime» para poder comprobarlo.
 *
 * @package MagicLinking
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, PEAR.NamingConventions.ValidClassName, Universal.Namespaces.OneDeclarationPerFile, Squiz.Commenting, WordPress.Files.FileName, WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DevelopmentFunctions

namespace {

	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Salida registrada de la orden en curso.
		 */
		class WP_CLI {

			/** @var array<int, string> */
			public static array $lines = array();

			/** @var array<string, mixed> */
			public static array $commands = array();

			public static function reset(): void {
				self::$lines = array();
			}

			public static function line( string $message = '' ): void {
				self::$lines[] = $message;
			}

			public static function log( string $message ): void {
				self::$lines[] = $message;
			}

			public static function success( string $message ): void {
				self::$lines[] = 'Success: ' . $message;
			}

			public static function warning( string $message ): void {
				self::$lines[] = 'Warning: ' . $message;
			}

			/**
			 * @throws \RuntimeException Como el error de WP-CLI, que termina la orden.
			 */
			public static function error( string $message ): void {
				throw new \RuntimeException( $message );
			}

			/**
			 * @param string|object $callable Clase u objeto.
			 */
			public static function add_command( string $name, $callable ): void {
				self::$commands[ $name ] = $callable;
			}
		}
	}
}

namespace WP_CLI\Utils {

	if ( ! function_exists( __NAMESPACE__ . '\format_items' ) ) {
		/**
		 * @param string                          $format Formato.
		 * @param iterable<array<string, mixed>>  $items  Filas.
		 * @param array<int, string>|string       $fields Columnas.
		 */
		function format_items( $format, $items, $fields ): void {
			$fields = is_array( $fields ) ? $fields : explode( ',', $fields );
			foreach ( $items as $item ) {
				$row = array();
				foreach ( $fields as $field ) {
					$row[] = (string) ( $item[ $field ] ?? '' );
				}
				\WP_CLI::$lines[] = $format . ':' . implode( '|', $row );
			}
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\make_progress_bar' ) ) {
		class FakeProgressBar {

			public int $ticks = 0;

			public function tick( int $increment = 1 ): void {
				$this->ticks += $increment;
			}

			public function finish(): void {
			}
		}

		function make_progress_bar( string $message, int $count ): FakeProgressBar {
			return new FakeProgressBar();
		}
	}
}
