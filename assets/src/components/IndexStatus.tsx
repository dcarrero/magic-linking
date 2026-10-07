import { Button, Notice } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import type { Job, StatusResponse } from '../types';
import { parseServerDate } from './Maintenance';

interface Props {
	canManage: boolean;
	/** Se llama cuando termina un proceso, para refrescar los datos. */
	onFinished: () => void;
	/** Se llama al conocer el estado (para saber si hay índice). */
	onStatus: ( status: StatusResponse ) => void;
	/** Cambia cuando algo fuera de aquí ha lanzado un proceso (p. ej. guardar ajustes). */
	refreshKey: number;
}

const POLL_MS = 3000;

/**
 * Texto del tiempo que queda, o cadena vacía si no hay estimación.
 * @param seconds Segundos estimados.
 */
export function remainingText( seconds: number | null | undefined ): string {
	if ( seconds === null || seconds === undefined ) {
		return '';
	}
	if ( seconds < 60 ) {
		return __( 'Less than a minute left.', 'magic-linking' );
	}
	const minutes = Math.ceil( seconds / 60 );
	if ( minutes < 90 ) {
		return sprintf(
			/* translators: %s: number of minutes. */
			_n(
				'About %s minute left.',
				'About %s minutes left.',
				minutes,
				'magic-linking'
			),
			minutes.toLocaleString()
		);
	}
	const hours = Math.round( minutes / 60 );
	return sprintf(
		/* translators: %s: number of hours. */
		_n(
			'About %s hour left.',
			'About %s hours left.',
			hours,
			'magic-linking'
		),
		hours.toLocaleString()
	);
}

/**
 * Resultado de un análisis terminado: cuántas entradas eran nuevas o modificadas.
 * @param job Proceso terminado.
 */
export function resultText( job: Job ): string {
	return sprintf(
		/* translators: 1: new or modified entries analyzed, 2: unchanged entries skipped. */
		__(
			'Analysis finished: %1$s new or modified, %2$s unchanged and skipped.',
			'magic-linking'
		),
		job.changed.toLocaleString(),
		job.unchanged.toLocaleString()
	);
}

/**
 * Estado del índice: primera vez, progreso con pausa y cancelación, y aviso de entradas pendientes.
 * @param root0
 * @param root0.canManage
 * @param root0.onFinished
 * @param root0.onStatus
 * @param root0.refreshKey
 */
