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
	 * Entradas pendientes de revisar, de la más reciente a la más antigua.
	 *
	 * @return list<array{post_id: int, time: int}>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $row ) {
			if ( is_array( $row ) && isset( $row['post_id'], $row['time'] ) ) {
				$out[] = array(
					'post_id' => (int) $row['post_id'],
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
		$rows = array_values( array_filter( self::all(), static fn( array $row ): bool => $row['post_id'] !== $post_id ) );
		array_unshift(
			$rows,
			array(
				'post_id' => $post_id,
				'time'    => time(),
			)
		);
		update_option( self::OPTION, array_slice( $rows, 0, self::MAX ), false );
	}

	/**
	 * Quita una entrada (ya se aplicó o el usuario la descartó).
	 *
	 * @param int $post_id Entrada.
	 */
	public static function remove( int $post_id ): void {
		$rows = self::all();
		$left = array_values( array_filter( $rows, static fn( array $row ): bool => $row['post_id'] !== $post_id ) );
		if ( count( $left ) !== count( $rows ) ) {
			update_option( self::OPTION, $left, false );
		}
	}
}
