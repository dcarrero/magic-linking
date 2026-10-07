import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
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
	/** Descartadas en esta sesión, por dirección. */
	dismissed: Record< 'outbound' | 'inbound', ReadonlySet< string > >;
	dismiss: (
		suggestion: Suggestion,
		direction: 'outbound' | 'inbound'
	) => void;
	/** Salientes que se enseñan (sin las descartadas). */
	visibleOutbound: Suggestion[];
	/** Entrantes de la página que se enseñan (sin las descartadas). */
	visibleInbound: Suggestion[];
	/** Entrantes sin las descartadas (de todas las páginas). */
	inboundCount: number;
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
	const [ dismissed, setDismissed ] = useState< {
		outbound: ReadonlySet< string >;
		inbound: ReadonlySet< string >;
	} >( { outbound: new Set(), inbound: new Set() } );

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

	const dismiss = useCallback(
		( suggestion: Suggestion, direction: 'outbound' | 'inbound' ) => {
			setDismissed( ( previous ) => ( {
				...previous,
				[ direction ]: new Set( previous[ direction ] ).add(
					suggestionKey( suggestion )
				),
			} ) );
		},
		[]
	);

	const { refresh } = outbound;
	const refreshOutbound = useCallback( () => {
		setDismissed( ( previous ) => ( {
			...previous,
			outbound: new Set(),
		} ) );
		setStatuses( {} );
		refresh();
	}, [ refresh ] );

	const visibleOutbound = useMemo(
		() =>
			( outbound.data?.items ?? [] ).filter(
				( item ) => ! dismissed.outbound.has( suggestionKey( item ) )
			),
		[ outbound.data, dismissed.outbound ]
	);

	const visibleInbound = useMemo(
		() =>
			( inbound.data?.items ?? [] ).filter(
				( item ) => ! dismissed.inbound.has( suggestionKey( item ) )
			),
		[ inbound.data, dismissed.inbound ]
	);

	const inboundCount = Math.max(
		0,
		( inbound.data?.total ?? 0 ) - dismissed.inbound.size
	);

	// Tras enlazar la última de una página (o al cambiar el total), la página pedida puede no existir ya:
	// se vuelve a la última que sí.
	const { data: inboundData, loading: inboundLoading } = inbound;
	useEffect( () => {
		if (
			inboundData &&
			! inboundLoading &&
			inboundData.total_pages >= 1 &&
			page > inboundData.total_pages
		) {
			setPage( inboundData.total_pages );
		}
	}, [ inboundData, inboundLoading, page ] );

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
		visibleInbound,
		inboundCount,
		refreshOutbound,
	};
}
