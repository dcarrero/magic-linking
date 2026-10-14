import { Button, Notice } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage, errorStatus } from '../api';
import type {
	ChangeResult,
	HistoryEntry,
	HistoryEntryChange,
	HistoryIssue,
	HistoryJob,
} from '../types';
import { ConfirmModal } from './ConfirmModal';
import {
	actionLabel,
	entryTitle,
	formatDate,
	formatFullDate,
	parseServerDate,
	sourceLabel,
} from './historyFormat';
import {
	countStatuses,
	needsAttention,
	statusLabel,
	summaryText,
} from './historyText';

interface Props {
	entry: HistoryEntry;
	/** Filtro de origen activo: lo que se cuenta, se confirma y se deshace es lo que el filtro deja ver. */
	origin: string;
	/** La entrada ha cambiado (o ya no hay nada que enseñar: null). */
	onChange: ( postId: number, entry: HistoryEntry | null ) => void;
}

interface Pending {
	mode: 'undo' | 'redo';
}

interface Outcome {
	redo: boolean;
	counts: ReturnType< typeof countStatuses >;
	lines: ReadonlyArray< ChangeResult | HistoryIssue >;
}

/** Cambios que se piden de golpe al pulsar «Mostrar más»: tantos como trae el listado la primera vez. */
const MORE_PER_PAGE = 10;
const POLL_MS = 3000;

/**
 * Una entrada del historial: cabecera con sus acciones y una tabla densa con un cambio por fila.
 * @param root0
 * @param root0.entry
 * @param root0.origin
 * @param root0.onChange
 */
