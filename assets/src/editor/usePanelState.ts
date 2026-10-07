import { useCallback, useMemo, useState } from '@wordpress/element';
import type { Suggestion, InboundResponse, OutboundResponse } from '../types';
import type { EditorAdapter } from './adapters/types';
import type { CardStatus } from './SuggestionCard';
import { suggestionKey } from './keys';
import { useInbound, useOutbound } from './useSuggestions';
import type { Fetched } from './useSuggestions';

export interface PanelState {
	outbound: Fetched< OutboundResponse >;
	inbound: Fetched< InboundResponse >;
	page: number;
	setPage: ( page: number ) => void;
	statuses: Record< string, CardStatus >;
	setStatus: ( suggestion: Suggestion, status: CardStatus | null ) => void;
	dismissed: ReadonlySet< string >;
	dismiss: ( suggestion: Suggestion ) => void;
	/** Salientes que se enseñan (sin las descartadas). */
	visibleOutbound: Suggestion[];
	/** Vuelve a pedir las salientes y recupera las descartadas. */
	refreshOutbound: () => void;
}

/**
 * Datos y estado de las tarjetas del panel. Vive fuera de la barra lateral para que no se pierda al cerrarla
 * y para que el botón de la barra superior pueda enseñar el contador con la barra cerrada.
 *
 * @param adapter Editor.
 * @param perPage Entrantes por página.
 */
export function usePanelState(
	adapter: EditorAdapter,
	perPage: number
): PanelState {
	const outbound = useOutbound( adapter );
	const [ page, setPage ] = useState( 1 );
	const inbound = useInbound( adapter, page, perPage );
	const [ statuses, setStatuses ] = useState< Record< string, CardStatus > >(
		{}
	);
	const [ dismissed, setDismissed ] = useState< ReadonlySet< string > >(
		new Set()
	);

	const setStatus = useCallback(
		( suggestion: Suggestion, status: CardStatus | null ) => {
			const key = suggestionKey( suggestion );
			setStatuses( ( previous ) => {
				const next = { ...previous };
				if ( status ) {
					next[ key ] = status;
				} else {
					delete next[ key ];
				}
				return next;
			} );
		},
		[]
	);

	const dismiss = useCallback( ( suggestion: Suggestion ) => {
		setDismissed( ( previous ) =>
			new Set( previous ).add( suggestionKey( suggestion ) )
		);
	}, [] );

	const { refresh } = outbound;
	const refreshOutbound = useCallback( () => {
		setDismissed( new Set() );
		setStatuses( {} );
		refresh();
	}, [ refresh ] );

	const visibleOutbound = useMemo(
		() =>
			( outbound.data?.items ?? [] ).filter(
				( item ) => ! dismissed.has( suggestionKey( item ) )
			),
		[ outbound.data, dismissed ]
	);

	return {
		outbound,
		inbound,
		page,
		setPage,
		statuses,
		setStatus,
		dismissed,
		dismiss,
		visibleOutbound,
		refreshOutbound,
	};
}
