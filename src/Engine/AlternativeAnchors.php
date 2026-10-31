<?php
/**
 * Anclas de las frases alternativas frente a las de las sugerencias que se muestran.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * La misma ancla (por raíz) no puede llevar a dos destinos desde un origen (docs/04 §5.3). El motor lo cumple con las
 * frases principales; aquí se cumple con las alternativas, comparadas solo con lo que **finalmente se muestra** (después
 * de los filtros y del recorte): una sugerencia que no sale no quita nada a las demás.
 */
final class AlternativeAnchors {

	/**
	 * Quita de las alternativas las que usan el ancla principal de otra sugerencia de la lista.
	 *
	 * @param Suggestion[] $shown    Sugerencias que se van a mostrar (de un mismo origen).
	 * @param Analyzer     $analyzer Analizador del idioma del origen.
	 *
	 * @phpstan-param list<Suggestion> $shown
	 *
	 * @return list<Suggestion>
	 */
	public static function prune( array $shown, Analyzer $analyzer ): array {
		$mains = array();
		foreach ( $shown as $n => $suggestion ) {
			$mains[ $n ] = $analyzer->phrase_key( $suggestion->anchor );
		}

		$out = array();
		foreach ( $shown as $n => $suggestion ) {
			if ( array() === $suggestion->alternatives ) {
				$out[] = $suggestion;
				continue;
			}

			$others = array_flip( array_diff_key( $mains, array( $n => true ) ) );
			$kept   = array_values(
				array_filter(
					$suggestion->alternatives,
					static fn( Suggestion $alternative ): bool => ! isset( $others[ $analyzer->phrase_key( $alternative->anchor ) ] )
				)
			);
			$out[]  = count( $kept ) === count( $suggestion->alternatives ) ? $suggestion : $suggestion->with_alternatives( $kept );
		}

		return $out;
	}
}
