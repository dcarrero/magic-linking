import { Button, Notice } from '@wordpress/components';
import { useCallback, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage, errorStatus } from '../api';
import type { HistoryChange, HistoryGroup } from '../types';
import { ConfirmModal } from './ConfirmModal';
import {
	entryTitle,
	formatDate,
	formatFullDate,
	parseServerDate,
	batchAiLabel,
	batchOriginLabel,
} from './historyFormat';
import { JobProgress, OutcomeSummary } from './HistoryJobParts';
import { HistorySource } from './HistorySource';
import { countStatuses } from './historyText';
import { useHistoryJob } from './useHistoryJob';

interface Props {
	group: HistoryGroup;
	/** El grupo ha cambiado (o ya no es visible: null). */
	onChange: ( group: HistoryGroup | null ) => void;
}

type Pending =
	| { mode: 'undo' | 'redo'; batch: true }
	| { mode: 'undo' | 'redo'; batch: false; change: HistoryChange };

const DETAILS_PER_PAGE = 50;

/**
 * Un grupo del historial: resumen, acciones con confirmación, lista de sus enlaces y resultado de la última operación.
 * @param root0
 * @param root0.group
 * @param root0.onChange
 */
export function HistoryGroupCard( { group, onChange }: Props ) {
	const aiLabel = batchAiLabel( group.ai_links, group.links );
	const [ expanded, setExpanded ] = useState( false );
	const [ details, setDetails ] = useState< HistoryChange[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ loadingDetails, setLoadingDetails ] = useState( false );
	const [ pending, setPending ] = useState< Pending | null >( null );
	const [ failure, setFailure ] = useState( '' );
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
			// Solo si el grupo ya no existe se quita la tarjeta; con cualquier otro error se conserva y se avisa.
			if ( errorStatus( e ) === 404 ) {
				onChange( null );
				return;
			}
			setFailure(
				errorMessage(
					e,
					__( 'Could not refresh this batch.', 'magic-linking' )
				)
			);
		}
		if ( detailsLoaded.current > 0 ) {
			await loadDetails( 1, true );
		}
	}, [ batchId, loadDetails, onChange ] );

	const {
		job,
		jobActive,
		outcome,
		start,
		showResult,
		busy,
		setBusy,
		control,
	} = useHistoryJob( {
		initial: group.job ?? null,
		refresh,
		setFailure,
		onCancelled: ( response ) => {
			if ( response.group ) {
				onChange( response.group );
			}
		},
	} );

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
				start( response.job );
			} else {
				showResult( {
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
				{ groupTitle( group ) }{ ' ' }
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
				) }{ ' ' }
				·{ ' ' }
				<span
					className={ `magiclinking-origin magiclinking-origin--${ group.origin }` }
				>
					{ batchOriginLabel( group.origin, group.kind ) }
				</span>
				{ aiLabel && <> · { aiLabel }</> }
			</p>
			{ ! expanded && (
				<p className="magiclinking-batch__entries">
					{ group.titles.map( entryTitle ).join( ', ' ) }
					{ more > 0 ? '…' : '' }
				</p>
			) }

			{ job && jobActive && (
				<JobProgress
					job={ job }
					labels={ {
						undo: __(
							'Progress of undoing the batch',
							'magic-linking'
						),
						redo: __(
							'Progress of redoing the batch',
							'magic-linking'
						),
					} }
					busy={ busy }
					onControl={ control }
				/>
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
				{ outcome && <OutcomeSummary outcome={ outcome } showEntry /> }
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
										{ __( 'Date', 'magic-linking' ) }
									</th>
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
										{ __( 'Source', 'magic-linking' ) }
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
										<td colSpan={ 7 }>
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
												<time
													dateTime={ parseServerDate(
														change.created_at
													).toISOString() }
													title={ formatFullDate(
														change.created_at
													) }
												>
													{ formatDate(
														change.created_at
													) }
												</time>
											</td>
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
												{ destination( change ) }
											</td>
											<td>
												<HistorySource
													origin={ change.origin }
													kind={
														change.kind ?? 'insert'
													}
													ai={ change.ai }
												/>
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
	if ( group.kind === 'retarget' || group.kind === 'unlink' ) {
		return fixConfirmText( pending, group );
	}

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

/**
 * Título de un grupo según lo que hizo.
 * @param group Grupo.
 */
function groupTitle( group: HistoryGroup ): string {
	const count = group.links.toLocaleString();
	if ( group.kind === 'retarget' ) {
		return sprintf(
			/* translators: %s: number of links. */
			_n(
				'%s link destination changed',
				'%s link destinations changed',
				group.links,
				'magic-linking'
			),
			count
		);
	}
	if ( group.kind === 'unlink' ) {
		return sprintf(
			/* translators: %s: number of links. */
			_n(
				'%s link removed',
				'%s links removed',
				group.links,
				'magic-linking'
			),
			count
		);
	}
	return sprintf(
		/* translators: %s: number of links. */
		_n( '%s link added', '%s links added', group.links, 'magic-linking' ),
		count
	);
}

/**
 * Explicación de lo que va a pasar al deshacer o rehacer un arreglo de enlace roto.
 * @param pending Lo que se va a hacer.
 * @param group   Grupo.
 */
function fixConfirmText( pending: Pending, group: HistoryGroup ): string {
	const removed = group.kind === 'unlink';
	if ( pending.mode === 'undo' ) {
		const base = removed
			? __(
					'The link will be put back around its text, as it was.',
					'magic-linking'
			  )
			: __(
					'The link will point to its previous address again, and nothing else changes.',
					'magic-linking'
			  );
		return (
			base +
			' ' +
			__(
				'If the entry was edited afterwards, only this link is touched; if that cannot be done safely, nothing is changed and you are told.',
				'magic-linking'
			)
		);
	}
	return (
		( removed
			? __( 'The link will be removed again.', 'magic-linking' )
			: __(
					'The new destination will be applied again.',
					'magic-linking'
			  ) ) +
		' ' +
		__(
			'Any entry edited in that spot since is left alone and listed.',
			'magic-linking'
		)
	);
}

/**
 * Dirección que enseña la lista de un cambio: la del enlace, o de dónde a dónde se cambió, o cuál se quitó.
 * @param change Cambio.
 */
function destination( change: HistoryChange ): string {
	if ( change.kind === 'retarget' && change.previous_url ) {
		return `${ change.previous_url } → ${ change.url }`;
	}
	if ( change.kind === 'unlink' ) {
		return sprintf(
			/* translators: %s: address the removed link pointed to. */
			__( '%s (link removed)', 'magic-linking' ),
			change.previous_url ?? ''
		);
	}
	return change.url;
}
