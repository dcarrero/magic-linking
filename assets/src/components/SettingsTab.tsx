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
import type {
	FilterMode,
	FilterName,
	SettingsValues,
	StatusResponse,
} from '../types';
import { CategoryList } from './CategoryList';
import { AiSettingsCards } from './AiSettingsCards';
import { Maintenance, useMaintenance } from './Maintenance';
import { SettingsSection, settingsSections } from './SettingsSection';
import { parseHash, TAB_FIELDS, type SettingsTabName } from './settingsTabs';

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
	| 'links_per_target_max'
	| 'outbound_shown'
	| 'alternatives'
	| 'inbound_per_page'
	| 'low_inbound_threshold'
	| 'k_terms'
	| 'score_threshold'
	| 'ai_daily_token_limit';

/** Límites y valor de respaldo de cada campo numérico (los mismos que aplica el servidor). */
const NUMBER_RULES: Record<
	NumberKey,
	{ fallback: number; min: number; max: number }
> = {
	words_per_link: { fallback: 100, min: 20, max: 1000 },
	same_anchor_max: { fallback: 5, min: 1, max: 50 },
	links_per_target_max: { fallback: 1, min: 1, max: 10 },
	outbound_shown: { fallback: 15, min: 1, max: 50 },
	alternatives: { fallback: 0, min: 0, max: 2 },
	inbound_per_page: { fallback: 10, min: 5, max: 50 },
	low_inbound_threshold: { fallback: 2, min: 1, max: 20 },
	k_terms: { fallback: 40, min: 20, max: 80 },
	score_threshold: { fallback: 35, min: 20, max: 60 },
	ai_daily_token_limit: { fallback: 2000000, min: 0, max: 1000000000 },
};
/**
 * Límites de un campo numérico: los del servidor (`Settings::INT_RANGES`, que manda) y, sin ellos, los de respaldo.
 *
 * @param key Campo numérico.
 */
