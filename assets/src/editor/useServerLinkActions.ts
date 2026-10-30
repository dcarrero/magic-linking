import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import type { Suggestion } from '../types';
import { ghostOf } from './ghosts';
import { suggestionKey } from './keys';
import type { CardStatus } from './SuggestionCard';

export type Direction = 'outbound' | 'inbound';

interface Options {
	statuses: Record< string, CardStatus >;
	setStatus: ( suggestion: Suggestion, status: CardStatus | null ) => void;
	/** Pide el foco para la tarjeta que vuelve tras un Deshacer del usuario. */
	requestFocus: ( key: string ) => void;
	/** Texto cuando un Deshacer no ha podido quitar el enlace. */
	undoFailedMessage: string;
	/** Entrantes que se enseñan ahora (para saber dónde queda la fila fantasma). */
	inboundItems?: Suggestion[];
	/** Vuelve a pedir la página de entrantes (el servidor ya no devuelve lo descartado ni lo enlazado). */
	refreshInbound?: () => void;
	/** Al enlazar una entrante, dejar la fila fantasma y volver a pedir la página, como al descartar. */
	ghostOnLink?: boolean;
	/** El grafo cambió (se añadió o deshizo un enlace): entradas cuyas cifras cambian. */
	onChanged?: ( ids: number[] ) => void;
	/** Se descartó una sugerencia (el editor quita el resaltado). */
	onDismissed?: () => void;
}

export interface ServerLinkActions {
	/** Inserta el enlace en el servidor (`POST /links`) con el `insert` de la sugerencia. */
	link: ( suggestion: Suggestion, direction: Direction ) => Promise< void >;
	/** Deshace el enlace de una tarjeta «enlazada» (cliente o servidor, según su `undo`). */
	undo: ( suggestion: Suggestion ) => Promise< void >;
	dismiss: (
		suggestion: Suggestion,
		direction: Direction
	) => Promise< void >;
	restore: (
		suggestion: Suggestion,
		direction: Direction
	) => Promise< void >;
}

/**
 * Enlazar, deshacer, descartar y restaurar una tarjeta contra el servidor, con sus estados, anuncios y errores.
 * Lo comparten el panel del editor (entrantes) y el panel lateral del informe, para que no se desvíen.
 *
 * @param options Estado de las tarjetas y lo que cambia según quien lo use.
 */
