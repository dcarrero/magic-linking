<?php
/**
 * Tope diario de gasto de IA (docs/05 §5.3).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\AI;

/**
 * Freno de gasto del propio usuario, en tokens por día. No es un límite comercial (docs/02 §5): lo fija él y Pro
 * no lo sube. Con 0 la IA está apagada; al alcanzar el tope se apaga hasta el día siguiente y el motor sigue con
 * el léxico.
 */
final class Budget {

	/**
	 * Tope diario por defecto, en tokens.
	 */
	public const DEFAULT_LIMIT = 2000000;

	/**
	 * Tope máximo que admite el ajuste.
	 */
	public const MAX_LIMIT = 1000000000;

	/**
	 * Constructor.
	 *
	 * @param UsageStore     $store Registro del gasto.
	 * @param callable():int $limit Devuelve el tope diario en tokens (lo lee de los ajustes cuando hace falta).
	 */
	public function __construct( private UsageStore $store, private $limit ) {
	}

	/**
	 * Tope diario en tokens; 0 = sin IA.
	 */
	public function limit(): int {
		return max( 0, (int) ( $this->limit )() );
	}

	/**
	 * La IA no está apagada por el usuario (tope distinto de cero).
	 */
	public function enabled(): bool {
		return $this->limit() > 0;
	}

	/**
	 * Tokens gastados hoy.
	 */
	public function used_today(): int {
		return $this->store->ai_tokens_today();
	}

	/**
	 * Tokens que quedan hoy.
	 */
	public function remaining(): int {
		return max( 0, $this->limit() - $this->used_today() );
	}

	/**
	 * El tope de hoy ya se ha alcanzado (o la IA está apagada).
	 */
	public function exhausted(): bool {
		return $this->limit() <= 0 || $this->used_today() >= $this->limit();
	}

	/**
	 * Si una petición de ese tamaño cabe en lo que queda de hoy.
	 *
	 * @param int $estimated_tokens Tokens estimados (entrada más salida).
	 */
	public function allows( int $estimated_tokens ): bool {
		return ! $this->exhausted() && $this->used_today() + max( 0, $estimated_tokens ) <= $this->limit();
	}

	/**
	 * Apunta el gasto de una petición en el registro.
	 *
	 * @param array $row Datos de la petición (ver `UsageStore`).
	 *
	 * @phpstan-param array{type: string, status: string, tokens_est: int, tokens_real: int, cost_est: float, cost_real: float, provider: string, model: string, user_id: int, params: array<string, mixed>, error: string} $row
	 */
	public function record( array $row ): int {
		return $this->store->record_ai( $row );
	}
}
