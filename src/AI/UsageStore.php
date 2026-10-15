<?php
/**
 * Dónde se apunta el gasto de IA.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\AI;

/**
 * Contrato del registro de gasto: lo implementa `JobRepository` sobre `magiclinking_jobs` (docs/05 §5.3) y las
 * pruebas unitarias, en memoria.
 *
 * @phpstan-type SpendRow array{type: string, status: string, tokens_est: int, tokens_real: int, cost_est: float, cost_real: float, provider: string, model: string, user_id: int, params: array<string, mixed>, error: string}
 */
interface UsageStore {

	/**
	 * Tokens gastados hoy (día del sitio) en todas las acciones con IA.
	 */
	public function ai_tokens_today(): int;

	/**
	 * Apunta el gasto de una petición.
	 *
	 * @param array $row Datos de la petición.
	 *
	 * @phpstan-param SpendRow $row
	 *
	 * @return int Identificador del apunte.
	 */
	public function record_ai( array $row ): int;
}
