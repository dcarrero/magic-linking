<?php
/**
 * Límite simple de peticiones de IA por usuario y minuto.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\AI;

/**
 * Evita que un usuario con permiso de edición gaste de golpe el tope diario de todo el sitio: ventana fija de un
 * minuto, un transitorio por usuario (sin autoload, caduca solo y se borra al desinstalar por su prefijo).
 */
final class RateLimit {

	/**
	 * Peticiones al modelo que un usuario puede hacer por minuto.
	 */
	public const PER_MINUTE = 10;

	/**
	 * Cuenta una petición y dice si cabe.
	 *
	 * @param int $user_id Usuario.
	 */
	public static function allow( int $user_id ): bool {
		/**
		 * Filtra cuántas peticiones de IA por minuto puede hacer cada usuario.
		 *
		 * @param int $per_minute Peticiones por minuto (por defecto 10).
		 */
		$max = max( 1, (int) apply_filters( 'magiclinking_ai_calls_per_minute', self::PER_MINUTE ) );
		$key = 'magiclinking_ai_rate_' . $user_id;
		$now = time();

		$window = get_transient( $key );
		if ( ! is_array( $window ) || ! isset( $window[0], $window[1] ) || $now - (int) $window[1] >= MINUTE_IN_SECONDS ) {
			$window = array( 0, $now );
		}

		if ( (int) $window[0] >= $max ) {
			return false;
		}

		set_transient( $key, array( (int) $window[0] + 1, (int) $window[1] ), max( 1, MINUTE_IN_SECONDS - ( $now - (int) $window[1] ) ) );

		return true;
	}
}
