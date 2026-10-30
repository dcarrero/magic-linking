import type { Suggestion } from '../types';
import type { CardStatus } from './SuggestionCard';
import { suggestionKey } from './keys';

/**
 * La fila fantasma de un estado: enlazada o descartada en entrantes, que el servidor ya no devuelve.
 * @param status
 */
export function ghostOf(
	status: CardStatus
): { suggestion: Suggestion; index: number } | undefined {
	return status.kind === 'dismissed' || status.kind === 'added'
		? status.ghost
		: undefined;
}

/**
 * Entrantes de la página con las filas fantasma puestas donde estaban: el servidor ya no devuelve lo enlazado ni lo
 * descartado, pero la fila se queda con su *Deshacer* hasta que se vuelve a pedir la lista.
 *
 * @param items    Lo que dio el servidor.
 * @param statuses Estado de cada tarjeta por clave.
 */
export function withGhosts(
	items: Suggestion[],
	statuses: Record< string, CardStatus >
): Suggestion[] {
	const merged = [ ...items ];
	const keys = new Set( items.map( suggestionKey ) );
	Object.values( statuses )
		.flatMap( ( status ) => {
			const ghost = ghostOf( status );
			return ghost ? [ ghost ] : [];
		} )
		.filter( ( ghost ) => ! keys.has( suggestionKey( ghost.suggestion ) ) )
		.sort( ( a, b ) => a.index - b.index )
		.forEach( ( ghost ) =>
			merged.splice(
				Math.min( ghost.index, merged.length ),
				0,
				ghost.suggestion
			)
		);
	return merged;
}

/**
 * Los estados sin las filas fantasma (al cambiar de página se olvidan: eran de la que se dejó).
 * @param statuses
 */
export function withoutGhosts(
	statuses: Record< string, CardStatus >
): Record< string, CardStatus > {
	return Object.fromEntries(
		Object.entries( statuses ).filter(
			( [ , status ] ) => ! ghostOf( status )
		)
	);
}
