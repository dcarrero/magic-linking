import {
	Button,
	CheckboxControl,
	Notice,
	TextControl,
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
	/** Último estado conocido del análisis. */
	status: StatusResponse | null;
}

/**
 * Ajustes mínimos: tipos de contenido, umbrales del informe y borrado de datos al desinstalar.
 * @param root0
 * @param root0.onReindex
 * @param root0.status
 */
export function SettingsTab( { onReindex, status }: Props ) {
	const { data, loading, error } = useLoad(
		() => api.settings(),
		[],
		__( 'Could not load the settings.', 'magic-linking' )
	);
	const [ values, setValues ] = useState< SettingsValues | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ message, setMessage ] = useState( '' );
	const [ failure, setFailure ] = useState( '' );

	useEffect( () => {
		if ( data ) {
			setValues( data.settings );
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
			const saved = await api.saveSettings( values );
			setValues( saved.settings );
			const text = saved.job
				? __(
						'Settings saved. The content types changed, so your site is being analyzed again.',
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
						set( 'low_inbound_threshold', Number( value ) || 1 )
					}
				/>

				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					min={ 20 }
					max={ 1000 }
					label={ __( 'Words per internal link', 'magic-linking' ) }
					help={ __(
						'An entry with more internal links than one for every this many words is shown as over-linked (always at least one link is allowed).',
						'magic-linking'
					) }
					value={ String( values.words_per_link ) }
					onChange={ ( value ) =>
						set( 'words_per_link', Number( value ) || 100 )
					}
				/>

				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'Delete all Magic Linking data when the plugin is deleted',
						'magic-linking'
					) }
					help={ __(
						'Removes its tables and settings. Nothing is ever changed in your content, so the links already in your entries stay as they are.',
						'magic-linking'
					) }
					checked={ values.delete_data_on_uninstall }
					onChange={ ( checked ) =>
						set( 'delete_data_on_uninstall', checked )
					}
				/>

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
			<Maintenance status={ status } onStarted={ onReindex } />
		</>
	);
}
