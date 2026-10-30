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
	 * Metadato de la entrada con el estado de la espera: `since` (marca de tiempo del primer bloqueo) y `attempts`. Va en
	 * la entrada y no en los argumentos de la acción para que haya **una sola** acción pendiente por entrada.
	 */
	public const RETRY_META = 'magiclinking_rules_retry';

	/**
	 * Primera espera, en segundos; las siguientes se duplican hasta {@see self::MAX_WAIT}.
	 */
	public const RETRY_SECONDS = 300;

	/**
	 * Espera máxima entre intentos.
	 */
	public const MAX_WAIT = 2 * HOUR_IN_SECONDS;

	/**
	 * Cuánto se insiste con una entrada que sigue abierta antes de dejar constancia en la pantalla de reglas. El
	 * bloqueo de edición caduca unos 150 s después de cerrar la pestaña, así que un día cubre cualquier sesión normal.
	 */
	public const GIVE_UP_AFTER = DAY_IN_SECONDS;

	/**
	 * Espera antes del siguiente intento, creciente y con techo.
	 *
	 * @param int $attempts Intentos ya hechos con esta entrada bloqueada (0 = el primero).
	 */
	public static function wait_for( int $attempts ): int {
		return (int) min( self::MAX_WAIT, self::RETRY_SECONDS * ( 2 ** min( max( 0, $attempts ), 10 ) ) );
	}

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
		// El metadato de «regla ya aplicada» no sale en «Campos personalizados».
		add_filter( 'is_protected_meta', static fn( $is_protected, $key ) => in_array( $key, array( RuleService::APPLIED_META, self::RETRY_META ), true ) ? true : $is_protected, 10, 2 );
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

		$this->enqueue( $post_id, 0 );
	}

	/**
	 * Manejador de Action Scheduler.
	 *
	 * @param int $post_id ID de la entrada.
	 * @param int $legacy  Número de intento de las acciones encoladas por versiones anteriores (se ignora).
	 */
	public function run( int $post_id, int $legacy = 0 ): void {
		unset( $legacy );
		if ( ! $this->ready() || ! $this->rules->has_active() ) {
			return;
		}

		try {
			$outcomes = $this->service->apply_saved( $post_id );
		} catch ( Throwable $e ) {
			PostWriter::log( sprintf( 'Las reglas fallaron en la entrada %d: %s', $post_id, $e->getMessage() ) );
			return;
		}

		$locked = false;
		foreach ( $outcomes as $outcome ) {
			foreach ( $outcome->failed as $failure ) {
				// Un fallo que no es «alguien tiene la entrada abierta» no se reintenta: queda en el registro de depuración.
				if ( ! in_array( $failure['reason'], array( 'locked', 'busy' ), true ) ) {
					PostWriter::log( sprintf( 'La regla %d no pudo enlazar en la entrada %d (%s): %s', $outcome->rule_id, $post_id, $failure['reason'], $failure['message'] ) );
				}
			}
			$locked = $locked || $this->was_locked( $outcome );
		}

		if ( ! $locked ) {
			delete_post_meta( $post_id, self::RETRY_META );
			RuleLog::remove( $post_id );
			return;
		}

		// Alguien tiene la entrada abierta: se espera cada vez más y, pasado un día, queda constancia en la pantalla.
		$state = get_post_meta( $post_id, self::RETRY_META, true );
		$since = is_array( $state ) && isset( $state['since'] ) ? (int) $state['since'] : time();
		$tries = is_array( $state ) && isset( $state['attempts'] ) ? (int) $state['attempts'] : 0;
		if ( time() - $since > self::GIVE_UP_AFTER ) {
			delete_post_meta( $post_id, self::RETRY_META );
			RuleLog::add( $post_id );
			return;
		}
		update_post_meta(
			$post_id,
			self::RETRY_META,
			array(
				'since'    => $since,
				'attempts' => $tries + 1,
			)
		);
		$this->enqueue( $post_id, self::wait_for( $tries ) );
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
	 * Encola la aplicación: **una sola acción pendiente por entrada**, sea cual sea el momento para el que esté
	 * programada (la espera y los intentos viven en la entrada, no en los argumentos).
	 *
	 * @param int $post_id ID.
	 * @param int $delay   Segundos de espera (0 = en cuanto pueda).
	 */
	private function enqueue( int $post_id, int $delay ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		// Solo cuentan las pendientes: `as_has_scheduled_action()` también cuenta la que está en ejecución, y un guardado
		// que llega mientras corre se perdería.
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'args'     => array( $post_id ),
				'group'    => Installer::ACTION_GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);
		if ( array() !== $pending ) {
			return;
		}

		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::HOOK, array( $post_id ), Installer::ACTION_GROUP );
			return;
		}

		as_enqueue_async_action( self::HOOK, array( $post_id ), Installer::ACTION_GROUP );
	}

	/**
	 * Si las tablas están instaladas (no lo están entre una actualización y la primera visita al administrador).
	 */
	private function ready(): bool {
		return (int) get_option( Installer::DB_VERSION_OPTION, 0 ) >= 1;
	}
}
