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

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Tabla propia; consultas pequeñas, con índice; los marcadores de la lista IN se generan.

/**
 * Tabla `magiclinking_dismissed` (docs/04 §11): un par por fila, para todo el sitio, sin autoload y sin
 * salir nunca de la base de datos del sitio. El par es dirigido: «enlazar desde el origen al destino».
 *
 * Un destino descartado desde más de {@see self::PENALTY_ORIGINS} orígenes distintos **publicados y de un tipo
 * que se analiza** baja su prior en todo el sitio ({@see \MagicLinking\Engine\Scorer::PENALTIES}): los
 * borradores no cuentan, para que nadie pueda rebajar un destino con entradas que no son contenido del sitio.
 *
 * El nombre de la tabla se resuelve en cada llamada con el prefijo actual de `$wpdb`, así que sigue a
 * `switch_to_blog()`.
 */
final class DismissalRepository {

	/**
	 * Orígenes distintos a partir de los cuales (estrictamente más) se rebaja el prior del destino.
	 */
	public const PENALTY_ORIGINS = 10;

	/**
	 * Grupo de la caché de objetos donde se guarda la lista de destinos rebajados.
	 */
	private const CACHE_GROUP = 'magiclinking_dismissals';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Tabla del sitio actual.
	 */
	private function table(): string {
		return Schema::table( $this->wpdb->prefix, 'dismissed' );
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

		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (source_id, target_id, user_id, created_at) VALUES (%d, %d, %d, %s)',
				$this->table(),
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
		return false !== $this->wpdb->delete(
			$this->table(),
			array(
				'source_id' => $source,
				'target_id' => $target,
			),
			array( '%d', '%d' )
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

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT target_id FROM %i WHERE source_id = %d', $this->table(), $source ) ) );
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

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT source_id FROM %i WHERE target_id = %d', $this->table(), $target ) ) );
	}

	/**
	 * Destinos descartados desde más de {@see self::PENALTY_ORIGINS} orígenes distintos que estén publicados y
	 * sean de un tipo que se analiza.
	 *
	 * Se guarda en la caché de objetos ligada a la época de {@see SuggestionCache}: cualquier cambio que renueve
	 * la época (descartar, deshacer, olvidar, publicar o despublicar una entrada) la invalida. Sin caché
	 * persistente vale lo que dura la petición.
	 *
	 * @param array $post_types Tipos de contenido que se analizan.
	 *
	 * @return list<int>
	 *
	 * @phpstan-param list<string> $post_types
	 */
	public function penalized_targets( array $post_types ): array {
		$wpdb = $this->wpdb;
		if ( array() === $post_types ) {
			return array();
		}

		$key    = md5( implode( '|', array( $wpdb->prefix, implode( ',', $post_types ), wp_cache_get_last_changed( SuggestionCache::GROUP ) ) ) );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		$marks = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$found = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT d.target_id FROM %i d INNER JOIN %i p ON p.ID = d.source_id WHERE p.post_status = 'publish' AND p.post_type IN ({$marks}) GROUP BY d.target_id HAVING COUNT(*) > %d ORDER BY d.target_id",
					...array_merge( array( $this->table(), $wpdb->posts ), $post_types, array( self::PENALTY_ORIGINS ) )
				)
			)
		);
		wp_cache_set( $key, $found, self::CACHE_GROUP, SuggestionCache::TTL );

		return $found;
	}

	/**
	 * Olvida todos los pares de una entrada (se borró): no quedan filas huérfanas.
	 *
	 * @param int $post_id Entrada.
	 *
	 * @return int Filas borradas.
	 */
	public function forget_post( int $post_id ): int {
		$wpdb = $this->wpdb;

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source_id = %d OR target_id = %d', $this->table(), $post_id, $post_id ) );
	}
}
