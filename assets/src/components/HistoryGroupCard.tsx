import { Button, Notice } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import type {
	ChangeResult,
	HistoryChange,
	HistoryGroup,
	HistoryIssue,
	HistoryJob,
} from '../types';
import { ConfirmModal } from './ConfirmModal';
import { parseServerDate } from './Maintenance';
import {
	countStatuses,
	needsAttention,
	statusLabel,
	summaryText,
} from './historyText';

interface Props {
	group: HistoryGroup;
	/** El grupo ha cambiado (o ya no es visible: null). */
	onChange: ( group: HistoryGroup | null ) => void;
}

type Pending =
	| { mode: 'undo' | 'redo'; batch: true }
	| { mode: 'undo' | 'redo'; batch: false; change: HistoryChange };

interface Outcome {
	redo: boolean;
	counts: ReturnType< typeof countStatuses >;
	lines: ReadonlyArray< ChangeResult | HistoryIssue >;
}

const POLL_MS = 3000;
const DETAILS_PER_PAGE = 50;

/**
 * Fecha y hora de un grupo en el idioma del usuario.
 * @param value Fecha RFC 3339 del servidor.
 */
function formatDate( value: string ): string {
	return new Intl.DateTimeFormat(
		document.documentElement.lang || undefined,
		{
			dateStyle: 'medium',
			timeStyle: 'short',
		}
	).format( parseServerDate( value ) );
}

/**
 * Título de una entrada, con texto para las que no tienen o ya no existen.
 * @param title Título (null = la entrada ya no existe).
 */
function entryTitle( title: string | null ): string {
	if ( title === null ) {
		return __( '(deleted entry)', 'magic-linking' );
	}
	return title === '' ? __( '(no title)', 'magic-linking' ) : title;
}

/**
 * Un grupo del historial: resumen, acciones con confirmación, lista de sus enlaces y resultado de la última operación.
 * @param root0
 * @param root0.group
 * @param root0.onChange
 */
