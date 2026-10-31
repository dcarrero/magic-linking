import { Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import type { MouseEvent } from 'react';
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

	const id = notice?.id;
	// Se avisa al servidor de que el aviso se ha pintado de verdad: de ahí cuenta «la primera vez» (la reseña dura una semana).
	useEffect( () => {
		if ( id ) {
			api.seenNotice( id ).catch( () => {
				// Si no se apunta, se apuntará la próxima vez que se vea.
			} );
		}
	}, [ id ] );

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

	// El enlace es un enlace normal. Con cualquier forma de abrirlo (clic, clic central, Cmd/Ctrl) se guarda el cierre; la
	// petición lleva `keepalive`, así que sigue su curso aunque la página navegue y no se espera a ella. El botón derecho
	// no cierra nada.
	const follow = ( event: MouseEvent< HTMLAnchorElement > ) => {
		if ( event.type === 'auxclick' && event.button !== 1 ) {
			return;
		}
		persist( true );
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
