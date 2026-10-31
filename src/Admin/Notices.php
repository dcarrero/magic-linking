<?php
/**
 * Registro de avisos del plugin (docs/07 §5).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Admin;

use MagicLinking\Core\Schema;
use MagicLinking\History\ChangeRepository;
use wpdb;

/**
 * Qué aviso toca enseñar, si toca alguno.
 *
 * Reglas (regla 7 de CLAUDE.md): solo dentro de las pantallas del plugin (nada de `admin_notices`), como máximo uno a
 * la vez (el de más prioridad), todos se cierran para siempre y por usuario en un solo metadato con los
 * identificadores cerrados, y ninguno es comercial ni habla de límites (docs/02 §3.3 y §5).
 *
 * Cada definición: `id`, `priority` (menor = antes), `type` (info, warning o error), `applies` (booleano o función
 * que lo calcula; solo se llama si el aviso no está cerrado), y para pintarlo `text`, `action` (opcional, con `label`
 * y `url`) y `dismiss_label`.
 *
 * @phpstan-type Definition array{id: string, priority: int, type: string, applies: bool|callable(): bool, text?: string, action?: array{label: string, url: string}, dismiss_label?: string}
 */
final class Notices {

	/**
	 * Metadato de usuario con la lista de identificadores cerrados (sin el prefijo del sitio).
	 */
	public const META = 'magiclinking_dismissed_notices';

	/**
	 * Opción (sin autoload) con la fecha de activación, en segundos Unix.
	 */
	public const ACTIVATED_OPTION = 'magiclinking_activated_at';

	/**
	 * Enlaces insertados que hacen falta para pedir una reseña.
	 */
	public const REVIEW_LINKS = 20;

	/**
	 * Días desde la activación antes de pedir una reseña.
	 */
	public const REVIEW_DAYS = 30;

	/**
	 * Página de reseñas del plugin en wordpress.org.
	 */
	public const REVIEW_URL = 'https://wordpress.org/support/plugin/magic-linking/reviews/#new-post';

	private const CACHE_GROUP = 'magiclinking_notices';

	/**
	 * Definiciones; null = las del plugin.
	 *
	 * @var list<Definition>|null
	 */
	private ?array $definitions;

	/**
	 * Constructor.
	 *
	 * @param wpdb                  $wpdb        Base de datos.
	 * @param list<Definition>|null $definitions Definiciones propias (pruebas); por defecto las del plugin.
	 */
	public function __construct( private wpdb $wpdb, ?array $definitions = null ) {
		$this->definitions = $definitions;
	}

	/**
	 * Clave del metadato en este sitio (con el prefijo de las tablas: en una red cada sitio cierra los suyos).
	 */
	public static function meta_key(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . self::META;
	}

	/**
	 * Guarda la fecha de activación si aún no existe, sin autoload.
	 */
	public static function remember_activation(): void {
		add_option( self::ACTIVATED_OPTION, time(), '', false );
	}

	/**
	 * Elige el aviso que se ve: el de más prioridad que no esté cerrado y cuya condición se cumpla.
	 *
	 * @param array $definitions Definiciones.
	 * @param array $dismissed   Identificadores cerrados.
	 *
	 * @phpstan-param list<Definition> $definitions
	 * @phpstan-param list<string>     $dismissed
	 * @phpstan-return Definition|null
	 *
	 * @return array|null
	 */
	public static function select( array $definitions, array $dismissed ): ?array {
		usort( $definitions, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );

		foreach ( $definitions as $definition ) {
			if ( in_array( $definition['id'], $dismissed, true ) ) {
				continue;
			}
			$applies = $definition['applies'];
			if ( is_callable( $applies ) ? (bool) $applies() : (bool) $applies ) {
				return $definition;
			}
		}

		return null;
	}

