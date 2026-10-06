<?php
/**
 * Recuperación de candidatas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Destinos para una entrada abierta (salientes) y orígenes para un destino (entrantes).
 */
final class Retriever {

	/**
	 * Candidatas salientes.
	 */
	public const CANDIDATES = 200;

	/**
	 * Orígenes para entrantes.
	 */
	public const ORIGINS = 100;

	/**
	 * Crea el recuperador.
	 *
	 * @param IndexRepository $index      Índice.
	 * @param int             $candidates Candidatas salientes.
	 * @param int             $origins    Orígenes para entrantes.
	 */
	public function __construct(
		private IndexRepository $index,
		private int $candidates = self::CANDIDATES,
		private int $origins = self::ORIGINS
	) {
	}

	/**
	 * Destinos más parecidos a los pesos del origen (calculados en caliente).
	 *
	 * @param array    $weights Pesos de todos los términos del origen.
	 * @param string   $lang    Idioma del origen.
	 * @param int[]    $exclude El propio origen, los destinos ya enlazados y los «nunca sugerir».
	 * @param int|null $limit Máximo de resultados si no es el estándar (para filtrar después).
	 * @return array<int, float> ID → similitud léxica, de mayor a menor.
	 *
	 * @phpstan-param array<string, float> $weights
	 * @phpstan-param list<int> $exclude
	 */
	public function outgoing( array $weights, string $lang, array $exclude, ?int $limit = null ): array {
		return $this->index->similar( $weights, $lang, $limit ?? $this->candidates, $exclude );
	}

	/**
	 * Orígenes que tienen las frases objetivo del destino entre sus términos
	 * principales; si no llegan a ORIGINS, se completan con los más parecidos al
	 * destino.
	 *
	 * @param DocMeta  $target  Destino.
	 * @param Phrase[] $phrases Frases objetivo del destino.
	 * @param int[]    $exclude El destino y los orígenes que ya le enlazan.
	 * @return list<int> IDs de los orígenes, los que contienen las frases primero.
	 *
	 * @phpstan-param list<Phrase> $phrases
	 * @phpstan-param list<int> $exclude
	 */
	public function incoming( DocMeta $target, array $phrases, array $exclude ): array {
		$terms = array();
		foreach ( $phrases as $phrase ) {
			if ( count( $phrase->keys ) <= 3 ) {
				$terms[] = $phrase->key();
			}
		}

		$origins = array_keys( $this->index->containing( $terms, $target->lang, $this->origins, $exclude ) );
		if ( count( $origins ) < $this->origins ) {
			$similar = $this->index->similar( $this->index->terms( $target->id ), $target->lang, $this->origins - count( $origins ), array_merge( $exclude, $origins ) );
			$origins = array_merge( $origins, array_keys( $similar ) );
		}
		return $origins;
	}
}
