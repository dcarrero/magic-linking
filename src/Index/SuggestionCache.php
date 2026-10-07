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
		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $prefix ) {
			add_action( $prefix . Installer::SETTINGS_OPTION, array( self::class, 'bump' ) );
		}
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
	private static function relevant( WP_Post $post ): bool {
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
