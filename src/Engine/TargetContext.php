<?php
/**
 * Lo de un destino que no depende del origen.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Lo de un destino que no depende del origen. Las frases, los términos y las ampliaciones hacen falta para buscar anclas;
 * lo que solo sirve para puntuar una coincidencia ({@see Suggester}: enlaces entrantes, edad, pilar…) se calcula la primera
 * vez que hay una y se recuerda en {@see self::$scoring}.
 */
final class TargetContext {

	/**
	 * Lo de puntuar, calculado al primer uso.
	 *
	 * @var array{inbound: int, age: int, unlike: bool, pillar: bool, penalty: bool}|null
	 */
	public ?array $scoring = null;

	/**
	 * Crea el contexto.
	 *
	 * @param DocMeta              $meta       Ficha del destino.
	 * @param array<string, float> $terms      Términos principales.
	 * @param array                $phrases    Frases objetivo.
	 * @param array                $expansions Palabras con que se puede ampliar un ancla.
	 * @param int|null             $inbound    Enlaces que ya recibe, si quien lo crea ya los tiene contados.
	 *
	 * @phpstan-param list<Phrase> $phrases
	 * @phpstan-param array<string, true> $expansions
	 */
	public function __construct(
		public readonly DocMeta $meta,
		public readonly array $terms,
		public readonly array $phrases,
		public readonly array $expansions,
		public readonly ?int $inbound = null
	) {
	}
}