export function IndexStatus( {
	canManage,
	onFinished,
	onStatus,
	refreshKey,
}: Props ) {
	const [ status, setStatus ] = useState< StatusResponse | null >( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState< Job | null >( null );
	const wasActive = useRef( false );

	const load = useCallback( async () => {
		try {
			const next = await api.status();
			setStatus( next );
			setError( '' );
			onStatus( next );
			const active = next.job !== null;
			if ( wasActive.current && ! active ) {
				onFinished();
				setResult( next.last_done );
				speak(
					next.last_done
						? resultText( next.last_done )
						: __( 'Analysis finished.', 'magic-linking' ),
					'polite'
				);
			}
			wasActive.current = active;
		} catch ( e ) {
			setError(
				errorMessage(
					e,
					__( 'Could not read the analysis status.', 'magic-linking' )
				)
			);
		}
	}, [ onFinished, onStatus ] );

	useEffect( () => {
		load();
	}, [ load, refreshKey ] );

	const active = status?.job ?? null;
	useEffect( () => {
		if ( ! active || active.status === 'paused' ) {
			return undefined;
		}
		const timer = setInterval( load, POLL_MS );
		return () => clearInterval( timer );
	}, [ active, load ] );

	const run = async ( action: () => Promise< unknown >, message: string ) => {
		setBusy( true );
		try {
			await action();
			speak( message, 'polite' );
			await load();
		} catch ( e ) {
			setError(
				errorMessage(
					e,
					__( 'That did not work. Try again.', 'magic-linking' )
				)
			);
		} finally {
			setBusy( false );
		}
	};

	if ( ! status ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : null;
	}

	// Acción normal: solo lo nuevo o modificado (el análisis desde cero está en Ajustes).
	const start = () =>
		run(
			() => api.startIndex( false ),
			__( 'Analysis started.', 'magic-linking' )
		);

	let body = null;

	if ( active ) {
		const paused = active.status === 'paused';
		body = (
			<div className="magiclinking-progress">
				<p id="magiclinking-progress-label">
					{ paused
						? sprintf(
								/* translators: 1: entries done, 2: total entries. */
								__(
									'Analysis paused: %1$s of %2$s entries.',
									'magic-linking'
								),
								active.done.toLocaleString(),
								active.total.toLocaleString()
						  )
						: sprintf(
								/* translators: 1: entries done, 2: total entries. */
								__(
									'Analyzing your entries: %1$s of %2$s.',
									'magic-linking'
								),
								active.done.toLocaleString(),
								active.total.toLocaleString()
						  ) }
				</p>
				<progress
					aria-labelledby="magiclinking-progress-label"
					max={ 100 }
					value={ active.percent }
				/>
				{ ! paused && ! status.stalled && status.eta !== null && (
					<p className="magiclinking-eta">
						{ remainingText( status.eta ) }
					</p>
				) }
				{ status.stalled && (
					<p>
						{ __(
							'The analysis seems to have stopped. Resume it, or check that WP-Cron or Action Scheduler is running.',
							'magic-linking'
						) }
					</p>
				) }
				{ canManage && (
					<div className="magiclinking-actions">
						{ paused || status.stalled ? (
							<Button
								variant="secondary"
								disabled={ busy }
								accessibleWhenDisabled
								onClick={ () =>
									run(
										() =>
											api.changeJob(
												active.id,
												'resume'
											),
										__(
											'Analysis resumed.',
											'magic-linking'
										)
									)
								}
							>
								{ __( 'Resume', 'magic-linking' ) }
							</Button>
						) : (
							<Button
								variant="secondary"
								disabled={ busy }
								accessibleWhenDisabled
								onClick={ () =>
									run(
										() =>
											api.changeJob( active.id, 'pause' ),
										__(
											'Analysis paused.',
											'magic-linking'
										)
									)
								}
							>
								{ __( 'Pause', 'magic-linking' ) }
							</Button>
						) }
						<Button
							variant="tertiary"
							isDestructive
							disabled={ busy }
							accessibleWhenDisabled
							onClick={ () =>
								run(
									() => api.changeJob( active.id, 'cancel' ),
									__( 'Analysis cancelled.', 'magic-linking' )
								)
							}
						>
							{ __( 'Cancel', 'magic-linking' ) }
						</Button>
					</div>
				) }
			</div>
		);
	} else if ( status.indexed === 0 ) {
		body = (
			<div className="magiclinking-empty">
				<h2>{ __( 'Analyze your site', 'magic-linking' ) }</h2>
				<p>
					{ __(
						'Magic Linking reads the internal links already written in your entries and shows which ones have no links pointing to them, which have too few, which have too many and which are broken. Analyzing only reads your content; it never changes it. Links are only added when you press Link.',
						'magic-linking'
					) }
				</p>
				{ canManage ? (
					<Button
						variant="primary"
						disabled={ busy }
						accessibleWhenDisabled
						onClick={ start }
					>
						{ __( 'Analyze my site', 'magic-linking' ) }
					</Button>
				) : (
					<p>
						{ __(
							'An administrator has to run the first analysis.',
							'magic-linking'
						) }
					</p>
				) }
			</div>
		);
	} else if ( status.pending > 0 ) {
		body = (
			<div className="magiclinking-pending">
				<p>
					{ sprintf(
						/* translators: %s: number of entries. */
						_n(
							'%s new or modified entry pending.',
							'%s new or modified entries pending.',
							status.pending,
							'magic-linking'
						),
						status.pending.toLocaleString()
					) }
				</p>
				{ canManage && (
					<Button
						variant="secondary"
						disabled={ busy }
						accessibleWhenDisabled
						onClick={ start }
					>
						{ __( 'Analyze changes', 'magic-linking' ) }
					</Button>
				) }
			</div>
		);
	} else if ( status.last_job?.status === 'failed' ) {
		body = (
			<Notice status="error" isDismissible={ false }>
				{ sprintf(
					/* translators: %s: error message. */
					__( 'The last analysis failed: %s', 'magic-linking' ),
					status.last_job.error ||
						__( 'unknown error', 'magic-linking' )
				) }
			</Notice>
		);
	} else if ( canManage && status.last_done ) {
		body = (
			<p className="description magiclinking-last-analysis">
				{ sprintf(
					/* translators: %s: date and time of the last analysis. */
					__( 'Last analysis: %s', 'magic-linking' ),
					parseServerDate(
						status.last_done.updated_at
					).toLocaleString()
				) }{ ' ' }
				<Button
					variant="secondary"
					disabled={ busy }
					accessibleWhenDisabled
					onClick={ start }
				>
					{ __( 'Analyze changes', 'magic-linking' ) }
				</Button>
			</p>
		);
	}

	return (
		<div className="magiclinking-status">
			{ result && ! active && (
				<Notice status="success" onRemove={ () => setResult( null ) }>
					{ resultText( result ) }
				</Notice>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ body }
		</div>
	);
}
