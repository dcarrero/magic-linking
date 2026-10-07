/**
 * Quita de un texto con formato solo el enlace que se puso desde el panel (deshacer dirigido, docs/06 §2).
 */

/** Formato de rich-text de un carácter (los que importan aquí). */
export interface FormatLike {
	type: string;
	attributes?: { url?: string };
}

export interface Run {
	start: number;
	end: number;
}

/**
 * Tramos continuos del texto que son un enlace `core/link` a esa dirección.
 *
 * @param formats Formatos por carácter (`value.formats`).
 * @param url     Dirección del enlace.
 */
export function linkRuns(
	formats: ReadonlyArray< ReadonlyArray< FormatLike > | undefined >,
	url: string
): Run[] {
	const runs: Run[] = [];
	let start = -1;
	const linked = ( index: number ) =>
		formats[ index ]?.some(
			( format ) =>
				format.type === 'core/link' && format.attributes?.url === url
		) ?? false;
	for ( let i = 0; i <= formats.length; i++ ) {
		const on = i < formats.length && linked( i );
		if ( on && start === -1 ) {
			start = i;
		} else if ( ! on && start !== -1 ) {
			runs.push( { start, end: i } );
			start = -1;
		}
	}
	return runs;
}

/**
 * El único tramo cuyo texto es exactamente el ancla que se enlazó y cuyo enlace es el que se puso; `null` si no
 * hay ninguno o hay más de uno (entonces no se toca nada).
 *
 * @param text    Texto plano del bloque.
 * @param formats Formatos por carácter.
 * @param url     Dirección del enlace.
 * @param anchor  Texto que se enlazó.
 */
export function findOwnLink(
	text: string,
	formats: ReadonlyArray< ReadonlyArray< FormatLike > | undefined >,
	url: string,
	anchor: string
): Run | null {
	const matches = linkRuns( formats, url ).filter(
		( run ) => text.slice( run.start, run.end ) === anchor
	);
	return matches.length === 1 ? matches[ 0 ] ?? null : null;
}
