<?php
/**
 * Caché de las sugerencias calculadas (docs/03 §8).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use MagicLinking\Core\Module;

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
	 * Engancha lo que invalida la caché.
	 */
	public function register(): void {
		foreach ( array( 'save_post', 'deleted_post', 'transition_post_status', 'update_option_magiclinking_settings', 'magiclinking_link_inserted', 'magiclinking_batch_undone' ) as $hook ) {
			add_action( $hook, array( self::class, 'bump' ) );
		}
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

		$key    = 'magiclinking_sg_' . md5( implode( '|', array( MAGICLINKING_VERSION, $kind, $post_id, wp_cache_get_last_changed( self::GROUP ) ) ) );
		$stored = get_transient( $key );
		if ( is_array( $stored ) ) {
			return $stored;
		}

		$value = $compute();
		set_transient( $key, $value, self::TTL );

		return $value;
	}
}
