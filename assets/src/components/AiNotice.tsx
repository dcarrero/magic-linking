import { Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import type { AiStatus } from '../types';

/**
 * Aviso «Sin conector de IA» (docs/02 §5): solo dentro de las pantallas del plugin, cerrable para siempre y sin ninguna
 * intención comercial. Solo sale si WordPress trae el cliente de IA pero no hay ningún conector; en WordPress anterior
 * a 7.0 no hay nada que conectar y no se avisa. Averiguarlo no llama a ningún modelo.
 *
 * Cuenta como el aviso visible: mientras salga este, el sistema de avisos (`Notices`) no enseña otro (como mucho uno).
 * @param root0
 * @param root0.onSettled
 */
export function AiNotice( {
	onSettled,
}: {
	onSettled?: ( visible: boolean ) => void;
} ) {
	const [ status, setStatus ] = useState< AiStatus | null >( null );
	const [ closed, setClosed ] = useState( false );

	useEffect( () => {
		const controller = new AbortController();
		api.aiStatus( false, controller.signal )
			.then( ( next ) => {
				if ( ! controller.signal.aborted ) {
					setStatus( next );
					onSettled?.(
						next.state === 'none' && ! next.notice_dismissed
					);
				}
			} )
			.catch( () => {
				// Sin respuesta no se avisa de nada.
				if ( ! controller.signal.aborted ) {
					onSettled?.( false );
				}
			} );
		return () => controller.abort();
		// eslint-disable-next-line react-hooks/exhaustive-deps -- Se pregunta una sola vez al abrir la pantalla.
	}, [] );

	if (
		! status ||
		status.state !== 'none' ||
		status.notice_dismissed ||
		closed
	) {
		return null;
	}

	const close = () => {
		setClosed( true );
		speak( __( 'Notice closed.', 'magic-linking' ), 'polite' );
		api.setAiNoticeDismissed( true ).catch( () => {
			// Si no se guarda, el aviso vuelve la próxima vez: se puede cerrar de nuevo.
			setClosed( false );
		} );
	};

	return (
		<Notice
			className="magiclinking-ai-notice"
			status="info"
			onRemove={ close }
			actions={ [
				{
					label: __( 'Go to Connectors', 'magic-linking' ),
					url: status.connectors_url,
				},
				{
					label: __( 'Close', 'magic-linking' ),
					onClick: close,
					variant: 'link',
				},
			] }
		>
			{ __(
				'This site has no AI connected. You can connect yours in Settings → Connectors (OpenAI, Google, Ollama local…). Meanwhile, suggestions use the lexical engine.',
				'magic-linking'
			) }
		</Notice>
	);
}
