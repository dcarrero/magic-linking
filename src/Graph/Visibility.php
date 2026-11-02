<?php
/**
 * Qué entradas puede ver en el informe el usuario actual (en SQL, para que total, páginas y CSV cuadren).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

use wpdb;

/**
 * Fragmento de WHERE sobre `wp_posts` (con el alias que se indique) que deja pasar solo lo que el usuario puede ver.
 *
 * Por tipo, con las capacidades del propio tipo (`get_post_type_object()->cap`) y la regla del núcleo
 * (`get_posts_by_author_sql()`): se ve lo que está en un estado público, lo propio (en cualquier estado), lo privado
 * si se puede `read_private_posts` y, con `edit_others_posts`, lo ajeno en los demás estados (borrador, pendiente,
 * programada). Tener `edit_others_posts` no basta para ver privadas ajenas. Límite aceptado (D-79): no se miran los
 * permisos por entrada concreta (`map_meta_cap`) ni los coautores. En WP-CLI sin usuario (`--user`) se actúa como el
 * sistema y no se filtra.
 *
 * El fragmento se construye una vez por sitio, usuario, capacidades, alias y tipos y se reutiliza en esa instancia
 * (que es única, la del contenedor). Nunca incluye papelera ni autoborradores.
 */
final class Visibility {

	/**
	 * Conexión.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Fragmentos ya construidos.
	 *
	 * @var array<string, string>
	 */
	private array $memo = array();

	/**
	 * Si se fuerza el contexto de sistema (null: se calcula en cada llamada).
	 *
	 * @var bool|null
	 */
	private ?bool $system;

	/**
	 * Constructor.
	 *
	 * @param wpdb      $wpdb   Conexión.
	 * @param bool|null $system Forzar (true) o impedir (false) el contexto de sistema; null lo deduce de WP-CLI sin usuario.
	 */
	public function __construct( wpdb $wpdb, ?bool $system = null ) {
		$this->wpdb   = $wpdb;
		$this->system = $system;
	}

	/**
	 * Si se actúa como el sistema: WP-CLI sin usuario.
	 */
	private function system_context(): bool {
		return $this->system ?? ( defined( 'WP_CLI' ) && WP_CLI && 0 === get_current_user_id() );
	}

	/**
	 * Condición SQL (entre paréntesis) de las entradas visibles para el usuario actual.
	 *
	 * @param string             $alias Alias de la tabla de entradas.
	 * @param array<int, string> $types Tipos que se tienen en cuenta; los demás solo ven lo público y lo propio.
	 */
	public function post_sql( string $alias, array $types ): string {
		if ( $this->system_context() ) {
			return '( 1=1 )';
		}

		$types = array_values( array_unique( $types ) );
		sort( $types );
		$user = get_current_user_id();
		$caps = md5( (string) wp_json_encode( wp_get_current_user()->allcaps ) );
		$key  = get_current_blog_id() . '|' . $user . '|' . $caps . '|' . $alias . '|' . implode( ',', $types );
		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}

		$wpdb   = $this->wpdb;
		$public = array_values( array_map( 'strval', get_post_stati( array( 'public' => true ) ) ) );
		if ( array() === $public ) {
			$public = array( 'publish' );
		}
		$conditions   = array( $wpdb->prepare( "{$alias}.post_status IN (" . implode( ',', array_fill( 0, count( $public ), '%s' ) ) . ')', $public ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Marcadores generados.
		$conditions[] = $wpdb->prepare( "{$alias}.post_author = %d", $user ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Alias fijo de quien llama.

		$others  = array();
		$private = array();
		foreach ( $types as $type ) {
			$object = get_post_type_object( $type );
			if ( null === $object ) {
				continue;
			}
			if ( current_user_can( $object->cap->edit_others_posts ) ) {
				$others[] = $type;
			}
			if ( current_user_can( $object->cap->read_private_posts ) ) {
				$private[] = $type;
			}
		}

		if ( array() !== $others ) {
			$conditions[] = $wpdb->prepare( "({$alias}.post_status <> 'private' AND {$alias}.post_type IN (" . implode( ',', array_fill( 0, count( $others ), '%s' ) ) . '))', $others ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Marcadores generados.
		}
		if ( array() !== $private ) {
			$conditions[] = $wpdb->prepare( "({$alias}.post_status = 'private' AND {$alias}.post_type IN (" . implode( ',', array_fill( 0, count( $private ), '%s' ) ) . '))', $private ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Marcadores generados.
		}

		$this->memo[ $key ] = "( {$alias}.post_status NOT IN ('trash', 'auto-draft') AND ( " . implode( ' OR ', $conditions ) . ' ) )';

		return $this->memo[ $key ];
	}
}
