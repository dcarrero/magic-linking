<?php
/**
 * Respuesta de texto del cliente de IA.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\AI;

/**
 * Texto devuelto y lo que costó, tal como lo informa el resultado (no la preferencia pedida).
 */
final class Response {

	/**
	 * Crea la respuesta.
	 *
	 * @param string     $text          Texto generado (nunca vacío).
	 * @param int        $tokens_in     Tokens de entrada reales.
	 * @param int        $tokens_out    Tokens de salida reales.
	 * @param string     $provider      Identificador del proveedor que respondió.
	 * @param string     $provider_name Nombre del proveedor.
	 * @param string     $model         Modelo que respondió.
	 * @param bool       $local         El proveedor es local: coste cero.
	 * @param float|null $cost          Coste estimado con la tabla de precios; null si el modelo no está en ella.
	 */
	public function __construct(
		public readonly string $text,
		public readonly int $tokens_in,
		public readonly int $tokens_out,
		public readonly string $provider,
		public readonly string $provider_name,
		public readonly string $model,
		public readonly bool $local,
		public readonly ?float $cost
	) {
	}

	/**
	 * Tokens totales.
	 */
	public function tokens(): int {
		return $this->tokens_in + $this->tokens_out;
	}
}
