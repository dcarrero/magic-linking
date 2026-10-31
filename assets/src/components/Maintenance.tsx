import { Button, Modal, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import type { StatusResponse } from '../types';
import { formatDate, formatDuration } from './historyFormat';
import { SettingsSection, settingsSections } from './SettingsSection';

interface Props {
	status: StatusResponse | null;
	/** Estado y acción del análisis completo (viven en `SettingsTab`, ver `useMaintenance`). */
	control: MaintenanceControl;
	/** Reabre el asistente de arranque. */
	onOpenWizard: () => void;
}

/**
 * Estado y acción de «Analizar todo desde cero». Se usa en `SettingsTab` para que un error o un análisis en
 * marcha no se pierdan al cambiar de pestaña (el contenido de la pestaña General se desmonta).
 * @param onStarted Se llama cuando se ha lanzado el análisis, para refrescar el estado.
 */
export function useMaintenance( onStarted: () => void ) {
	const [ confirming, setConfirming ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ failure, setFailure ] = useState( '' );

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

	return { confirming, setConfirming, busy, failure, start };
}

export type MaintenanceControl = ReturnType< typeof useMaintenance >;

/**
 * Sección «Mantenimiento» de Ajustes: datos del último análisis y botón para repetirlo.
 * @param root0
 * @param root0.status
 * @param root0.control
 * @param root0.onOpenWizard
 */
export function Maintenance( { status, control, onOpenWizard }: Props ) {
	const { confirming, setConfirming, busy, failure, start } = control;
	const last = status?.last_done ?? null;
	const active = status?.job ?? null;

	return (
		<SettingsSection section={ settingsSections().maintenance }>
			{ last ? (
				<dl className="magiclinking-maintenance-facts">
					<div>
						<dt>{ __( 'Last full analysis', 'magic-linking' ) }</dt>
						<dd>
							<time dateTime={ last.updated_at }>
								{ formatDate( last.updated_at ) }
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
					'Analyze every entry again from scratch, even those that have not changed. To analyze only what is new or modified, use “Analyze changes” in the Report. It runs in the background. Analyzing only reads your content; it never changes it.',
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
							'Magic Linking will read every entry again. It runs in the background, so you can keep working. Analyzing only reads your content; it never changes it.',
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
		</SettingsSection>
	);
}
