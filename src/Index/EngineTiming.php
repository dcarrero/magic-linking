<?php
/**
 * Tiempo del motor de sugerencias por fase (D-30, F1-27).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

/**
 * Apunta cuánto tarda el motor en cada petición de sugerencias y lo resume para la pestaña *Estado*
 * (`docs/07 §8`, `docs/04 §12`).
 *
 * **Coste en la petición:** solo lecturas de `hrtime()` en el motor y un `array_push` en memoria. La escritura va en
 * `shutdown`, con la respuesta ya enviada si el servidor lo permite, y es una sola por petición aunque haya
 * muchas medidas (el banco de pruebas y WP-CLI hacen cientos).
 *
 * **Dónde se guarda:** un búfer circular de las últimas {@see self::KEEP} peticiones de cada dirección en una opción
 * propia con `autoload` desactivado, no en un transitorio: sin caché de objetos persistente un transitorio es una fila
 * de `wp_options` con autoload y se cargaría en cada petición del sitio (regla 8); con ella, desaparecería al reiniciar
 * la caché y la pestaña saldría vacía sin motivo. Sin caducidad: son 40 filas de números, se borran al desinstalar con el
 * resto de las opciones `magiclinking_*`.
 */
final class EngineTiming {

	/**
	 * Opción donde viven las medidas.
	 */
	public const OPTION = 'magiclinking_engine_timing';

	/**
	 * Peticiones que se recuerdan por dirección.
	 */
	public const KEEP = 20;

	/**
	 * Direcciones del motor.
	 */
	public const OUTGOING = 'outgoing';
	public const INCOMING = 'incoming';

	/**
	 * Medidas aún sin escribir, por dirección.
	 *
	 * @var array<string, list<array{0: float, 1: float, 2: float, 3: float, 4: int}>>
	 */
	private array $pending = array();

	/**
	 * Apunta una petición.
	 *
	 * @param string $direction `outgoing` o `incoming`.
	 * @param int    $total_ns  Duración total en nanosegundos.
	 * @param array  $phases    Fases del motor ({@see \MagicLinking\Engine\Suggester::timing()}), en nanosegundos.
	 *
	 * @phpstan-param array{retrieval: int, phrases: int, scoring: int, candidates: int} $phases
	 */
	public function record( string $direction, int $total_ns, array $phases ): void {
		if ( self::OUTGOING !== $direction && self::INCOMING !== $direction ) {
			return;
		}
		$first                         = array() === $this->pending;
		$this->pending[ $direction ][] = array(
			self::ms( $total_ns ),
			self::ms( $phases['retrieval'] ),
			self::ms( $phases['phrases'] ),
			self::ms( $phases['scoring'] ),
			$phases['candidates'],
		);
		if ( $first ) {
			// Idempotente: una sola escritura por petición aunque se apunten cientos.
			add_action( 'shutdown', array( $this, 'flush' ) );
		}
	}

	/**
	 * Escribe lo apuntado en el búfer circular. Se llama en `shutdown`; público para las pruebas y el banco.
	 */
	public function flush(): void {
		if ( array() === $this->pending ) {
			return;
		}
		$stored = $this->stored();
		foreach ( $this->pending as $direction => $rows ) {
			$stored[ $direction ] = array_slice( array_merge( $stored[ $direction ], $rows ), -self::KEEP );
		}
		$this->pending = array();
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Resumen para la pantalla: por dirección, cuántas peticiones hay y la mediana y el p95 del total, las medianas por
	 * fase y la media de candidatas evaluadas. Sin peticiones, la dirección sale con `count` = 0.
	 *
	 * @return array<string, array{count: int, median: float, p95: float, retrieval: float, phrases: float, scoring: float, candidates: float}>
	 */
	public function summary(): array {
		$this->flush();
		$stored = $this->stored();
		$out    = array();
		foreach ( array( self::OUTGOING, self::INCOMING ) as $direction ) {
			$rows  = $stored[ $direction ];
			$count = count( $rows );
			$col   = static fn( int $i ): array => array_column( $rows, $i );

			$out[ $direction ] = array(
				'count'      => $count,
				'median'     => self::median( $col( 0 ) ),
				'p95'        => self::p95( $col( 0 ) ),
				'retrieval'  => self::median( $col( 1 ) ),
				'phrases'    => self::median( $col( 2 ) ),
				'scoring'    => self::median( $col( 3 ) ),
				'candidates' => $count > 0 ? round( array_sum( $col( 4 ) ) / $count, 1 ) : 0.0,
			);
		}

		return $out;
	}

	/**
	 * Medidas guardadas, con la forma esperada aunque la opción no exista o esté dañada.
	 *
	 * @return array<string, list<array{0: float, 1: float, 2: float, 3: float, 4: int}>>
	 */
	private function stored(): array {
		$raw = get_option( self::OPTION, array() );
		$out = array(
			self::OUTGOING => array(),
			self::INCOMING => array(),
		);
		foreach ( array_keys( $out ) as $direction ) {
			if ( ! is_array( $raw ) || ! is_array( $raw[ $direction ] ?? null ) ) {
				continue;
			}
			foreach ( $raw[ $direction ] as $row ) {
				if ( is_array( $row ) && 5 === count( $row ) ) {
					$out[ $direction ][] = array( (float) $row[0], (float) $row[1], (float) $row[2], (float) $row[3], (int) $row[4] );
				}
			}
		}

		return $out;
	}

	/**
	 * Nanosegundos a milisegundos con dos decimales (una fase de un sitio pequeño dura menos de 0,05 ms).
	 *
	 * @param int $ns Nanosegundos.
	 */
	private static function ms( int $ns ): float {
		return round( $ns / 1000000, 2 );
	}

	/**
	 * Mediana: el elemento central del orden, como en `tests/perf/suggest.php`.
	 *
	 * @param array $values Valores.
	 *
	 * @phpstan-param list<float|int> $values
	 */
	public static function median( array $values ): float {
		if ( array() === $values ) {
			return 0.0;
		}
		sort( $values );

		return (float) $values[ (int) floor( count( $values ) / 2 ) ];
	}

	/**
	 * Percentil 95 por rango más cercano, como en `tests/perf/suggest.php`.
	 *
	 * @param array $values Valores.
	 *
	 * @phpstan-param list<float|int> $values
	 */
	public static function p95( array $values ): float {
		if ( array() === $values ) {
			return 0.0;
		}
		sort( $values );

		return (float) $values[ (int) min( count( $values ) - 1, ceil( 0.95 * count( $values ) ) - 1 ) ];
	}
}