function rule( key: NumberKey ): {
	fallback: number;
	min: number;
	max: number;
} {
	const base = NUMBER_RULES[ key ];
	const range = window.magiclinking?.intRanges?.[ key ];
	return range ? { ...base, min: range[ 0 ], max: range[ 1 ] } : base;
}

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
	// Los tipos de contenido y las categorías excluidas son conjuntos: el orden en que se marcaron no es un cambio.
	values.post_types = [ ...values.post_types ].sort();
	values.excluded_categories = [ ...values.excluded_categories ].sort(
		( a, b ) => a - b
	);
	NUMBER_KEYS.forEach( ( key ) => {
		const { fallback, min, max } = rule( key );
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

/**
 * ¿El texto del campo es un entero dentro de los límites de ese campo?
 * @param key  Campo numérico.
 * @param text Lo escrito.
 */
function isValidNumber( key: NumberKey, text: string ): boolean {
	const { min, max } = rule( key );
	return (
		/^\d+$/.test( text ) && Number( text ) >= min && Number( text ) <= max
	);
}

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
	const [ location, setLocation ] = useState(
		() =>
			parseHash( window.location.hash ) ?? {
				tab: 'general' as SettingsTabName,
				anchor: '',
			}
	);
	const [ rev, setRev ] = useState( 0 );
	// El estado de Mantenimiento vive aquí para no perder un error o un análisis en curso al cambiar de pestaña.
	const maintenance = useMaintenance( onReindex );
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
			// Solo `#tab=…` y las anclas antiguas piden una pestaña; cualquier otro fragmento se ignora.
			const next = parseHash( window.location.hash );
			if ( ! next ) {
				return;
			}
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

	const toggleCategory = ( id: number, checked: boolean ) => {
		const next = checked
			? [ ...values.excluded_categories, id ]
			: values.excluded_categories.filter( ( term ) => term !== id );
		set( 'excluded_categories', next );
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

	const setFilter = ( name: FilterName, mode: FilterMode ) =>
		set( 'filters', { ...values.filters, [ name ]: mode } );

	const filterControl = ( name: FilterName, label: string, help: string ) => (
		<SelectControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ label }
			help={ help }
			value={ values.filters[ name ] }
			options={ [
				{
					value: 'ignore',
					label: __( 'Ignore', 'magic-linking' ),
				},
				{
					value: 'consider',
					label: __(
						'Take into account (a small boost if they share it)',
						'magic-linking'
					),
				},
				{
					value: 'require',
					label: __(
						'Require (only entries that share it)',
						'magic-linking'
					),
				},
			] }
			onChange={ ( mode ) => setFilter( name, mode as FilterMode ) }
		/>
	);

	const number = (
		key: NumberKey,
		label: string,
		help: string,
		disabled = false
	) => {
		const { fallback, min, max } = rule( key );
		const text = draft.numbers[ key ];
		const valid = isValidNumber( key, text );
		return (
			<TextControl
				className="magiclinking-number"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="number"
				min={ min }
				max={ max }
				disabled={ disabled }
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
	// Un número fuera de rango se corrige solo al salir del campo (y al guardar), así que nunca queda
	// pendiente en otra pestaña: su error se ve junto al campo mientras se escribe. La marca de «requiere
	// atención» es solo para lo que no se autocorrige: no haber elegido ningún tipo de contenido.
	const tabInvalid = ( name: SettingsTabName ) =>
		name === 'general' && noTypes;

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
		[ 'ai', __( 'AI', 'magic-linking' ) ],
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
								'alternatives',
								__(
									'Alternative sentences per suggestion',
									'magic-linking'
								),
								__(
									'0 shows one sentence per suggestion. With 1 or 2, each card offers “More sentences” to link from another sentence of the same entry.',
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
									'An entry with more internal links than one for every this many words is shown as over-linked (always at least one link is allowed). Automatic rules never add a link to an entry that already has that many.',
									'magic-linking'
								)
							) }
							{ number(
								'links_per_target_max',
								__(
									'Links to the same target from one entry',
									'magic-linking'
								),
								__(
									'Automatic rules add at most this many links from an entry to the same target. Existing links are never removed.',
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
									'Beyond this many entries, the same anchor text towards the same target is suggested less, and automatic rules stop using it in new entries.',
									'magic-linking'
								)
							) }
						</SettingsSection>
						<SettingsSection section={ sections.filters }>
							{ filterControl(
								'type',
								__( 'Content type', 'magic-linking' ),
								__(
									'For example, suggest links from posts only to posts.',
									'magic-linking'
								)
							) }
							{ filterControl(
								'category',
								__( 'Category', 'magic-linking' ),
								__(
									'In a custom content type, its main hierarchical taxonomy is used instead.',
									'magic-linking'
								)
							) }
							{ filterControl(
								'tag',
								__( 'Tag', 'magic-linking' ),
								__(
									'In a custom content type, its main non-hierarchical taxonomy is used instead.',
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
						<SettingsSection section={ sections.advanced }>
							<details className="magiclinking-advanced">
								<summary>
									{ __(
										'Show advanced settings',
										'magic-linking'
									) }
								</summary>
								{ number(
									'k_terms',
									__(
										'Key terms per entry',
										'magic-linking'
									),
									__(
										'How many of the most important terms of each entry are kept to find related entries (20 to 80). Changing it makes Magic Linking analyze your site again.',
										'magic-linking'
									)
								) }
								{ number(
									'score_threshold',
									__(
										'Score threshold (%)',
										'magic-linking'
									),
									__(
										'A suggestion is shown only if its score reaches this percentage. Higher shows fewer, surer suggestions; lower shows more. It does not analyze your site again.',
										'magic-linking'
									)
								) }
							</details>
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
			case 'ai':
				return (
					<AiSettingsCards
						sections={ sections }
						model={ values.ai_model }
						onModel={ ( value ) => set( 'ai_model', value ) }
						limitField={ ( disabled ) =>
							number(
								'ai_daily_token_limit',
								__(
									'Daily AI limit (tokens)',
									'magic-linking'
								),
								__(
									'The most tokens Magic Linking may use through your connector in a day. 0 turns AI off. When the limit is reached, AI pauses until tomorrow and the suggestions use the lexical engine.',
									'magic-linking'
								),
								disabled
							)
						}
					/>
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
					<>
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
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ __(
									'Detect the language of each entry from its text',
									'magic-linking'
								) }
								help={
									data.multilingual
										? __(
												'WPML or Polylang already tell Magic Linking the language of each entry, so this option does nothing while one of them is active.',
												'magic-linking'
										  )
										: sprintf(
												/* translators: %d: minimum number of words an entry needs for its language to be detected. */
												__(
													'Useful when your site is in one language but some entries are written in another. It is a best guess from the most common words, so it needs at least %d words; when it is not sure, the site language is used. Saving makes Magic Linking analyze your site again.',
													'magic-linking'
												),
												data.detect_min_words
										  )
								}
								disabled={ data.multilingual }
								checked={ values.detect_language }
								onChange={ ( checked ) =>
									set( 'detect_language', checked )
								}
							/>
						</SettingsSection>
						<SettingsSection
							section={ sections.excludedCategories }
						>
							{ data.category_groups.length === 0 ? (
								<p className="description">
									{ __(
										'The content types you analyze have no categories.',
										'magic-linking'
									) }
								</p>
							) : (
								data.category_groups.map( ( group ) => (
									<CategoryList
										key={ group.taxonomy }
										group={ group }
										selected={ values.excluded_categories }
										full={
											values.excluded_categories.length >=
											data.max_excluded
										}
										onToggle={ toggleCategory }
									/>
								) )
							) }
							<p className="description">
								{ values.excluded_categories.length >=
									data.max_excluded &&
									sprintf(
										/* translators: %d: maximum number of categories. */
										__(
											'You have reached the limit of %d categories. Untick one to choose another.',
											'magic-linking'
										),
										data.max_excluded
									) + ' ' }
								{ __(
									'Only the exact category counts: its subcategories are not excluded with it. Saving makes Magic Linking analyze your site again. The list follows the content types saved above, so save first after choosing new ones.',
									'magic-linking'
								) }
							</p>
						</SettingsSection>
					</>
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
								control={ maintenance }
								onOpenWizard={ onOpenWizard }
							/>
						) }
					</div>
				) }
			</TabPanel>
		</div>
	);
}
