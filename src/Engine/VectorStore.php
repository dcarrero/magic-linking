<?php
/**
 * Contrato del almacén de vectores: en memoria (banco) o en tablas (plugin).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Vectores ya calculados, uno por entrada y todos del mismo modelo.
 */
interface VectorStore {

	/**
	 * Vector normalizado (norma 1) de una entrada, o null si no lo tiene.
	 *
	 * @param int $id ID.
	 * @return list<float>|null
	 */
	public function vector( int $id ): ?array;

	/**
	 * Entradas del idioma más parecidas a un vector, por coseno.
	 *
	 * @param float[] $vector  Vector de consulta (se normaliza).
	 * @param string  $lang    Idioma.
	 * @param int     $limit   Máximo de resultados.
	 * @param int[]   $exclude IDs que no se devuelven.
	 * @return array<int, float> ID → coseno, de mayor a menor.
	 *
	 * @phpstan-param list<float> $vector
	 * @phpstan-param list<int> $exclude
	 */
	public function nearest( array $vector, string $lang, int $limit, array $exclude = array() ): array;
}
