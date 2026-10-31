import { useCallback, useMemo, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import type { Suggestion } from '../types';
import { suggestionKey } from './keys';
import {
	applyChoice,
	displacements,
	optionsOf,
	resolve,
	signature,
	type Choice,
	type Choices,
} from './variants';

/** Una tarjeta tal como se enseña tras las elecciones del usuario. */
export interface VariantView {
	/** La sugerencia que se enseña y se inserta. */
	item: Suggestion;
	/** La principal y sus alternativas tal como estaban antes de elegir (la opción 0 es la principal). */
	base: Suggestion;
	/** Opción que se enseña ahora. */
	option: number;
	/** Hay otra frase u otro ancla elegidos: lo que dijo la IA ya no se refiere a lo que se ve. */
	changed: boolean;
}

export interface Variants {
	/** La tarjeta con las elecciones aplicadas, o null si su frase la usa ahora otra sugerencia. */
	view: ( item: Suggestion, original: Suggestion ) => VariantView | null;
	/** Elige otra frase (0 = la principal); se pierde el ancla ajustada de la anterior. */
	choose: ( original: Suggestion, option: number ) => void;
	/** Fija el tramo de la frase elegida que va enlazado (índices UTF-16). */
	adjust: (
		original: Suggestion,
		span: { start: number; end: number }
	) => void;
	/** Quita todas las elecciones (al volver a pedir la lista). */
	reset: () => void;
	/** Sugerencias de la lista que ya no se enseñan porque otra tomó su frase. */
	hidden: ( original: Suggestion ) => boolean;
}

/**
 * Elecciones del usuario sobre una lista de sugerencias: otra frase y otro tramo de ella como ancla (docs/07 §3.1 y
 * §3.2). Vive con el estado del panel para que no se pierdan al cambiar de pestaña. Una frase solo la usa una
 * tarjeta: si el usuario toma la de otra, esa pasa a su siguiente frase libre o desaparece, y se anuncia.
 *
 * @param items Lista tal como la dio el servidor.
 */
export function useVariants( items: Suggestion[] ): Variants {
	const [ choices, setChoices ] = useState< Choices >( {} );
	const choicesRef = useRef( choices );
	const seq = useRef( 0 );

	const resolved = useMemo(
		() => resolve( items, choices, suggestionKey ),
		[ items, choices ]
	);

	const commit = useCallback( ( next: Choices ) => {
		choicesRef.current = next;
		setChoices( next );
	}, [] );

	/** Anuncia qué otras tarjetas cambiaron de frase por una elección. */
	const announce = useCallback(
		( key: string, next: Choices ) => {
			const before = resolve( items, choicesRef.current, suggestionKey );
			const after = resolve( items, next, suggestionKey );
			for ( const change of displacements( before, after, key ) ) {
				const other = items.find(
					( item ) => suggestionKey( item ) === change.key
				);
				const name = other?.target.title ?? '';
				speak(
					change.kind === 'moved'
						? sprintf(
								/* translators: %s: title of the destination of the suggestion. */
								__(
									'The suggestion to “%s” has changed sentence.',
									'magic-linking'
								),
								name
						  )
						: sprintf(
								/* translators: %s: title of the destination of the suggestion. */
								__(
									'The suggestion to “%s” was removed because its sentence is now used by another one.',
									'magic-linking'
								),
								name
						  ),
					'polite'
				);
			}
		},
		[ items ]
	);

	const choose = useCallback(
		( original: Suggestion, option: number ) => {
			const key = suggestionKey( original );
			const next: Choices = {
				...choicesRef.current,
				[ key ]: {
					option,
					seq: ++seq.current,
					sig: signature( original ),
				},
			};
			announce( key, next );
			commit( next );
		},
		[ announce, commit ]
	);

	const adjust = useCallback(
		( original: Suggestion, span: { start: number; end: number } ) => {
			const key = suggestionKey( original );
			const current: Choice | undefined = choicesRef.current[ key ];
			const next: Choices = {
				...choicesRef.current,
				[ key ]: {
					option: current?.option ?? 0,
					seq: ++seq.current,
					sig: signature( original ),
					span,
				},
			};
			commit( next );
		},
		[ commit ]
	);

	const reset = useCallback( () => commit( {} ), [ commit ] );

	const view = useCallback(
		( item: Suggestion, original: Suggestion ): VariantView | null => {
			const key = suggestionKey( original );
			const option = resolved.get( key );
			if ( option === null ) {
				return null;
			}
			const stored = choices[ key ];
			// Una elección hecha para otras frases (la lista se volvió a pedir) no se aplica.
			const choice =
				stored?.sig === signature( original ) ? stored : undefined;
			const shown = option ?? 0;
			// Si la frase elegida se la quedó otra tarjeta, la elección ya no vale (tampoco su tramo).
			const effective: Choice | undefined =
				shown !== 0 || choice?.span
					? {
							option: shown,
							seq: choice?.seq ?? 0,
							sig: choice?.sig ?? '',
							span:
								choice && choice.option === shown
									? choice.span
									: undefined,
					  }
					: undefined;
			const chosen =
				effective && optionsOf( item )[ effective.option ]
					? effective
					: undefined;
			return {
				item: applyChoice( item, chosen ),
				base: item,
				option: chosen?.option ?? 0,
				changed:
					chosen !== undefined &&
					( chosen.option !== 0 || chosen.span !== undefined ),
			};
		},
		[ resolved, choices ]
	);

	const hidden = useCallback(
		( original: Suggestion ) =>
			resolved.get( suggestionKey( original ) ) === null,
		[ resolved ]
	);

	return useMemo(
		() => ( { view, choose, adjust, reset, hidden } ),
		[ view, choose, adjust, reset, hidden ]
	);
}
