// Lógica pura del «Mejorar con IA» del panel (docs/04 §9): qué se envía, cómo se recoloca la lista con la respuesta y
// cómo se aplica un ancla mejorada. Sin WordPress ni React, para poder probarla.
import type {
	RerankImproved,
	RerankItem,
	RerankResponse,
	Suggestion,
} from '../types';
import { suggestionKey } from './keys';

/** Candidatas que se envían como máximo (las 20 mejores del motor). */
export const MAX_CANDIDATES = 20;

/** Lo que la IA opina de una sugerencia. */
export interface AiMark {
	/** Nulo si la IA no la valoró. */
	fit: boolean | null;
	confidence: number | null;
	improved: RerankImproved | null;
	/** Modelo que respondió en la llamada de la que sale (F1-38). */
	model: string;
}

/** Una tarjeta ya recolocada: la sugerencia que se enseña, la original (su identidad en la lista) y lo que opina la IA. */
export interface AiView {
	item: Suggestion;
	original: Suggestion;
	mark?: AiMark;
	/** El ancla que se enseña es la que propuso la respuesta vigente de la IA (no la de una ejecución anterior). */
	aiAnchor: boolean;
}

/** Resultado de una llamada: orden nuevo (claves de las originales) y opinión sobre cada una. */
export interface AiOutcome {
	order: string[];
	marks: Record< string, AiMark >;
}

/**
 * Sugerencias que se pueden enviar: las 20 primeras que no tienen estado (ni enlazadas, ni descartadas, ni con error).
 *
 * @param items  Salientes en orden léxico.
 * @param isBusy Dice si la sugerencia ya tiene un estado propio en el panel.
 */
export function candidates(
	items: Suggestion[],
	isBusy: ( suggestion: Suggestion ) => boolean
): Suggestion[] {
	return items
		.filter( ( item ) => ! isBusy( item ) )
		.slice( 0, MAX_CANDIDATES );
}

/**
 * Cuerpo de la petición: solo lo que el servidor necesita, nada más.
 *
 * @param sent Sugerencias que se envían.
 */
export function rerankItems( sent: Suggestion[] ): RerankItem[] {
	return sent.map( ( suggestion ) => ( {
		target_id: suggestion.target.id,
		sentence: suggestion.sentence,
		anchor: suggestion.anchor,
		offset: suggestion.offset,
	} ) );
}

/**
 * Orden y opiniones de una respuesta correcta. Una posición que el servidor no devuelve (no se envió) no entra: sigue
 * en su sitio detrás de las valoradas. Una posición que no existe o repetida se ignora.
 *
 * @param sent     Sugerencias enviadas, en el orden de la petición.
 * @param response Respuesta con `ok: true`.
 */
export function outcomeOf(
	sent: Suggestion[],
	response: Extract< RerankResponse, { ok: true } >
): AiOutcome {
	const order: string[] = [];
	const marks: Record< string, AiMark > = {};

	for ( const result of response.items ) {
		const original = sent[ result.index ];
		if ( ! original ) {
			continue;
		}
		const key = suggestionKey( original );
		if ( marks[ key ] ) {
			continue;
		}
		order.push( key );
		marks[ key ] = {
			fit: result.fit,
			confidence: result.confidence,
			improved: result.improved,
			model: response.usage.model,
		};
	}

	return { order, marks };
}

/** Longitud máxima del identificador del modelo que acepta el servidor. */
export const MODEL_MAX = 100;

/** Lo que el panel avisa al servidor de un enlace que recomendó la IA. */
export interface AiLinkInfo {
	ai_recommended: true;
	ai_anchor: boolean;
	ai_model: string;
}

/**
 * Lo que se anota de la IA cuando el usuario pulsa *Enlazar* en una tarjeta de salientes (F1-38): solo si la IA la
 * valoró como apta. Si no la recomendó (no apta, sin valorar, sin llamada o con el orden original) no se anota nada.
 *
 * @param mark         Opinión de la IA sobre la tarjeta, si la hay.
 * @param usedImproved Si el enlace usa el ancla que propuso la IA y el usuario aceptó.
 * @return La marca, o null si la IA no recomendaba el enlace.
 */