	/**
	 * Aviso que toca enseñar a un usuario, listo para la pantalla, o null.
	 *
	 * @param int $user_id Usuario.
	 *
	 * @return array{id: string, type: string, text: string, action: array{label: string, url: string}|null, dismiss_label: string}|null
	 */
	public function current( int $user_id ): ?array {
		$definition = self::select( $this->definitions( $user_id ), $this->dismissed( $user_id ) );
		if ( null === $definition ) {
			return null;
		}

		return array(
			'id'            => $definition['id'],
			'type'          => $definition['type'],
			'text'          => $definition['text'] ?? '',
			'action'        => $definition['action'] ?? null,
			'dismiss_label' => $definition['dismiss_label'] ?? __( 'Do not show again', 'magic-linking' ),
		);
	}

	/**
	 * Cierra un aviso para siempre para ese usuario. Devuelve false si el identificador no existe.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $id      Identificador del aviso.
	 */
	public function dismiss( int $user_id, string $id ): bool {
		if ( ! in_array( $id, array_column( $this->definitions( $user_id ), 'id' ), true ) ) {
			return false;
		}

		$dismissed = $this->dismissed( $user_id );
		if ( ! in_array( $id, $dismissed, true ) ) {
			$dismissed[] = $id;
			update_user_meta( $user_id, self::meta_key(), $dismissed );
		}

		return true;
	}

	/**
	 * Identificadores cerrados por un usuario.
	 *
	 * @param int $user_id Usuario.
	 *
	 * @return list<string>
	 */
	private function dismissed( int $user_id ): array {
		$value = get_user_meta( $user_id, self::meta_key(), true );

		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * Definiciones vigentes.
	 *
	 * @param int $user_id Usuario para el que se evalúan.
	 *
	 * @return list<Definition>
	 */
	private function definitions( int $user_id ): array {
		if ( null !== $this->definitions ) {
			return $this->definitions;
		}

		return array(
			array(
				'id'            => 'review',
				'priority'      => 100,
				'type'          => 'info',
				'applies'       => fn(): bool => $this->review_due( $user_id ),
				'text'          => __( 'You have added a good number of links with Magic Linking. If it is saving you time, a review on wordpress.org helps other people find it.', 'magic-linking' ),
				'action'        => array(
					'label' => __( 'Write a review', 'magic-linking' ),
					'url'   => self::REVIEW_URL,
				),
				'dismiss_label' => __( 'No, thanks', 'magic-linking' ),
			),
		);
	}

	/**
	 * ¿Toca pedir la reseña? Primero lo barato (los días desde la activación) y después el recuento.
	 *
	 * @param int $user_id Usuario.
	 */
	private function review_due( int $user_id ): bool {
		$activated = (int) get_option( self::ACTIVATED_OPTION, 0 );
		if ( $activated <= 0 ) {
			// Sitio que ya tenía el plugin antes de este aviso: los 30 días cuentan desde esta primera carga.
			self::remember_activation();

			return false;
		}
		if ( time() - $activated < self::REVIEW_DAYS * DAY_IN_SECONDS ) {
			return false;
		}

		return $this->inserted_links( $user_id ) >= self::REVIEW_LINKS;
	}

	/**
	 * Enlaces insertados por el usuario que siguen puestos, contados solo hasta el umbral.
	 *
	 * Una subconsulta con LIMIT: se detiene al llegar a 20 en vez de recorrer todo el Historial. Se guarda en la caché
	 * de objetos unos minutos.
	 *
	 * @param int $user_id Usuario.
	 */
	private function inserted_links( int $user_id ): int {
		$key    = 'links_' . $user_id;
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$table = Schema::table( $this->wpdb->prefix, 'changes' );
		$query = $this->wpdb->prepare(
			'SELECT COUNT(*) FROM ( SELECT 1 FROM %i WHERE user_id = %d AND action = %s AND undone_at IS NULL LIMIT %d ) AS c',
			$table,
			$user_id,
			ChangeRepository::INSERT,
			self::REVIEW_LINKS
		);
		$count = (int) $this->wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Preparada arriba y cacheada justo debajo.
		wp_cache_set( $key, $count, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );

		return $count;
	}
}
