import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
	TabPanel,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import { useLoad } from '../hooks';
import type { SettingsValues, StatusResponse } from '../types';
import { Maintenance } from './Maintenance';
import { SettingsSection, settingsSections } from './SettingsSection';
import { tabFromHash, type SettingsTabName } from './settingsTabs';

interface Props {
	/** Se llama cuando guardar los ajustes ha lanzado un análisis nuevo. */
	onReindex: () => void;
	/** Reabre el asistente de arranque. */
	onOpenWizard: () => void;
	/** Último estado conocido del análisis. */
	status: StatusResponse | null;
}

/**
 * Un entero dentro de sus límites; si el campo está vacío o no es válido, el valor de respaldo.
 * @param value    Texto del campo.
 * @param fallback Valor si no es un número.
 * @param min      Mínimo del campo.
 * @param max      Máximo del campo.
 */
function whole(
	value: string,
	fallback: number,
	min: number,
	max: number
): number {
	const number = Math.round( Number( value ) );
	if ( ! Number.isFinite( number ) || value === '' ) {
		return fallback;
	}
	return Math.min( max, Math.max( min, number ) );
}

type NumberKey =
	| 'words_per_link'
	| 'same_anchor_max'
	| 'outbound_shown'
	| 'inbound_per_page'
	| 'low_inbound_threshold';

/** Límites y valor de respaldo de cada campo numérico (los mismos que aplica el servidor). */
const NUMBER_RULES: Record<
	NumberKey,
	{ fallback: number; min: number; max: number }
> = {
	words_per_link: { fallback: 100, min: 20, max: 1000 },
	same_anchor_max: { fallback: 5, min: 1, max: 50 },
	outbound_shown: { fallback: 15, min: 1, max: 50 },
	inbound_per_page: { fallback: 10, min: 5, max: 50 },
	low_inbound_threshold: { fallback: 2, min: 1, max: 20 },
};
const NUMBER_KEYS = Object.keys( NUMBER_RULES ) as NumberKey[];

type NumberTexts = Record< NumberKey, string >;

/** Lo que hay escrito en un formulario: ajustes, textos de las listas y textos de los campos numéricos. */
interface Draft {
	values: SettingsValues;
	urls: string;
	words: string;
	numbers: NumberTexts;
}

/**
 * Borrador a partir de ajustes guardados.
 * @param settings Ajustes.
 */
function draftOf( settings: SettingsValues ): Draft {
	const numbers = {} as NumberTexts;
	NUMBER_KEYS.forEach( ( key ) => {
		numbers[ key ] = String( settings[ key ] );
	} );
	return {
		values: settings,
		urls: settings.ignored_urls.join( '\n' ),
		words: settings.ignored_words.join( '\n' ),
		numbers,
	};
}

/**
 * Los ajustes tal como se enviarían: los números se limitan a su rango (un campo vacío vuelve al valor por defecto).
 * @param draft Borrador.
 */
function effective( draft: Draft ): SettingsValues {
	const values = { ...draft.values };
	NUMBER_KEYS.forEach( ( key ) => {
		const { fallback, min, max } = NUMBER_RULES[ key ];
		values[ key ] = whole( draft.numbers[ key ], fallback, min, max );
	} );
	const lines = ( text: string ) =>
		text
			.split( /\r?\n/ )
			.map( ( line ) => line.trim() )
			.filter( Boolean );
	values.ignored_urls = lines( draft.urls );
	values.ignored_words = lines( draft.words );
	return values;
}

/**
 * Huella de lo que se enviaría, para saber si hay cambios sin guardar.
 * @param draft Borrador.
 */
function snapshot( draft: Draft ): string {
	return JSON.stringify( effective( draft ) );
}

/** Qué ajustes (y listas) de cada pestaña: de aquí salen sus marcas de cambios sin guardar. */
const TAB_FIELDS: Record< SettingsTabName, ( keyof SettingsValues )[] > = {
	general: [ 'post_types' ],
	suggestions: [
		'outbound_shown',
		'inbound_per_page',
		'words_per_link',
		'same_anchor_max',
		'low_inbound_threshold',
	],
	links: [
		'link_in_headings',
		'link_in_quotes',
		'link_in_tables',
		'open_in_new_tab',
		'extra_rel',
		'ignored_urls',
		'ignored_words',
	],
	data: [ 'history_retention_days', 'delete_data_on_uninstall' ],
};

