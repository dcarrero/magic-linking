import {
	useEffect,
	useId,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import type { KeyboardEvent } from 'react';
import { Button } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import type { AnchorCheckResponse } from '../types';
import { byteLength, spanForWords, wordRange, wordsOf } from './variants';

/** Lo que se sabe del tramo elegido: se está comprobando, ya se comprobó o la comprobación falló. */
type Check =
	| { state: 'idle' }
	| { state: 'pending' }
	| { state: 'done'; result: AnchorCheckResponse }
	| { state: 'failed'; message: string };

interface Props {
	/** Entrada donde irá el enlace: el servidor usa su idioma para comprobar el ancla. */
	postId: number;
	/** Destino del enlace: con él el servidor comprueba que la ancla no lleve ya a otro destino desde este origen. */
	targetId: number;
	/** Anclas que el panel ya ha elegido para otros destinos del mismo origen. */
	taken?: { anchor: string; target_id: number }[];
	sentence: string;
	/** Tramo actual del ancla (índices UTF-16 de la frase). */
	start: number;
	end: number;
	onApply: ( span: { start: number; end: number } ) => void;
	onCancel: () => void;
}

const DEBOUNCE = 250;

/**
 * Ajustar el ancla dentro de la frase (docs/07 §3.2). La frase nunca se edita: solo se elige qué tramo va enlazado.
 *
 * Teclado: `→` y `←` amplían o recortan por la derecha; `Mayús+←` y `Mayús+→` amplían o recortan por la izquierda;
 * `Intro` confirma y `Esc` cancela. `Tab` sale del control sin trampas. Ratón: pulsar una palabra fuera del ancla la
 * amplía hasta ella; pulsar una del borde la quita. Cada cambio se anuncia, se comprueba en el servidor y, si el tramo
 * no vale, se explica en línea y no se deja confirmar.
 *
 * @param root0
 * @param root0.postId
 * @param root0.targetId
 * @param root0.taken
 * @param root0.sentence
 * @param root0.start
 * @param root0.end
 * @param root0.onApply
 * @param root0.onCancel
 */
export function AnchorAdjuster( {
	postId,
	targetId,
	taken = [],
	sentence,
	start,
	end,
	onApply,
	onCancel,
}: Props ) {
	const words = useMemo( () => wordsOf( sentence ), [ sentence ] );
	const initial = useMemo(
		() => spanForWords( words, start, end ),
		[ words, start, end ]
	);
	const [ first, setFirst ] = useState( initial.first );
	const [ last, setLast ] = useState( initial.last );
	const [ check, setCheck ] = useState< Check >( { state: 'idle' } );
	const groupRef = useRef< HTMLDivElement >( null );
	const changed = useRef( false );
	const id = useId();

	// Sin mover nada, el ancla es la del motor tal cual, aunque no encaje en los límites de palabra de aquí.
	const same = first === initial.first && last === initial.last;
	const moved = words.length > 0 ? wordRange( words, first, last ) : null;
	const range = same ? { start, end } : moved;
	const anchor = range ? sentence.slice( range.start, range.end ) : '';
	// El tramo del motor es válido por construcción: solo se comprueba lo que el usuario cambia.
	const needsCheck = changed.current && ! same;
	const count = last - first + 1;

	const result = check.state === 'done' ? check.result : null;

	// Pregunta al servidor por el tramo elegido; la comprobación con retardo y la de confirmar usan la misma.
	const runCheck = ( signal: AbortSignal ) =>
		api.checkAnchor(
			postId,
			sentence,
			byteLength( sentence.slice( 0, range?.start ?? 0 ) ),
			anchor,
			targetId,
			taken,
			signal
		);
	const failureMessage = ( error: unknown ) =>
		errorMessage(
			error,
			__( 'The anchor could not be checked. Try again.', 'magic-linking' )
		);

	// La confirmación en curso: si se cancela, se mueve el tramo o el control desaparece, su respuesta se ignora.
	const confirming = useRef< AbortController | null >( null );
	const cancelConfirmation = () => {
		confirming.current?.abort();
		confirming.current = null;
	};
	const cancel = () => {
		cancelConfirmation();
		onCancel();
	};
	useEffect( () => {
		groupRef.current?.focus();
		return () => confirming.current?.abort();
	}, [] );

	// Comprobación en el servidor, sin esperar a confirmar: el mensaje sale mientras se ajusta.
	useEffect( () => {
		if ( ! range || ! needsCheck ) {
			setCheck( { state: 'idle' } );
			return undefined;
		}
		setCheck( { state: 'pending' } );
		const controller = new AbortController();
		const timer = window.setTimeout( () => {
			runCheck( controller.signal )
				.then( ( reply ) => {
					if ( ! controller.signal.aborted ) {
						setCheck( { state: 'done', result: reply } );
					}
				} )
				.catch( ( error: unknown ) => {
					if ( ! controller.signal.aborted ) {
						setCheck( {
							state: 'failed',
							message: failureMessage( error ),
						} );
					}
				} );
		}, DEBOUNCE );
		return () => {
			window.clearTimeout( timer );
			controller.abort();
		};
		// `anchor` y `range` salen de `first` y `last`.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ first, last, needsCheck, postId, sentence ] );

	const change = ( nextFirst: number, nextLast: number ) => {
		if ( nextFirst === first && nextLast === last ) {
			return;
		}
		cancelConfirmation();
		changed.current = true;
		setFirst( nextFirst );
		setLast( nextLast );
		const text = sentence.slice(
			words[ nextFirst ]?.start ?? 0,
			words[ nextLast ]?.end ?? 0
		);
		const size = nextLast - nextFirst + 1;
		speak(
			sprintf(
				/* translators: 1: anchor text, 2: number of words. */
				_n(
					'Anchor: %1$s, %2$d word',
					'Anchor: %1$s, %2$d words',
					size,
					'magic-linking'
				),
				text,
				size
			),
			'polite'
		);
	};

	const extendRight = () =>
		last < words.length - 1 && change( first, last + 1 );
	const trimRight = () => last > first && change( first, last - 1 );
	const extendLeft = () => first > 0 && change( first - 1, last );
	const trimLeft = () => first < last && change( first + 1, last );

	const confirm = async () => {
		if ( ! range || confirming.current ) {
			return;
		}
		if ( same ) {
			cancel();
			return;
		}
		// Un tramo que el servidor ya rechazó no se vuelve a enviar hasta que cambie; si la comprobación falló por la red,
		// confirmar la vuelve a lanzar.
		if ( result && ! result.valid ) {
			return;
		}
		// Siempre se vuelve a preguntar al servidor: lo que se ve puede ser de un tramo anterior.
		const controller = new AbortController();
		confirming.current = controller;
		setCheck( { state: 'pending' } );
		try {
			const answer = await runCheck( controller.signal );
			if ( controller.signal.aborted ) {
				return;
			}
			confirming.current = null;
			setCheck( { state: 'done', result: answer } );
			if ( answer.valid ) {
				onApply( range );
			} else {
				speak( answer.errors[ 0 ]?.message ?? '', 'assertive' );
			}
		} catch ( error ) {
			if ( controller.signal.aborted ) {
				return;
			}
			confirming.current = null;
			const message = failureMessage( error );
			setCheck( { state: 'failed', message } );
			speak( message, 'assertive' );
		}
	};

	const onKeyDown = ( event: KeyboardEvent< HTMLDivElement > ) => {
		if ( event.ctrlKey || event.metaKey || event.altKey ) {
			return;
		}
		switch ( event.key ) {
			case 'ArrowRight':
				event.preventDefault();
				if ( event.shiftKey ) {
					trimLeft();
				} else {
					extendRight();
				}
				break;
			case 'ArrowLeft':
				event.preventDefault();
				if ( event.shiftKey ) {
					extendLeft();
				} else {
					trimRight();
				}
				break;
			case 'Enter':
				event.preventDefault();
				void confirm();
				break;
			case 'Escape':
				// El editor cierra paneles con Escape: aquí solo se cancela el ajuste.
				event.preventDefault();
				event.stopPropagation();
				cancel();
				break;
		}
	};

	const onWord = ( index: number ) => {
		if ( index < first ) {
			change( index, last );
		} else if ( index > last ) {
			change( first, index );
		} else if ( index === first && first < last ) {
			change( first + 1, last );
		} else if ( index === last && first < last ) {
			change( first, last - 1 );
		}
	};

	const blocked =
		check.state === 'pending' || ( result !== null && ! result.valid );

	// El texto de la frase alrededor de cada palabra se pinta tal cual: nada se edita.
	const parts: JSX.Element[] = [];
	let cursor = 0;
	words.forEach( ( word, index ) => {
		if ( word.start > cursor ) {
			parts.push(
				<span key={ `gap-${ index }` }>
					{ sentence.slice( cursor, word.start ) }
				</span>
			);
		}
		const inside = index >= first && index <= last;
		parts.push(
			<button
				key={ `word-${ index }` }
				type="button"
				tabIndex={ -1 }
				className={ `magiclinking-adjust__word${
					inside ? ' is-in-anchor' : ''
				}` }
				aria-pressed={ inside }
				onClick={ () => {
					onWord( index );
					groupRef.current?.focus();
				} }
			>
				{ word.text }
			</button>
		);
		cursor = word.end;
	} );
	if ( cursor < sentence.length ) {
		parts.push( <span key="tail">{ sentence.slice( cursor ) }</span> );
	}

	return (
		<div className="magiclinking-adjust">
			<p id={ `${ id }-hint` } className="magiclinking-adjust__hint">
				{ __(
					'Right arrow extends the anchor, left arrow shortens it. Shift with the arrows does the same on the left. Enter confirms and Escape cancels.',
					'magic-linking'
				) }
			</p>
			{ /* El control es un grupo con foco propio: las flechas viven aquí y Tab sigue saliendo de él. */ }
			{ /* eslint-disable jsx-a11y/no-noninteractive-tabindex, jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				ref={ groupRef }
				role="group"
				tabIndex={ 0 }
				aria-label={ __( 'Adjust anchor', 'magic-linking' ) }
				aria-describedby={ `${ id }-hint ${ id }-state` }
				className="magiclinking-adjust__words"
				onKeyDown={ onKeyDown }
			>
				{ parts }
			</div>
			{ /* eslint-enable jsx-a11y/no-noninteractive-tabindex, jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				id={ `${ id }-state` }
				className="magiclinking-adjust__state"
				role="status"
			>
				<p>
					{ sprintf(
						/* translators: 1: anchor text, 2: number of words. */
						_n(
							'Anchor: %1$s, %2$d word',
							'Anchor: %1$s, %2$d words',
							count,
							'magic-linking'
						),
						anchor,
						count
					) }
				</p>
				{ check.state === 'pending' && (
					<p>{ __( 'Checking the anchor…', 'magic-linking' ) }</p>
				) }
				{ check.state === 'failed' && (
					<p className="magiclinking-adjust__error">
						<span aria-hidden="true">✕ </span>
						{ check.message }
					</p>
				) }
				{ result?.errors.map( ( issue ) => (
					<p
						key={ issue.code }
						className="magiclinking-adjust__error"
					>
						<span aria-hidden="true">✕ </span>
						{ issue.message }
					</p>
				) ) }
				{ result?.warnings.map( ( issue ) => (
					<p
						key={ issue.code }
						className="magiclinking-adjust__warning"
					>
						<span aria-hidden="true">⚠ </span>
						{ issue.message }
					</p>
				) ) }
			</div>
			<div className="magiclinking-card__actions">
				<Button
					variant="primary"
					size="compact"
					disabled={ blocked }
					accessibleWhenDisabled
					onClick={ () => void confirm() }
				>
					{ __( 'Apply anchor', 'magic-linking' ) }
				</Button>
				<Button variant="tertiary" size="compact" onClick={ cancel }>
					{ __( 'Cancel', 'magic-linking' ) }
				</Button>
			</div>
		</div>
	);
}
