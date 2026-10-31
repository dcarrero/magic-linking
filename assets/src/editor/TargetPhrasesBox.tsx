import { useEffect, useId, useRef, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import type { TargetPhrase, TargetPhrasesState } from '../types';

/** Una fila del formulario: las variantes van en un solo campo, una por línea. */
interface Draft {
	key: number;
	phrase: string;
	variants: string;
	gap: boolean;
}

let nextKey = 1;

const toDraft = ( entry: TargetPhrase ): Draft => ( {
	key: nextKey++,
	phrase: entry.phrase,
	variants: entry.variants.join( '\n' ),
	gap: entry.gap,
} );

/**
 * Variantes limpias, como las limpia el servidor: sin espacios sobrantes, sin líneas vacías y sin repetir.
 *
 * @param text Una variante por línea.
 */
const cleanVariants = ( text: string ): string[] => {
	const seen = new Set< string >();
	const out: string[] = [];
	for ( const line of text.split( '\n' ) ) {
		const variant = line.replace( /\s+/g, ' ' ).trim();
		const key = variant.toLowerCase();
		if ( variant !== '' && ! seen.has( key ) ) {
			seen.add( key );
			out.push( variant );
		}
	}
	return out;
};

const toPhrase = ( draft: Draft ): TargetPhrase => ( {
	phrase: draft.phrase,
	variants: cleanVariants( draft.variants ),
	gap: draft.gap,
} );

/**
 * Lo que cuenta de una lista para saber si hay cambios sin guardar.
 * @param drafts
 */
const signature = ( drafts: Draft[] ): string =>
	JSON.stringify( drafts.map( toPhrase ) );

/**
 * Sección plegable «Frases objetivo» del panel (docs/07 §3.4, docs/04 §4.2): frases por las que se quiere que enlacen a
 * esta entrada, con variantes y la casilla «Permitir una palabra en medio». Opcional: el motor funciona igual sin ellas.
 * Se carga al abrirla y se guarda con el botón (no al escribir): cada guardado reindexa la entrada.
 *
 * @param root0
 * @param root0.postId Entrada que se edita.
 */
export function TargetPhrasesBox( { postId }: { postId: number } ) {
	const [ open, setOpen ] = useState( false );
	const [ state, setState ] = useState< TargetPhrasesState | null >( null );
	const [ drafts, setDrafts ] = useState< Draft[] >( [] );
	const [ saved, setSaved ] = useState( '[]' );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const loadedFor = useRef< number | null >( null );
	const addButton = useRef< HTMLButtonElement >( null );
	const panelId = useId();

	const load = ( loadedState: TargetPhrasesState ) => {
		const list = loadedState.phrases.map( toDraft );
		setState( loadedState );
		setDrafts( list );
		setSaved( signature( list ) );
	};

	useEffect( () => {
		if ( ! open || loadedFor.current === postId ) {
			return undefined;
		}
		const controller = new AbortController();
		api.targetPhrases( postId, controller.signal )
			.then( ( result ) => {
				if ( ! controller.signal.aborted ) {
					loadedFor.current = postId;
					load( result );
				}
			} )
			.catch( ( e: unknown ) => {
				if ( ! controller.signal.aborted ) {
					setError(
						errorMessage(
							e,
							__(
								'Could not load the target phrases.',
								'magic-linking'
							)
						)
					);
				}
			} );
		// Cerrar la sección a medias cancela la carga; al abrirla otra vez se reintenta. Lo ya cargado no se pierde.
		return () => controller.abort();
	}, [ open, postId ] );

	const limits = state?.limits ?? {
		phrases: 10,
		variants: 5,
		words: 8,
		chars: 120,
	};
	const dirty = signature( drafts ) !== saved;

	const update = ( key: number, change: Partial< Draft > ) => {
		setStatus( '' );
		setDrafts( ( list ) =>
			list.map( ( d ) => ( d.key === key ? { ...d, ...change } : d ) )
		);
	};

	const add = () => {
		setStatus( '' );
		setDrafts( ( list ) => [
			...list,
			{ key: nextKey++, phrase: '', variants: '', gap: false },
		] );
	};

	const remove = ( key: number ) => {
		setStatus( '' );
		setDrafts( ( list ) => list.filter( ( d ) => d.key !== key ) );
		// El campo que tenía el foco desaparece: el foco pasa al botón de añadir.
		requestAnimationFrame( () => addButton.current?.focus() );
	};

	const save = () => {
		setSaving( true );
		setError( '' );
		setStatus( '' );
		api.setTargetPhrases( postId, drafts.map( toPhrase ) )
			.then( ( result ) => {
				load( result );
				const message = sprintf(
					/* translators: %d: number of target phrases saved. */
					_n(
						'Target phrases saved: %d phrase.',
						'Target phrases saved: %d phrases.',
						result.phrases.length,
						'magic-linking'
					),
					result.phrases.length
				);
				const dropped =
					result.removed.length > 0
						? ' ' +
						  sprintf(
								/* translators: %s: list of repeated phrases the server did not keep. */
								__(
									'Not kept because they were repeated: %s.',
									'magic-linking'
								),
								result.removed
									.map( ( text ) => `“${ text }”` )
									.join( ', ' )
						  )
						: '';
				setStatus( message + dropped );
				speak( message + dropped, 'polite' );
			} )
			.catch( ( e: unknown ) => {
				setError(
					errorMessage(
						e,
						__(
							'The target phrases could not be saved.',
							'magic-linking'
						)
					)
				);
			} )
			.finally( () => setSaving( false ) );
	};

	return (
		<div className="magiclinking-panel__phrases">
			<Button
				variant="link"
				className="magiclinking-panel__phrases-toggle"
				aria-expanded={ open }
				aria-controls={ panelId }
				onClick={ () => setOpen( ! open ) }
			>
				<span aria-hidden="true">{ open ? '▾ ' : '▸ ' }</span>
				{ __( 'Target phrases', 'magic-linking' ) }
			</Button>
			<div id={ panelId } hidden={ ! open }>
				<p className="description">
					{ __(
						'Optional. The engine finds phrases on its own; this helps with the ones you care about.',
						'magic-linking'
					) }
				</p>
				{ drafts.length > 0 && (
					<ul className="magiclinking-panel__phrases-list">
						{ drafts.map( ( draft, index ) => (
							<li key={ draft.key }>
								<fieldset className="magiclinking-panel__phrase">
									<legend>
										{ sprintf(
											/* translators: %d: position of the phrase in the list. */
											__( 'Phrase %d', 'magic-linking' ),
											index + 1
										) }
									</legend>
									<TextControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __(
											'Phrase',
											'magic-linking'
										) }
										value={ draft.phrase }
										disabled={ saving }
										maxLength={ limits.chars }
										onChange={ ( value: string ) =>
											update( draft.key, {
												phrase: value,
											} )
										}
									/>
									<TextareaControl
										__nextHasNoMarginBottom
										label={ __(
											'Variants (one per line)',
											'magic-linking'
										) }
										value={ draft.variants }
										disabled={ saving }
										rows={ 2 }
										onChange={ ( value: string ) =>
											update( draft.key, {
												variants: value,
											} )
										}
									/>
									<CheckboxControl
										__nextHasNoMarginBottom
										label={ __(
											'Allow one word in between',
											'magic-linking'
										) }
										help={ __(
											'“kitchen remodel” also matches “kitchen full remodel”, but not two extra words.',
											'magic-linking'
										) }
										checked={ draft.gap }
										disabled={ saving }
										onChange={ ( value: boolean ) =>
											update( draft.key, { gap: value } )
										}
									/>
									<Button
										variant="tertiary"
										isDestructive
										disabled={ saving }
										onClick={ () => remove( draft.key ) }
									>
										{ sprintf(
											/* translators: %d: position of the phrase in the list. */
											__(
												'Remove phrase %d',
												'magic-linking'
											),
											index + 1
										) }
									</Button>
								</fieldset>
							</li>
						) ) }
					</ul>
				) }
				<div className="magiclinking-panel__phrases-actions">
					<Button
						ref={ addButton }
						variant="secondary"
						disabled={
							state === null ||
							drafts.length >= limits.phrases ||
							saving
						}
						onClick={ add }
					>
						{ __( 'Add phrase', 'magic-linking' ) }
					</Button>
					<Button
						variant="primary"
						disabled={ ! dirty || saving || state === null }
						isBusy={ saving }
						onClick={ save }
					>
						{ __( 'Save phrases', 'magic-linking' ) }
					</Button>
				</div>
				<p className="magiclinking-panel__phrases-status" role="status">
					{ status }
				</p>
				{ error && (
					<p
						className="magiclinking-panel__phrases-error"
						role="alert"
					>
						{ error }
					</p>
				) }
			</div>
		</div>
	);
}