export function HistoryGroupCard( { group, onChange }: Props ) {
	const [ expanded, setExpanded ] = useState( false );
	const [ details, setDetails ] = useState< HistoryChange[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ loadingDetails, setLoadingDetails ] = useState( false );
	const [ pending, setPending ] = useState< Pending | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ failure, setFailure ] = useState( '' );
	const [ outcome, setOutcome ] = useState< Outcome | null >( null );
	const [ job, setJob ] = useState< HistoryJob | null >( group.job ?? null );
	const detailsLoaded = useRef( 0 );
	const batchId = group.batch_id;
	const detailsId = `magiclinking-details-${ batchId }`;

	const loadDetails = useCallback(
		async ( page: number, replace: boolean ) => {
			setLoadingDetails( true );
			try {
				const response = await api.historyChanges(
					batchId,
					page,
					DETAILS_PER_PAGE
				);
				setDetails( ( previous ) =>
					replace
						? response.items
						: [ ...previous, ...response.items ]
				);
				setTotal( response.total );
				detailsLoaded.current = page;
				setFailure( '' );
			} catch ( e ) {
				setFailure(
					errorMessage(
						e,
						__(
							'Could not load the links of this batch.',
							'magic-linking'
						)
					)
				);
			} finally {
				setLoadingDetails( false );
			}
		},
		[ batchId ]
	);

	const refresh = useCallback( async () => {
		try {
			const response = await api.historyGroup( batchId );
			onChange( response.group );
		} catch ( e ) {
			onChange( null );
		}
		if ( detailsLoaded.current > 0 ) {
			await loadDetails( 1, true );
		}
	}, [ batchId, loadDetails, onChange ] );

	// Seguimiento de un proceso en segundo plano: cada 3 s mientras esté en cola o en marcha.
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
					setOutcome( {
						redo: next.mode === 'redo',
						counts: next.counts,
						lines: next.issues,
					} );
					await refresh();
				}
			} catch ( e ) {
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
	}, [ jobActive, jobId, refresh ] );

	const toggle = () => {
		const next = ! expanded;
		setExpanded( next );
		if ( next && detailsLoaded.current === 0 ) {
			loadDetails( 1, true );
		}
	};

	const run = async () => {
		if ( ! pending ) {
			return;
		}
		setBusy( true );
		setFailure( '' );
		try {
			const response = await api.runHistory(
				pending.mode,
				pending.batch ? { batchId } : { changeId: pending.change.id }
			);
			setPending( null );
			if ( response.job ) {
				setJob( response.job );
				setOutcome( null );
			} else {
				setOutcome( {
					redo: pending.mode === 'redo',
					counts: countStatuses( response.results ),
					lines: response.results,
				} );
			}
			onChange( response.group );
			if ( detailsLoaded.current > 0 ) {
				await loadDetails( 1, true );
			}
		} catch ( e ) {
			setPending( null );
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

	const working = jobActive;
	let state: { label: string; cls: string } = {
		label: __( 'In place', 'magic-linking' ),
		cls: 'ok',
	};
	if ( group.active === 0 ) {
		state = { label: __( 'Undone', 'magic-linking' ), cls: 'undone' };
	} else if ( group.undone > 0 ) {
		state = { label: __( 'Partly undone', 'magic-linking' ), cls: 'low' };
	}
	const more = group.posts - group.titles.length;

	return (
		<li className="magiclinking-batch">
			<h2
				className="magiclinking-batch__title"
				id={ `magiclinking-batch-${ batchId }` }
				tabIndex={ -1 }
			>
				{ sprintf(
					/* translators: %s: number of links. */
					_n(
						'%s link added',
						'%s links added',
						group.links,
						'magic-linking'
					),
					group.links.toLocaleString()
				) }{ ' ' }
				<span
					className={ `magiclinking-badge magiclinking-badge--${ state.cls }` }
				>
					{ state.label }
				</span>
			</h2>
			<p className="magiclinking-batch__meta">
				{ group.user_name } ·{ ' ' }
				<time
					dateTime={ parseServerDate(
						group.created_at
					).toISOString() }
				>
					{ formatDate( group.created_at ) }
				</time>{ ' ' }
				·{ ' ' }
				{ sprintf(
					/* translators: %s: number of entries. */
					_n(
						'%s entry',
						'%s entries',
						group.posts,
						'magic-linking'
					),
					group.posts.toLocaleString()
				) }
			</p>
			<p className="magiclinking-batch__entries">
				{ group.titles.map( entryTitle ).join( ', ' ) }
				{ more > 0 ? '…' : '' }
			</p>

			{ job && jobActive && (
				<div className="magiclinking-batch__progress">
					<progress
						max={ Math.max( 1, job.total ) }
						value={ job.done }
						aria-label={
							job.mode === 'redo'
								? __(
										'Progress of redoing the batch',
										'magic-linking'
								  )
								: __(
										'Progress of undoing the batch',
										'magic-linking'
								  )
						}
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
				</div>
			) }

			<div className="magiclinking-actions">
				<Button
					variant="secondary"
					onClick={ () =>
						setPending( { mode: 'undo', batch: true } )
					}
					disabled={ group.active === 0 || working }
					accessibleWhenDisabled
				>
					{ __( 'Undo batch', 'magic-linking' ) }
				</Button>
				{ group.undone > 0 && (
					<Button
						variant="secondary"
						onClick={ () =>
							setPending( { mode: 'redo', batch: true } )
						}
						disabled={ working }
						accessibleWhenDisabled
					>
						{ __( 'Redo batch', 'magic-linking' ) }
					</Button>
				) }
				<Button
					variant="secondary"
					onClick={ toggle }
					aria-expanded={ expanded }
					aria-controls={ detailsId }
				>
					{ expanded
						? __( 'Hide links', 'magic-linking' )
						: __( 'Show links', 'magic-linking' ) }
				</Button>
			</div>

			<div
				role="status"
				aria-live="polite"
				aria-atomic="false"
				className="magiclinking-outcome"
			>
				{ outcome && (
					<>
						<p>
							<strong>
								{ summaryText( outcome.counts, outcome.redo ) }
							</strong>
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
										{ ' · ' }
										{ entryTitle( line.post_title ) }
										{ line.message
											? `: ${ line.message }`
											: '' }
										{ line.edit_url && (
											<>
												{ ' ' }
												<a href={ line.edit_url }>
													{ __(
														'Edit entry',
														'magic-linking'
													) }
												</a>
											</>
										) }
									</li>
								) ) }
							</ul>
						) }
					</>
				) }
			</div>

			{ failure && (
				<Notice status="error" isDismissible={ false }>
					{ failure }
				</Notice>
			) }

			<div id={ detailsId } hidden={ ! expanded }>
				{ expanded && (
					<>
						<table
							className="wp-list-table widefat striped"
							aria-busy={ loadingDetails }
						>
							<caption className="screen-reader-text">
								{ __( 'Links in this batch', 'magic-linking' ) }
							</caption>
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Entry', 'magic-linking' ) }
									</th>
									<th scope="col">
										{ __( 'Anchor text', 'magic-linking' ) }
									</th>
									<th scope="col">
										{ __( 'Links to', 'magic-linking' ) }
									</th>
									<th scope="col">
										{ __( 'Status', 'magic-linking' ) }
									</th>
									<th scope="col">
										<span className="screen-reader-text">
											{ __( 'Actions', 'magic-linking' ) }
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ details.length === 0 && (
									<tr className="no-items">
										<td colSpan={ 5 }>
											{ loadingDetails
												? __(
														'Loading…',
														'magic-linking'
												  )
												: __(
														'No links to show.',
														'magic-linking'
												  ) }
										</td>
									</tr>
								) }
								{ details.map( ( change ) => {
									const title = entryTitle(
										change.post_title
									);
									const undone = change.state === 'undone';
									return (
										<tr key={ change.id }>
											<td>
												{ change.edit_url ? (
													<a href={ change.edit_url }>
														{ title }
													</a>
												) : (
													title
												) }
											</td>
											<td>{ change.anchor }</td>
											<td className="magiclinking-url">
												{ change.url }
											</td>
											<td>
												{ undone
													? __(
															'Undone',
															'magic-linking'
													  )
													: __(
															'In place',
															'magic-linking'
													  ) }
											</td>
											<td>
												<Button
													variant="secondary"
													size="small"
													disabled={ working }
													accessibleWhenDisabled
													aria-label={
														undone
															? sprintf(
																	/* translators: %s: entry title. */
																	__(
																		'Redo change in %s',
																		'magic-linking'
																	),
																	title
															  )
															: sprintf(
																	/* translators: %s: entry title. */
																	__(
																		'Undo change in %s',
																		'magic-linking'
																	),
																	title
															  )
													}
													onClick={ () =>
														setPending( {
															mode: undone
																? 'redo'
																: 'undo',
															batch: false,
															change,
														} )
													}
												>
													{ undone
														? __(
																'Redo change',
																'magic-linking'
														  )
														: __(
																'Undo change',
																'magic-linking'
														  ) }
												</Button>
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
						{ details.length < total && (
							<p>
								<Button
									variant="secondary"
									isBusy={ loadingDetails }
									onClick={ () =>
										loadDetails(
											detailsLoaded.current + 1,
											false
										)
									}
								>
									{ sprintf(
										/* translators: 1: links shown, 2: total links. */
										__(
											'Show more links (%1$s of %2$s)',
											'magic-linking'
										),
										details.length.toLocaleString(),
										total.toLocaleString()
									) }
								</Button>
							</p>
						) }
					</>
				) }
			</div>

			{ pending && (
				<ConfirmModal
					title={ confirmTitle( pending ) }
					confirmLabel={ confirmLabel( pending ) }
					busy={ busy }
					onConfirm={ run }
					onCancel={ () => setPending( null ) }
				>
					<p>{ confirmText( pending, group ) }</p>
				</ConfirmModal>
			) }
		</li>
	);
}

/**
 * Título del cuadro de confirmación.
 * @param pending Lo que se va a hacer.
 */
function confirmTitle( pending: Pending ): string {
	if ( pending.batch ) {
		return pending.mode === 'undo'
			? __( 'Undo this batch?', 'magic-linking' )
			: __( 'Redo this batch?', 'magic-linking' );
	}
	return pending.mode === 'undo'
		? __( 'Undo this change?', 'magic-linking' )
		: __( 'Redo this change?', 'magic-linking' );
}

/**
 * Texto del botón que confirma.
 * @param pending Lo que se va a hacer.
 */
function confirmLabel( pending: Pending ): string {
	if ( pending.batch ) {
		return pending.mode === 'undo'
			? __( 'Undo batch', 'magic-linking' )
			: __( 'Redo batch', 'magic-linking' );
	}
	return pending.mode === 'undo'
		? __( 'Undo change', 'magic-linking' )
		: __( 'Redo change', 'magic-linking' );
}

/**
 * Explicación de lo que va a pasar.
 * @param pending Lo que se va a hacer.
 * @param group   Grupo.
 */
function confirmText( pending: Pending, group: HistoryGroup ): string {
	if ( ! pending.batch ) {
		return pending.mode === 'undo'
			? sprintf(
					/* translators: %s: entry title. */
					__(
						'The link in “%s” will be taken out. If the entry was edited afterwards, only this link is removed.',
						'magic-linking'
					),
					entryTitle( pending.change.post_title )
			  )
			: sprintf(
					/* translators: %s: entry title. */
					__(
						'The link will be added again in “%s”, as it was.',
						'magic-linking'
					),
					entryTitle( pending.change.post_title )
			  );
	}

	if ( pending.mode === 'undo' ) {
		return (
			sprintf(
				/* translators: %s: number of links. */
				_n(
					'%s link will be taken out.',
					'%s links will be taken out.',
					group.active,
					'magic-linking'
				),
				group.active.toLocaleString()
			) +
			' ' +
			__(
				'Where an entry was edited afterwards, only the link is removed; anything that cannot be undone safely is left alone and listed so you can fix it by hand.',
				'magic-linking'
			)
		);
	}

	return (
		sprintf(
			/* translators: %s: number of links. */
			_n(
				'%s undone link will be added again, as it was.',
				'%s undone links will be added again, as they were.',
				group.undone,
				'magic-linking'
			),
			group.undone.toLocaleString()
		) +
		' ' +
		__(
			'Any entry edited in that spot since is left alone and listed.',
			'magic-linking'
		)
	);
}
