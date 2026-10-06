import { Button, Modal, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import type { StatusResponse } from '../types';

interface Props {
	status: StatusResponse | null;
	/** Se llama cuando se ha lanzado el análisis, para refrescar el estado. */
	onStarted: () => void;
}

/**
 * Fecha del servidor (UTC, a veces sin zona) a un Date.
 * @param value Fecha RFC 3339.
 */
export function parseServerDate( value: string ): Date {
	return new Date(
		/(Z|[+-]\d\d:?\d\d)$/.test( value ) ? value : value + 'Z'
	);
}

/**
 * Duración legible entre dos fechas.
 * @param from Inicio.
 * @param to   Fin.
 */
export function formatDuration( from: string, to: string ): string {
	const seconds = Math.max(
		0,
		Math.round(
			( parseServerDate( to ).getTime() -
				parseServerDate( from ).getTime() ) /
				1000
		)
	);
	if ( seconds < 60 ) {
		return sprintf(
			/* translators: %s: number of seconds. */
			__( '%s s', 'magic-linking' ),
			String( seconds )
		);
	}
	return sprintf(
		/* translators: 1: minutes, 2: seconds. */
		__( '%1$s min %2$s s', 'magic-linking' ),
		String( Math.floor( seconds / 60 ) ),
		String( seconds % 60 )
	);
}

/**
 * Sección «Mantenimiento» de Ajustes: datos del último análisis y botón para repetirlo.
 * @param root0
 * @param root0.status
 * @param root0.onStarted
 */
export function Maintenance( { status, onStarted }: Props ) {
	const [ confirming, setConfirming ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ failure, setFailure ] = useState( '' );

	const last = status?.last_done ?? null;
	const active = status?.job ?? null;

	const start = async () => {
		setBusy( true );
		setFailure( '' );
		try {
			await api.startIndex( true );
			setConfirming( false );
			speak( __( 'Analysis started.', 'magic-linking' ), 'polite' );
			onStarted();
		} catch ( e ) {
			setConfirming( false );
			setFailure(
				errorMessage(
					e,
					__( 'That did not work. Try again.', 'magic-linking' )
				)
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<section
			className="magiclinking-maintenance"
			aria-labelledby="magiclinking-maintenance-title"
		>
			<h2 id="magiclinking-maintenance-title">
				{ __( 'Maintenance', 'magic-linking' ) }
			</h2>
			{ last ? (
				<dl className="magiclinking-maintenance-facts">
					<div>
						<dt>{ __( 'Last full analysis', 'magic-linking' ) }</dt>
						<dd>
							<time dateTime={ last.updated_at }>
								{ parseServerDate(
									last.updated_at
								).toLocaleString() }
							</time>
						</dd>
					</div>
					<div>
						<dt>{ __( 'Duration', 'magic-linking' ) }</dt>
						<dd>
							{ formatDuration(
								last.created_at,
								last.updated_at
							) }
						</dd>
					</div>
					<div>
						<dt>{ __( 'Entries analyzed', 'magic-linking' ) }</dt>
						<dd>{ last.done.toLocaleString() }</dd>
					</div>
				</dl>
			) : (
				<p>
					{ __(
						'There is no completed analysis yet.',
						'magic-linking'
					) }
				</p>
			) }
			<p className="description">
				{ __(
					'Analyze every entry again from scratch, even those that have not changed. To analyze only what is new or modified, use “Analyze changes” in the Report. It runs in the background and does not change any content.',
					'magic-linking'
				) }
			</p>
			{ failure && (
				<Notice status="error" isDismissible={ false }>
					{ failure }
				</Notice>
			) }
			<Button
				variant="secondary"
				disabled={ active !== null || busy }
				accessibleWhenDisabled
				onClick={ () => setConfirming( true ) }
			>
				{ __(
					'Analyze everything again from scratch',
					'magic-linking'
				) }
			</Button>
			{ active !== null && (
				<p className="description">
					{ __(
						'An analysis is already running; its progress is shown above.',
						'magic-linking'
					) }
				</p>
			) }
			{ confirming && (
				<Modal
					title={ __(
						'Analyze everything again from scratch?',
						'magic-linking'
					) }
					onRequestClose={ () => setConfirming( false ) }
					size="medium"
				>
					<p>
						{ __(
							'Magic Linking will read every entry again. It runs in the background, so you can keep working, and it does not modify any of your content.',
							'magic-linking'
						) }
					</p>
					<div className="magiclinking-actions">
						<Button
							variant="tertiary"
							onClick={ () => setConfirming( false ) }
						>
							{ __( 'Cancel', 'magic-linking' ) }
						</Button>
						<Button
							variant="primary"
							disabled={ busy }
							accessibleWhenDisabled
							onClick={ start }
						>
							{ __( 'Start analysis', 'magic-linking' ) }
						</Button>
					</div>
				</Modal>
			) }
		</section>
	);
}
