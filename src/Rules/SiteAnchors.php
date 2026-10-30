<?php
/**
 * Anclas que ya enlazan a un destino en todo el sitio.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rules;

use MagicLinking\Core\Schema;
use MagicLinking\Engine\Analyzer;
use wpdb;

/**
 * Cuenta, para un destino, en cuántas entradas distintas se usa cada ancla (por clave de raíz) según el grafo. Es la
 * cuenta del tope «misma ancla hacia el mismo destino» de docs/04 §6.1.
 */
final class SiteAnchors {

	/**
	 * Enlaces (entrada, ancla) por destino, leídos en esta petición.
	 *
	 * @var array<int, list<array{0: int, 1: string}>>
	 */
	private array $rows = array();

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Función que da, para una clave de ancla, las entradas distintas (sin contar la indicada) que ya la enlazan al destino.
	 *
	 * @param int      $target_id ID del destino.
	 * @param int      $exclude   Entrada que no cuenta (la que se está mirando).
	 * @param Analyzer $analyzer  Analizador con el que se calculan las claves.
	 *
	 * @return callable(string): int
	 */
	public function counter( int $target_id, int $exclude, Analyzer $analyzer ): callable {
		$by_key = null;

		return function ( string $key ) use ( &$by_key, $target_id, $exclude, $analyzer ): int {
			if ( null === $by_key ) {
				$by_key = array();
				foreach ( $this->links_to( $target_id ) as [ $source, $anchor ] ) {
					if ( $source !== $exclude ) {
						$by_key[ $analyzer->phrase_key( $anchor ) ][ $source ] = true;
					}
				}
			}

			return count( $by_key[ $key ] ?? array() );
		};
	}

	/**
	 * Olvida lo leído (tras escribir un enlace).
	 */
	public function forget(): void {
		$this->rows = array();
	}

	/**
	 * Enlaces internos que funcionan hacia un destino.
	 *
	 * @param int $target_id ID.
	 *
	 * @return list<array{0: int, 1: string}>
	 */
	private function links_to( int $target_id ): array {
		if ( ! isset( $this->rows[ $target_id ] ) ) {
			$wpdb  = $this->wpdb;
			$table = Schema::table( $wpdb->prefix, 'links' );
			$found = $wpdb->get_results( $wpdb->prepare( 'SELECT source_id, anchor FROM %i WHERE target_id = %d AND is_internal = 1 AND is_broken = 0', $table, $target_id ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tabla propia.

			$this->rows[ $target_id ] = array_map( static fn( array $row ): array => array( (int) $row[0], (string) $row[1] ), (array) $found );
		}

		return $this->rows[ $target_id ];
	}
}
