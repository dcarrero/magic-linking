<?php
/**
 * Identificador de lote (ULID).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\History;

/**
 * ULID de 26 caracteres (docs/03 §4.6): 48 bits de tiempo en milisegundos y 80 de azar, en base 32 de
 * Crockford. Se ordenan por fecha de creación.
 */
final class BatchId {

	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * Genera un identificador nuevo.
	 *
	 * @param int|null $millis Instante en milisegundos (solo para las pruebas).
	 */
	public static function generate( ?int $millis = null ): string {
		$millis ??= (int) floor( microtime( true ) * 1000 );
		$time     = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$time   = self::ALPHABET[ $millis % 32 ] . $time;
			$millis = intdiv( $millis, 32 );
		}

		$bytes  = random_bytes( 10 );
		$random = '';
		$bits   = '';
		foreach ( str_split( $bytes ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		foreach ( str_split( $bits, 5 ) as $group ) {
			$random .= self::ALPHABET[ (int) bindec( $group ) ];
		}

		return $time . $random;
	}
}
