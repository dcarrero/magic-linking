<?php
/**
 * Caché de las sugerencias calculadas (docs/03 §8).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\Engine\Suggester;
use WP_Post;

/**
 * Guarda el resultado del motor por entrada en un *transient* **solo si el sitio tiene una caché de objetos
 * persistente**: sin ella, un transient iría a `wp_options` y el plugin no escribe sugerencias en la base de
 * datos (regla 8: nada nuevo en opciones). Sin caché de objetos se calcula cada vez (48 ms de p95 con
 * 10.000 entradas, `docs/03 §8`).
 *
 * La invalidación es por época: cualquier cambio que pueda alterar un resultado (guardar, borrar o cambiar de
 * estado una entrada, tocar los ajustes, indexar, insertar o deshacer un enlace) renueva la época y las
 * entradas viejas dejan de ser alcanzables; caducan solas a los 10 minutos. La época es una marca de tiempo
 * de `wp_cache_get_last_changed()`, que no se repite aunque la caché la expulse.
 */
final class SuggestionCache implements Module {

	/**
	 * Grupo de la caché de objetos donde vive la época.
	 */
	public const GROUP = 'magiclinking_suggestions';

	/**
	 * Segundos que se conserva un resultado.
	 */
	public const TTL = 600;

	/**
	 * Grupo de la caché de objetos que solo vive lo que dura la petición (nunca persistente).
	 */
	private const RUNTIME_GROUP = 'magiclinking_runtime';

