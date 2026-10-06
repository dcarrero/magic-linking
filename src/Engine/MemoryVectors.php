<?php
/**
 * Almacén de vectores en memoria (banco y pruebas).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Búsqueda exhaustiva por coseno: con unos miles de entradas y vectores de
 * 768 dimensiones basta y no necesita índice aproximado.
 */
final class MemoryVectors implements VectorStore {

	/**
	 * Vectores normalizados por idioma.
	 *
	 * @var array<string, array<int, list<float>>>
	 */
	private array $vectors = array();

	/**
	 * Idioma de cada entrada.
	 *
	 * @var array<int, string>
	 */
	private array $langs = array();

	/**
	 * Guarda (o sustituye) el vector de una entrada.
	 *
	 * @param int     $id     ID.
	 * @param string  $lang   Idioma.
	 * @param float[] $vector Vector (se normaliza).
	 *
	 * @phpstan-param list<float> $vector
	 */
	public function put( int $id, string $lang, array $vector ): void {
		if ( isset( $this->langs[ $id ] ) ) {
			unset( $this->vectors[ $this->langs[ $id ] ][ $id ] );
		}
		$this->langs[ $id ]            = $lang;
		$this->vectors[ $lang ][ $id ] = Semantic::normalize( $vector );
	}

	/**
	 * Entradas con vector.
	 */
	public function count(): int {
		return count( $this->langs );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id ID.
	 */
	public function vector( int $id ): ?array {
		$lang = $this->langs[ $id ] ?? null;
		return null === $lang ? null : $this->vectors[ $lang ][ $id ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param float[] $vector  Consulta.
	 * @param string  $lang    Idioma.
	 * @param int     $limit   Máximo.
	 * @param int[]   $exclude Excluidos.
	 */
	public function nearest( array $vector, string $lang, int $limit, array $exclude = array() ): array {
		$query  = Semantic::normalize( $vector );
		$skip   = array_flip( $exclude );
		$scores = array();
		foreach ( $this->vectors[ $lang ] ?? array() as $id => $other ) {
			if ( ! isset( $skip[ $id ] ) ) {
				$scores[ $id ] = Semantic::dot( $query, $other );
			}
		}
		arsort( $scores );
		return array_slice( $scores, 0, max( 0, $limit ), true );
	}
}
