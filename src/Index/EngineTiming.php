<?php
/**
 * Tiempo del motor de sugerencias por fase (D-30, F1-27).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

/**
 * Apunta cuánto tarda el motor en las peticiones de sugerencias y lo resume para la pestaña *Estado*
 * (`docs/07 §8`, `docs/04 §12`).
 *
 * **Coste en la petición:** solo lecturas de `hrtime()` en el motor y un `array_push` en memoria (recortado a
 * {@see self::KEEP} por dirección). La escritura va en `shutdown`, con la respuesta ya enviada si el servidor lo
 * permite, y es una por dirección y petición aunque haya muchas medidas.
 *
 * **Dónde se guarda:** una opción por dirección ({@see self::OPTIONS}) con `autoload` desactivado, no un transitorio:
 * sin caché de objetos persistente un transitorio es una fila de `wp_options` con autoload y se cargaría en cada
 * petición del sitio (regla 8). Dos opciones, no una, para que una saliente y una entrante que terminan a la vez no se
 * pisen al leer-modificar-escribir. Cada una guarda las últimas {@see self::KEEP} peticiones **registradas** y la hora
 * de la última escritura.
 *
 * **Muestreo:** mientras se teclea en el editor llega una petición de borrador por pausa; escribir cada una sería una
 * escritura en la base de datos por pulsación. En `shutdown` se escribe como mucho una vez cada
 * {@see self::MIN_INTERVAL} segundos por dirección y lo que llega entre medias se descarta. Por eso «últimas 20» son
 * las últimas 20 registradas, no las últimas 20 que hubo.
 *
 * **Qué mide:** el total de la llamada al servicio ({@see Suggestions}) y, dentro, las fases del motor: `retrieval`
 * (analizar el origen, pesar y recuperar candidatas), `load` (leer el destino o los orígenes), `phrases` (buscar la
 * frase donde anclar) y `scoring`. Queda fuera de las fases, y dentro del total, ordenar, los motivos y quitar las
 * palabras ignoradas. Las respuestas servidas desde {@see SuggestionCache} se apuntan con las fases vacías y la marca
 * de caché: el total es lo que tardó en servirse.
 */
final class EngineTiming {

	/**
	 * Opciones donde viven las medidas, por dirección.
	 */
	public const OPTIONS = array(
		self::OUTGOING => 'magiclinking_engine_timing_out',
		self::INCOMING => 'magiclinking_engine_timing_in',
	);

	/**
	 * Peticiones que se recuerdan por dirección.
	 */
	public const KEEP = 20;

	/**
	 * Segundos mínimos entre dos escrituras de una misma dirección.
	 */
	public const MIN_INTERVAL = 10;

	/**
	 * Presupuesto del p95 en ms (`docs/09 §4`).
	 */
	public const BUDGET_OUTGOING = 500;
	public const BUDGET_INCOMING = 800;

	/**
	 * Direcciones del motor.
	 */
	public const OUTGOING = 'outgoing';
	public const INCOMING = 'incoming';

	/**
	 * Medidas aún sin escribir, por dirección: total, recuperación, carga, frases, puntuación (ms), candidatas y
	 * si salió de la caché (0/1).
	 *
	 * @var array<string, list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: int, 6: int}>>
	 */
	private array $pending = array();

	/**
	 * Apunta una petición calculada por el motor.
	 *
	 * @param string $direction `outgoing` o `incoming`.
	 * @param int    $total_ns  Duración total en nanosegundos.
	 * @param array  $phases    Fases del motor ({@see \MagicLinking\Engine\Suggester::timing()}), en nanosegundos.
	 *
	 * @phpstan-param array{retrieval: int, load: int, phrases: int, scoring: int, candidates: int} $phases
	 */
	public function record( string $direction, int $total_ns, array $phases ): void {
		$this->push(
			$direction,
			array( self::ms( $total_ns ), self::ms( $phases['retrieval'] ), self::ms( $phases['load'] ), self::ms( $phases['phrases'] ), self::ms( $phases['scoring'] ), $phases['candidates'], 0 )
		);
	}

