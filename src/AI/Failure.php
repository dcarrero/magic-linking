<?php
/**
 * Error tipado del cliente de IA.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\AI;

/**
 * Por qué no hubo respuesta. El código decide qué se le dice al usuario; el detalle es técnico, va al registro y
 * nunca se enseña (docs/05 §2.4).
 */
final class Failure {

	/** No hay cliente de IA (WordPress anterior a 7.0) o ningún conector con generación de texto. */
	public const UNAVAILABLE = 'unavailable';

	/** El sitio ha vetado la petición (`wp_ai_client_prevent_prompt`, `wp_supports_ai` o `magiclinking_before_ai_request`). */
	public const VETOED = 'vetoed';

	/** El tope diario de tokens está alcanzado, o la petición no cabe en lo que queda. */
	public const BUDGET = 'budget';

	/** Error de red o de servidor del proveedor, también tras el reintento. */
	public const NETWORK = 'network';

	/** La petición llegó pero el proveedor no contestó en el tiempo dado (no se reintenta: repetirla solo dobla la espera). */
	public const TIMEOUT = 'timeout';

	/** El servidor del proveedor falló (5xx), también tras el reintento. No es un problema de red del sitio. */
	public const UPSTREAM = 'upstream';

	/** El proveedor rechaza por límite de uso (429), también tras el reintento. */
	public const RATE_LIMIT = 'rate_limit';

	/** La respuesta llegó vacía o no se puede usar. */
	public const INVALID = 'invalid';

	/** El tope diario que fijó el usuario es menor que lo que necesita una sola petición. */
	public const TOO_SMALL = 'limit_too_small';

	/** El usuario pide IA demasiado a menudo (límite simple por minuto). */
	public const TOO_MANY = 'too_many';

	/** Cualquier otro fallo (clave rechazada, argumentos, fallo interno). */
	public const FAILED = 'failed';

	/**
	 * Crea el error.
	 *
	 * @param string $code      Una de las constantes de la clase.
	 * @param string $detail    Detalle técnico para el registro; nunca para el usuario.
	 * @param int    $http      Código HTTP del proveedor si se conoce.
	 * @param int    $tokens    Tokens ya gastados aunque no sirviera la respuesta (respuesta vacía).
	 * @param string $provider  Proveedor que respondió, si hubo gasto.
	 * @param string $model     Modelo que respondió, si hubo gasto.
	 * @param float  $cost      Coste estimado de lo gastado.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $detail = '',
		public readonly int $http = 0,
		public readonly int $tokens = 0,
		public readonly string $provider = '',
		public readonly string $model = '',
		public readonly float $cost = 0.0
	) {
	}

	/**
	 * Un reintento puede arreglarlo: red, servidor del proveedor y límite de uso.
	 */
	public function retryable(): bool {
		return in_array( $this->code, array( self::NETWORK, self::UPSTREAM, self::RATE_LIMIT ), true );
	}

	/**
	 * Detalle técnico sin claves ni direcciones con parámetros y recortado: es lo único del error que se enseña,
	 * y solo a quien administra el sitio.
	 *
	 * @param string $detail Detalle tal como lo dio el cliente de IA.
	 */
	public static function scrub( string $detail ): string {
		// Se ocultan las claves de Google, OpenAI, Anthropic y OpenRouter, y las parecidas.
		$detail = (string) preg_replace( '/\bAIza[A-Za-z0-9_-]{20,}/', '[clave]', $detail );
		$detail = (string) preg_replace( '/\b(?:sk|pk|rk)-[A-Za-z0-9_-]{8,}/i', '[clave]', $detail );
		$detail = (string) preg_replace( '/(Bearer|Basic)\s+\S+/i', '$1 [clave]', $detail );
		$detail = (string) preg_replace( '/([?&](?:key|api_key|apikey|token|access_token)=)[^&\s"\']+/i', '$1[clave]', $detail );
		// Los proveedores citan trozos del texto enviado («near '…'», cuerpos JSON): nada entre comillas ni llaves
		// llega a la pantalla, porque la interfaz promete que no se enseña texto de las entradas.
		$detail = (string) preg_replace( '/(?<![\pL\pN])([\'"`])(?:(?!\1).)*\1|[“‘«][^”’»]*[”’»]|\{[^{}]*\}/us', '…', $detail );
		$detail = (string) preg_replace( '/\s+/', ' ', strip_tags( $detail ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Sin WordPress en las pruebas unitarias.

		return mb_substr( trim( $detail ), 0, 300, 'UTF-8' );
	}

	/**
	 * Se debe a una decisión del sitio o del usuario y no es un fallo: no se avisa de error.
	 */
	public function is_silent(): bool {
		return in_array( $this->code, array( self::UNAVAILABLE, self::VETOED ), true );
	}
}
