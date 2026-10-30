<?php
/**
 * Aplicación de las reglas automáticas al guardar una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rules;

use MagicLinking\Content\PostWriter;
use MagicLinking\Core\Installer;
use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use Throwable;
use WP_Post;

/**
 * Al guardar una entrada publicada, **encola** una acción de Action Scheduler que aplica las reglas activas; nunca
 * escribe en la misma petición del guardado ni al renderizar (docs/02 §4.4). Así:
 *
 * - El guardado del usuario no espera ni se pisa con lo que escribe una regla.
 * - Una entrada abierta en el editor no se toca (docs/06 §1.5, regla 5): si hay un bloqueo vigente, la acción se
 *   reprograma unos minutos más tarde, hasta {@see self::MAX_ATTEMPTS} veces.
 * - Sin bucles: lo que escribe el propio plugin (insertar, deshacer, rehacer, las propias reglas) vuelve a pasar por
 *   `wp_after_insert_post`, y {@see PostWriter::is_writing()} lo deja pasar sin encolar nada. Además, una regla que ya
 *   se aplicó a una entrada (o se deshizo allí) no vuelve a aplicarse sola.
 *
 * Sin coste en el front-end: los ganchos solo se enganchan en peticiones que pueden guardar entradas y no hacen
 * consultas si no hay ninguna regla activa.
 */
final class RuleRunner implements Module {

	/**
	 * Acción de Action Scheduler que aplica las reglas a una entrada.
	 */
	public const HOOK = 'magiclinking_apply_rules';

	/**
	 * Veces que se reintenta una entrada que tiene abierta alguien en el editor.
	 */
	public const MAX_ATTEMPTS = 12;

	/**
	 * Segundos entre reintentos.
	 */
	public const RETRY_SECONDS = 300;

	/**
	 * Constructor.
	 *
	 * @param RuleRepository $rules    Reglas.
	 * @param RuleService    $service  Aplicación.
	 * @param Settings       $settings Ajustes.
	 */
	public function __construct( private RuleRepository $rules, private RuleService $service, private Settings $settings ) {
	}

	/**
	 * Engancha el guardado y la acción diferida.
	 */
	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'on_post_saved' ), 30, 4 );
		add_action( self::HOOK, array( $this, 'run' ), 10, 2 );
	}

	/**
	 * Al guardar: encola la aplicación de las reglas si hay alguna activa y la entrada es candidata.
	 *
	 * @param int          $post_id     ID.
	 * @param WP_Post      $post        Entrada guardada.
	 * @param bool         $update      Si es una actualización.
	 * @param WP_Post|null $post_before Entrada antes del cambio.
	 */
	public function on_post_saved( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before = null ): void {
		unset( $update );

		if ( PostWriter::is_writing() || 'publish' !== $post->post_status || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		// Solo si cambió el texto o la entrada acaba de publicarse: guardar sin tocar el contenido no dispara nada.
		if ( null !== $post_before && 'publish' === $post_before->post_status && $post_before->post_content === $post->post_content && $post_before->post_type === $post->post_type ) {
			return;
		}
		if ( ! $this->ready() || ! in_array( $post->post_type, $this->settings->post_types(), true ) || ! $this->rules->has_active() ) {
			return;
		}

		$this->enqueue( $post_id, 0, 0 );
	}

	/**
	 * Manejador de Action Scheduler.
	 *
	 * @param int $post_id ID de la entrada.
	 * @param int $attempt Intento actual (0 la primera vez).
	 */
	public function run( int $post_id, int $attempt = 0 ): void {
		if ( ! $this->ready() || ! $this->rules->has_active() ) {
			return;
		}

		try {
			$outcomes = $this->service->apply_saved( $post_id );
		} catch ( Throwable $e ) {
			PostWriter::log( sprintf( 'Las reglas fallaron en la entrada %d: %s', $post_id, $e->getMessage() ) );
			return;
		}

		foreach ( $outcomes as $outcome ) {
			foreach ( $outcome->failed as $failure ) {
				// Un fallo que no es «alguien tiene la entrada abierta» no se reintenta: queda en el registro de depuración.
				if ( ! in_array( $failure['reason'], array( 'locked', 'busy' ), true ) ) {
					PostWriter::log( sprintf( 'La regla %d no pudo enlazar en la entrada %d (%s): %s', $outcome->rule_id, $post_id, $failure['reason'], $failure['message'] ) );
				}
			}
			if ( array() !== $outcome->failed && $this->was_locked( $outcome ) && $attempt < self::MAX_ATTEMPTS ) {
				$this->enqueue( $post_id, $attempt + 1, self::RETRY_SECONDS );
				return;
			}
		}
	}

	/**
	 * Si el fallo fue que alguien tiene la entrada abierta (el único que merece reintento).
	 *
	 * @param RuleOutcome $outcome Resultado.
	 */
	private function was_locked( RuleOutcome $outcome ): bool {
		foreach ( $outcome->failed as $failure ) {
			if ( in_array( $failure['reason'], array( 'locked', 'busy' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Encola la aplicación (una sola acción pendiente por entrada).
	 *
	 * @param int $post_id ID.
	 * @param int $attempt Intento.
	 * @param int $delay   Segundos de espera.
	 */
	private function enqueue( int $post_id, int $attempt, int $delay ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::HOOK, array( $post_id, $attempt ), Installer::ACTION_GROUP, true );
			return;
		}

		as_enqueue_async_action( self::HOOK, array( $post_id, $attempt ), Installer::ACTION_GROUP, true );
	}

	/**
	 * Si las tablas están instaladas (no lo están entre una actualización y la primera visita al administrador).
	 */
	private function ready(): bool {
		return (int) get_option( Installer::DB_VERSION_OPTION, 0 ) >= 1;
	}
}
