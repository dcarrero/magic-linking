import { useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { api, errorMessage, errorStatus } from '../api';
import type {
	ChangeResult,
	HistoryGroup,
	HistoryIssue,
	HistoryJob,
} from '../types';
import { countStatuses, summaryText } from './historyText';

export interface Outcome {
	redo: boolean;
	counts: ReturnType< typeof countStatuses >;
	lines: ReadonlyArray< ChangeResult | HistoryIssue >;
}

const POLL_MS = 3000;

interface Options {
	/** Proceso que ya estaba en marcha al cargar la vista. */
	initial: HistoryJob | null;
	/** Vuelve a leer lo que muestra la vista; se llama al terminar el proceso. Debe ser estable. */
	refresh: () => Promise< void >;
	/** Anuncia con `speak` el resumen cuando el proceso termina (la vista por entrada lo hace; la de lote usa una región viva). */
	announce?: boolean;
	/** Aviso de error de la vista (lo comparte con sus otras operaciones). */
	setFailure: ( message: string ) => void;
	/** Qué hacer tras cancelar; por defecto, releer la vista. */
	onCancelled?: ( response: {
		job: HistoryJob;
		group?: HistoryGroup | null;
	} ) => void | Promise< void >;
}

/**
 * Resultado visible de un proceso terminado o cancelado.
 * @param job Proceso.
 */
export function outcomeOfJob( job: HistoryJob ): Outcome {
	return {
		redo: job.mode === 'redo',
		counts: job.counts,
		lines: job.issues,
	};
}

/**
 * Seguimiento de un proceso de deshacer o rehacer en segundo plano: sondeo cada 3 s mientras esté en cola o en marcha,
 * reanudar y cancelar, y el resultado final. Lo comparten la vista por lote y la vista por entrada del historial.
 * @param options
 */
export function useHistoryJob( options: Options ) {
	const { initial, refresh, setFailure } = options;
	const [ job, setJob ] = useState< HistoryJob | null >( initial );
	const [ outcome, setOutcome ] = useState< Outcome | null >( null );
	const [ busy, setBusy ] = useState( false );
	const latest = useRef( options );
	latest.current = options;

	const jobId = job?.id ?? 0;
	const jobActive = job?.status === 'queued' || job?.status === 'running';
	useEffect( () => {
		if ( ! jobActive ) {
			return undefined;
		}
		const timer = setInterval( async () => {
			try {
				const { job: next } = await api.historyJob( jobId );
				setJob( next );
				if ( next.status !== 'queued' && next.status !== 'running' ) {
					setOutcome( outcomeOfJob( next ) );
					if ( latest.current.announce ) {
						speak(
							summaryText( next.counts, next.mode === 'redo' ),
							'polite'
						);
					}
					await refresh();
				}
			} catch ( e ) {
				const status = errorStatus( e );
				if ( status === 403 || status === 404 ) {
					// Ya no se puede seguir (permiso o proceso desaparecido): se deja de sondear.
					setJob( null );
				}
				setFailure(
					errorMessage(
						e,
						__(
							'Could not read the progress of the process.',
							'magic-linking'
						)
					)
				);
			}
		}, POLL_MS );
		return () => clearInterval( timer );
	}, [ jobActive, jobId, refresh, setFailure ] );

	const control = async ( action: 'resume' | 'cancel' ) => {
		if ( ! job ) {
			return;
		}
		setBusy( true );
		setFailure( '' );
		try {
			const response = await api.controlHistoryJob( job.id, action );
			setJob( response.job );
			if ( action === 'cancel' ) {
				if ( latest.current.onCancelled ) {
					await latest.current.onCancelled( response );
				} else {
					await refresh();
				}
			}
		} catch ( e ) {
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

	return {
		job,
		setJob,
		jobActive,
		outcome,
		setOutcome,
		busy,
		setBusy,
		control,
	};
}