/**
 * Ajustes (docs/07 §8): pocos y con valores por defecto sensatos.
 * @param root0
 * @param root0.onReindex
 * @param root0.onOpenWizard
 * @param root0.status
 */
export function SettingsTab( { onReindex, onOpenWizard, status }: Props ) {
	const { data, loading, error } = useLoad(
		() => api.settings(),
		[],
		__( 'Could not load the settings.', 'magic-linking' )
	);
	const [ draft, setDraft ] = useState< Draft | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ message, setMessage ] = useState( '' );
	const [ failure, setFailure ] = useState( '' );
	const [ baseline, setBaseline ] = useState( '' );
	// Último borrador pintado: al volver la respuesta del servidor se compara con lo que se envió.
	const latest = useRef< Draft | null >( null );
	latest.current = draft;
	// Pestaña activa (va en la dirección). `rev` cambia solo cuando la pide la dirección desde fuera.
	const [ location, setLocation ] = useState( () =>
		tabFromHash( window.location.hash )
	);
	const [ rev, setRev ] = useState( 0 );
	const [ anchor, setAnchor ] = useState( location.anchor );
	const activeTab = useRef< SettingsTabName >( location.tab );

	/**
	 * Deja el formulario y la referencia de «sin cambios» en unos ajustes guardados.
	 * @param settings Ajustes.
	 */
	const reset = ( settings: SettingsValues ) => {
		const next = draftOf( settings );
		setDraft( next );
		setBaseline( snapshot( next ) );
	};

	useEffect( () => {
		if ( data ) {
			const next = draftOf( data.settings );
			setDraft( next );
			setBaseline( snapshot( next ) );
		}
	}, [ data ] );

	const dirty = useMemo(
		() =>
			draft !== null && baseline !== '' && snapshot( draft ) !== baseline,
		[ draft, baseline ]
	);

	const baseValues = useMemo(
		() =>
			baseline === ''
				? null
				: ( JSON.parse( baseline ) as SettingsValues ),
		[ baseline ]
	);

	// Aviso del navegador al salir solo si hay cambios sin guardar.
	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}
		const warn = ( event: BeforeUnloadEvent ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	// Un enlace con otra pestaña (o atrás/adelante) cambia la pestaña sin perder lo escrito.
	useEffect( () => {
		const onHash = () => {
			if ( ! /^#?(tab=|magiclinking-)/.test( window.location.hash ) ) {
				return;
			}
			const next = tabFromHash( window.location.hash );
			if ( next.tab !== activeTab.current ) {
				activeTab.current = next.tab;
				setLocation( next );
				setRev( ( value ) => value + 1 );
			}
			setAnchor( next.anchor );
		};
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	// Un ancla de la versión anterior lleva a su tarjeta cuando la pestaña ya está pintada.
	const ready = draft !== null && !! data;
	useEffect( () => {
		if ( ready && anchor ) {
			window.requestAnimationFrame( () => {
				document.getElementById( anchor )?.scrollIntoView();
			} );
			setAnchor( '' );
		}
	}, [ ready, anchor, rev ] );

	if ( ! draft || ! data ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<p>{ loading ? __( 'Loading…', 'magic-linking' ) : '' }</p>
		);
	}

	const { values } = draft;
	const sections = settingsSections();

	const set = < K extends keyof SettingsValues >(
		key: K,
		value: SettingsValues[ K ]
	) => {
		setDraft( { ...draft, values: { ...values, [ key ]: value } } );
		setMessage( '' );
	};

	const setText = ( key: 'urls' | 'words', value: string ) => {
		setDraft( { ...draft, [ key ]: value } );
		setMessage( '' );
	};

	const toggleType = ( name: string, checked: boolean ) => {
		const next = checked
			? [ ...values.post_types, name ]
			: values.post_types.filter( ( type ) => type !== name );
		set( 'post_types', next );
	};

	const save = async ( event: { preventDefault: () => void } ) => {
		event.preventDefault();
		setSaving( true );
		setFailure( '' );
		try {
			const sent = snapshot( draft );
			const saved = await api.saveSettings( effective( draft ) );
			if (
				latest.current !== null &&
				snapshot( latest.current ) === sent
			) {
				reset( saved.settings );
			} else {
				// Se editó mientras se guardaba: se conservan esas ediciones y solo avanza lo guardado.
				setBaseline( snapshot( draftOf( saved.settings ) ) );
			}
			const text = saved.job
				? __(
						'Settings saved. What Magic Linking reads has changed, so your site is being analyzed again.',
						'magic-linking'
				  )
				: __( 'Settings saved.', 'magic-linking' );
			setMessage( text );
			speak( text, 'polite' );
			if ( saved.job ) {
				onReindex();
			}
		} catch ( e ) {
			setFailure(
				errorMessage(
					e,
					__( 'The settings could not be saved.', 'magic-linking' )
				)
			);
		} finally {
			setSaving( false );
		}
	};

	const number = ( key: NumberKey, label: string, help: string ) => {
		const { fallback, min, max } = NUMBER_RULES[ key ];
		const text = draft.numbers[ key ];
		const valid =
			/^\d+$/.test( text ) &&
			Number( text ) >= min &&
			Number( text ) <= max;
		return (
			<TextControl
				className="magiclinking-number"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="number"
				min={ min }
				max={ max }
				label={ label }
				aria-invalid={ ! valid }
				help={
					valid ? (
						help
					) : (
						<>
							{ help }
							<span className="magiclinking-field-error">
								{ sprintf(
									/* translators: 1: minimum, 2: maximum. */
									__(
										'Enter a whole number from %1$s to %2$s; otherwise it is adjusted when you leave the field.',
										'magic-linking'
									),
									String( min ),
									String( max )
								) }
							</span>
						</>
					)
				}
				value={ text }
				onChange={ ( value ) => {
					setDraft( {
						...draft,
						numbers: { ...draft.numbers, [ key ]: value },
					} );
					setMessage( '' );
				} }
				onBlur={ () =>
					setDraft( {
						...draft,
						numbers: {
							...draft.numbers,
							[ key ]: String(
								whole( text, fallback, min, max )
							),
						},
					} )
				}
			/>
		);
	};

	const noTypes = values.post_types.length === 0;
	let statusText = '';
	if ( noTypes ) {
		statusText = __( 'Choose at least one content type.', 'magic-linking' );
	} else if ( dirty ) {
		statusText = __( 'Unsaved changes', 'magic-linking' );
	}

	// Estado de cada pestaña: cambios sin guardar o un valor que no es válido.
	const current = effective( draft );
	const tabDirty = ( name: SettingsTabName ) =>
		baseValues !== null &&
		TAB_FIELDS[ name ].some(
			( key ) =>
				JSON.stringify( current[ key ] ) !==
				JSON.stringify( baseValues[ key ] )
		);
	const tabInvalid = ( name: SettingsTabName ) =>
		( name === 'general' && noTypes ) ||
		NUMBER_KEYS.some( ( key ) => {
			if ( ! ( TAB_FIELDS[ name ] as string[] ).includes( key ) ) {
				return false;
			}
			const { min, max } = NUMBER_RULES[ key ];
			const text = draft.numbers[ key ];
			return ! (
				/^\d+$/.test( text ) &&
				Number( text ) >= min &&
				Number( text ) <= max
			);
		} );

	const tabTitle = ( name: SettingsTabName, label: string ) => {
		const invalid = tabInvalid( name );
		const changed = tabDirty( name );
		return (
			<>
				{ label }
				{ ( invalid || changed ) && (
					<>
						<span
							className={ `magiclinking-tab-mark${
								invalid ? ' is-invalid' : ''
							}` }
							aria-hidden="true"
						/>
						<span className="screen-reader-text">
							{ invalid
								? __( '(needs attention)', 'magic-linking' )
								: __( '(unsaved changes)', 'magic-linking' ) }
						</span>
					</>
				) }
			</>
		);
	};

	// `title` es un texto en los tipos de TabPanel, pero se pinta tal cual y admite la marca.
	const labels: [ SettingsTabName, string ][] = [
		[ 'general', __( 'General', 'magic-linking' ) ],
		[ 'suggestions', __( 'Suggestions', 'magic-linking' ) ],
		[ 'links', __( 'Links', 'magic-linking' ) ],
		[ 'data', __( 'History and data', 'magic-linking' ) ],
	];
	const tabs = labels.map( ( [ name, label ] ) => ( {
		name,
		title: tabTitle( name as SettingsTabName, label ) as unknown as string,
	} ) );

	const onSelect = ( name: string ) => {
		activeTab.current = name as SettingsTabName;
		window.history.replaceState(
			null,
			'',
			`${ window.location.pathname }${ window.location.search }#tab=${ name }`
		);
	};

	const cards = ( name: string ) => {
		switch ( name ) {
			case 'suggestions':
				return (
					<>
						<SettingsSection section={ sections.suggestions }>
							{ number(
								'outbound_shown',
								__(
									'Outbound suggestions in the editor',
									'magic-linking'
								),
								__(
									'The most useful links to add from the entry you are editing.',
									'magic-linking'
								)
							) }
							{ number(
								'inbound_per_page',
								__(
									'Inbound suggestions per page in the editor',
									'magic-linking'
								),
								__(
									'Entries that could link to the one you are editing, shown this many at a time.',
									'magic-linking'
								)
							) }
						</SettingsSection>
						<SettingsSection section={ sections.overload }>
							{ number(
								'words_per_link',
								__(
									'Words per internal link',
									'magic-linking'
								),
								__(
									'An entry with more internal links than one for every this many words is shown as over-linked (always at least one link is allowed).',
									'magic-linking'
								)
							) }
							{ number(
								'same_anchor_max',
								__(
									'Entries that can use the same anchor text for the same target',
									'magic-linking'
								),
								__(
									'Beyond this many entries, the same anchor text towards the same target is suggested less.',
									'magic-linking'
								)
							) }
						</SettingsSection>
						<SettingsSection section={ sections.underlinked }>
							{ number(
								'low_inbound_threshold',
								__(
									'Under-linked below this many inbound links',
									'magic-linking'
								),
								__(
									'An entry with at least one inbound link but fewer than this number is shown as under-linked.',
									'magic-linking'
								)
							) }
						</SettingsSection>
					</>
				);
			case 'links':
				return (
					<>
						<SettingsSection section={ sections.insertion }>
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ __( 'Headings', 'magic-linking' ) }
								checked={ values.link_in_headings }
								onChange={ ( checked ) =>
									set( 'link_in_headings', checked )
								}
							/>
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ __( 'Quotes', 'magic-linking' ) }
								checked={ values.link_in_quotes }
								onChange={ ( checked ) =>
									set( 'link_in_quotes', checked )
								}
							/>
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ __( 'Tables', 'magic-linking' ) }
								checked={ values.link_in_tables }
								onChange={ ( checked ) =>
									set( 'link_in_tables', checked )
								}
							/>
							<p className="description">
								{ __(
									'Changing these makes Magic Linking read your entries again.',
									'magic-linking'
								) }
							</p>
						</SettingsSection>
						<SettingsSection section={ sections.manner }>
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ __(
									'Open links in a new tab',
									'magic-linking'
								) }
								help={ __(
									'Off by default. Adds target="_blank" and rel="noopener" to the links Magic Linking inserts; links already in your content are not changed.',
									'magic-linking'
								) }
								checked={ values.open_in_new_tab }
								onChange={ ( checked ) =>
									set( 'open_in_new_tab', checked )
								}
							/>
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __(
									'Additional rel values',
									'magic-linking'
								) }
								help={ __(
									'Optional, separated by spaces (for example: nofollow). Added to the links Magic Linking inserts.',
									'magic-linking'
								) }
								value={ values.extra_rel }
								onChange={ ( value ) =>
									set( 'extra_rel', value )
								}
							/>
						</SettingsSection>
						<SettingsSection section={ sections.exclusions }>
							<TextareaControl
								__nextHasNoMarginBottom
								label={ __(
									'Ignored addresses',
									'magic-linking'
								) }
								help={ __(
									'One address per line. These entries are never suggested as a target, and never suggested to link to others.',
									'magic-linking'
								) }
								rows={ 4 }
								value={ draft.urls }
								onChange={ ( value ) =>
									setText( 'urls', value )
								}
							/>
							<TextareaControl
								__nextHasNoMarginBottom
								label={ __( 'Ignored words', 'magic-linking' ) }
								help={ __(
									'One word or phrase per line. Suggestions whose anchor text contains one of them are not shown.',
									'magic-linking'
								) }
								rows={ 4 }
								value={ draft.words }
								onChange={ ( value ) =>
									setText( 'words', value )
								}
							/>
						</SettingsSection>
					</>
				);
			case 'data':
				return (
					<>
						<SettingsSection section={ sections.history }>
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __(
									'Keep the history of changes for',
									'magic-linking'
								) }
								help={ __(
									'Older batches are deleted by the nightly task, so you can no longer undo them. The links stay in your content. There is no limit on the number of changes kept.',
									'magic-linking'
								) }
								value={
									String( values.history_retention_days ) as
										| '0'
										| '30'
										| '90'
										| '365'
								}
								options={ [
									{
										value: '30',
										label: __( '30 days', 'magic-linking' ),
									},
									{
										value: '90',
										label: __( '90 days', 'magic-linking' ),
									},
									{
										value: '365',
										label: __(
											'365 days',
											'magic-linking'
										),
									},
									{
										value: '0',
										label: __(
											'No expiry date',
											'magic-linking'
										),
									},
								] }
								onChange={ ( value ) =>
									set(
										'history_retention_days',
										Number( value )
									)
								}
							/>
						</SettingsSection>
						<SettingsSection section={ sections.uninstall }>
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ __(
									'Delete all Magic Linking data when the plugin is deleted',
									'magic-linking'
								) }
								help={ __(
									'Removes its tables, history and settings. The links already in your entries stay as they are; your content is not touched.',
									'magic-linking'
								) }
								checked={ values.delete_data_on_uninstall }
								onChange={ ( checked ) =>
									set( 'delete_data_on_uninstall', checked )
								}
							/>
						</SettingsSection>
					</>
				);
			default:
				return (
					<SettingsSection section={ sections.content }>
						<fieldset>
							<legend>
								{ __(
									'Content types to analyze',
									'magic-linking'
								) }
							</legend>
							{ data.post_types.map( ( type ) => (
								<CheckboxControl
									key={ type.name }
									__nextHasNoMarginBottom
									label={ type.label }
									checked={ values.post_types.includes(
										type.name
									) }
									onChange={ ( checked ) =>
										toggleType( type.name, checked )
									}
								/>
							) ) }
						</fieldset>
					</SettingsSection>
				);
		}
	};

	return (
		<div className="magiclinking-settings-page">
			<TabPanel
				key={ rev }
				className="magiclinking-settings-tabs"
				tabs={ tabs }
				initialTabName={ location.tab }
				onSelect={ onSelect }
			>
				{ ( tab ) => (
					<div className="magiclinking-settings-tabcontent">
						<form
							id="magiclinking-settings-form"
							className="magiclinking-settings"
							onSubmit={ save }
						>
							{ cards( tab.name ) }
						</form>

						<div className="magiclinking-savebar">
							{ failure && (
								<Notice status="error" isDismissible={ false }>
									{ failure }
								</Notice>
							) }
							{ message && (
								<Notice
									status="success"
									isDismissible={ false }
								>
									{ message }
								</Notice>
							) }
							<div className="magiclinking-savebar__row">
								<Button
									type="submit"
									form="magiclinking-settings-form"
									variant="primary"
									disabled={ saving || ! dirty || noTypes }
									accessibleWhenDisabled
								>
									{ __( 'Save settings', 'magic-linking' ) }
								</Button>
								<p
									className="magiclinking-savebar__status"
									role="status"
								>
									{ statusText }
								</p>
							</div>
						</div>

						{ tab.name === 'general' && (
							<Maintenance
								status={ status }
								onStarted={ onReindex }
								onOpenWizard={ onOpenWizard }
							/>
						) }
					</div>
				) }
			</TabPanel>
		</div>
	);
}
