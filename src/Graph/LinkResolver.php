<?php
/**
 * Resuelve URLs internas a entradas, sin peticiones HTTP.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

use wpdb;

/**
 * Decide si una URL interna funciona, a qué entrada apunta y, si no funciona, por qué.
 *
 * Roto es solo lo que de verdad no lleva a ninguna parte: una URL que parece
 * de una entrada y no existe, o una entrada en la papelera, sin publicar o
 * privada. La portada, los archivos (categorías, etiquetas, autores, fechas),
 * las búsquedas y los feeds cuentan como destinos válidos aunque no sean una
 * entrada. Las URLs antiguas de una entrada (`_wp_old_slug`) se siguen
 * resolviendo, porque WordPress las redirige.
 *
 * Usa una caché en memoria por URL; conviene una instancia por proceso.
 */
final class LinkResolver {

	/**
	 * Variables de consulta que identifican una entrada suelta.
	 */
	private const POST_QUERY_VARS = array( 'p', 'page_id', 'pagename', 'name', 'attachment', 'attachment_id' );

	/**
	 * Máximo de resoluciones en memoria; al llegar, se vacía la caché.
	 */
	private const CACHE_LIMIT = 5000;

	/**
	 * Estados que no son públicos, con su código.
	 */
	private const STATUS_CODES = array(
		'trash'   => BrokenReason::TRASHED,
		'draft'   => BrokenReason::UNPUBLISHED,
		'pending' => BrokenReason::UNPUBLISHED,
		'future'  => BrokenReason::UNPUBLISHED,
		'private' => BrokenReason::PRIVATE_POST,
	);

	/**
	 * Conexión.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Resoluciones ya calculadas.
	 *
	 * @var array<string, Resolution>
	 */
	private array $cache = array();

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Olvida las resoluciones guardadas (al cambiar el estado de una entrada).
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * Resuelve una URL interna absoluta.
	 *
	 * @param string $url URL absoluta y normalizada (LinkParser).
	 */
	public function resolve( string $url ): Resolution {
		if ( isset( $this->cache[ $url ] ) ) {
			return $this->cache[ $url ];
		}

		// Una ejecución larga no debe acumular todas las URLs del sitio en memoria.
		if ( count( $this->cache ) >= self::CACHE_LIMIT ) {
			$this->cache = array();
		}

		$this->cache[ $url ] = $this->compute( $url );

		return $this->cache[ $url ];
	}

	/**
	 * Cálculo sin caché.
	 *
	 * @param string $url URL absoluta.
	 */
	private function compute( string $url ): Resolution {
		/**
		 * Permite a un plugin de redirecciones (o a cualquier otro) decidir el destino de una URL interna.
		 *
		 * @param Resolution|null $resolution Null para dejar que decida el plugin.
		 * @param string          $url        URL interna absoluta.
		 */
		$override = apply_filters( 'magiclinking_resolve_link', null, $url );
		if ( $override instanceof Resolution ) {
			return $override;
		}

		$post_id = url_to_postid( $url );
		if ( $post_id > 0 ) {
			return $this->from_post( $post_id );
		}

		$by_id = $this->post_from_query( $url );
		if ( null !== $by_id ) {
			return $by_id;
		}

		$path = $this->relative_path( $url );

		// Portada sin página estática, o URL con consulta que no es una entrada suelta.
		if ( '' === $path && ! $this->has_post_query_var( $url ) ) {
			return new Resolution( null );
		}

		$hidden = $this->find_by_slug( $path );
		if ( null !== $hidden ) {
			return $hidden;
		}

		return $this->is_post_route( $url, $path )
			? new Resolution( null, BrokenReason::NOT_FOUND )
			: new Resolution( null );
	}

	/**
	 * Resolución a partir de una entrada encontrada.
	 *
	 * @param int $post_id ID.
	 */
	private function from_post( int $post_id ): Resolution {
		$status = get_post_status( $post_id );

		// url_to_postid() devuelve el número de ?p=123 aunque la entrada no exista.
		if ( false === $status ) {
			return new Resolution( null, BrokenReason::NOT_FOUND );
		}

		if ( isset( self::STATUS_CODES[ $status ] ) ) {
			return new Resolution( $post_id, self::STATUS_CODES[ $status ] );
		}

		return new Resolution( $post_id );
	}

