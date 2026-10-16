import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import type {
	AiCapabilities,
	AiUsage,
	OutboundResponse,
	RerankImproved,
	Suggestion,
} from '../types';
import {
	arrange,
	candidates,
	outcomeOf,
	rerankItems,
	unfitCount,
	withAnchor,
	type AiOutcome,
	type AiView,
} from './ai';
import { suggestionKey } from './keys';

/** Segundos que el navegador espera de más sobre lo que espera el servidor al modelo. */
const CLIENT_MARGIN = 10;

/** Si no se sabe cuánto espera el servidor, se supone el tope de un proveedor local. */
const DEFAULT_SERVER_TIMEOUT = 20;

/** Lo que dice el panel cuando la IA no responde (docs/05 §8). */
export const NO_RESPONSE = () =>
	__(
		'The AI did not respond; showing suggestions without AI.',
		'magic-linking'
	);

/**
 * Lo que se dice (y se enseña) mientras se espera al modelo: que sigue pensando y cuánto puede tardar como máximo.
 *
 * @param seconds Segundos que espera el servidor; sin dato, no se dice cifra.
 */
export function waitingText( seconds?: number ): string {
	if ( ! seconds ) {
		return __( 'Asking the AI…', 'magic-linking' );
	}
	return sprintf(
		/* translators: %d: maximum number of seconds the wait can last. */
		__( 'Asking the AI… it can take up to %d seconds.', 'magic-linking' ),
		seconds
	);
}

export type AiPhase = 'idle' | 'loading' | 'done' | 'failed';

export interface AiRerank {
	/** Lo que se sabe del conector; nulo mientras carga o si no hay cliente de IA. */
	capabilities: AiCapabilities | null;
	phase: AiPhase;
	/** Texto de lo ocurrido cuando la IA no respondió o no se pudo usar. */
	message: string;
	/** Gasto de la última llamada que se hizo. */
	usage: AiUsage | null;
	/** Sugerencias que la IA marcó como no aptas en la última llamada. */
	unfit: number;
	/** Pide a la IA que reordene las salientes que se ven. */
	run: () => void;
	/** Vuelve al orden del motor. */
	reset: () => void;
	/** Acepta el ancla mejorada de una tarjeta. */
	acceptAnchor: ( original: Suggestion, improved: RerankImproved ) => void;
	/** La lista con el orden de la IA, si lo hay. */
	view: ( items: Suggestion[] ) => AiView[];
	/** Cuántas sugerencias se enviarían ahora. */
	pending: number;
}

/**
 * «Mejorar con IA»: reordena las salientes con el modelo del conector (docs/04 §9, docs/05).
 *
 * Solo hace una llamada al modelo cuando el usuario pulsa el botón; averiguar si hay conector y cuánto costaría no
 * llama a ningún modelo. El resultado vale para la lista con la que se pidió: si las salientes se vuelven a pedir
 * (guardar, actualizar, descartar), se olvida y vuelve el orden léxico.
 *
 * @param postId     Entrada abierta.
 * @param data       Respuesta de las salientes (su identidad dice si la lista es la misma).
 * @param isBusy     Dice si una sugerencia ya tiene un estado propio (enlazada, descartada, con error).
 * @param getContent Contenido que el editor tiene ahora (el servidor comprueba que las frases son de él).
 */
