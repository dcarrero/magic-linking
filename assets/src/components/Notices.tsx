import { Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import type { AdminNotice } from '../types';

interface Props {
	notice?: AdminNotice | null;
}

/**
 * Devuelve el foco al título de la pantalla: al cerrar el aviso su botón desaparece y el foco se perdería.
 */
function focusHeading() {
	const heading = document.querySelector< HTMLElement >(
		'.magiclinking-wrap h1'
	);
	if ( heading ) {
		heading.setAttribute( 'tabindex', '-1' );
		heading.focus();
	}
}

/**
 * Aviso del plugin (docs/07 §5): como mucho uno, solo dentro de las pantallas del plugin, con ✕ y un botón para no
 * volver a verlo. Cerrar guarda el cierre en el servidor para este usuario; las dos formas de cerrar son para siempre.
 * @param root0
 * @param root0.notice
 */
export function Notices( { notice }: Props ) {
	const [ closed, setClosed ] = useState( false );

	if ( ! notice || closed ) {
		return null;
	}

	const close = () => {
		setClosed( true );
		speak( __( 'Notice closed.', 'magic-linking' ), 'polite' );
		focusHeading();
		api.dismissNotice( notice.id ).catch( () => {
			// Si no se guarda, el aviso vuelve la próxima vez: se puede cerrar de nuevo.
		} );
	};

	return (
		<Notice
			className="magiclinking-notice"
			status={ notice.type }
			onRemove={ close }
			actions={ [
				...( notice.action
					? [
							{
								label: notice.action.label,
								url: notice.action.url,
								// Quien va a escribir la reseña ya no necesita que se la pidan otra vez.
								onClick: () => {
									api.dismissNotice( notice.id ).catch(
										() => {}
									);
								},
							},
					  ]
					: [] ),
				{
					label: notice.dismiss_label,
					onClick: close,
					variant: 'link' as const,
				},
			] }
		>
			{ notice.text }
		</Notice>
	);
}