export function aiLinkInfo(
	mark: AiMark | undefined,
	usedImproved: boolean
): AiLinkInfo | null {
	if ( ! mark || mark.fit !== true ) {
		return null;
	}
	return {
		ai_recommended: true,
		ai_anchor: usedImproved,
		// Por puntos de código, como cuenta el servidor (mb_substr). Guarda como mucho 100 caracteres (AiOrigin::MODEL_MAX) y rechaza más en POST /links.
		ai_model: Array.from( mark.model ).slice( 0, MODEL_MAX ).join( '' ),
	};
}

/**
 * La misma sugerencia con otra ancla (un tramo de la misma frase): se pinta y se inserta con ella.
 *
 * @param suggestion Sugerencia original.
 * @param improved   Ancla mejor que propone la IA.
 */
export function withAnchor(
	suggestion: Suggestion,
	improved: RerankImproved
): Suggestion {
	return {
		...suggestion,
		anchor: improved.anchor,
		offset: improved.offset,
		before: improved.before,
		after: improved.after,
		anchor_start: improved.anchor_start,
		anchor_end: improved.anchor_end,
		// El ancla es otra: los avisos de la anterior no valen y la tarjeta los pide al servidor.
		anchor_notes: null,
		insert: suggestion.insert && {
			...suggestion.insert,
			anchor: improved.anchor,
			offset: improved.offset,
		},
	};
}

/**
 * La lista tal como se enseña: primero lo que la IA ordenó, después el resto en su orden; cada tarjeta con su opinión y,
 * si el usuario aceptó el ancla mejorada, con esa ancla.
 *
 * @param items     Salientes en orden léxico.
 * @param outcome   Resultado de la última llamada, o null si no hay.
 * @param overrides Sugerencias con el ancla mejorada ya aceptada, por clave de la original.
 */
export function arrange(
	items: Suggestion[],
	outcome: AiOutcome | null,
	overrides: Record< string, Suggestion >
): AiView[] {
	const views = items.map( ( original ) => {
		const key = suggestionKey( original );
		const mark = outcome?.marks[ key ];
		const replaced = overrides[ key ];
		// Solo cuenta si el ancla aceptada es la que propone esta misma respuesta: un ancla de una ejecución anterior
		// ya no es de la IA vigente.
		const aiAnchor =
			!! mark &&
			!! replaced &&
			!! mark.improved &&
			replaced.anchor === mark.improved.anchor &&
			replaced.offset === mark.improved.offset;
		return {
			key,
			view: {
				item: replaced ?? original,
				original,
				// Con el ancla aceptada ya no hay nada que proponer.
				mark: mark && replaced ? { ...mark, improved: null } : mark,
				aiAnchor,
			} as AiView,
		};
	} );

	if ( ! outcome ) {
		return views.map( ( entry ) => entry.view );
	}

	const rank = new Map(
		outcome.order.map( ( key, index ) => [ key, index ] )
	);
	return views
		.map( ( entry, index ) => ( { ...entry, index } ) )
		.sort( ( a, b ) => {
			const ra = rank.get( a.key );
			const rb = rank.get( b.key );
			if ( ra !== undefined && rb !== undefined ) {
				return ra - rb;
			}
			if ( ra !== undefined ) {
				return -1;
			}
			if ( rb !== undefined ) {
				return 1;
			}
			return a.index - b.index;
		} )
		.map( ( entry ) => entry.view );
}

/**
 * Cuántas sugerencias de la lista marca la IA como no aptas.
 *
 * @param outcome Resultado de la llamada.
 */
export function unfitCount( outcome: AiOutcome ): number {
	return Object.values( outcome.marks ).filter(
		( mark ) => mark.fit === false
	).length;
}
