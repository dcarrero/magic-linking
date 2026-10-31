import type { Suggestion } from '../types';

/**
 * Identifica una tarjeta dentro de una lista: origen y destino de su sugerencia principal. No cambia cuando el usuario
 * elige otra frase o ajusta el ancla, así que el estado y el foco de la tarjeta la siguen (F1-22).
 *
 * @param suggestion Sugerencia.
 */
export function suggestionKey( suggestion: Suggestion ): string {
	return [ suggestion.source.id, suggestion.target.id ].join( ':' );
}