export function HistoryEntryGroup( { entry, origin, onChange }: Props ) {
	const [ changes, setChanges ] = useState< HistoryEntryChange[] >(
		entry.changes
	);
	const [ pending, setPending ] = useState< Pending | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ loadingMore, setLoadingMore ] = useState( false );
	const [ failure, setFailure ] = useState( '' );
	const [ outcome, setOutcome ] = useState< Outcome | null >( null );
	const [ job, setJob ] = useState< HistoryJob | null >( entry.job ?? null );
	const heading = useRef< HTMLHeadingElement >( null );
	const section = useRef< HTMLElement >( null );
	const restoreFocus = useRef( false );
	const title = entryTitle( entry.post_title );

	// Un listado nuevo del servidor (tras deshacer, filtrar o paginar) sustituye a lo cargado.
	useEffect( () => {
		setChanges( entry.changes );
	}, [ entry ] );

	// Si el botón pulsado ya no existe (p. ej. al rehacer, la fila cambia de sitio), el foco pasa al título.
	useEffect( () => {
		if ( ! restoreFocus.current ) {
			return;
		}
		restoreFocus.current = false;
		if (
			section.current &&
			! section.current.contains(
				section.current.ownerDocument.activeElement
			)
		) {
			heading.current?.focus();
		}
	}, [ changes ] );

	const refresh = useCallback( async () => {
		try {
			onChange(
				entry.post_id,
				( await api.historyEntry( entry.post_id, origin ) ).entry
			);
		} catch ( e ) {
			setFailure(
				errorMessage(
					e,
					__( 'Could not refresh this entry.', 'magic-linking' )
				)
			);
		}
	}, [ entry.post_id, origin, onChange ] );

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
					const counts = next.counts;
					setOutcome( {
						redo: next.mode === 'redo',
						counts,
						lines: next.issues,
					} );
					speak(
						summaryText( counts, next.mode === 'redo' ),
						'polite'
					);
					await refresh();
				}
			} catch ( e ) {
				const status = errorStatus( e );
				if ( status === 403 || status === 404 ) {
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
	}, [ jobActive, jobId, refresh ] );

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
				await refresh();
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

	const run = async ( mode: 'undo' | 'redo', ids?: number[] ) => {
		setBusy( true );
		setFailure( '' );
		restoreFocus.current = true;
		let results: ChangeResult[] = [];
		let latest: HistoryEntry | null = entry;
		try {
			// Una sola petición: el servidor aplica exactamente la lista o el filtro de origen que se confirmó.
			const response = await api.runHistoryEntry( mode, entry.post_id, {
				changeIds: ids,
				origin,
			} );
			results = response.results;
			latest = response.entry;
			if ( response.job ) {
				setJob( response.job );
				setOutcome( null );
			}
		} catch ( e ) {
			setFailure(
				errorMessage(
					e,
					__( 'That did not work. Try again.', 'magic-linking' )
				)
			);
		} finally {
			setPending( null );
			setBusy( false );
		}

		if ( results.length > 0 ) {
			const counts = countStatuses( results );
			setOutcome( {
				redo: mode === 'redo',
				counts,
				lines: results.filter(
					( result ) =>
						needsAttention( result.status ) ||
						result.status === 'link_removed' ||
						result.status === 'fix_reverted'
				),
			} );
			speak( summaryText( counts, mode === 'redo' ), 'polite' );
		}
		onChange( entry.post_id, latest );
	};

	const loadMore = async () => {
		setLoadingMore( true );
		setFailure( '' );
		try {
			const response = await api.historyEntryChanges(
				entry.post_id,
				changes[ changes.length - 1 ]?.id ?? 0,
				MORE_PER_PAGE,
				origin
			);
			setChanges( ( previous ) => [
				...previous,
				...response.items.filter(
					( item ) =>
						! previous.some( ( shown ) => shown.id === item.id )
				),
			] );
			speak(
				sprintf(
					/* translators: %s: number of changes. */
					_n(
						'%s more change shown.',
						'%s more changes shown.',
						response.items.length,
						'magic-linking'
					),
					response.items.length.toLocaleString()
				),
				'polite'
			);
		} catch ( e ) {
			setFailure(
				errorMessage(
					e,
					__( 'Could not load more changes.', 'magic-linking' )
				)
			);
		} finally {
			setLoadingMore( false );
		}
	};

	const headingId = `magiclinking-entry-${ entry.post_id }`;

	return (
		<section
			className="magiclinking-entry"
			aria-labelledby={ headingId }
			ref={ section }
		>
			<header className="magiclinking-entry__head">
				<h2
					id={ headingId }
					className="magiclinking-entry__title"
					tabIndex={ -1 }
					ref={ heading }
				>
					{ title }
				</h2>
				<p className="magiclinking-entry__meta">
					{ sprintf(
						/* translators: 1: active changes, 2: total changes. */
						__( '%1$s of %2$s changes active', 'magic-linking' ),
						entry.active.toLocaleString(),
						entry.total.toLocaleString()
					) }{ ' ' }
					· { __( 'Last change', 'magic-linking' ) }{ ' ' }
					<time
						dateTime={ parseServerDate(
							entry.last_at
						).toISOString() }
						title={ formatFullDate( entry.last_at ) }
					>
						{ formatDate( entry.last_at ) }
					</time>
					{ entry.edit_url && (
						<>
							{ ' · ' }
							<a href={ entry.edit_url }>
								<span aria-hidden="true">
									{ __( 'Edit', 'magic-linking' ) }
								</span>
								<span className="screen-reader-text">
									{ sprintf(
										/* translators: %s: entry title. */
										__( 'Edit “%s”', 'magic-linking' ),
										title
									) }
								</span>
							</a>
						</>
					) }
					{ entry.view_url && (
						<>
							{ ' · ' }
							<a href={ entry.view_url }>
								<span aria-hidden="true">
									{ __( 'View', 'magic-linking' ) }
								</span>
								<span className="screen-reader-text">
									{ sprintf(
										/* translators: %s: entry title. */
										__( 'View “%s”', 'magic-linking' ),
										title
									) }
								</span>
							</a>
						</>
					) }
				</p>
				<div className="magiclinking-entry__actions">
					<Button
						variant="secondary"
						size="small"
						disabled={ entry.active === 0 || busy || jobActive }
						accessibleWhenDisabled
						aria-label={ sprintf(
							/* translators: 1: number of active changes, 2: entry title. */
							__( 'Undo all (%1$s) in %2$s', 'magic-linking' ),
							entry.active.toLocaleString(),
							title
						) }
						onClick={ () => setPending( { mode: 'undo' } ) }
					>
						{ sprintf(
							/* translators: %s: number of active changes. */
							__( 'Undo all (%s)', 'magic-linking' ),
							entry.active.toLocaleString()
						) }
					</Button>
					{ entry.undone > 0 && (
						<Button
							variant="secondary"
							size="small"
							disabled={ busy || jobActive }
							accessibleWhenDisabled
							aria-label={ sprintf(
								/* translators: 1: number of undone changes, 2: entry title. */
								__(
									'Redo all (%1$s) in %2$s',
									'magic-linking'
								),
								entry.undone.toLocaleString(),
								title
							) }
							onClick={ () => setPending( { mode: 'redo' } ) }
						>
							{ sprintf(
								/* translators: %s: number of undone changes. */
								__( 'Redo all (%s)', 'magic-linking' ),
								entry.undone.toLocaleString()
							) }
						</Button>
					) }
				</div>
			</header>

			{ job && jobActive && (
				<div className="magiclinking-batch__progress">
					<progress
						max={ Math.max( 1, job.total ) }
						value={ job.done }
						aria-label={
							job.mode === 'redo'
								? __(
										'Progress of redoing the changes',
										'magic-linking'
								  )
								: __(
										'Progress of undoing the changes',
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
					{ job.stalled && job.can_control && (
						<div className="magiclinking-actions">
							<Button
								variant="secondary"
								onClick={ () => control( 'resume' ) }
								disabled={ busy }
								accessibleWhenDisabled
							>
								{ __( 'Resume', 'magic-linking' ) }
							</Button>
							<Button
								variant="secondary"
								isDestructive
								onClick={ () => control( 'cancel' ) }
								disabled={ busy }
								accessibleWhenDisabled
							>
								{ __( 'Cancel process', 'magic-linking' ) }
							</Button>
						</div>
					) }
				</div>
			) }

			{ outcome && (
				<div className="magiclinking-outcome">
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
				</div>
			) }

			{ failure && (
				<Notice status="error" isDismissible={ false }>
					{ failure }
				</Notice>
			) }

			<table
				className="widefat striped magiclinking-changes"
				aria-busy={ busy || loadingMore }
			>
				<caption className="screen-reader-text">
					{ sprintf(
						/* translators: %s: entry title. */
						__( 'Changes in “%s”, newest first', 'magic-linking' ),
						title
					) }
				</caption>
				<colgroup>
					{ [ 1, 2, 3, 4, 5, 6, 7, 8 ].map( ( n ) => (
						<col key={ n } />
					) ) }
				</colgroup>
				<thead>
					<tr>
						<th scope="col">{ __( 'Date', 'magic-linking' ) }</th>
						<th scope="col">{ __( 'Change', 'magic-linking' ) }</th>
						<th scope="col">
							{ __( 'Anchor text', 'magic-linking' ) }
						</th>
						<th scope="col">
							{ __( 'Links to', 'magic-linking' ) }
						</th>
						<th scope="col">{ __( 'Source', 'magic-linking' ) }</th>
						<th scope="col">{ __( 'User', 'magic-linking' ) }</th>
						<th scope="col">{ __( 'Status', 'magic-linking' ) }</th>
						<th scope="col">
							<span className="screen-reader-text">
								{ __( 'Actions', 'magic-linking' ) }
							</span>
						</th>
					</tr>
				</thead>
				<tbody>
					{ changes.map( ( change ) => (
						<ChangeRow
							key={ change.id }
							change={ change }
							title={ title }
							disabled={ busy || jobActive }
							onRun={ ( mode ) => run( mode, [ change.id ] ) }
						/>
					) ) }
				</tbody>
			</table>

			{ changes.length < entry.total && (
				<p className="magiclinking-entry__more">
					<Button
						variant="tertiary"
						size="small"
						isBusy={ loadingMore }
						disabled={ loadingMore }
						accessibleWhenDisabled
						onClick={ loadMore }
					>
						{ sprintf(
							/* translators: 1: changes shown, 2: total changes. */
							__(
								'Show more changes (%1$s of %2$s)',
								'magic-linking'
							),
							changes.length.toLocaleString(),
							entry.total.toLocaleString()
						) }
					</Button>
				</p>
			) }

			{ pending && (
				<ConfirmModal
					title={
						pending.mode === 'undo'
							? __(
									'Undo all changes in this entry?',
									'magic-linking'
							  )
							: __(
									'Redo all changes in this entry?',
									'magic-linking'
							  )
					}
					confirmLabel={
						pending.mode === 'undo'
							? __( 'Undo all', 'magic-linking' )
							: __( 'Redo all', 'magic-linking' )
					}
					busy={ busy }
					onConfirm={ () => run( pending.mode ) }
					onCancel={ () => setPending( null ) }
				>
					<p>
						{ pending.mode === 'undo'
							? sprintf(
									/* translators: 1: number of links, 2: entry title. */
									_n(
										'%1$s change will be undone in “%2$s”, newest first.',
										'%1$s changes will be undone in “%2$s”, newest first.',
										entry.active,
										'magic-linking'
									),
									entry.active.toLocaleString(),
									title
							  ) +
							  ' ' +
							  __(
									'If the entry was edited afterwards, only the link is touched; anything that cannot be undone safely is left alone and listed so you can fix it by hand.',
									'magic-linking'
							  )
							: sprintf(
									/* translators: 1: number of links, 2: entry title. */
									_n(
										'%1$s undone change will be applied again in “%2$s”, as it was.',
										'%1$s undone changes will be applied again in “%2$s”, as they were.',
										entry.undone,
										'magic-linking'
									),
									entry.undone.toLocaleString(),
									title
							  ) +
							  ' ' +
							  __(
									'Any spot edited since is left alone and listed.',
									'magic-linking'
							  ) }
					</p>
				</ConfirmModal>
			) }
		</section>
	);
}

interface RowProps {
	change: HistoryEntryChange;
	title: string;
	disabled: boolean;
	onRun: ( mode: 'undo' | 'redo' ) => void;
}

/**
 * Una fila compacta: un cambio, con su fecha, ancla, destino, origen, usuario y estado.
 * @param root0
 * @param root0.change
 * @param root0.title
 * @param root0.disabled
 * @param root0.onRun
 */
function ChangeRow( { change, title, disabled, onRun }: RowProps ) {
	const undone = change.state === 'undone';
	const anchor = change.anchor || __( '(unknown)', 'magic-linking' );
	const shown = change.url || change.previous_url || '';
	const label = change.target_title ?? shown;

	return (
		<tr className={ undone ? 'magiclinking-change--undone' : undefined }>
			<th
				scope="row"
				className="magiclinking-change__date"
				data-label={ __( 'Date', 'magic-linking' ) }
			>
				<time
					dateTime={ parseServerDate(
						change.created_at
					).toISOString() }
					title={ formatFullDate( change.created_at ) }
				>
					{ formatDate( change.created_at ) }
				</time>
			</th>
			<td data-label={ __( 'Change', 'magic-linking' ) }>
				{ actionLabel( change.kind ) }
				{ change.batch_posts > 1 && (
					<span className="magiclinking-change__note">
						{ sprintf(
							/* translators: %s: number of entries in the batch. */
							__(
								'Part of a batch of %s entries',
								'magic-linking'
							),
							change.batch_posts.toLocaleString()
						) }
					</span>
				) }
			</td>
			<td data-label={ __( 'Anchor text', 'magic-linking' ) }>
				{ anchor }
			</td>
			<td
				className="magiclinking-url"
				data-label={ __( 'Links to', 'magic-linking' ) }
			>
				{ change.url ? (
					<a href={ change.url } title={ change.url }>
						{ label }
					</a>
				) : (
					<span title={ shown }>{ label }</span>
				) }
				{ change.kind === 'retarget' && change.previous_url && (
					<span className="magiclinking-change__note">
						{ sprintf(
							/* translators: %s: address the link pointed to before. */
							__( 'Was: %s', 'magic-linking' ),
							change.previous_url
						) }
					</span>
				) }
			</td>
			<td data-label={ __( 'Source', 'magic-linking' ) }>
				{ sourceLabel( change.origin, change.kind ) }
			</td>
			<td data-label={ __( 'User', 'magic-linking' ) }>
				{ change.user_name }
			</td>
			<td data-label={ __( 'Status', 'magic-linking' ) }>
				{ undone ? (
					<span
						title={
							change.undone_at
								? formatFullDate( change.undone_at )
								: undefined
						}
					>
						{ __( 'Undone', 'magic-linking' ) }
					</span>
				) : (
					__( 'In place', 'magic-linking' )
				) }
			</td>
			<td className="magiclinking-change__actions">
				<Button
					variant="secondary"
					size="small"
					disabled={ disabled }
					accessibleWhenDisabled
					aria-label={
						undone
							? sprintf(
									/* translators: 1: anchor text, 2: entry title. */
									__(
										'Redo “%1$s” in %2$s',
										'magic-linking'
									),
									anchor,
									title
							  )
							: sprintf(
									/* translators: 1: anchor text, 2: entry title. */
									__(
										'Undo “%1$s” in %2$s',
										'magic-linking'
									),
									anchor,
									title
							  )
					}
					onClick={ () => onRun( undone ? 'redo' : 'undo' ) }
				>
					{ undone
						? __( 'Redo', 'magic-linking' )
						: __( 'Undo', 'magic-linking' ) }
				</Button>
			</td>
		</tr>
	);
}
