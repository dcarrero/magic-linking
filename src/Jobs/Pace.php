<?php
/**
 * Ritmo del indexado: tamaño de corte adaptable y estimación del tiempo restante.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Jobs;

/**
 * Cálculos puros, sin acceso a WordPress, para poder probarlos sin base de datos.
 */
final class Pace {

	/**
	 * Entradas por corte, mínimo y máximo.
	 */
	public const MIN_SLICE = 5;
	public const MAX_SLICE = 200;

	/**
	 * Muestras (instante, entradas hechas) que se guardan en el proceso para estimar el ritmo.
	 */
	public const KEEP_SAMPLES = 6;

	/**
	 * Tamaño del siguiente corte: crece si el anterior fue rápido y baja si fue lento.
	 *
	 * @param int   $current Tamaño del corte que acaba de terminar.
	 * @param float $elapsed Segundos que tardó.
	 * @param float $target  Segundos que debería tardar un corte.
	 */
	public static function next_slice( int $current, float $elapsed, float $target ): int {
		if ( $elapsed < $target / 2 ) {
			return min( self::MAX_SLICE, max( self::MIN_SLICE, $current * 2 ) );
		}

		if ( $elapsed > $target * 1.5 ) {
			return max( self::MIN_SLICE, intdiv( $current, 2 ) );
		}

		return min( self::MAX_SLICE, max( self::MIN_SLICE, $current ) );
	}

	/**
	 * Añade una muestra y conserva solo las últimas.
	 *
	 * @param array<int, array{0: int, 1: int}> $samples Muestras anteriores.
	 * @param int                               $time    Instante (segundos Unix).
	 * @param int                               $done    Entradas hechas.
	 *
	 * @return list<array{0: int, 1: int}>
	 */
	public static function push_sample( array $samples, int $time, int $done ): array {
		$samples[] = array( $time, $done );

		return array_slice( $samples, -self::KEEP_SAMPLES );
	}

	/**
	 * Entradas por segundo de reloj (con las esperas entre lotes incluidas) según las últimas muestras.
	 *
	 * @param array<int, array{0: int, 1: int}> $samples Muestras.
	 *
	 * @return float|null Null si aún no hay datos para estimar.
	 */
	public static function rate( array $samples ): ?float {
		if ( count( $samples ) < 2 ) {
			return null;
		}

		$first = reset( $samples );
		$last  = end( $samples );
		$secs  = $last[0] - $first[0];
		$done  = $last[1] - $first[1];

		return $secs > 0 && $done > 0 ? $done / $secs : null;
	}

	/**
	 * Segundos que faltan para terminar.
	 *
	 * @param int                               $remaining Entradas por hacer.
	 * @param array<int, array{0: int, 1: int}> $samples   Muestras.
	 *
	 * @return int|null Null si no se puede estimar.
	 */
	public static function eta( int $remaining, array $samples ): ?int {
		$rate = self::rate( $samples );

		if ( null === $rate ) {
			return null;
		}

		return (int) ceil( max( 0, $remaining ) / $rate );
	}
}
