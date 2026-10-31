import type { Suggestion, SuggestionAlternative } from '../types';

/**
 * Lo que el usuario ha elegido en una tarjeta (docs/07 §3.1 y §3.2): otra frase (`option`: 0 = la principal, 1 y 2 =
 * las alternativas) y, dentro de esa frase, otro tramo como ancla (`span`, índices UTF-16 de la frase). La frase nunca
 * se edita: solo se elige cuál y qué tramo va enlazado.
 */
export interface Choice {
	option: number;
	/** Orden en que se eligió; la elección más reciente gana si dos tarjetas quieren la misma frase. */
	seq: number;
	/** Las frases de la sugerencia cuando se eligió ({@see signature}): si la lista cambia, la elección ya no vale. */
	sig: string;
	span?: { start: number; end: number };
}

/** Elecciones por clave de la sugerencia original (la que dio el servidor). */
export type Choices = Record< string, Choice >;

/** Una palabra de la frase con su posición (índices UTF-16, los de `String.prototype.slice`). */
export interface Word {
	text: string;
	start: number;
	end: number;
}

/**
 * Longitud en bytes de UTF-8, que es como cuenta el servidor el desplazamiento del ancla.
 *
 * @param text Texto.
 */
export function byteLength( text: string ): number {
	let bytes = 0;
	for ( const char of text ) {
		const code = char.codePointAt( 0 ) ?? 0;
		if ( code < 0x80 ) {
			bytes += 1;
		} else if ( code < 0x800 ) {
			bytes += 2;
		} else if ( code < 0x10000 ) {
			bytes += 3;
		} else {
			bytes += 4;
		}
	}
	return bytes;
}

/**
 * Palabras de una frase, con el mismo criterio que el servidor (`AnchorCheck::boundaries`): letras, cifras y marcas
 * combinantes, con guiones y separadores decimales en medio; el apóstrofo separa (l'école son «l» y «école»).
 *
 * @param sentence Frase.
 */
export function wordsOf( sentence: string ): Word[] {
	const words: Word[] = [];
	const pattern =
		/[\p{L}\p{N}\p{M}]+(?:[\u2010\u2011-][\p{L}\p{N}\p{M}]+|(?<=\p{N})[.,]\p{N}+)*/gu;
	let match = pattern.exec( sentence );
	while ( match !== null ) {
		words.push( {
			text: match[ 0 ],
			start: match.index,
			end: match.index + match[ 0 ].length,
		} );
		match = pattern.exec( sentence );
	}
	return words;
}

/**
 * Primera y última palabra que toca un tramo.
 *
 * @param words Palabras de la frase.
 * @param start Inicio del tramo.
 * @param end   Fin del tramo (exclusivo).
 */
export function spanForWords(
	words: Word[],
	start: number,
	end: number
): { first: number; last: number } {
	let first = words.findIndex( ( word ) => word.end > start );
	if ( first < 0 ) {
		first = Math.max( 0, words.length - 1 );
	}
	let last = first;
	words.forEach( ( word, index ) => {
		if ( word.start < end && index >= first ) {
			last = index;
		}
	} );
	return { first, last };
}

/**
 * El tramo de texto que cubren las palabras de la `first` a la `last`.
 *
 * @param words Palabras de la frase.
 * @param first Primera palabra.
 * @param last  Última palabra.
 */
export function wordRange(
	words: Word[],
	first: number,
	last: number
): { start: number; end: number } {
	return {
		start: words[ first ]?.start ?? 0,
		end: words[ last ]?.end ?? 0,
	};
}

/**
 * La principal y las alternativas como una sola lista: la opción 0 es la principal.
 *
 * @param item Sugerencia.
 */
export function optionsOf( item: Suggestion ): SuggestionAlternative[] {
	return [ item, ...item.alternatives ];
}

/**
 * Huella de las frases que ofrece una sugerencia (párrafo y texto, en orden). Una elección solo vale mientras el
 * servidor siga devolviendo las mismas frases en el mismo orden.
 *
 * @param item Sugerencia tal como la dio el servidor.
 */
export function signature( item: Suggestion ): string {
	return optionsOf( item )
		.map( ( option ) => `${ option.paragraph }|${ option.sentence }` )
		.join( '\n' );
}

/**
 * La sugerencia tal como queda con una elección: la frase alternativa y/o el tramo ajustado. Sin elección (o con la
 * principal sin tocar) es la misma, así que lo que se enseña y se inserta sigue siendo lo que dijo el motor.
 *
 * @param item   Sugerencia (ya con la IA, si la hay).
 * @param choice Elección del usuario.
 * @param key    Clave de la tarjeta (la de la sugerencia del motor): se conserva en la que se enseña.
 */
