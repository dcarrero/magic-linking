<?php
/**
 * Sugerencias descartadas: pares (origen, destino) que no se vuelven a proponer.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use MagicLinking\Core\Schema;
use wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Tabla propia; las lecturas son pequeñas, con índice, y se cachean por petición.

/**
 * Tabla `magiclinking_dismissed` (docs/04 §11): un par por fila, para todo el sitio, sin autoload y sin
 * salir nunca de la base de datos del sitio. El par es dirigido: «enlazar desde el origen al destino».
 *
 * Un destino descartado desde más de {@see self::PENALTY_ORIGINS} orígenes distintos baja su prior en todo
 * el sitio ({@see \MagicLinking\Engine\Scorer::PENALTIES}).
 */
final class DismissalRepository {

	/**
	 * Orígenes distintos a partir de los cuales (estrictamente más) se rebaja el prior del destino.
	 */
	public const PENALTY_ORIGINS = 10;

	/**
	 * Tabla.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Destinos con el prior rebajado, calculados una vez por petición y escritura.
	 *
	 * @var list<int>|null
	 */
	private ?array $penalized = null;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión.
	 */
	public function __construct( private wpdb $wpdb ) {
		$this->table = Schema::table( $wpdb->prefix, 'dismissed' );
	}

	/**
	 * Descarta un par. Idempotente: descartar dos veces el mismo par deja una sola fila.
	 *
	 * @param int $source  Entrada donde iría el enlace.
	 * @param int $target  Entrada a la que enlazaría.
	 * @param int $user_id Quién lo descarta.
	 *
	 * @return bool Si el par queda descartado.
	 */
	public function add( int $source, int $target, int $user_id ): bool {
		$wpdb = $this->wpdb;
		if ( $source < 1 || $target < 1 || $source === $target ) {
			return false;
		}

		$this->penalized = null;
		$result          = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (source_id, target_id, user_id, created_at) VALUES (%d, %d, %d, %s)',
				$this->table,
				$source,
				$target,
				$user_id,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return false !== $result;
	}

	/**
	 * Deshace el descarte de un par. Idempotente.
	 *
	 * @param int $source Origen.
	 * @param int $target Destino.
	 *
	 * @return bool Si el par queda sin descartar.
	 */
	public function remove( int $source, int $target ): bool {
		$wpdb            = $this->wpdb;
		$this->penalized = null;

		return false !== $wpdb->delete(
			$this->table,
			array(
				'source_id' => $source,
				'target_id' => $target,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Si un par está descartado.
	 *
	 * @param int $source Origen.
	 * @param int $target Destino.
	 */
	public function has( int $source, int $target ): bool {
		$wpdb = $this->wpdb;
		return null !== $wpdb->get_var(
			$wpdb->prepare( 'SELECT 1 FROM %i WHERE source_id = %d AND target_id = %d', $this->table, $source, $target )
		);
	}

	/**
	 * Destinos descartados para un origen.
	 *
	 * @param int $source Origen.
	 *
	 * @return list<int>
	 */
	public function targets_for_source( int $source ): array {
		$wpdb = $this->wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT target_id FROM %i WHERE source_id = %d', $this->table, $source ) ) );
	}

	/**
	 * Orígenes descartados para un destino.
	 *
	 * @param int $target Destino.
	 *
	 * @return list<int>
	 */
	public function sources_for_target( int $target ): array {
		$wpdb = $this->wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT source_id FROM %i WHERE target_id = %d', $this->table, $target ) ) );
	}

	/**
	 * Destinos descartados desde más de {@see self::PENALTY_ORIGINS} orígenes distintos.
	 *
	 * @return list<int>
	 */
	public function penalized_targets(): array {
		$wpdb = $this->wpdb;
		if ( null === $this->penalized ) {
			$this->penalized = array_map(
				'intval',
				$wpdb->get_col(
					$wpdb->prepare( 'SELECT target_id FROM %i GROUP BY target_id HAVING COUNT(*) > %d ORDER BY target_id', $this->table, self::PENALTY_ORIGINS )
				)
			);
		}

		return $this->penalized;
	}

	/**
	 * Olvida todos los pares de una entrada (se borró): no quedan filas huérfanas.
	 *
	 * @param int $post_id Entrada.
	 */
	public function forget_post( int $post_id ): void {
		$wpdb            = $this->wpdb;
		$this->penalized = null;

		// Una entrada puede borrarse (cron, otro plugin) antes de que el esquema nuevo se haya instalado: sin tabla no hay nada que olvidar.
		$quiet = $wpdb->suppress_errors( true );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source_id = %d OR target_id = %d', $this->table, $post_id, $post_id ) );
		$wpdb->suppress_errors( $quiet );
	}
}
