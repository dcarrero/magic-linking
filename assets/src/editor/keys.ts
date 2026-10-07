import type { Suggestion } from '../types';

/**
 * Identifica una sugerencia dentro de una lista (origen, destino y posición del ancla).
 *
 * @param suggestion Sugerencia.
 */
export function suggestionKey( suggestion: Suggestion ): string {
	return [
		suggestion.source.id,
		suggestion.target.id,
		suggestion.offset,
		suggestion.anchor,
	].join( ':' );
}
