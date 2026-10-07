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

/** Espera antes del primer análisis, para que el editor termine de cargar. */
const FIRST_DELAY = 800;

/**
 * Salientes del contenido que el usuario tiene ahora en el editor (sin guardarlo).
 *
 * Se piden al abrir, al guardar y con `refresh()`; nunca en cada pulsación (docs/07 §3).
 *
 * @param adapter Editor.
 */
export function useOutbound(
	adapter: EditorAdapter
): Fetched< OutboundResponse > {
	const [ version, setVersion ] = useState( 0 );
	const [ state, setState ] = useState< {
		data: OutboundResponse | null;
		loading: boolean;
		error: string;
	} >( { data: null, loading: true, error: '' } );

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
				api.outboundDraft(
					adapter.postId,
					adapter.getContent(),
					adapter.getTitle(),
					controller.signal
				)
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
			version === 0 ? FIRST_DELAY : 0
		);
		return () => {
			clearTimeout( timer );
			controller.abort();
		};
	}, [ adapter, version ] );

	return { ...state, refresh };
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
	const [ version, setVersion ] = useState( 0 );
	const [ state, setState ] = useState< {
		data: InboundResponse | null;
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
				api.inbound( adapter.postId, page, perPage, controller.signal )
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
	}, [ adapter, page, perPage, version ] );

	return { ...state, refresh };
}
