import { useEffect, useRef, useState } from '@wordpress/element';
import { errorMessage } from './api';

export interface Loaded< T > {
	data: T | null;
	loading: boolean;
	error: string;
}

/**
 * Carga un dato y lo vuelve a cargar cuando cambian las dependencias, cancelando la petición anterior.
 *
 * @param load    Función que pide el dato; recibe la señal para cancelar.
 * @param deps    Dependencias que disparan una nueva carga.
 * @param failure Texto si la petición falla sin mensaje.
 */
export function useLoad< T >(
	load: ( signal: AbortSignal ) => Promise< T >,
	deps: readonly unknown[],
	failure: string
): Loaded< T > {
	const [ state, setState ] = useState< Loaded< T > >( {
		data: null,
		loading: true,
		error: '',
	} );

	useEffect( () => {
		const controller = new AbortController();
		setState( ( previous ) => ( {
			data: previous.data,
			loading: true,
			error: '',
		} ) );
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
						error: errorMessage( error, failure ),
					} ) );
				}
			} );
		return () => controller.abort();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, deps );

	return state;
}

/**
 * Valor con retraso, para no pedir datos a cada tecla.
 *
 * @param value Valor actual.
 * @param delay Milisegundos de espera.
 */
export function useDebounced< T >( value: T, delay = 300 ): T {
	const [ debounced, setDebounced ] = useState( value );
	useEffect( () => {
		const timer = setTimeout( () => setDebounced( value ), delay );
		return () => clearTimeout( timer );
	}, [ value, delay ] );
	return debounced;
}

/**
 * Indica si es el primer renderizado (para no anunciar la carga inicial dos veces).
 */
export function useIsFirstRender(): boolean {
	const first = useRef( true );
	useEffect( () => {
		first.current = false;
	}, [] );
	return first.current;
}
