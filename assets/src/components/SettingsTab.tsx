import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import { useLoad } from '../hooks';
import type { SettingsValues, StatusResponse } from '../types';
import { Maintenance } from './Maintenance';

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

	useEffect( () => {
		if ( data ) {
			setValues( data.settings );
			setUrlsText( data.settings.ignored_urls.join( '\n' ) );
			setWordsText( data.settings.ignored_words.join( '\n' ) );
		}
	}, [ data ] );

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

	return (
		<>
			<form className="magiclinking-settings" onSubmit={ save }>
				<fieldset>
					<legend>
						{ __( 'Content types to analyze', 'magic-linking' ) }
					</legend>
					{ data.post_types.map( ( type ) => (
						<CheckboxControl
							key={ type.name }
							__nextHasNoMarginBottom
							label={ type.label }
							checked={ values.post_types.includes( type.name ) }
							onChange={ ( checked ) =>
								toggleType( type.name, checked )
							}
						/>
					) ) }
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
						label={ __( 'Additional rel values', 'magic-linking' ) }
						help={ __(
							'Optional, separated by spaces (for example: nofollow). Added to the links Magic Linking inserts.',
							'magic-linking'
						) }
						value={ values.extra_rel }
						onChange={ ( value ) => set( 'extra_rel', value ) }
					/>
				</fieldset>

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

				<fieldset>
					<legend>
						{ __( 'Where links can be inserted', 'magic-linking' ) }
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
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min={ 20 }
						max={ 1000 }
						label={ __(
							'Words per internal link',
							'magic-linking'
						) }
						help={ __(
							'An entry with more internal links than one for every this many words is shown as over-linked (always at least one link is allowed).',
							'magic-linking'
						) }
						value={ String( values.words_per_link ) }
						onChange={ ( value ) =>
							set(
								'words_per_link',
								whole( value, 100, 20, 1000 )
							)
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min={ 1 }
						max={ 50 }
						label={ __(
							'Entries that can use the same anchor text for the same target',
							'magic-linking'
						) }
						help={ __(
							'Beyond this many entries, the same anchor text towards the same target is suggested less.',
							'magic-linking'
						) }
						value={ String( values.same_anchor_max ) }
						onChange={ ( value ) =>
							set( 'same_anchor_max', whole( value, 5, 1, 50 ) )
						}
					/>
				</fieldset>

				<fieldset>
					<legend>
						{ __( 'Suggestions shown', 'magic-linking' ) }
					</legend>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min={ 1 }
						max={ 50 }
						label={ __(
							'Outbound suggestions in the editor',
							'magic-linking'
						) }
						help={ __(
							'The most useful links to add from the entry you are editing.',
							'magic-linking'
						) }
						value={ String( values.outbound_shown ) }
						onChange={ ( value ) =>
							set( 'outbound_shown', whole( value, 15, 1, 50 ) )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min={ 5 }
						max={ 50 }
						label={ __(
							'Inbound suggestions per page in the editor',
							'magic-linking'
						) }
						help={ __(
							'Entries that could link to the one you are editing, shown this many at a time.',
							'magic-linking'
						) }
						value={ String( values.inbound_per_page ) }
						onChange={ ( value ) =>
							set( 'inbound_per_page', whole( value, 10, 5, 50 ) )
						}
					/>
				</fieldset>

				<fieldset>
					<legend>{ __( 'Ignored', 'magic-linking' ) }</legend>
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
				</fieldset>

				<details className="magiclinking-advanced">
					<summary>{ __( 'Advanced', 'magic-linking' ) }</summary>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min={ 1 }
						max={ 20 }
						label={ __(
							'Under-linked below this many inbound links',
							'magic-linking'
						) }
						help={ __(
							'An entry with at least one inbound link but fewer than this number is shown as under-linked.',
							'magic-linking'
						) }
						value={ String( values.low_inbound_threshold ) }
						onChange={ ( value ) =>
							set(
								'low_inbound_threshold',
								whole( value, 2, 1, 20 )
							)
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
				</details>

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

				<Button
					type="submit"
					variant="primary"
					disabled={ saving || values.post_types.length === 0 }
					accessibleWhenDisabled
				>
					{ __( 'Save settings', 'magic-linking' ) }
				</Button>
				{ values.post_types.length === 0 && (
					<p className="description">
						{ __(
							'Choose at least one content type.',
							'magic-linking'
						) }
					</p>
				) }
			</form>
			<div className="magiclinking-maintenance">
				<h2>{ __( 'Setup assistant', 'magic-linking' ) }</h2>
				<p>
					{ __(
						'Go through the first steps again: content types, AI and the first analysis.',
						'magic-linking'
					) }
				</p>
				<Button variant="secondary" onClick={ onOpenWizard }>
					{ __( 'Open the setup assistant', 'magic-linking' ) }
				</Button>
			</div>
			<Maintenance status={ status } onStarted={ onReindex } />
		</>
	);
}