	/**
	 * Engancha lo que invalida la caché.
	 *
	 * Solo cuenta lo que puede cambiar un resultado: las entradas de un tipo que se analiza, sin revisiones,
	 * autoguardados ni borradores automáticos, y de ellas el contenido de las publicadas y los cambios de
	 * estado que entran o salen de `publish` (un borrador no es candidato de nadie y sus salientes no se
	 * guardan). Los ajustes se vigilan al crearlos, cambiarlos y borrarlos. El resto (insertar, deshacer)
	 * escribe con `wp_update_post()` y ya pasa por aquí; el controlador renueva la época al final del lote.
	 */
	public function register(): void {
		add_action( 'save_post', array( $this, 'on_save' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'on_save' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		wp_cache_add_non_persistent_groups( self::RUNTIME_GROUP );
		Taxonomies::register();
		// Categorías y etiquetas (filtros de taxonomía, docs/04 §4.3): solo cuentan si hay alguno activo.
		// Renombrar o reordenar un término no cambia su `term_taxonomy_id`, que es lo que comparan los filtros.
		add_action( 'set_object_terms', array( $this, 'on_set_terms' ), 10, 6 );
		add_action( 'deleted_term_relationships', array( $this, 'on_terms' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_delete_term' ), 10, 3 );
		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $prefix ) {
			add_action( $prefix . Installer::SETTINGS_OPTION, array( self::class, 'bump' ) );
			add_action( $prefix . Installer::SETTINGS_OPTION, array( self::class, 'forget_filters' ) );
		}
	}

	/**
	 * Se asignan términos a una entrada: solo importa si de verdad cambia el conjunto.
	 *
	 * @param int          $object_id Entrada.
	 * @param array<mixed> $terms   Términos recibidos (no se usa).
	 * @param int[]        $tt_ids    `term_taxonomy_id` asignados.
	 * @param string       $taxonomy  Taxonomía.
	 * @param bool         $append    Se añaden a los que ya tenía.
	 * @param int[]|null   $old_tt_ids Los que tenía antes.
	 */
	public function on_set_terms( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ): void {
		unset( $terms );
		$old = array_map( 'intval', (array) $old_tt_ids );
		$new = array_map( 'intval', (array) $tt_ids );
		$new = $append ? array_merge( $old, $new ) : $new;
		sort( $old );
		$new = array_values( array_unique( $new ) );
		sort( $new );
		if ( $old !== $new ) {
			$this->on_terms( (int) $object_id, array(), (string) $taxonomy );
		}
	}

	/**
	 * Cambian los términos de una entrada: renueva la época si es una entrada publicada de un tipo que se analiza y la
	 * taxonomía es la que usa algún filtro en modo Considerar o Exigir.
	 *
	 * @param int          $object_id Entrada.
	 * @param array<mixed> $tt_ids `term_taxonomy_id` afectados (no se usa).
	 * @param string       $taxonomy  Taxonomía.
	 */
	public function on_terms( $object_id, $tt_ids = array(), $taxonomy = '' ): void {
		unset( $tt_ids );
		if ( ! self::taxonomy_filters_active() ) {
			return;
		}
		$post = get_post( (int) $object_id );
		if ( $post instanceof WP_Post && 'publish' === $post->post_status && self::relevant( $post ) && self::taxonomy_used( $post->post_type, (string) $taxonomy ) ) {
			self::bump();
		}
	}

	/**
	 * Se borra un término: importa si lo usa algún filtro activo de un tipo que se analiza.
	 *
	 * @param int    $term     Término.
	 * @param int    $tt_id    `term_taxonomy_id`.
	 * @param string $taxonomy Taxonomía.
	 */
	public function on_delete_term( $term, $tt_id, $taxonomy ): void {
		unset( $term, $tt_id );
		if ( ! self::taxonomy_filters_active() ) {
			return;
		}
		foreach ( ( new Settings() )->post_types() as $type ) {
			if ( self::taxonomy_used( $type, (string) $taxonomy ) ) {
				self::bump();
				return;
			}
		}
	}

	/**
	 * Si una taxonomía es la que lee algún filtro de categoría o etiqueta en modo Considerar o Exigir para un tipo.
	 *
	 * @param string $post_type Tipo de contenido.
	 * @param string $taxonomy  Taxonomía.
	 */
	private static function taxonomy_used( string $post_type, string $taxonomy ): bool {
		$filters = self::filters();
		foreach ( array(
			'category' => 'category',
			'post_tag' => 'tag',
		) as $logical => $filter ) {
			if ( Suggester::IGNORE !== $filters[ $filter ] && Taxonomies::primary( $post_type, $logical ) === $taxonomy ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Si algún filtro de categoría o etiqueta está en modo Considerar o Exigir (salida rápida de los hooks de términos).
	 */
	private static function taxonomy_filters_active(): bool {
		$filters = self::filters();

		return Suggester::IGNORE !== $filters['category'] || Suggester::IGNORE !== $filters['tag'];
	}

	/**
	 * Los filtros de los ajustes, leídos una vez por petición: los hooks de términos se disparan muchas veces y cada
	 * lectura sanea todos los ajustes. Se olvidan al cambiar los ajustes.
	 *
	 * @return array<string, string>
	 */
	private static function filters(): array {
		return self::site_settings()['filters'];
	}

	/**
	 * Lo que los hooks de términos y el indexado necesitan de los ajustes (los filtros por taxonomía, las categorías
	 * excluidas y K, F1-16), con una sola lectura por petición.
	 *
	 * @return array{filters: array<string, string>, excluded_categories: list<int>, k_terms: int}
	 */
	public static function site_settings(): array {
		$cached = wp_cache_get( 'site_settings', self::RUNTIME_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$all  = ( new Settings() )->all();
		$read = array(
			'filters'             => $all['filters'],
			'excluded_categories' => $all['excluded_categories'],
			'k_terms'             => $all['k_terms'],
		);
		wp_cache_set( 'site_settings', $read, self::RUNTIME_GROUP );

		return $read;
	}

	/**
	 * Olvida los ajustes recordados (cambian los ajustes).
	 */
	public static function forget_filters(): void {
		wp_cache_delete( 'site_settings', self::RUNTIME_GROUP );
		Exclusions::forget();
	}

	/**
	 * Guardar o borrar una entrada: renueva la época si es una publicada de un tipo que se analiza.
	 *
	 * @param int          $post_id ID.
	 * @param WP_Post|null $post    Entrada.
	 */
	public function on_save( int $post_id, ?WP_Post $post = null ): void {
		$post ??= get_post( $post_id );
		if ( $post instanceof WP_Post && 'publish' === $post->post_status && self::relevant( $post ) ) {
			self::bump();
		}
	}

	/**
	 * Cambio de estado: solo importa si entra o sale de `publish`.
	 *
	 * @param string  $new_status Estado nuevo.
	 * @param string  $old_status Estado anterior.
	 * @param WP_Post $post       Entrada.
	 */
	public function on_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( $new_status !== $old_status && ( 'publish' === $new_status || 'publish' === $old_status ) && self::relevant( $post ) ) {
			self::bump();
		}
	}

	/**
	 * Si una entrada puede afectar a las sugerencias de otras: de un tipo que se analiza y que no es una
	 * revisión ni un autoguardado.
	 *
	 * @param WP_Post $post Entrada.
	 */
	public static function relevant( WP_Post $post ): bool {
		if ( 'revision' === $post->post_type || wp_is_post_autosave( $post ) || 'auto-draft' === $post->post_status ) {
			return false;
		}

		return in_array( $post->post_type, ( new Settings() )->post_types(), true );
	}

	/**
	 * Renueva la época: lo guardado hasta ahora deja de servir.
	 */
	public static function bump(): void {
		wp_cache_set_last_changed( self::GROUP );
	}

	/**
	 * Si hay dónde guardar sin tocar la base de datos.
	 */
	public static function enabled(): bool {
		return (bool) wp_using_ext_object_cache();
	}

	/**
	 * El resultado guardado o, si no lo hay, el que calcula `$compute` (que se guarda).
	 *
	 * @template T
	 *
	 * @param string        $kind    Tipo de cálculo (`out`, `in`).
	 * @param int           $post_id Entrada.
	 * @param callable(): T $compute Cálculo.
	 *
	 * @return T
	 */
	public static function remember( string $kind, int $post_id, callable $compute ): mixed {
		if ( ! self::enabled() ) {
			return $compute();
		}

		$key    = 'magiclinking_sg_' . md5( implode( '|', array( MAGICLINKING_VERSION, $kind, $post_id, wp_cache_get_last_changed( self::GROUP ), Pillars::signature() ) ) );
		$stored = get_transient( $key );
		if ( is_array( $stored ) ) {
			return $stored;
		}

		$value = $compute();
		set_transient( $key, $value, self::TTL );

		return $value;
	}
}
