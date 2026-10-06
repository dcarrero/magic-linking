<?php
/**
 * Índice en memoria para el banco de pruebas y las pruebas unitarias.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * {@see IndexRepository} con arrays de PHP. Se puede serializar para guardarlo
 * en caché. En el plugin lo sustituye un repositorio sobre tablas.
 */
final class MemoryIndex implements IndexRepository {

	/**
	 * Datos por entrada.
	 *
	 * @var array<int, DocMeta>
	 */
	private array $docs = array();

	/**
	 * Términos principales por entrada.
	 *
	 * @var array<int, array<string, float>>
	 */
	private array $terms = array();

	/**
	 * Índice invertido de los términos principales: idioma → término → ID → peso.
	 *
	 * @var array<string, array<string, array<int, float>>>
	 */
	private array $postings = array();

	/**
	 * Frecuencia documental: idioma → término → df.
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $df = array();

	/**
	 * Entradas y suma de longitudes por idioma.
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	private array $totals = array();

	/**
	 * Enlaces salientes: origen → lista de [destino, clave del ancla].
	 *
	 * @var array<int, list<array{0: int, 1: string}>>
	 */
	private array $links = array();

	/**
	 * Enlaces entrantes: destino → origen → true.
	 *
	 * @var array<int, array<int, true>>
	 */
	private array $inbound = array();

	/**
	 * Usos de cada ancla: destino → clave → origen → true.
	 *
	 * @var array<int, array<string, array<int, true>>>
	 */
	private array $anchors = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $lang   Idioma.
	 * @param string[] $terms  Términos.
	 * @param int      $length Longitud.
	 */
	public function count_terms( string $lang, array $terms, int $length ): void {
		$df = &$this->df[ $lang ];
		foreach ( $terms as $term ) {
			$df[ $term ] = ( $df[ $term ] ?? 0 ) + 1;
		}
		$this->totals[ $lang ] = array( ( $this->totals[ $lang ][0] ?? 0 ) + 1, ( $this->totals[ $lang ][1] ?? 0 ) + $length );
	}

	/**
	 * Olvida los términos con df menor que $min: no pueden unir dos entradas y
	 * ocupan la mayor parte de la memoria. Su df pasa a contar como 0.
	 *
	 * @param int $min df mínimo que se conserva.
	 */
	public function prune( int $min ): void {
		foreach ( $this->df as $lang => $terms ) {
			$this->df[ $lang ] = array_filter( $terms, static fn( int $df ): bool => $df >= $min );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $lang Idioma.
	 */
	public function stats( string $lang ): IndexStats {
		[ $count, $length ] = $this->totals[ $lang ] ?? array( 0, 0 );
		return new IndexStats( $count, $count > 0 ? $length / $count : 0.0, $this->df[ $lang ] ?? array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param IndexedDoc $doc Entrada.
	 */
	public function put( IndexedDoc $doc ): void {
		$id   = $doc->meta->id;
		$lang = $doc->meta->lang;
		$this->remove( $id );

		$this->docs[ $id ]  = $doc->meta;
		$this->terms[ $id ] = $doc->terms;
		foreach ( $doc->terms as $term => $weight ) {
			$this->postings[ $lang ][ $term ][ $id ] = $weight;
		}
		$this->links[ $id ] = $doc->links;
		foreach ( $doc->links as [ $target, $anchor ] ) {
			$this->inbound[ $target ][ $id ]            = true;
			$this->anchors[ $target ][ $anchor ][ $id ] = true;
		}
	}

	/**
	 * Quita una entrada del índice (no de las estadísticas).
	 *
	 * @param int $id ID.
	 */
	private function remove( int $id ): void {
		if ( ! isset( $this->docs[ $id ] ) ) {
			return;
		}
		$lang = $this->docs[ $id ]->lang;
		foreach ( array_keys( $this->terms[ $id ] ) as $term ) {
			unset( $this->postings[ $lang ][ $term ][ $id ] );
		}
		foreach ( $this->links[ $id ] as [ $target, $anchor ] ) {
			unset( $this->inbound[ $target ][ $id ], $this->anchors[ $target ][ $anchor ][ $id ] );
		}
		unset( $this->docs[ $id ], $this->terms[ $id ], $this->links[ $id ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id ID.
	 */
	public function meta( int $id ): ?DocMeta {
		return $this->docs[ $id ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id ID.
	 */
	public function terms( int $id ): array {
		return $this->terms[ $id ] ?? array();
	}

	/**
	 * IDs indexados.
	 *
	 * @return list<int>
	 */
	public function ids(): array {
		return array_keys( $this->docs );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, float> $weights Pesos.
	 * @param string               $lang    Idioma.
	 * @param int                  $limit   Máximo.
	 * @param int[]                $exclude Excluidos.
	 */
	public function similar( array $weights, string $lang, int $limit, array $exclude = array() ): array {
		$postings = $this->postings[ $lang ] ?? array();
		$scores   = array();
		foreach ( $weights as $term => $weight ) {
			if ( ! isset( $postings[ $term ] ) ) {
				continue;
			}
			foreach ( $postings[ $term ] as $id => $other ) {
				$scores[ $id ] = ( $scores[ $id ] ?? 0.0 ) + $weight * $other;
			}
		}
		return self::best( $scores, $limit, $exclude );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string[] $terms   Términos.
	 * @param string   $lang    Idioma.
	 * @param int      $limit   Máximo.
	 * @param int[]    $exclude Excluidos.
	 */
	public function containing( array $terms, string $lang, int $limit, array $exclude = array() ): array {
		$postings = $this->postings[ $lang ] ?? array();
		$scores   = array();
		foreach ( array_unique( $terms ) as $term ) {
			foreach ( $postings[ $term ] ?? array() as $id => $weight ) {
				$scores[ $id ] = ( $scores[ $id ] ?? 0.0 ) + $weight;
			}
		}
		return self::best( $scores, $limit, $exclude );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $a ID.
	 * @param int $b ID.
	 */
	public function similarity( int $a, int $b ): float {
		$sum   = 0.0;
		$other = $this->terms[ $b ] ?? array();
		foreach ( $this->terms[ $a ] ?? array() as $term => $weight ) {
			if ( isset( $other[ $term ] ) ) {
				$sum += $weight * $other[ $term ];
			}
		}
		return $sum;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $target ID.
	 */
	public function linking_to( int $target ): array {
		return array_keys( $this->inbound[ $target ] ?? array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $anchor Clave del ancla.
	 * @param int    $target Destino.
	 */
	public function anchor_uses( string $anchor, int $target ): int {
		return count( $this->anchors[ $target ][ $anchor ] ?? array() );
	}

	/**
	 * Los mejores resultados sin los excluidos.
	 *
	 * @param array<int, float> $scores  ID → puntuación.
	 * @param int               $limit   Máximo.
	 * @param int[]             $exclude Excluidos.
	 * @return array<int, float>
	 *
	 * @phpstan-param list<int> $exclude
	 */
	private static function best( array $scores, int $limit, array $exclude ): array {
		foreach ( $exclude as $id ) {
			unset( $scores[ $id ] );
		}
		// Empates por ID ascendente para que el resultado sea estable.
		uksort(
			$scores,
			static fn( int $a, int $b ): int => array( $scores[ $b ], $a ) <=> array( $scores[ $a ], $b )
		);
		return array_slice( $scores, 0, max( 0, $limit ), true );
	}
}
