<?php
/**
 * Proveedor de IA simulado para las pruebas E2E (se copia a mu-plugins desde la prueba y se quita al terminar).
 *
 * Modo en la opción `magiclinking_e2e_ai`: `on` (contesta un reordenado), `fail` (el proveedor falla) u `off`
 * (ningún conector registrado).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

use MagicLinking\Tests\Integration\Support\FakeAiProvider;
use WordPress\AiClient\Providers\Http\Exception\ServerException;

add_action(
	'init',
	static function (): void {
		$mode = get_option( 'magiclinking_e2e_ai', 'off' );
		if ( 'off' === $mode || ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return;
		}

		require_once WP_PLUGIN_DIR . '/magiclinking-wordpress-plugin/vendor/autoload.php';
		FakeAiProvider::install();

		if ( 'fail' === $mode ) {
			FakeAiProvider::$reply = new ServerException( 'Overloaded', 529 );
			return;
		}

		// La primera candidata no encaja; el resto, con más confianza cuanto más abajo estaban; la última lleva un
		// ancla mejorada (la palabra que va delante del ancla más el ancla, que es literal de su frase).
		FakeAiProvider::$reply = static function (): string {
			$sent    = (string) end( FakeAiProvider::$sent );
			$count   = substr_count( $sent, '<candidate n=' );
			$results = array();
			for ( $i = 1; $i <= $count; $i++ ) {
				$row = array(
					'candidato' => $i,
					'apto'      => 1 !== $i,
					'confianza' => 1 === $i ? 0.2 : round( $i / ( $count + 1 ), 2 ),
				);
				if ( $i === $count && $count > 1 ) {
					$blocks = explode( '<candidate n=', $sent );
					if ( preg_match( '/(\p{L}+\s)\[\[(.+?)\]\]/u', (string) end( $blocks ), $m ) ) {
						$row['ancla_mejorada'] = $m[1] . $m[2];
					}
				}
				$results[] = $row;
			}

			return (string) wp_json_encode( array( 'results' => $results ) );
		};
	},
	1
);
