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
	/** Proceso que ya estaba en marcha al cargar la vista; si llega otro con distinto id, se pasa a seguir ese. */
	initial: HistoryJob | null;
	/** Vuelve a leer lo que muestra la vista; se llama al terminar el proceso. */
	refresh: () => Promise< void >;
	/** Aviso de error de la vista (lo comparte con sus otras operaciones). */
	setFailure: ( message: string ) => void;
	/** Anuncia con `speak` el resumen cuando el proceso termina (la vista por entrada lo hace; la de lote usa una región viva). */
	announce?: boolean;
	/** Lo específico de cada vista tras cancelar (el resultado parcial ya está fijado); por defecto, releer la vista. */
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

const isActive = ( job: HistoryJob | null ): boolean =>
	job?.status === 'queued' || job?.status === 'running';

/**
 * Seguimiento de un proceso de deshacer o rehacer en segundo plano: sondeo cada 3 s mientras esté en cola o en marcha
 * (una petición cada vez, sin solaparse), reanudar y cancelar, y el resultado final. Lo comparten la vista por lote y la
 * vista por entrada del historial. Los callbacks se leen siempre de la última llamada, así que no reinician el sondeo.
 * @param options Opciones.
 */
export function useHistoryJob( options: Options ) {
	const [ job, setJob ] = useState< HistoryJob | null >( options.initial );
	const [ outcome, setOutcome ] = useState< Outcome | null >( null );
	const [ busy, setBusy ] = useState( false );
	const latest = useRef( options );
	latest.current = options;
	const mounted = useRef( true );
	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	// Un proceso nuevo en las propiedades (otro id) sustituye al que se seguía.
	const initialId = options.initial?.id ?? 0;
	useEffect( () => {
		const next = latest.current.initial;
		if ( next ) {
			setJob( ( current ) =>
				current?.id === next.id ? current : next
			);
		}
	}, [ initialId ] );

	const jobId = job?.id ?? 0;
	const jobActive = isActive( job );
	useEffect( () => {
		if ( ! jobActive ) {
			return undefined;
		}
		let cancelled = false;
		let timer: ReturnType< typeof setTimeout > | undefined;

		const tick = async () => {
			let finished = false;
			try {
				const { job: next } = await api.historyJob( jobId );
				if ( cancelled ) {
					return;
				}
				setJob( next );
				if ( ! isActive( next ) ) {
					finished = true;
					setOutcome( outcomeOfJob( next ) );
					if ( latest.current.announce ) {
						speak(
							summaryText( next.counts, next.mode === 'redo' ),
							'polite'
						);
					}
					await latest.current.refresh();
				}
			} catch ( e ) {
				if ( cancelled ) {
					return;
				}
				const status = errorStatus( e );
				if ( status === 403 || status === 404 ) {
					// Ya no se puede seguir (permiso o proceso desaparecido): se deja de sondear.
					finished = true;
					setJob( null );
				}
				latest.current.setFailure(
					errorMessage(
						e,
						__(
							'Could not read the progress of the process.',
							'magic-linking'
						)
					)
				);
			}
			// La siguiente petición sale 3 s después de que acabe la anterior: nunca hay dos en vuelo.
			if ( ! cancelled && ! finished ) {
				timer = setTimeout( tick, POLL_MS );
			}
		};

		timer = setTimeout( tick, POLL_MS );
		return () => {
			cancelled = true;
			if ( timer ) {
				clearTimeout( timer );
			}
		};
	}, [ jobActive, jobId ] );

	/**
	 * Empieza a seguir un proceso recién creado y borra el resultado anterior.
	 * @param next Proceso.
	 */
	const start = ( next: HistoryJob ) => {
		setJob( next );
		setOutcome( null );
	};

	/**
	 * Fija el resultado visible de una operación hecha en la petición.
	 * @param next Resultado.
	 */
	const showResult = ( next: Outcome | null ) => setOutcome( next );

	const control = async ( action: 'resume' | 'cancel' ) => {
		if ( ! job ) {
			return;
		}
		setBusy( true );
		latest.current.setFailure( '' );
		try {
			const response = await api.controlHistoryJob( job.id, action );
			if ( ! mounted.current ) {
				return;
			}
			setJob( response.job );
			if ( action === 'cancel' ) {
				// Lo ya tratado (deshechos y avisos) se enseña también al cancelar.
				setOutcome(
					Object.keys( response.job.counts ).length === 0
						? null
						: outcomeOfJob( response.job )
				);
				if ( latest.current.onCancelled ) {
					await latest.current.onCancelled( response );
				} else {
					await latest.current.refresh();
				}
			}
		} catch ( e ) {
			if ( mounted.current ) {
				latest.current.setFailure(
					errorMessage(
						e,
						__( 'That did not work. Try again.', 'magic-linking' )
					)
				);
			}
		} finally {
			if ( mounted.current ) {
				setBusy( false );
			}
		}
	};

	return {
		job,
		jobActive,
		outcome,
		busy,
		setBusy,
		start,
		showResult,
		control,
	};
}
