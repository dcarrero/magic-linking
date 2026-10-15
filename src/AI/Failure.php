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

	/** El proveedor rechaza por límite de uso (429), también tras el reintento. */
	public const RATE_LIMIT = 'rate_limit';

	/** La respuesta llegó vacía o no se puede usar. */
	public const INVALID = 'invalid';

	/** Cualquier otro fallo (clave rechazada, argumentos, fallo interno). */
	public const FAILED = 'failed';

	/**
	 * Crea el error.
	 *
	 * @param string $code      Una de las constantes de la clase.
	 * @param string $detail    Detalle técnico para el registro; nunca para el usuario.
	 * @param int    $http      Código HTTP del proveedor si se conoce.
	 * @param int    $tokens    Tokens ya gastados aunque no sirviera la respuesta (respuesta vacía).
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $detail = '',
		public readonly int $http = 0,
		public readonly int $tokens = 0
	) {
	}

	/**
	 * Un reintento puede arreglarlo: red, servidor del proveedor y límite de uso.
	 */
	public function retryable(): bool {
		return in_array( $this->code, array( self::NETWORK, self::RATE_LIMIT ), true );
	}

	/**
	 * Se debe a una decisión del sitio o del usuario y no es un fallo: no se avisa de error.
	 */
	public function is_silent(): bool {
		return in_array( $this->code, array( self::UNAVAILABLE, self::VETOED ), true );
	}
}
