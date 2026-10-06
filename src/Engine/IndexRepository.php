<?php
/**
 * Contrato del almacén del índice: en memoria (banco) o en tablas (plugin).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Lectura y escritura del índice léxico y del grafo de enlaces.
 *
 * Las puntuaciones de {@see similar()} y {@see containing()} son estas:
 * producto de pesos por término compartido, sumado.
 */
interface IndexRepository {

	/**
	 * Suma una entrada a las estadísticas del idioma (primera pasada del indexado).
	 *
	 * @param string   $lang   Idioma.
	 * @param string[] $terms  Términos distintos de la entrada.
	 * @param int      $length Longitud (doc_len).
	 *
	 * @phpstan-param list<string> $terms
	 */
	public function count_terms( string $lang, array $terms, int $length ): void;

	/**
	 * Estadísticas de un idioma.
	 *
	 * @param string $lang Idioma.
	 */
	public function stats( string $lang ): IndexStats;

	/**
	 * Guarda (o sustituye) una entrada indexada.
	 *
	 * @param IndexedDoc $doc Entrada.
	 */
	public function put( IndexedDoc $doc ): void;

	/**
	 * Datos de una entrada, o null si no está indexada.
	 *
	 * @param int $id ID.
	 */
	public function meta( int $id ): ?DocMeta;

	/**
	 * Términos principales de una entrada, de mayor a menor peso.
	 *
	 * @param int $id ID.
	 * @return array<string, float>
	 */
	public function terms( int $id ): array;

	/**
	 * Entradas del idioma más parecidas a unos pesos: Σ peso · peso_destino.
	 *
	 * @param array  $weights Término → peso.
	 * @param string $lang    Idioma.
	 * @param int    $limit   Máximo de resultados.
	 * @param int[]  $exclude IDs que no se devuelven.
	 * @return array<int, float> ID → similitud, de mayor a menor.
	 *
	 * @phpstan-param array<string, float> $weights
	 * @phpstan-param list<int> $exclude
	 */
	public function similar( array $weights, string $lang, int $limit, array $exclude = array() ): array;

	/**
	 * Entradas del idioma que tienen estos términos entre los principales: Σ peso.
	 *
	 * @param string[] $terms   Términos.
	 * @param string   $lang    Idioma.
	 * @param int      $limit   Máximo de resultados.
	 * @param int[]    $exclude IDs que no se devuelven.
	 * @return array<int, float> ID → suma de pesos, de mayor a menor.
	 *
	 * @phpstan-param list<string> $terms
	 * @phpstan-param list<int> $exclude
	 */
	public function containing( array $terms, string $lang, int $limit, array $exclude = array() ): array;

	/**
	 * Similitud entre dos entradas indexadas (producto de sus términos principales).
	 *
	 * @param int $a ID.
	 * @param int $b ID.
	 */
	public function similarity( int $a, int $b ): float;

	/**
	 * Entradas distintas que enlazan a una entrada.
	 *
	 * @param int $target ID.
	 * @return list<int>
	 */
	public function linking_to( int $target ): array;

	/**
	 * Entradas distintas que usan un ancla (por clave) para enlazar a un destino.
	 *
	 * @param string $anchor Clave de raíz del ancla.
	 * @param int    $target ID del destino.
	 */
	public function anchor_uses( string $anchor, int $target ): int;
}
