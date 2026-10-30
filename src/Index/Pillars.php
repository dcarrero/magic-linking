<?php
/**
 * Contenido pilar: la marca propia del plugin y la de Yoast SEO y Rank Math.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use MagicLinking\Core\Module;
use wpdb;

/**
 * Una entrada es pilar si está marcada en cualquiera de los tres sitios; no se pide nada al usuario que ya la
 * tenga marcada en su plugin de SEO (docs/04 §6, docs/07 §3.4). La meta de cada plugin solo se lee mientras ese
 * plugin está activo (decisión del usuario): con Yoast SEO o Rank Math desactivados, su marca se ignora. Se sabe por la
 * constante que cada uno define en su fichero principal, sin depender de sus clases:
 *  - `WPSEO_VERSION` (https://github.com/Yoast/wordpress-seo/blob/trunk/wp-seo-main.php).
 *  - `RANK_MATH_VERSION` (https://github.com/rankmath/seo-by-rank-math/blob/master/rank-math.php).
 * El filtro `magiclinking_seo_plugin_active` permite a las pruebas decidirlo.
 *
 * Nombres y valores de las metas, contrastados el 30-10-2026 con el código de cada plugin:
 *  - Yoast SEO: `_yoast_wpseo_is_cornerstone`, valor `1`
 *    (https://github.com/Yoast/wordpress-seo/blob/trunk/inc/class-upgrade.php renombra la clave antigua a esa;
 *    https://github.com/Yoast/wordpress-seo/blob/trunk/tests/Unit/Builders/Indexable_Post_Builder_Test.php usa `'1'`).
 *  - Rank Math: `rank_math_pillar_content`, valor `on`
 *    (https://github.com/rankmath/seo-by-rank-math/blob/master/includes/admin/class-post-columns.php compara con `'on'`).
 *
 * Los metadatos de entrada no se cargan automáticamente (no son opciones, regla 8). La lista de pilares se
 * guarda en la caché de objetos de la petición, atada al mismo contador que las sugerencias.
 */
final class Pillars implements Module {

	/**
	 * Marca propia del plugin (valor `1`).
	 */
	public const OWN_KEY = '_magiclinking_pillar';

	/**
	 * Meta de Yoast SEO (valor `1`).
	 */
	public const YOAST_KEY = '_yoast_wpseo_is_cornerstone';

	/**
	 * Meta de Rank Math (valor `on`).
	 */
	public const RANK_MATH_KEY = 'rank_math_pillar_content';

	/**
	 * Grupo de la caché de objetos.
	 */
	private const CACHE_GROUP = 'magiclinking_pillars';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Cualquier cambio en una de las tres marcas invalida las sugerencias guardadas.
	 */
	public function register(): void {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_meta' ), 10, 3 );
		}
	}

	/**
	 * Un cambio de meta: si es de una marca de pilar, invalida las sugerencias.
	 *
	 * @param int|int[] $meta_id Identificador(es) de la meta.
	 * @param int       $post_id Entrada.
	 * @param string    $key     Clave.
	 */
	public function on_meta( $meta_id, int $post_id, string $key ): void {
		unset( $meta_id, $post_id );
		if ( in_array( $key, array( self::OWN_KEY, self::YOAST_KEY, self::RANK_MATH_KEY ), true ) ) {
			SuggestionCache::bump();
		}
	}

	/**
	 * Si un plugin de SEO está activo.
	 *
	 * @param string $plugin `yoast` o `rank_math`.
	 */
	public function active( string $plugin ): bool {
		$constant = 'yoast' === $plugin ? 'WPSEO_VERSION' : 'RANK_MATH_VERSION';

		return (bool) apply_filters( 'magiclinking_seo_plugin_active', defined( $constant ), $plugin ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Filtro propio.
	}

	/**
	 * IDs de todas las entradas marcadas como pilar (una consulta por petición y estado de las sugerencias).
	 *
	 * @return list<int>
	 */
	public function ids(): array {
		$yoast  = $this->active( 'yoast' );
		$rank   = $this->active( 'rank_math' );
		$key    = 'ids_' . (int) $yoast . (int) $rank . '_' . wp_cache_get_last_changed( SuggestionCache::GROUP );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		$wpdb  = $this->wpdb;
		$where = array( $wpdb->prepare( '( meta_key = %s AND meta_value = %s )', self::OWN_KEY, '1' ) );
		if ( $yoast ) {
			$where[] = $wpdb->prepare( '( meta_key = %s AND meta_value = %s )', self::YOAST_KEY, '1' );
		}
		if ( $rank ) {
			$where[] = $wpdb->prepare( '( meta_key = %s AND meta_value = %s )', self::RANK_MATH_KEY, 'on' );
		}
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Condiciones ya preparadas; se guarda en la caché de objetos justo debajo.
			$wpdb->prepare( 'SELECT DISTINCT post_id FROM %i WHERE ', $wpdb->postmeta ) . implode( ' OR ', $where ) . ' ORDER BY post_id ASC'
		);
		$ids = array_map( 'intval', (array) $ids );

		wp_cache_set( $key, $ids, self::CACHE_GROUP, SuggestionCache::TTL );

		return $ids;
	}

	/**
	 * Marca de un plugin de SEO, si la hay: `yoast` o `rank_math` (en ese orden). Esa se cambia en su plugin, no aquí.
	 *
	 * @param int $post_id ID.
	 */
	public function external( int $post_id ): ?string {
		if ( $this->active( 'yoast' ) && '1' === (string) get_post_meta( $post_id, self::YOAST_KEY, true ) ) {
			return 'yoast';
		}
		if ( $this->active( 'rank_math' ) && 'on' === (string) get_post_meta( $post_id, self::RANK_MATH_KEY, true ) ) {
			return 'rank_math';
		}

		return null;
	}

	/**
	 * Si la casilla propia está marcada (independiente de lo que digan Yoast o Rank Math).
	 *
	 * @param int $post_id ID.
	 */
	public function own( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, self::OWN_KEY, true );
	}

	/**
	 * Marca o desmarca la casilla propia. Desmarcada se borra la meta, no se guarda un 0.
	 *
	 * @param int  $post_id ID.
	 * @param bool $pillar  Marcada.
	 */
	public function set_own( int $post_id, bool $pillar ): void {
		if ( $pillar ) {
			update_post_meta( $post_id, self::OWN_KEY, '1' );
		} else {
			delete_post_meta( $post_id, self::OWN_KEY );
		}
	}
}
