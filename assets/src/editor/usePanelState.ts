import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { api } from '../api';
import type { Suggestion, InboundResponse, OutboundResponse } from '../types';
import type { EditorAdapter } from './adapters/types';
import type { CardStatus } from './SuggestionCard';
import { useAiRerank, type AiRerank } from './useAiRerank';
import type { AiLinkInfo } from './ai';
import { createInsertionLog, shortKey } from './insertions';
import { InsertionSync } from './sync';
import { suggestionKey } from './keys';
import { useInbound, useOutbound } from './useSuggestions';
import type { Fetched } from './useSuggestions';

export interface PanelState {
	outbound: Fetched< OutboundResponse >;
	inbound: Fetched< InboundResponse >;
	page: number;
	setPage: ( page: number ) => void;
	/** Estado de cada tarjeta por clave; es la única fuente de verdad de lo enlazado y lo descartado. */
	statuses: Record< string, CardStatus >;
	setStatus: ( suggestion: Suggestion, status: CardStatus | null ) => void;
	/** Salientes que cuentan (sin las descartadas, que siguen en la lista con su *Deshacer*). */
	visibleOutbound: Suggestion[];
	/** Entrantes de la página, con las descartadas aquí en su sitio (el servidor ya no las devuelve). */
	inboundItems: Suggestion[];
	/** Entrantes que cuentan, de todas las páginas. */
	inboundCount: number;
	/** Vuelve a pedir las salientes (el servidor ya no propone las descartadas). */
	refreshOutbound: () => void;
	/**
	 * Apunta un enlace que el panel acaba de poner en el editor; al guardar la entrada se avisa al servidor para que
	 * lo registre en el Historial (F1-34). Devuelve el identificador de la inserción.
	 */
	trackInsertion: (
		suggestion: Suggestion,
		anchor: string,
		ai?: AiLinkInfo | null
	) => string;
	/** Deja de contar un enlace que se deshizo en el editor antes de guardar. */
	forgetInsertion: ( insertionId: string ) => void;
	/** «Mejorar con IA»: reordena las salientes con el modelo del conector (docs/04 §9). */
	ai: AiRerank;
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

	// Las filas de «descartada» son de la página que se dejó: al cambiar de página se olvidan.
	const changePage = useCallback( ( next: number ) => {
		setStatuses( ( previous ) =>
			Object.fromEntries(
				Object.entries( previous ).filter(
					( [ , status ] ) =>
						! ( status.kind === 'dismissed' && status.ghost )
				)
			)
		);
		setPage( next );
	}, [] );

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

	// Enlaces puestos en el editor, pendientes de registrar en el Historial cuando la entrada se guarde.
	const sync = useMemo(
		() =>
			new InsertionSync(
				createInsertionLog( adapter.postId ),
				( links ) => api.recordEditorLinks( adapter.postId, links )
			),
		[ adapter.postId ]
	);

	// Al terminar un guardado con éxito (Gutenberg) y al cargar (el clásico recarga la página al guardar).
	useEffect(
		() =>
			adapter.onSaveCycle( {
				begin: () => sync.begin(),
				finish: ( autosave ) => void sync.finish( autosave ),
			} ),
		[ adapter, sync ]
	);
	useEffect( () => {
		void sync.flushAll();
	}, [ sync ] );

	const trackInsertion = useCallback(
		( suggestion: Suggestion, anchor: string, ai?: AiLinkInfo | null ) =>
			sync.add( {
				suggestion: shortKey( suggestionKey( suggestion ) ),
				target_id: suggestion.target.id,
				anchor,
				...( ai ?? {} ),
			} ),
		[ sync ]
	);
	const forgetInsertion = useCallback(
		( insertionId: string ) => sync.forget( insertionId ),
		[ sync ]
	);

	// Una sugerencia con estado propio (enlazada, descartada, con error) no se envía a la IA.
	const isBusy = useCallback(
		( suggestion: Suggestion ) =>
			statuses[ suggestionKey( suggestion ) ] !== undefined,
		[ statuses ]
	);
	const getContent = useCallback( () => adapter.getContent(), [ adapter ] );
	const ai = useAiRerank( adapter.postId, outbound.data, isBusy, getContent );

	const { refresh } = outbound;
	const refreshOutbound = useCallback( () => {
		setStatuses( {} );
		refresh();
	}, [ refresh ] );

	const isDismissed = ( item: Suggestion ) =>
		statuses[ suggestionKey( item ) ]?.kind === 'dismissed';

	const visibleOutbound = useMemo(
		() =>
			( outbound.data?.items ?? [] ).filter(
				( item ) =>
					statuses[ suggestionKey( item ) ]?.kind !== 'dismissed'
			),
		[ outbound.data, statuses ]
	);

	// Al descartar una entrante se vuelve a pedir la página (los demás elementos suben y no se salta ninguno);
	// la descartada ya no viene del servidor y se pone aquí donde estaba, con su *Deshacer*.
	const inboundItems = useMemo( () => {
		const items = [ ...( inbound.data?.items ?? [] ) ];
		const keys = new Set( items.map( suggestionKey ) );
		Object.values( statuses )
			.flatMap( ( status ) =>
				status.kind === 'dismissed' && status.ghost
					? [ status.ghost ]
					: []
			)
			.filter(
				( ghost ) => ! keys.has( suggestionKey( ghost.suggestion ) )
			)
			.sort( ( a, b ) => a.index - b.index )
			.forEach( ( ghost ) =>
				items.splice(
					Math.min( ghost.index, items.length ),
					0,
					ghost.suggestion
				)
			);
		return items;
	}, [ inbound.data, statuses ] );

	// El total del servidor ya no cuenta lo descartado antes de pedirlo; solo hay que restar lo descartado
	// ahora mismo que sigue en la lista que se tiene.
	const inboundCount = Math.max(
		0,
		( inbound.data?.total ?? 0 ) -
			( inbound.data?.items ?? [] ).filter( isDismissed ).length
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
		setPage: changePage,
		statuses,
		setStatus,
		visibleOutbound,
		inboundItems,
		inboundCount,
		refreshOutbound,
		trackInsertion,
		forgetInsertion,
		ai,
	};
}
