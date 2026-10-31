import { useCallback, useMemo, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import type { Suggestion } from '../types';
import { suggestionKey } from './keys';
import {
	applyChoice,
	displacements,
	optionsOf,
	resolveAll,
	signature,
	type Choice,
	type Choices,
	type Resolution,
} from './variants';

/** Una tarjeta tal como se enseña tras las elecciones del usuario. */
export interface VariantView {
	/** La sugerencia que se enseña y se inserta; lleva la clave de la tarjeta (`cardKey`). */
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
	/** Elige otra frase (0 = la principal); se pierde el ancla ajustada de la anterior. Devuelve si se aplicó. */
	choose: ( original: Suggestion, option: number ) => boolean;
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
 * tarjeta y una ancla solo lleva a un destino por origen: si el usuario toma la de otra, esa pasa a su siguiente frase
 * libre o desaparece, y se anuncia. Las tarjetas ya enlazadas o descartadas (`frozen`) no pierden nada: la elección que
 * choque con ellas se rechaza con un aviso.
 *
 * @param items     Lista tal como la dio el servidor.
 * @param frozen    Claves de las tarjetas enlazadas, descartadas o en curso.
 * @param direction En las entrantes las tarjetas se nombran por la entrada de origen, no por el destino.
 */
export function useVariants(
	items: Suggestion[],
	frozen: ReadonlySet< string >,
	direction: 'outbound' | 'inbound' = 'outbound'
): Variants {
	const [ choices, setChoices ] = useState< Choices >( {} );
	const choicesRef = useRef( choices );
	const seq = useRef( 0 );

	const resolved = useMemo(
		() => resolveAll( items, choices, suggestionKey, frozen ),
		[ items, choices, frozen ]
	);

	const commit = useCallback( ( next: Choices ) => {
		choicesRef.current = next;
		setChoices( next );
	}, [] );

	/** Anuncia qué otras tarjetas cambiaron de frase o de ancla por una elección. */
	const announce = useCallback(
		( key: string, before: Resolution, after: Resolution ) => {
			for ( const change of displacements(
				before.options,
				after.options,
				key
			) ) {
				const other = items.find(
					( item ) => suggestionKey( item ) === change.key
				);
				const moved = change.kind === 'moved';
				let text: string;
				if ( direction === 'inbound' ) {
					const name = other?.source.title ?? '';
					text = moved
						? sprintf(
								/* translators: %s: title of the entry where the link would go. */
								__(
									'The suggestion in “%s” has changed sentence.',
									'magic-linking'
								),
								name
						  )
						: sprintf(
								/* translators: %s: title of the entry where the link would go. */
								__(
									'The suggestion in “%s” was removed because its sentence is now used by another one.',
									'magic-linking'
								),
								name
						  );
				} else {
					const name = other?.target.title ?? '';
					text = moved
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
						  );
				}
				speak( text, 'polite' );
			}
		},
		[ items, direction ]
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
			const after = resolveAll( items, next, suggestionKey, frozen );
			if ( after.options.get( key ) !== option ) {
				speak(
					__(
						'That sentence cannot be used: a link you added or dismissed already takes it.',
						'magic-linking'
					),
					'assertive'
				);
				return false;
			}
			const before = resolveAll(
				items,
				choicesRef.current,
				suggestionKey,
				frozen
			);
			announce( key, before, after );
			commit( next );
			return true;
		},
		[ announce, commit, items, frozen ]
	);

	const adjust = useCallback(
		( original: Suggestion, span: { start: number; end: number } ) => {
			const key = suggestionKey( original );
			const current: Choice | undefined = choicesRef.current[ key ];
			const next: Choices = {
				...choicesRef.current,
				[ key ]: {
					option: resolved.options.get( key ) ?? current?.option ?? 0,
					seq: ++seq.current,
					sig: signature( original ),
					span,
				},
			};
			commit( next );
		},
		[ commit, resolved ]
	);

	const reset = useCallback( () => commit( {} ), [ commit ] );

	const view = useCallback(
		( item: Suggestion, original: Suggestion ): VariantView | null => {
			const key = suggestionKey( original );
			const option = resolved.options.get( key );
			if ( option === null ) {
				return null;
			}
			const stored = choices[ key ];
			// Una elección hecha para otras frases (la lista se volvió a pedir) no se aplica.
			const choice =
				stored?.sig === signature( original ) ? stored : undefined;
			const shown = option ?? 0;
			const span = resolved.spans.get( key );
			const effective: Choice | undefined =
				shown !== 0 || span
					? {
							option: shown,
							seq: choice?.seq ?? 0,
							sig: choice?.sig ?? '',
							span,
					  }
					: undefined;
			const chosen =
				effective && optionsOf( item )[ effective.option ]
					? effective
					: undefined;
			let shownItem = applyChoice( item, chosen, key );
			// Con la IA el ancla ya no es la del motor, pero la tarjeta sigue siendo la misma.
			if ( shownItem === item && item !== original ) {
				shownItem = { ...item, cardKey: key };
			}
			return {
				item: shownItem,
				base: item,
				option: chosen?.option ?? 0,
				changed: chosen !== undefined,
			};
		},
		[ resolved, choices ]
	);

	const hidden = useCallback(
		( original: Suggestion ) =>
			resolved.options.get( suggestionKey( original ) ) === null,
		[ resolved ]
	);

	return useMemo(
		() => ( { view, choose, adjust, reset, hidden } ),
		[ view, choose, adjust, reset, hidden ]
	);
}