export function useServerLinkActions( options: Options ): ServerLinkActions {
	const {
		statuses,
		setStatus,
		requestFocus,
		undoFailedMessage,
		inboundItems = [],
		refreshInbound,
		ghostOnLink = false,
		onChanged,
		onDismissed,
	} = options;

	const ghostFor = ( suggestion: Suggestion ) => ( {
		suggestion,
		index: Math.max(
			0,
			inboundItems.findIndex(
				( item ) =>
					suggestionKey( item ) === suggestionKey( suggestion )
			)
		),
	} );

	const link = async ( suggestion: Suggestion, direction: Direction ) => {
		if ( ! suggestion.insert ) {
			return;
		}
		setStatus( suggestion, { kind: 'busy' } );
		try {
			const response = await api.insertLinks( [ suggestion.insert ] );
			const result = response.results[ 0 ];
			if ( result?.status === 'inserted' && response.batch_id ) {
				const batch = response.batch_id;
				const message =
					direction === 'inbound'
						? sprintf(
								/* translators: %s: title of the entry that got the link. */
								__( 'Link added in “%s”.', 'magic-linking' ),
								suggestion.source.title ?? ''
						  )
						: sprintf(
								/* translators: %s: title of the destination. */
								__( 'Link to “%s” added.', 'magic-linking' ),
								suggestion.target.title ?? ''
						  );
				const ids = [ suggestion.source.id, suggestion.target.id ];
				const ghost =
					direction === 'inbound' && ghostOnLink
						? ghostFor( suggestion )
						: undefined;
				setStatus( suggestion, {
					kind: 'added',
					message,
					history: true,
					ghost,
					undo: async () => {
						const undone = await api.runHistory( 'undo', {
							batchId: batch,
						} );
						const first = undone.results[ 0 ];
						const ok =
							!! first &&
							[
								'restored',
								'link_removed',
								'already_gone',
							].includes( first.status );
						// Haya salido bien o no, el servidor puede haber cambiado: se vuelven a leer las cifras.
						onChanged?.( ids );
						return ok
							? __( 'Link removed.', 'magic-linking' )
							: null;
					},
				} );
				onChanged?.( ids );
				if ( ghost ) {
					refreshInbound?.();
				}
				speak( message, 'polite' );
				return;
			}
			const message =
				result?.message ||
				__( 'The link could not be added.', 'magic-linking' );
			setStatus( suggestion, { kind: 'error', message } );
			speak( message, 'assertive' );
		} catch ( error ) {
			const message = errorMessage(
				error,
				__(
					'The link could not be added. Check your connection and try again.',
					'magic-linking'
				)
			);
			setStatus( suggestion, { kind: 'error', message } );
			speak( message, 'assertive' );
		}
	};

	const undo = async ( suggestion: Suggestion ) => {
		const status = statuses[ suggestionKey( suggestion ) ];
		if ( status?.kind !== 'added' || ! status.undo ) {
			return;
		}
		try {
			const message = await status.undo();
			if ( message ) {
				requestFocus( suggestionKey( suggestion ) );
				setStatus( suggestion, null );
				if ( ghostOf( status ) ) {
					// La sugerencia vuelve a la lista del servidor.
					refreshInbound?.();
				}
				speak( message, 'polite' );
				return;
			}
			setStatus( suggestion, {
				kind: 'error',
				message: undoFailedMessage,
			} );
			speak( undoFailedMessage, 'assertive' );
		} catch ( error ) {
			const message = errorMessage(
				error,
				__( 'The link could not be removed.', 'magic-linking' )
			);
			setStatus( suggestion, { kind: 'error', message } );
			speak( message, 'assertive' );
		}
	};

	const dismiss = async ( suggestion: Suggestion, direction: Direction ) => {
		setStatus( suggestion, { kind: 'dismissing' } );
		try {
			await api.dismissSuggestion(
				suggestion.source.id,
				suggestion.target.id
			);
		} catch ( error ) {
			const message = errorMessage(
				error,
				__(
					'The suggestion could not be dismissed. Check your connection and try again.',
					'magic-linking'
				)
			);
			setStatus( suggestion, { kind: 'error', message } );
			speak( message, 'assertive' );
			return;
		}
		onDismissed?.();
		if ( direction === 'inbound' ) {
			// Los elementos de las páginas siguientes suben: se vuelve a pedir la página para no saltarse ninguno;
			// la descartada ya no viene del servidor y se pone donde estaba, con su *Deshacer*.
			setStatus( suggestion, {
				kind: 'dismissed',
				ghost: ghostFor( suggestion ),
			} );
			refreshInbound?.();
		} else {
			setStatus( suggestion, { kind: 'dismissed' } );
		}
		speak(
			__(
				'Suggestion dismissed. It will not be proposed again.',
				'magic-linking'
			),
			'polite'
		);
	};

	const restore = async ( suggestion: Suggestion, direction: Direction ) => {
		const previous = statuses[ suggestionKey( suggestion ) ];
		setStatus( suggestion, {
			kind: 'dismissed',
			busy: true,
			ghost: previous ? ghostOf( previous ) : undefined,
		} );
		try {
			await api.restoreSuggestion(
				suggestion.source.id,
				suggestion.target.id
			);
		} catch ( error ) {
			setStatus( suggestion, previous ?? { kind: 'dismissed' } );
			speak(
				errorMessage(
					error,
					__(
						'The dismissal could not be undone. Check your connection and try again.',
						'magic-linking'
					)
				),
				'assertive'
			);
			return;
		}
		requestFocus( suggestionKey( suggestion ) );
		setStatus( suggestion, null );
		if ( direction === 'inbound' ) {
			refreshInbound?.();
		}
		speak( __( 'Suggestion restored.', 'magic-linking' ), 'polite' );
	};

	return { link, undo, dismiss, restore };
}
