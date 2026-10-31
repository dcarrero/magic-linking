import { useEffect, useRef, useState } from '@wordpress/element';
import { api } from '../api';
import type { CurrentLink } from '../types';
import type { EditorAdapter } from './adapters/types';

/** Espera tras enlazar o deshacer, para que el editor ya tenga el cambio y varias acciones seguidas sean una petición. */
const SETTLE = 300;

/**
 * Resumen de los enlaces que el panel ha puesto en la entrada abierta (tarjetas salientes enlazadas): cambia al enlazar
 * o deshacer uno. Las tarjetas entrantes enlazan en otras entradas y no cuentan. La clave de una saliente empieza por el
 * ID de la entrada abierta (`origen:destino:posición:ancla`).
 *
 * @param statuses Estado de cada tarjeta por clave.
 * @param postId   Entrada abierta.
 */
export function outboundSignature(
	statuses: Record< string, { kind: string } >,
	postId: number
): string {
	return Object.entries( statuses )
		.filter(
			( [ key, status ] ) =>
				status.kind === 'added' && key.startsWith( `${ postId }:` )
		)
		.map( ( [ key ] ) => key )
		.sort()
		.join( '\n' );
}

/**
 * Los enlaces actuales de la entrada para el bloque «Enlaces actuales» (docs/07 §3.3). Parten de los que dio el servidor
 * con las salientes y, cuando cambia lo enlazado desde el panel (`signature`: al enlazar o al deshacer), se vuelven a
 * pedir al servidor con lo que hay ahora en el editor: así deshacer o cambiar el contenido a mano se refleja y las
 * anclas se comparan donde se calculan, no aquí.
 *
 * @param adapter   Editor.
 * @param initial   Enlaces de la última respuesta de salientes.
 * @param signature Resumen de lo enlazado desde el panel; cualquier cambio vuelve a pedirlos.
 */
export function useCurrentLinks(
	adapter: EditorAdapter,
	initial: CurrentLink[] | undefined,
	signature: string
): CurrentLink[] {
	const [ fresh, setFresh ] = useState< {
		base: CurrentLink[] | undefined;
		links: CurrentLink[];
	} | null >( null );
	const seen = useRef( { base: initial, signature } );

	useEffect( () => {
		// Una respuesta nueva de salientes ya trae los enlaces de ahora.
		if ( seen.current.base !== initial ) {
			seen.current = { base: initial, signature };
			setFresh( null );
			return undefined;
		}
		if ( seen.current.signature === signature ) {
			return undefined;
		}
		// La firma solo se da por atendida cuando la respuesta llega: si el efecto se repite antes (un render que cambia
		// el editor, el doble efecto del modo estricto), se cancela esta petición y se vuelve a lanzar.
		const controller = new AbortController();
		const timer = window.setTimeout( () => {
			api.currentLinks(
				adapter.postId,
				adapter.getContent(),
				adapter.getTitle(),
				controller.signal
			)
				.then( ( reply ) => {
					if ( ! controller.signal.aborted ) {
						seen.current.signature = signature;
						setFresh( {
							base: initial,
							links: reply.current_links,
						} );
					}
				} )
				.catch( () => undefined );
		}, SETTLE );
		return () => {
			window.clearTimeout( timer );
			controller.abort();
		};
	}, [ adapter, initial, signature ] );

	return fresh && fresh.base === initial ? fresh.links : initial ?? [];
}
