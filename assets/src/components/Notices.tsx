import { Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import type { MouseEvent } from 'react';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import { navigate } from '../navigate';
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
 * Aviso del plugin (docs/07 §5): como mucho uno, el que decide el servidor, solo dentro de las pantallas del plugin,
 * con ✕ y un botón para no volver a verlo. Todas las formas de cerrarlo (✕, el botón y abrir el enlace de la acción,
 * con clic normal, central o con Cmd/Ctrl) guardan el cierre en el servidor para este usuario con la misma función.
 * Si no se puede guardar, el aviso vuelve con un mensaje de error.
 * @param root0
 * @param root0.notice
 */
export function Notices( { notice }: Props ) {
	const [ closed, setClosed ] = useState( false );
	const [ failed, setFailed ] = useState( false );

	if ( ! notice || closed ) {
		return null;
	}

	/**
	 * Guarda el cierre en el servidor y oculta el aviso. Es la única vía de cierre: ✕, botón y enlace.
	 *
	 * @param fromLink El cierre viene del enlace de la acción: el aviso se oculta después del clic (quitar el enlace en
	 *                 su propio clic anularía la navegación) y no se mueve el foco.
	 */
	const persist = ( fromLink: boolean ): Promise< void > => {
		setFailed( false );
		if ( fromLink ) {
			window.setTimeout( () => setClosed( true ), 0 );
		} else {
			setClosed( true );
			speak( __( 'Notice closed.', 'magic-linking' ), 'polite' );
			focusHeading();
		}
		return api
			.dismissNotice( notice.id )
			.then( () => undefined )
			.catch( () => {
				setClosed( false );
				setFailed( true );
				speak(
					__(
						'The notice could not be closed. Try again.',
						'magic-linking'
					),
					'assertive'
				);
			} );
	};

	// Clic normal en la misma pestaña: la página se va, así que se espera a que el servidor guarde el cierre antes de
	// salir (una petición en vuelo se pierde al navegar). Con Cmd/Ctrl, Mayús o botón central la página sigue y no hace
	// falta esperar.
	const follow = ( event: MouseEvent< HTMLAnchorElement > ) => {
		const plain =
			event.type === 'click' &&
			event.button === 0 &&
			! event.metaKey &&
			! event.ctrlKey &&
			! event.shiftKey &&
			! event.altKey;
		if ( ! plain ) {
			persist( true );
			return;
		}
		event.preventDefault();
		const href = event.currentTarget.href;
		persist( true ).then( () => navigate( href ) );
	};

	return (
		<Notice
			className={
				'magiclinking-notice' +
				( notice.id === 'ai-connector'
					? ' magiclinking-ai-notice'
					: '' )
			}
			status={ notice.type }
			onRemove={ () => persist( false ) }
			actions={ [
				{
					label: notice.dismiss_label,
					onClick: () => persist( false ),
					variant: 'link' as const,
				},
			] }
		>
			{ notice.text }
			{ notice.action && (
				<>
					{ ' ' }
					{ /* Un enlace normal: el Notice de WordPress ignora el clic de las acciones con url, así que va aquí. */ }
					<a
						href={ notice.action.url }
						onClick={ follow }
						onAuxClick={ follow }
					>
						{ notice.action.label }
					</a>
				</>
			) }
			{ failed && (
				<span role="alert" className="magiclinking-notice__error">
					{ ' ' }
					{ __(
						'The notice could not be closed. Try again.',
						'magic-linking'
					) }
				</span>
			) }
		</Notice>
	);
}
