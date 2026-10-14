import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import type { HistoryJob } from '../types';
import { entryTitle } from './historyFormat';
import { needsAttention, statusLabel, summaryText } from './historyText';
import type { Outcome } from './useHistoryJob';

interface ProgressProps {
	job: HistoryJob;
	/** Etiqueta accesible de la barra según el modo. */
	labels: { undo: string; redo: string };
	busy: boolean;
	onControl: ( action: 'resume' | 'cancel' ) => void;
}

/**
 * Barra de progreso de un proceso en segundo plano, con el aviso de atasco y los botones de reanudar y cancelar.
 * @param root0
 * @param root0.job
 * @param root0.labels
 * @param root0.busy
 * @param root0.onControl
 */
export function JobProgress( { job, labels, busy, onControl }: ProgressProps ) {
	return (
		<div className="magiclinking-batch__progress">
			<progress
				max={ Math.max( 1, job.total ) }
				value={ job.done }
				aria-label={ job.mode === 'redo' ? labels.redo : labels.undo }
			/>
			<p>
				{ sprintf(
					/* translators: 1: changes done, 2: total changes. */
					__(
						'Working in the background: %1$s of %2$s.',
						'magic-linking'
					),
					job.done.toLocaleString(),
					job.total.toLocaleString()
				) }
			</p>
			{ job.stalled && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This is taking longer than expected. Background tasks need WP-Cron or Action Scheduler to be running.',
						'magic-linking'
					) }
				</Notice>
			) }
			{ job.stalled && job.can_control && (
				<div className="magiclinking-actions">
					<Button
						variant="secondary"
						onClick={ () => onControl( 'resume' ) }
						disabled={ busy }
						accessibleWhenDisabled
					>
						{ __( 'Resume', 'magic-linking' ) }
					</Button>
					<Button
						variant="secondary"
						isDestructive
						onClick={ () => onControl( 'cancel' ) }
						disabled={ busy }
						accessibleWhenDisabled
					>
						{ __( 'Cancel process', 'magic-linking' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

interface OutcomeProps {
	outcome: Outcome;
	/** Nombra la entrada de cada línea (la vista por lote mezcla varias). */
	showEntry?: boolean;
}

/**
 * Resumen del resultado de una operación y la lista de lo que no se pudo tratar o requiere atención.
 * @param root0
 * @param root0.outcome
 * @param root0.showEntry
 */
export function OutcomeSummary( { outcome, showEntry = false }: OutcomeProps ) {
	return (
		<>
			<p>
				<strong>{ summaryText( outcome.counts, outcome.redo ) }</strong>
			</p>
			{ outcome.lines.length > 0 && (
				<ul>
					{ outcome.lines.map( ( line ) => (
						<li key={ line.change_id }>
							<span
								className={
									needsAttention( line.status )
										? 'magiclinking-warn'
										: undefined
								}
							>
								{ statusLabel( line.status ) }
							</span>
							{ showEntry && (
								<>
									{ ' · ' }
									{ entryTitle( line.post_title ) }
								</>
							) }
							{ line.message ? `: ${ line.message }` : '' }
							{ line.edit_url && (
								<>
									{ ' ' }
									<a href={ line.edit_url }>
										{ __( 'Edit entry', 'magic-linking' ) }
									</a>
								</>
							) }
						</li>
					) ) }
				</ul>
			) }
		</>
	);
}
