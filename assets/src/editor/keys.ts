import type { Suggestion } from '../types';

/**
 * Identifica una tarjeta dentro de una lista: la sugerencia **principal** que devolvió el motor (origen, destino, posición
 * y ancla). No cambia cuando el usuario elige otra frase o ajusta el ancla (la sugerencia que se enseña lleva esa clave en
 * `cardKey`), así que el estado y el foco de la tarjeta la siguen. Varias sugerencias al mismo destino (tope de enlaces
 * por destino mayor que 1) se distinguen por su posición (F1-22).
 *
 * @param suggestion Sugerencia.
 */
export function suggestionKey( suggestion: Suggestion ): string {
	return (
		suggestion.cardKey ??
		[
			suggestion.source.id,
			suggestion.target.id,
			suggestion.offset,
			suggestion.anchor,
		].join( ':' )
	);
}
