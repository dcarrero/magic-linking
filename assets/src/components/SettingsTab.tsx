import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import { useLoad } from '../hooks';
import type { SettingsValues, StatusResponse } from '../types';
import { Maintenance } from './Maintenance';
import { SettingsSection } from './SettingsSection';

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

/**
 * Huella de lo que hay en pantalla, para saber si hay cambios sin guardar.
 * @param values Ajustes.
 * @param urls   Texto de direcciones ignoradas.
 * @param words  Texto de palabras ignoradas.
 */
function snapshot( values: SettingsValues, urls: string, words: string ) {
	return JSON.stringify( [ values, urls, words ] );
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
	const [ values, setValues ] = useState< SettingsValues | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ message, setMessage ] = useState( '' );
	const [ failure, setFailure ] = useState( '' );
	const [ urlsText, setUrlsText ] = useState( '' );
	const [ wordsText, setWordsText ] = useState( '' );
	const [ baseline, setBaseline ] = useState( '' );

	useEffect( () => {
		if ( data ) {
			setValues( data.settings );
			setUrlsText( data.settings.ignored_urls.join( '\n' ) );
			setWordsText( data.settings.ignored_words.join( '\n' ) );
			setBaseline(
				snapshot(
					data.settings,
					data.settings.ignored_urls.join( '\n' ),
					data.settings.ignored_words.join( '\n' )
				)
			);
		}
	}, [ data ] );

	const dirty = useMemo(
		() =>
			values !== null &&
			baseline !== '' &&
			snapshot( values, urlsText, wordsText ) !== baseline,
		[ values, urlsText, wordsText, baseline ]
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

	if ( ! values || ! data ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<p>{ loading ? __( 'Loading…', 'magic-linking' ) : '' }</p>
		);
	}

	const set = < K extends keyof SettingsValues >(
		key: K,
		value: SettingsValues[ K ]
	) => {
		setValues( { ...values, [ key ]: value } );
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
			const lines = ( text: string ) =>
				text
					.split( /\r?\n/ )
					.map( ( line ) => line.trim() )
					.filter( Boolean );
			const saved = await api.saveSettings( {
				...values,
				ignored_urls: lines( urlsText ),
				ignored_words: lines( wordsText ),
			} );
			setValues( saved.settings );
			setUrlsText( saved.settings.ignored_urls.join( '\n' ) );
			setWordsText( saved.settings.ignored_words.join( '\n' ) );
			setBaseline(
				snapshot(
					saved.settings,
					saved.settings.ignored_urls.join( '\n' ),
					saved.settings.ignored_words.join( '\n' )
				)
			);
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

	const number = (
		key:
			| 'words_per_link'
			| 'same_anchor_max'
			| 'outbound_shown'
			| 'inbound_per_page'
			| 'low_inbound_threshold',
		fallback: number,
		min: number,
		max: number,
		label: string,
		help: string
	) => (
		<TextControl
			className="magiclinking-number"
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			type="number"
			min={ min }
			max={ max }
			label={ label }
			help={ help }
			value={ String( values[ key ] ) }
			onChange={ ( value ) =>
				set( key, whole( value, fallback, min, max ) )
			}
		/>
	);

	const sections = [
		{
			id: 'magiclinking-s-content',
			label: __( 'Content analyzed', 'magic-linking' ),
		},
		{
			id: 'magiclinking-s-suggestions',
			label: __( 'Suggestions', 'magic-linking' ),
		},
		{
			id: 'magiclinking-s-insertion',
			label: __( 'Link insertion', 'magic-linking' ),
		},
		{
			id: 'magiclinking-s-exclusions',
			label: __( 'Exclusions', 'magic-linking' ),
		},
		{
			id: 'magiclinking-s-data',
			label: __( 'History and data', 'magic-linking' ),
		},
		{
			id: 'magiclinking-maintenance',
			label: __( 'Maintenance', 'magic-linking' ),
		},
	];

	const noTypes = values.post_types.length === 0;
	let statusText = '';
	if ( noTypes ) {
		statusText = __( 'Choose at least one content type.', 'magic-linking' );
	} else if ( dirty ) {
		statusText = __( 'Unsaved changes', 'magic-linking' );
	}

	return (
		<div className="magiclinking-settings-page">
			<nav
				className="magiclinking-settings-nav"
				aria-label={ __( 'Settings sections', 'magic-linking' ) }
			>
				<ul>
					{ sections.map( ( section ) => (
						<li key={ section.id }>
							<a href={ `#${ section.id }` }>{ section.label }</a>
						</li>
					) ) }
				</ul>
			</nav>

			<form
				id="magiclinking-settings-form"
				className="magiclinking-settings"
				onSubmit={ save }
			>
				<SettingsSection
					id="magiclinking-s-content"
					title={ __( 'Content analyzed', 'magic-linking' ) }
					description={ __(
						'Magic Linking reads these content types to find links between them.',
						'magic-linking'
					) }
				>
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

				<SettingsSection
					id="magiclinking-s-suggestions"
					title={ __( 'Suggestions', 'magic-linking' ) }
					description={ __(
						'How many suggestions you see, and how entries are judged as linked too little or too much.',
						'magic-linking'
					) }
				>
					<fieldset>
						<legend>
							{ __( 'Suggestions shown', 'magic-linking' ) }
						</legend>
						{ number(
							'outbound_shown',
							15,
							1,
							50,
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
							10,
							5,
							50,
							__(
								'Inbound suggestions per page in the editor',
								'magic-linking'
							),
							__(
								'Entries that could link to the one you are editing, shown this many at a time.',
								'magic-linking'
							)
						) }
					</fieldset>
					<fieldset>
						<legend>
							{ __(
								'To avoid overloading your texts',
								'magic-linking'
							) }
						</legend>
						<p className="description">
							{ __(
								'These are quality settings, not limits: you can always insert any link yourself.',
								'magic-linking'
							) }
						</p>
						{ number(
							'words_per_link',
							100,
							20,
							1000,
							__( 'Words per internal link', 'magic-linking' ),
							__(
								'An entry with more internal links than one for every this many words is shown as over-linked (always at least one link is allowed).',
								'magic-linking'
							)
						) }
						{ number(
							'same_anchor_max',
							5,
							1,
							50,
							__(
								'Entries that can use the same anchor text for the same target',
								'magic-linking'
							),
							__(
								'Beyond this many entries, the same anchor text towards the same target is suggested less.',
								'magic-linking'
							)
						) }
					</fieldset>
					<fieldset>
						<legend>
							{ __( 'Under-linked entries', 'magic-linking' ) }
						</legend>
						{ number(
							'low_inbound_threshold',
							2,
							1,
							20,
							__(
								'Under-linked below this many inbound links',
								'magic-linking'
							),
							__(
								'An entry with at least one inbound link but fewer than this number is shown as under-linked.',
								'magic-linking'
							)
						) }
					</fieldset>
				</SettingsSection>

				<SettingsSection
					id="magiclinking-s-insertion"
					title={ __( 'Link insertion', 'magic-linking' ) }
					description={ __(
						'Where in your content links can go, and what the links Magic Linking inserts look like.',
						'magic-linking'
					) }
				>
					<fieldset>
						<legend>
							{ __(
								'Where links can be inserted',
								'magic-linking'
							) }
						</legend>
						<p className="description">
							{ __(
								'Paragraphs and lists are always used. These are off by default; turn them on if you want suggestions and links there too.',
								'magic-linking'
							) }
						</p>
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
					</fieldset>
					<fieldset>
						<legend>
							{ __( 'Links you insert', 'magic-linking' ) }
						</legend>
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
							onChange={ ( value ) => set( 'extra_rel', value ) }
						/>
					</fieldset>
				</SettingsSection>

				<SettingsSection
					id="magiclinking-s-exclusions"
					title={ __( 'Exclusions', 'magic-linking' ) }
					description={ __(
						'Entries and words that Magic Linking should leave alone.',
						'magic-linking'
					) }
				>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Ignored addresses', 'magic-linking' ) }
						help={ __(
							'One address per line. These entries are never suggested as a target, and never suggested to link to others.',
							'magic-linking'
						) }
						rows={ 4 }
						value={ urlsText }
						onChange={ ( value ) => {
							setUrlsText( value );
							setMessage( '' );
						} }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Ignored words', 'magic-linking' ) }
						help={ __(
							'One word or phrase per line. Suggestions whose anchor text contains one of them are not shown.',
							'magic-linking'
						) }
						rows={ 4 }
						value={ wordsText }
						onChange={ ( value ) => {
							setWordsText( value );
							setMessage( '' );
						} }
					/>
				</SettingsSection>

				<SettingsSection
					id="magiclinking-s-data"
					title={ __( 'History and data', 'magic-linking' ) }
					description={ __(
						'How long you can undo changes, and what happens to the data when the plugin is deleted.',
						'magic-linking'
					) }
				>
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
								label: __( '365 days', 'magic-linking' ),
							},
							{
								value: '0',
								label: __( 'No expiry date', 'magic-linking' ),
							},
						] }
						onChange={ ( value ) =>
							set( 'history_retention_days', Number( value ) )
						}
					/>
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
			</form>

			<Maintenance status={ status } onStarted={ onReindex }>
				<div className="magiclinking-subsection">
					<h3>{ __( 'Setup assistant', 'magic-linking' ) }</h3>
					<p className="description">
						{ __(
							'Go through the first steps again: content types, AI and the first analysis.',
							'magic-linking'
						) }
					</p>
					<Button variant="secondary" onClick={ onOpenWizard }>
						{ __( 'Open the setup assistant', 'magic-linking' ) }
					</Button>
				</div>
			</Maintenance>

			<div className="magiclinking-savebar">
				{ failure && (
					<Notice status="error" isDismissible={ false }>
						{ failure }
					</Notice>
				) }
				{ message && (
					<Notice status="success" isDismissible={ false }>
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
					<p className="magiclinking-savebar__status" role="status">
						{ statusText }
					</p>
				</div>
			</div>
		</div>
	);
}
