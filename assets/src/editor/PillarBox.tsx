import { CheckboxControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import type { PillarState } from '../types';

/**
 * Casilla «Contenido pilar» (docs/07 §3.4). La casilla es siempre y solo la marca propia de Magic Linking, editable;
 * la de Yoast SEO o Rank Math se enseña aparte, en una nota, y se cambia en su plugin. Que la entrada cuente como
 * pilar es propia o externa. Guardar es inmediato: no espera a guardar la entrada.
 *
 * @param root0
 * @param root0.postId Entrada que se edita.
 */
export function PillarBox( { postId }: { postId: number } ) {
	const [ state, setState ] = useState< PillarState | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		const controller = new AbortController();
		api.pillar( postId, controller.signal )
			.then( ( loaded ) => {
				if ( ! controller.signal.aborted ) {
					setState( loaded );
				}
			} )
			.catch( ( e: unknown ) => {
				if ( ! controller.signal.aborted ) {
					setError(
						errorMessage(
							e,
							__(
								'Could not load the pillar content mark.',
								'magic-linking'
							)
						)
					);
				}
			} );
		return () => controller.abort();
	}, [ postId ] );

	const change = ( checked: boolean ) => {
		const previous = state;
		setSaving( true );
		setError( '' );
		// La casilla responde al momento; si el servidor no lo guarda, vuelve a como estaba.
		setState( ( current ) =>
			current
				? {
						...current,
						own: checked,
						pillar: checked || current.external !== null,
				  }
				: current
		);
		api.setPillar( postId, checked )
			.then( ( saved ) => {
				setState( saved );
				speak(
					saved.own
						? __( 'Marked as pillar content.', 'magic-linking' )
						: __( 'Pillar content mark removed.', 'magic-linking' ),
					'polite'
				);
			} )
			.catch( ( e: unknown ) => {
				setState( previous );
				setError(
					errorMessage(
						e,
						__(
							'The pillar content mark could not be saved.',
							'magic-linking'
						)
					)
				);
			} )
			.finally( () => setSaving( false ) );
	};

	let external = '';
	if ( state?.external === 'yoast' ) {
		external = __(
			'Also marked as cornerstone content in Yoast SEO. Change that mark there.',
			'magic-linking'
		);
	} else if ( state?.external === 'rank_math' ) {
		external = __(
			'Also marked as pillar content in Rank Math. Change that mark there.',
			'magic-linking'
		);
	}
	let effective = '';
	if ( state?.pillar ) {
		effective = state.own
			? __( 'Counts as pillar content.', 'magic-linking' )
			: __(
					'Counts as pillar content because of that mark, whatever this box says.',
					'magic-linking'
			  );
	}

	return (
		<div className="magiclinking-panel__pillar">
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Pillar content', 'magic-linking' ) }
				help={ __(
					'Suggestions that link to this entry rank a little higher. Nothing else changes.',
					'magic-linking'
				) }
				checked={ state?.own ?? false }
				disabled={ state === null || saving }
				onChange={ change }
			/>
			{ external && (
				<p className="magiclinking-panel__pillar-note">
					{ external } { effective }
				</p>
			) }
			{ error && (
				<p className="magiclinking-panel__pillar-error" role="alert">
					{ error }
				</p>
			) }
		</div>
	);
}
