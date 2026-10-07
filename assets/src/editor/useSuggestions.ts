import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import type { InboundResponse, OutboundResponse } from '../types';
import type { EditorAdapter } from './adapters/types';

export interface Fetched< T > {
	data: T | null;
	loading: boolean;
	error: string;
	/** Vuelve a pedir los datos. */
	refresh: () => void;
}

/** Espera antes de la primera petición, para que el editor termine de cargar. */
const FIRST_DELAY = 800;

/**
 * Pide un dato al abrir, cuando cambian `deps`, al guardar la entrada y con `refresh()`; nunca en cada
 * pulsación (docs/07 §3). Cancela la petición anterior.
 *
 * @param adapter Editor (avisa al guardar).
 * @param load    Función que pide el dato; recibe la señal para cancelar.
 * @param deps    Lo que, al cambiar, vuelve a pedirlo.
 */
function useFetched< T >(
	adapter: EditorAdapter,
	load: ( signal: AbortSignal ) => Promise< T >,
	deps: readonly unknown[]
): Fetched< T > {
	const [ version, setVersion ] = useState( 0 );
	const [ state, setState ] = useState< {
		data: T | null;
		loading: boolean;
		error: string;
	} >( { data: null, loading: true, error: '' } );
	const first = useRef( true );

	const refresh = useCallback( () => setVersion( ( v ) => v + 1 ), [] );

	useEffect( () => adapter.onSaved( refresh ), [ adapter, refresh ] );

	useEffect( () => {
		const controller = new AbortController();
		setState( ( previous ) => ( {
			...previous,
			loading: true,
			error: '',
		} ) );
		const timer = setTimeout(
			() => {
				load( controller.signal )
					.then( ( data ) => {
						if ( ! controller.signal.aborted ) {
							setState( { data, loading: false, error: '' } );
						}
					} )
					.catch( ( error: unknown ) => {
						if ( ! controller.signal.aborted ) {
							setState( ( previous ) => ( {
								data: previous.data,
								loading: false,
								error: errorMessage(
									error,
									__(
										'The suggestions could not be loaded. Check your connection and try again.',
										'magic-linking'
									)
								),
							} ) );
						}
					} );
			},
			first.current ? FIRST_DELAY : 0
		);
		first.current = false;
		return () => {
			clearTimeout( timer );
			controller.abort();
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ adapter, version, ...deps ] );

	return { ...state, refresh };
}

/**
 * Salientes del contenido que el usuario tiene ahora en el editor (sin guardarlo).
 *
 * @param adapter Editor.
 */
export function useOutbound(
	adapter: EditorAdapter
): Fetched< OutboundResponse > {
	return useFetched(
		adapter,
		( signal ) =>
			api.outboundDraft(
				adapter.postId,
				adapter.getContent(),
				adapter.getTitle(),
				signal
			),
		[]
	);
}

/**
 * Entrantes hacia la entrada abierta, paginadas.
 *
 * @param adapter Editor.
 * @param page    Página.
 * @param perPage Por página.
 */
export function useInbound(
	adapter: EditorAdapter,
	page: number,
	perPage: number
): Fetched< InboundResponse > {
	return useFetched(
		adapter,
		( signal ) => api.inbound( adapter.postId, page, perPage, signal ),
		[ page, perPage ]
	);
}