	/**
	 * Entrada a la que apunta una URL del tipo ?p=123 o ?page_id=123, esté como esté.
	 *
	 * @param string $url URL.
	 */
	private function post_from_query( string $url ): ?Resolution {
		$vars = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $vars );

		foreach ( array( 'p', 'page_id' ) as $key ) {
			if ( isset( $vars[ $key ] ) && is_string( $vars[ $key ] ) && ctype_digit( $vars[ $key ] ) && get_post_status( (int) $vars[ $key ] ) ) {
				return $this->from_post( (int) $vars[ $key ] );
			}
		}

		return null;
	}

	/**
	 * Busca una entrada que no es pública (papelera, borrador…) o con un slug antiguo.
	 *
	 * @param string $path Ruta relativa al sitio.
	 */
	private function find_by_slug( string $path ): ?Resolution {
		$segments = array_values( array_filter( explode( '/', $path ), static fn( string $segment ): bool => '' !== $segment ) );
		$slug     = array() === $segments ? '' : sanitize_title( rawurldecode( (string) end( $segments ) ) );

		if ( '' === $slug ) {
			return null;
		}

		$wpdb  = $this->wpdb;
		$posts = $wpdb->posts;
		$meta  = $wpdb->postmeta;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Consulta de análisis puntual.
			$wpdb->prepare(
				"SELECT ID, post_status FROM {$posts} WHERE post_name IN (%s, %s) AND post_status IN ('trash', 'draft', 'pending', 'private', 'future') AND post_type NOT IN ('revision', 'nav_menu_item') ORDER BY ID DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla del núcleo.
				$slug,
				$slug . '__trashed'
			)
		);
		if ( null !== $row && isset( self::STATUS_CODES[ $row->post_status ] ) ) {
			return new Resolution( (int) $row->ID, self::STATUS_CODES[ $row->post_status ] );
		}

		$old = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Consulta de análisis puntual.
			$wpdb->prepare(
				"SELECT post_id FROM {$meta} WHERE meta_key = '_wp_old_slug' AND meta_value = %s ORDER BY meta_id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla del núcleo.
				$slug
			)
		);

		return null === $old ? null : $this->from_post( (int) $old );
	}

	/**
	 * Si WordPress trataría esta ruta como la de una entrada suelta (y no como un archivo, un feed, etc.).
	 *
	 * @param string $url  URL absoluta.
	 * @param string $path Ruta relativa al sitio.
	 */
	private function is_post_route( string $url, string $path ): bool {
		if ( $this->has_post_query_var( $url ) ) {
			return true;
		}

		global $wp_rewrite;

		$rules = is_object( $wp_rewrite ) ? $wp_rewrite->wp_rewrite_rules() : false;
		if ( ! is_array( $rules ) || array() === $rules ) {
			// Enlaces permanentes simples: una ruta sin consulta no lleva a nada.
			return '' !== $path;
		}

		$request = preg_replace( '#^index\.php/#', '', $path );

		foreach ( $rules as $match => $query ) {
			if ( ! preg_match( "#^{$match}#", (string) $request ) ) {
				continue;
			}

			$vars = array();
			parse_str( (string) substr( (string) strstr( (string) $query, '?' ), 1 ), $vars );

			return array() !== array_intersect( array_keys( $vars ), $this->post_query_vars() );
		}

		return true;
	}

	/**
	 * Variables de consulta de una entrada suelta, incluidas las de los tipos personalizados.
	 *
	 * @return list<string>
	 */
	private function post_query_vars(): array {
		$vars = self::POST_QUERY_VARS;

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( is_string( $type->query_var ) && '' !== $type->query_var ) {
				$vars[] = $type->query_var;
			}
		}

		return $vars;
	}

	/**
	 * Si la URL lleva una consulta de entrada suelta (?p=123, ?page_id=…).
	 *
	 * @param string $url URL.
	 */
	private function has_post_query_var( string $url ): bool {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $query ) {
			return false;
		}

		$vars = array();
		parse_str( $query, $vars );

		return array() !== array_intersect( array_keys( $vars ), self::POST_QUERY_VARS );
	}

	/**
	 * Ruta de la URL sin la del sitio ni las barras de los extremos.
	 *
	 * @param string $url URL absoluta.
	 */
	private function relative_path( string $url ): string {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( '' !== $home && ( $path === $home || 0 === strpos( $path, $home . '/' ) ) ) {
			$path = ltrim( substr( $path, strlen( $home ) ), '/' );
		}

		return $path;
	}
}