export function useAiRerank(
	postId: number,
	data: OutboundResponse | null,
	isBusy: ( suggestion: Suggestion ) => boolean,
	getContent: () => string
): AiRerank {
	const [ capabilities, setCapabilities ] = useState< AiCapabilities | null >(
		null
	);
	const [ phase, setPhase ] = useState< AiPhase >( 'idle' );
	const [ message, setMessage ] = useState( '' );
	const [ usage, setUsage ] = useState< AiUsage | null >( null );
	const [ outcome, setOutcome ] = useState< AiOutcome | null >( null );
	const [ overrides, setOverrides ] = useState<
		Record< string, Suggestion >
	>( {} );
	const running = useRef< AbortController | null >( null );

	// Una sugerencia con el ancla de la IA aceptada guarda su estado bajo la clave nueva.
	const busy = useCallback(
		( suggestion: Suggestion ) =>
			isBusy( suggestion ) ||
			( overrides[ suggestionKey( suggestion ) ] !== undefined &&
				isBusy(
					overrides[ suggestionKey( suggestion ) ] as Suggestion
				) ),
		[ isBusy, overrides ]
	);

	const items = data?.items;
	const pending = useMemo(
		() => ( items ? candidates( items, busy ).length : 0 ),
		[ items, busy ]
	);

	// Lo que se sabe del conector (sin llamar al modelo). Se pide una vez por lista de sugerencias: enlazar o
	// descartar no la cambia, y cada petición puede costar una consulta de red al servidor del conector.
	const count = Math.max( 1, Math.min( items?.length ?? 0, 20 ) );
	const hasItems = !! items && items.length > 0;
	useEffect( () => {
		if ( ! hasItems ) {
			return undefined;
		}
		const controller = new AbortController();
		api.aiCapabilities( count, controller.signal )
			.then( ( caps ) => {
				if ( ! controller.signal.aborted ) {
					setCapabilities( caps );
				}
			} )
			.catch( () => {
				// Sin respuesta, el panel queda como sin IA: ni botón ni avisos.
				if ( ! controller.signal.aborted ) {
					setCapabilities( null );
				}
			} );
		return () => controller.abort();
	}, [ hasItems, count ] );

	// Una lista nueva (guardar, actualizar, descartar) deja sin valor lo que la IA dijo de la anterior.
	useEffect( () => {
		// Se suelta antes de abortar: la petición cancelada no es un fallo de la IA y no debe avisar de uno.
		const previous = running.current;
		running.current = null;
		previous?.abort();
		setPhase( 'idle' );
		setMessage( '' );
		setOutcome( null );
		setOverrides( {} );
	}, [ data ] );

	useEffect(
		() => () => {
			const previous = running.current;
			running.current = null;
			previous?.abort();
		},
		[]
	);

	const run = useCallback( () => {
		if ( ! items || phase === 'loading' ) {
			return;
		}
		const sent = candidates( items, busy );
		if ( sent.length === 0 ) {
			return;
		}

		running.current?.abort();
		const controller = new AbortController();
		running.current = controller;
		const timer = window.setTimeout(
			() => controller.abort(),
			( ( capabilities?.timeout ?? DEFAULT_SERVER_TIMEOUT ) +
				CLIENT_MARGIN ) *
				1000
		);
		const failed = ( text: string ) => {
			setPhase( 'failed' );
			setMessage( text );
			setOutcome( null );
			speak( text, 'polite' );
		};

		setPhase( 'loading' );
		setMessage( '' );
		speak( waitingText( capabilities?.timeout ), 'polite' );

		api.rerank(
			postId,
			rerankItems( sent ),
			getContent(),
			controller.signal
		)
			.then( ( response ) => {
				if ( running.current !== controller ) {
					return;
				}
				if ( ! response.ok ) {
					setUsage( response.usage ?? null );
					failed( response.message || NO_RESPONSE() );
					return;
				}
				const next = outcomeOf( sent, response );
				const unfitNow = unfitCount( next );
				setUsage( response.usage );
				setOutcome( next );
				// Un ancla aceptada en una ejecución anterior solo sigue si esta respuesta la propone otra vez; las
				// tarjetas que no se enviaron (ya enlazadas) conservan la suya.
				setOverrides( ( previous ) =>
					Object.fromEntries(
						Object.entries( previous ).filter(
							( [ key, replaced ] ) => {
								const mark = next.marks[ key ];
								return (
									! mark ||
									( mark.improved?.anchor ===
										replaced.anchor &&
										mark.improved?.offset ===
											replaced.offset )
								);
							}
						)
					)
				);
				setPhase( 'done' );
				speak(
					sprintf(
						/* translators: %d: number of suggestions the AI does not recommend. */
						_n(
							'Suggestions improved with AI. %d is not recommended.',
							'Suggestions improved with AI. %d are not recommended.',
							unfitNow,
							'magic-linking'
						),
						unfitNow
					),
					'polite'
				);
			} )
			.catch( () => {
				if ( running.current === controller ) {
					failed( NO_RESPONSE() );
				}
			} )
			.finally( () => window.clearTimeout( timer ) );
	}, [ items, phase, busy, postId, getContent, capabilities ] );

	const reset = useCallback( () => {
		setPhase( 'idle' );
		setMessage( '' );
		setOutcome( null );
		// Sin respuesta vigente no se puede insertar un ancla de la IA sin su marca: las tarjetas vuelven a su ancla
		// (salvo las ya enlazadas, que guardan su estado bajo la clave del ancla nueva).
		setOverrides( ( previous ) =>
			Object.fromEntries(
				Object.entries( previous ).filter( ( [ , replaced ] ) =>
					busy( replaced )
				)
			)
		);
		speak( __( 'Back to the original order.', 'magic-linking' ), 'polite' );
	}, [ busy ] );

	const acceptAnchor = useCallback(
		( original: Suggestion, improved: RerankImproved ) => {
			setOverrides( ( previous ) => ( {
				...previous,
				[ suggestionKey( original ) ]: withAnchor( original, improved ),
			} ) );
			speak(
				sprintf(
					/* translators: %s: new anchor text. */
					__( 'Anchor changed to “%s”.', 'magic-linking' ),
					improved.anchor
				),
				'polite'
			);
		},
		[]
	);

	const view = useCallback(
		( list: Suggestion[] ) => arrange( list, outcome, overrides ),
		[ outcome, overrides ]
	);

	return {
		capabilities,
		phase,
		message,
		usage,
		unfit: outcome ? unfitCount( outcome ) : 0,
		run,
		reset,
		acceptAnchor,
		view,
		pending,
	};
}
