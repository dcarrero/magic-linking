import { CheckboxControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import type { PillarState } from '../types';

/**
 * Casilla «Contenido pilar» (docs/07 §3.4). Si Yoast SEO o Rank Math ya la marcan, aparece marcada, con la nota de
 * dónde, y se cambia allí. Guardar es inmediato: no espera a guardar la entrada.
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
			current ? { ...current, pillar: checked, own: checked } : current
		);
		api.setPillar( postId, checked )
			.then( ( saved ) => {
				setState( saved );
				speak(
					saved.pillar
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

	let help: string = __(
		'Suggestions that link to this entry rank a little higher. Nothing else changes.',
		'magic-linking'
	);
	if ( state?.external === 'yoast' ) {
		help = __(
			'Marked as cornerstone content in Yoast SEO. Change it there.',
			'magic-linking'
		);
	} else if ( state?.external === 'rank_math' ) {
		help = __(
			'Marked as pillar content in Rank Math. Change it there.',
			'magic-linking'
		);
	}

	return (
		<div className="magiclinking-panel__pillar">
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Pillar content', 'magic-linking' ) }
				help={ help }
				checked={ state?.pillar ?? false }
				disabled={ state === null || saving || !! state.external }
				onChange={ change }
			/>
			{ error && (
				<p className="magiclinking-panel__pillar-error" role="alert">
					{ error }
				</p>
			) }
		</div>
	);
}
