<?php
/**
 * Entradas donde una regla no ha podido escribir.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rules;

/**
 * Constancia visible (pantalla *Reglas*) de las entradas que siguieron abiertas en el editor hasta agotar la espera de
 * {@see RuleRunner}. Vive en una opción sin autoload, acotada a {@see self::MAX} entradas; se limpia sola cuando la
 * entrada por fin se aplica y se puede descartar a mano.
 */
final class RuleLog {

	/**
	 * Opción (sin autoload).
	 */
	public const OPTION = 'magiclinking_rules_log';

	/**
	 * Entradas como máximo.
	 */
	public const MAX = 50;

	/**
	 * Avisos pendientes, del más reciente al más antiguo. Una fila de entrada lleva `rule_id` 0 (la entrada siguió abierta
	 * en el editor); una fila de regla lleva `post_id` 0 (quien la creó ya no puede gestionar el sitio).
	 *
	 * @return list<array{post_id: int, rule_id: int, time: int}>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $row ) {
			if ( is_array( $row ) && isset( $row['time'] ) ) {
				$out[] = array(
					'post_id' => (int) ( $row['post_id'] ?? 0 ),
					'rule_id' => (int) ( $row['rule_id'] ?? 0 ),
					'time'    => (int) $row['time'],
				);
			}
		}

		return $out;
	}

	/**
	 * Anota una entrada (una sola vez).
	 *
	 * @param int $post_id Entrada.
	 */
	public static function add( int $post_id ): void {
		self::put( $post_id, 0, true );
	}

	/**
	 * Anota una regla que no se puede aplicar porque su creador ya no puede. Si ya estaba anotada no se toca: el aviso
	 * no se repite ni se escribe la opción en cada guardado.
	 *
	 * @param int $rule_id Regla.
	 */
	public static function add_rule( int $rule_id ): void {
		self::put( 0, $rule_id, false );
	}

	/**
	 * Quita una entrada (ya se aplicó o el usuario la descartó).
	 *
	 * @param int $post_id Entrada.
	 */
	public static function remove( int $post_id ): void {
		self::drop( static fn( array $row ): bool => 0 === $row['rule_id'] && $row['post_id'] === $post_id );
	}

	/**
	 * Quita el aviso de una regla (se aplicó, se borró o el usuario lo descartó).
	 *
	 * @param int $rule_id Regla.
	 */
	public static function remove_rule( int $rule_id ): void {
		self::drop( static fn( array $row ): bool => $row['rule_id'] === $rule_id );
	}

	/**
	 * Escribe una fila.
	 *
	 * @param int  $post_id Entrada (0 en las filas de regla).
	 * @param int  $rule_id Regla (0 en las filas de entrada).
	 * @param bool $renew   Si una fila existente se renueva (sube al principio con la hora de ahora).
	 */
	private static function put( int $post_id, int $rule_id, bool $renew ): void {
		$rows = self::all();
		foreach ( $rows as $row ) {
			if ( $row['post_id'] === $post_id && $row['rule_id'] === $rule_id && ! $renew ) {
				return;
			}
		}
		$rows = array_values( array_filter( $rows, static fn( array $row ): bool => ! ( $row['post_id'] === $post_id && $row['rule_id'] === $rule_id ) ) );
		array_unshift(
			$rows,
			array(
				'post_id' => $post_id,
				'rule_id' => $rule_id,
				'time'    => time(),
			)
		);
		update_option( self::OPTION, array_slice( $rows, 0, self::MAX ), false );
	}

	/**
	 * Quita las filas que cumplen una condición.
	 *
	 * @param callable $matches Recibe una fila y dice si se quita.
	 *
	 * @phpstan-param callable(array{post_id: int, rule_id: int, time: int}): bool $matches
	 */
	private static function drop( callable $matches ): void {
		$rows = self::all();
		$left = array_values( array_filter( $rows, static fn( array $row ): bool => ! $matches( $row ) ) );
		if ( count( $left ) !== count( $rows ) ) {
			update_option( self::OPTION, $left, false );
		}
	}
}
