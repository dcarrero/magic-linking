<?php
/**
 * Texto con frases para buscarle anclas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Lo que hace falta de un texto para buscarle anclas: su documento, sus palabras, sus frases y las formas para mostrar.
 * Lo comparten el análisis completo ({@see AnalyzedDocument}) y el ligero de los orígenes de las entrantes ({@see AnchorSource}).
 */
abstract class AnchorText {

	/**
	 * Posiciones de cada clave de raíz en las frases, calculadas al primer uso.
	 *
	 * @var array<string, list<array{0: int, 1: int}>>|null
	 */
	private ?array $positions = null;

	/**
	 * Crea el resultado.
	 *
	 * @param Document   $document  Documento de origen.
	 * @param int        $words     Palabras del cuerpo.
	 * @param Sentence[] $sentences Frases del cuerpo.
	 * @param array      $surfaces  Forma más frecuente de cada término, para mostrar.
	 *
	 * @phpstan-param list<Sentence> $sentences
	 * @phpstan-param array<string, string> $surfaces
	 */
	public function __construct(
		public readonly Document $document,
		public readonly int $words,
		public readonly array $sentences = array(),
		public readonly array $surfaces = array()
	) {
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