export function applyChoice(
	item: Suggestion,
	choice?: Choice,
	key?: string
): Suggestion {
	if ( ! choice || ( choice.option === 0 && ! choice.span ) ) {
		return item;
	}
	const source = optionsOf( item )[ choice.option ] ?? item;
	let result: Suggestion = {
		...item,
		anchor: source.anchor,
		sentence: source.sentence,
		offset: source.offset,
		before: source.before,
		after: source.after,
		anchor_start: source.anchor_start,
		anchor_end: source.anchor_end,
		paragraph: source.paragraph,
		score: source.score,
		insert: choice.option === 0 ? item.insert : source.insert,
		cardKey: key ?? item.cardKey,
	};

	if ( choice.span ) {
		const { start, end } = choice.span;
		const before = source.sentence.slice( 0, start );
		const anchor = source.sentence.slice( start, end );
		const offset = byteLength( before );
		result = {
			...result,
			anchor,
			before,
			after: source.sentence.slice( end ),
			offset,
			anchor_start: start,
			anchor_end: end,
			insert: result.insert && {
				...result.insert,
				anchor,
				offset,
				adjusted: true,
			},
		};
	}
	return result;
}

/**
 * Identifica la frase de una opción dentro de una entrada.
 * @param item
 * @param option
 */
function sentenceId( item: Suggestion, option: number ): string {
	const source = optionsOf( item )[ option ] ?? item;
	return `${ item.source.id }|${ source.paragraph }|${ source.sentence }`;
}

/** Resultado del reparto: la opción que enseña cada tarjeta (null = ya no se enseña) y su tramo ajustado, si lo conserva. */
export interface Resolution {
	options: Map< string, number | null >;
	spans: Map< string, { start: number; end: number } >;
}

/**
 * Qué opción y qué tramo enseña cada tarjeta una vez repartidas las frases.
 *
 * - Una frase solo la usa una tarjeta. (Que una ancla no lleve a dos destinos desde un origen lo comprueba solo el
 *   servidor, con la clave del motor: `POST /suggestions/anchor` y `POST /links`.)
 * - Las tarjetas ya enlazadas o descartadas (`frozen`) conservan su frase y su ancla: nada se las quita.
 * - Después van las elecciones del usuario, la más reciente primero; a las demás les toca la primera de sus frases que
 *   siga libre, y si no les queda ninguna desaparecen (`null`).
 *
 * @param items   Sugerencias tal como las dio el servidor.
 * @param choices Elecciones por clave de la original.
 * @param keyOf   Clave de una sugerencia original.
 * @param frozen  Claves de las tarjetas que no pueden perder su frase.
 */
export function resolveAll(
	items: Suggestion[],
	choices: Choices,
	keyOf: ( item: Suggestion ) => string,
	frozen: ReadonlySet< string > = new Set()
): Resolution {
	const options = new Map< string, number | null >();
	const spans = new Map< string, { start: number; end: number } >();
	const claimed = new Set< string >();
	const decided = new Set< string >();
	const valid = ( item: Suggestion ): Choice | undefined => {
		const choice = choices[ keyOf( item ) ];
		return choice?.sig === signature( item ) ? choice : undefined;
	};

	// 1. Las congeladas conservan su frase; después, las elecciones del usuario (la más reciente primero).
	for ( const item of items ) {
		const key = keyOf( item );
		if ( frozen.has( key ) ) {
			const option = valid( item )?.option ?? 0;
			const shown = optionsOf( item )[ option ] ? option : 0;
			claimed.add( sentenceId( item, shown ) );
			options.set( key, shown );
			decided.add( key );
		}
	}
	const chosen = items
		.filter( ( item ) => ! decided.has( keyOf( item ) ) && valid( item ) )
		.sort(
			( x, y ) =>
				( choices[ keyOf( y ) ]?.seq ?? 0 ) -
				( choices[ keyOf( x ) ]?.seq ?? 0 )
		);
	for ( const item of chosen ) {
		const key = keyOf( item );
		const option = choices[ key ]?.option ?? 0;
		if ( optionsOf( item )[ option ] === undefined ) {
			continue;
		}
		const id = sentenceId( item, option );
		if ( ! claimed.has( id ) ) {
			claimed.add( id );
			options.set( key, option );
			decided.add( key );
		}
	}
	for ( const item of items ) {
		const key = keyOf( item );
		if ( decided.has( key ) ) {
			continue;
		}
		let taken: number | null = null;
		for ( let option = 0; option < optionsOf( item ).length; option++ ) {
			const id = sentenceId( item, option );
			if ( ! claimed.has( id ) ) {
				claimed.add( id );
				taken = option;
				break;
			}
		}
		options.set( key, taken );
	}

	// 2. Los tramos ajustados que siguen valiendo para la frase que se enseña.
	for ( const item of items ) {
		const key = keyOf( item );
		const choice = valid( item );
		if ( choice?.span && choice.option === options.get( key ) ) {
			spans.set( key, choice.span );
		}
	}

	return { options, spans };
}

/** Cambio de otra tarjeta provocado por una elección. */
export interface Displacement {
	key: string;
	kind: 'moved' | 'removed';
}

/**
 * Qué otras tarjetas han cambiado de frase o han desaparecido entre dos repartos (para anunciarlo).
 *
 * @param before Reparto anterior.
 * @param after  Reparto nuevo.
 * @param except Tarjeta que ha elegido (su cambio no se anuncia como desplazamiento).
 */
export function displacements(
	before: Map< string, number | null >,
	after: Map< string, number | null >,
	except: string
): Displacement[] {
	const changes: Displacement[] = [];
	after.forEach( ( option, key ) => {
		if ( key === except || before.get( key ) === option ) {
			return;
		}
		changes.push( { key, kind: option === null ? 'removed' : 'moved' } );
	} );
	return changes;
}
