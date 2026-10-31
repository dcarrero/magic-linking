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
 * @param root0.sentence
 * @param root0.start
 * @param root0.end
 * @param root0.onApply
 * @param root0.onCancel
 */
export function AnchorAdjuster( {
	postId,
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

	useEffect( () => {
		groupRef.current?.focus();
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
			api.checkAnchor(
				postId,
				sentence,
				byteLength( sentence.slice( 0, range.start ) ),
				anchor,
				controller.signal
			)
				.then( ( result ) => {
					if ( ! controller.signal.aborted ) {
						setCheck( { state: 'done', result } );
					}
				} )
				.catch( ( error: unknown ) => {
					if ( ! controller.signal.aborted ) {
						setCheck( {
							state: 'failed',
							message: errorMessage(
								error,
								__(
									'The anchor could not be checked. Try again.',
									'magic-linking'
								)
							),
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
		if ( ! range ) {
			return;
		}
		if ( same ) {
			onCancel();
			return;
		}
		// Siempre se vuelve a preguntar al servidor: lo que se ve puede ser de un tramo anterior.
		setCheck( { state: 'pending' } );
		try {
			const result = await api.checkAnchor(
				postId,
				sentence,
				byteLength( sentence.slice( 0, range.start ) ),
				anchor
			);
			setCheck( { state: 'done', result } );
			if ( result.valid ) {
				onApply( range );
			} else {
				speak( result.errors[ 0 ]?.message ?? '', 'assertive' );
			}
		} catch ( error ) {
			const message = errorMessage(
				error,
				__(
					'The anchor could not be checked. Try again.',
					'magic-linking'
				)
			);
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
				onCancel();
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

	const result = check.state === 'done' ? check.result : null;
	const blocked =
		check.state === 'pending' ||
		check.state === 'failed' ||
		( result !== null && ! result.valid );

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
				<Button variant="tertiary" size="compact" onClick={ onCancel }>
					{ __( 'Cancel', 'magic-linking' ) }
				</Button>
			</div>
		</div>
	);
}
