<?php
/**
 * Resultado de la verificación de un cambio.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

/**
 * Cambio verificado o motivo técnico (para el registro de depuración) por el que no se puede escribir.
 */
final class VerifyResult {

	/**
	 * Constructor.
	 *
	 * @param bool   $ok     Si el cambio es exactamente el enlace.
	 * @param string $reason Código del primer fallo ('' si va bien).
	 */
	public function __construct( public readonly bool $ok, public readonly string $reason = '' ) {
	}

	/**
	 * Verificación superada.
	 */
	public static function pass(): self {
		return new self( true );
	}

	/**
	 * Verificación fallida.
	 *
	 * @param string $reason Código del fallo.
	 */
	public static function fail( string $reason ): self {
		return new self( false, $reason );
	}
}
