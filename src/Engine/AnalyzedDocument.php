<?php
/**
 * Documento analizado: términos por campo y, si se pide, sus frases.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Resultado de {@see Analyzer::analyze()}.
 */
final class AnalyzedDocument {

	/**
	 * Posiciones de cada clave de raíz en las frases, calculadas al primer uso.
	 *
	 * @var array<string, list<array{0: int, 1: int}>>|null
	 */
	private ?array $positions = null;

	/**
	 * Crea el resultado.
	 *
	 * @param Document   $document   Documento de origen.
	 * @param array      $counts     Frecuencia de cada término por campo (Analyzer::TITLE…).
	 * @param int        $length     Palabras no vacías en todos los campos (doc_len de BM25).
	 * @param int        $words      Palabras del cuerpo.
	 * @param Sentence[] $sentences  Frases del cuerpo (vacío si no se pidieron).
	 * @param array      $surfaces   Forma más frecuente de cada término, para mostrar (vacío si no se pidieron).
	 *
	 * @phpstan-param array<int, array<string, int>> $counts
	 * @phpstan-param list<Sentence> $sentences
	 * @phpstan-param array<string, string> $surfaces
	 */
	public function __construct(
		public readonly Document $document,
		public readonly array $counts,
		public readonly int $length,
		public readonly int $words,
		public readonly array $sentences = array(),
		public readonly array $surfaces = array()
	) {
	}

	/**
	 * Todos los términos distintos del documento.
	 *
	 * @return list<string>
	 */
	public function terms(): array {
		$terms = array();
		foreach ( $this->counts as $field ) {
			$terms += $field;
		}
		return array_map( 'strval', array_keys( $terms ) );
	}

	/**
	 * Dónde aparece cada clave de raíz: [frase, token].
	 *
	 * @return array<string, list<array{0: int, 1: int}>>
	 */
	public function positions(): array {
		if ( null === $this->positions ) {
			$this->positions = array();
			foreach ( $this->sentences as $s => $sentence ) {
				foreach ( $sentence->keys as $i => $key ) {
					$this->positions[ $key ][] = array( $s, $i );
				}
			}
		}
		return $this->positions;
	}
}