	/**
	 * Apunta una respuesta servida desde la caché de resultados: sin fases ni candidatas.
	 *
	 * @param string $direction `outgoing` o `incoming`.
	 * @param int    $total_ns  Lo que tardó en servirse, en nanosegundos.
	 */
	public function record_cached( string $direction, int $total_ns ): void {
		$this->push( $direction, array( self::ms( $total_ns ), 0.0, 0.0, 0.0, 0.0, 0, 1 ) );
	}

	/**
	 * Escribe lo apuntado. En `shutdown` (sin argumentos) respeta el intervalo mínimo por dirección; con `$force`
	 * escribe siempre (pruebas, banco de pruebas y {@see self::summary()}).
	 *
	 * @param bool $force Saltarse el intervalo mínimo.
	 */
	public function flush( bool $force = false ): void {
		$pending       = $this->pending;
		$this->pending = array();
		foreach ( $pending as $direction => $rows ) {
			$stored = $this->stored( $direction );
			$now    = time();
			if ( ! $force && $now - $stored['t'] < self::MIN_INTERVAL ) {
				continue;
			}
			update_option(
				self::OPTIONS[ $direction ],
				array(
					't'    => $now,
					'rows' => array_slice( array_merge( $stored['rows'], $rows ), -self::KEEP ),
				),
				false
			);
		}
	}

	/**
	 * Resumen para la pantalla, por dirección: peticiones registradas y cuántas salieron de la caché, mediana y p95 del
	 * total de todas (lo que ve el usuario), y medianas por fase y media de candidatas de las que calculó el motor.
	 *
	 * @return array<string, array{count: int, cached: int, median: float, p95: float, retrieval: float, load: float, phrases: float, scoring: float, candidates: float}>
	 */
	public function summary(): array {
		$this->flush( true );
		$out = array();
		foreach ( array_keys( self::OPTIONS ) as $direction ) {
			$rows     = $this->stored( $direction )['rows'];
			$computed = array_values( array_filter( $rows, static fn( array $row ): bool => 0 === $row[6] ) );
			$n        = count( $computed );
			$col      = static fn( array $set, int $i ): array => array_column( $set, $i );

			$out[ $direction ] = array(
				'count'      => count( $rows ),
				'cached'     => count( $rows ) - $n,
				'median'     => self::median( $col( $rows, 0 ) ),
				'p95'        => self::p95( $col( $rows, 0 ) ),
				'retrieval'  => self::median( $col( $computed, 1 ) ),
				'load'       => self::median( $col( $computed, 2 ) ),
				'phrases'    => self::median( $col( $computed, 3 ) ),
				'scoring'    => self::median( $col( $computed, 4 ) ),
				'candidates' => $n > 0 ? round( array_sum( $col( $computed, 5 ) ) / $n, 1 ) : 0.0,
			);
		}

		return $out;
	}

	/**
	 * Guarda una fila en memoria (recortada a las últimas KEEP) y engancha la escritura.
	 *
	 * @param string $direction `outgoing` o `incoming`.
	 * @param array  $row       Fila.
	 *
	 * @phpstan-param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: int, 6: int} $row
	 */
	private function push( string $direction, array $row ): void {
		if ( ! isset( self::OPTIONS[ $direction ] ) ) {
			return;
		}
		$first                       = array() === $this->pending;
		$rows                        = $this->pending[ $direction ] ?? array();
		$rows[]                      = $row;
		$this->pending[ $direction ] = array_slice( $rows, -self::KEEP );
		if ( $first ) {
			// Idempotente: una sola escritura por petición aunque se apunten cientos.
			add_action( 'shutdown', array( $this, 'flush' ) );
		}
	}

	/**
	 * Medidas guardadas de una dirección, con la forma esperada aunque la opción no exista o esté dañada.
	 *
	 * @param string $direction `outgoing` o `incoming`.
	 *
	 * @return array{t: int, rows: list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: int, 6: int}>}
	 */
	private function stored( string $direction ): array {
		$raw  = get_option( self::OPTIONS[ $direction ], array() );
		$out  = array(
			't'    => is_array( $raw ) ? (int) ( $raw['t'] ?? 0 ) : 0,
			'rows' => array(),
		);
		$rows = is_array( $raw ) && is_array( $raw['rows'] ?? null ) ? $raw['rows'] : array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && 7 === count( $row ) ) {
				$out['rows'][] = array( (float) $row[0], (float) $row[1], (float) $row[2], (float) $row[3], (float) $row[4], (int) $row[5], (int) $row[6] );
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
