import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, TabPanel } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import { AiBar } from './AiBar';
import { aiLinkInfo, type AiLinkInfo } from './ai';
import type { Suggestion } from '../types';
import type { ApplyFailure, EditorAdapter } from './adapters/types';
import type { EditorBoot } from './boot';
import { suggestionKey } from './keys';
import { SuggestionCard } from './SuggestionCard';
import { EmptyState, ErrorState, Loading } from './States';
import type { PanelState } from './usePanelState';

interface Props {
	adapter: EditorAdapter;
	boot: EditorBoot;
	state: PanelState;
	/** Si el panel anuncia por sí mismo las sugerencias nuevas (false cuando lo hace quien lo aloja). */
	announce?: boolean;
}

function failureText( reason: ApplyFailure ): string {
	switch ( reason ) {
		case 'not_found':
			return __(
				'The text has changed since the suggestion was made. Refresh the suggestions and try again.',
				'magic-linking'
			);
		case 'ambiguous':
			return __(
				'This sentence appears more than once, so the right place is unclear. Add the link by hand.',
				'magic-linking'
			);
		case 'already_linked':
			return __( 'This text is already a link.', 'magic-linking' );
		case 'text_mode':
			return __(
				'Switch to the Visual tab to add the link, or add it by hand.',
				'magic-linking'
			);
	}
}

/**
 * Pager de la lista de entrantes: Anterior, «Página 2 de 4», Siguiente.
 *
 * @param root0
 * @param root0.page
 * @param root0.totalPages
 * @param root0.onPage
 * @param root0.disabled
 */
function Pager( {
	page,
	totalPages,
	onPage,
	disabled,
}: {
	page: number;
	totalPages: number;
	onPage: ( page: number ) => void;
	disabled: boolean;
} ) {
	if ( totalPages <= 1 ) {
		return null;
	}
	return (
		<nav
			className="magiclinking-pager"
			aria-label={ __( 'Inbound suggestions pages', 'magic-linking' ) }
		>
			<Button
				variant="secondary"
				size="compact"
				disabled={ disabled || page <= 1 }
				accessibleWhenDisabled
				onClick={ () => onPage( page - 1 ) }
			>
				{ __( 'Previous', 'magic-linking' ) }
			</Button>
			<span aria-live="off">
				{ sprintf(
					/* translators: 1: current page, 2: total pages. */
					__( 'Page %1$s of %2$s', 'magic-linking' ),
					String( page ),
					String( totalPages )
				) }
			</span>
			<Button
				variant="secondary"
				size="compact"
				disabled={ disabled || page >= totalPages }
				accessibleWhenDisabled
				onClick={ () => onPage( page + 1 ) }
			>
				{ __( 'Next', 'magic-linking' ) }
			</Button>
		</nav>
	);
}

/**
 * Contenido del panel: pestañas Salientes y Entrantes (docs/07 §3), igual en Gutenberg y en el editor clásico.
 *
 * @param root0
 * @param root0.adapter
 * @param root0.boot
 * @param root0.state
 * @param root0.announce
 */
export function Panel( { adapter, boot, state, announce = true }: Props ) {
	const { outbound, inbound, statuses, setStatus } = state;

	// Anuncia el resultado cuando llegan sugerencias nuevas después de las primeras (no la carga inicial, que en
	// el editor clásico ocurre con el panel ya montado y se leería en cada carga de la pantalla).
	const announced = useRef< unknown >( outbound.data );
	useEffect( () => {
		if ( outbound.data && outbound.data !== announced.current ) {
			const initial = announced.current === null;
			announced.current = outbound.data;
			if ( initial || ! announce ) {
				return;
			}
			speak(
				sprintf(
					/* translators: %d: number of suggestions. */
					_n(
						'%d outbound suggestion',
						'%d outbound suggestions',
						outbound.data.total,
						'magic-linking'
					),
					outbound.data.total
				),
				'polite'
			);
		}
	}, [ outbound.data, announce ] );

	// Tras un Deshacer del usuario (enlace o descarte) la fila desaparece: el foco lo recoge la tarjeta que vuelve,
	// una sola vez y solo ella (Actualizar o cambiar de pestaña no mueven el foco). Caduca por si la tarjeta no vuelve.
	const [ focusKey, setFocusKey ] = useState< string | null >( null );
	const clearFocusKey = useCallback( () => setFocusKey( null ), [] );
	useEffect( () => {
		if ( ! focusKey ) {
			return undefined;
		}
		const timer = window.setTimeout( clearFocusKey, 4000 );
		return () => window.clearTimeout( timer );
	}, [ focusKey, clearFocusKey ] );

	const linkOutbound = (
		suggestion: Suggestion,
		ai: AiLinkInfo | null = null
	) => {
		const result = adapter.apply( suggestion );
		if ( result.ok ) {
			const message = __( 'Link added.', 'magic-linking' );
			// Al guardar la entrada se registra en el Historial; si se deshace aquí antes, deja de contar.
			const insertion = state.trackInsertion(
				suggestion,
				result.anchor,
				ai
			);
			setStatus( suggestion, {
				kind: 'added',
				message,
				undo: () => {
					const removed = result.undo();
					if ( removed ) {
						state.forgetInsertion( insertion );
					}
					return Promise.resolve(
						removed ? __( 'Link removed.', 'magic-linking' ) : null
					);
				},
			} );
			speak( message, 'polite' );
			return;
		}
		const message = failureText( result.reason );
		setStatus( suggestion, { kind: 'error', message } );
		speak( message, 'assertive' );
	};

	const linkInbound = async ( suggestion: Suggestion ) => {
		if ( ! suggestion.insert ) {
			return;
		}
		setStatus( suggestion, { kind: 'busy' } );
		try {
			const response = await api.insertLinks( [ suggestion.insert ] );
			const result = response.results[ 0 ];
			if ( result?.status === 'inserted' && response.batch_id ) {
				const batch = response.batch_id;
				const message = sprintf(
					/* translators: %s: title of the entry that got the link. */
					__( 'Link added in “%s”.', 'magic-linking' ),
					suggestion.source.title ?? ''
				);
				setStatus( suggestion, {
					kind: 'added',
					message,
					undo: async () => {
						const undone = await api.runHistory( 'undo', {
							batchId: batch,
						} );
						const first = undone.results[ 0 ];
						return first &&
							[
								'restored',
								'link_removed',
								'already_gone',
							].includes( first.status )
							? __( 'Link removed.', 'magic-linking' )
							: null;
					},
				} );
				speak( message, 'polite' );
				return;
			}
			const message =
				result?.message ||
				__( 'The link could not be added.', 'magic-linking' );
			setStatus( suggestion, { kind: 'error', message } );
			speak( message, 'assertive' );
		} catch ( error ) {
			const message = errorMessage(
				error,
				__(
					'The link could not be added. Check your connection and try again.',
					'magic-linking'
				)
			);
			setStatus( suggestion, { kind: 'error', message } );
			speak( message, 'assertive' );
		}
	};

	const undo = async ( suggestion: Suggestion ) => {
		const status = statuses[ suggestionKey( suggestion ) ];
		if ( status?.kind !== 'added' || ! status.undo ) {
			return;
		}
		try {
			const message = await status.undo();
			if ( message ) {
				setFocusKey( suggestionKey( suggestion ) );
				setStatus( suggestion, null );
				speak( message, 'polite' );
				return;
			}
			const fallback = __(
				'The text has changed since the link was added. Use Undo in the editor or remove the link by hand.',
				'magic-linking'
			);
			setStatus( suggestion, { kind: 'error', message: fallback } );
			speak( fallback, 'assertive' );
		} catch ( error ) {
			const message = errorMessage(
				error,
				__( 'The link could not be removed.', 'magic-linking' )
			);
			setStatus( suggestion, { kind: 'error', message } );
			speak( message, 'assertive' );
		}
	};

	const dismiss = async (
		suggestion: Suggestion,
		direction: 'outbound' | 'inbound'
	) => {
		setStatus( suggestion, { kind: 'dismissing' } );
		try {
			await api.dismissSuggestion(
				suggestion.source.id,
				suggestion.target.id
			);
		} catch ( error ) {
			const message = errorMessage(
				error,
				__(
					'The suggestion could not be dismissed. Check your connection and try again.',
					'magic-linking'
				)
			);
			setStatus( suggestion, { kind: 'error', message } );
			speak( message, 'assertive' );
			return;
		}
		adapter.highlight( null );
		if ( direction === 'inbound' ) {
			// Los elementos de las páginas siguientes suben: se vuelve a pedir la página para no saltarse ninguno.
			const index = state.inboundItems.findIndex(
				( item ) =>
					suggestionKey( item ) === suggestionKey( suggestion )
			);
			setStatus( suggestion, {
				kind: 'dismissed',
				ghost: { suggestion, index: Math.max( 0, index ) },
			} );
			inbound.refresh();
		} else {
			setStatus( suggestion, { kind: 'dismissed' } );
		}
		speak(
			__(
				'Suggestion dismissed. It will not be proposed again.',
				'magic-linking'
			),
			'polite'
		);
	};

	const restore = async (
		suggestion: Suggestion,
		direction: 'outbound' | 'inbound'
	) => {
		const previous = statuses[ suggestionKey( suggestion ) ];
		setStatus( suggestion, {
			kind: 'dismissed',
			busy: true,
			ghost: previous?.kind === 'dismissed' ? previous.ghost : undefined,
		} );
		try {
			await api.restoreSuggestion(
				suggestion.source.id,
				suggestion.target.id
			);
		} catch ( error ) {
			setStatus( suggestion, previous ?? { kind: 'dismissed' } );
			speak(
				errorMessage(
					error,
					__(
						'The dismissal could not be undone. Check your connection and try again.',
						'magic-linking'
					)
				),
				'assertive'
			);
			return;
		}
		setFocusKey( suggestionKey( suggestion ) );
		setStatus( suggestion, null );
		if ( direction === 'inbound' ) {
			inbound.refresh();
		}
		speak( __( 'Suggestion restored.', 'magic-linking' ), 'polite' );
	};

	const outboundCount = state.visibleOutbound.length;
	const inboundTotal = state.inboundCount;

	const tabs = [
		{
			name: 'outbound',
			title: `${ __(
				'Outbound',
				'magic-linking'
			) } (${ outboundCount })`,
		},
		{
			name: 'inbound',
			title: `${ __( 'Inbound', 'magic-linking' ) } (${ inboundTotal })`,
		},
	];

	return (
		<div className="magiclinking-panel">
			<TabPanel
				className="magiclinking-panel__tabs"
				tabs={ tabs }
				onSelect={ () => adapter.highlight( null ) }
			>
				{ ( tab ) =>
					tab.name === 'outbound' ? (
						<div className="magiclinking-panel__list">
							<div className="magiclinking-panel__toolbar">
								<Button
									variant="secondary"
									size="compact"
									onClick={ state.refreshOutbound }
									disabled={ outbound.loading }
									accessibleWhenDisabled
								>
									{ __( 'Refresh', 'magic-linking' ) }
								</Button>
								<span className="magiclinking-panel__hint">
									{ __(
										'Analyzes what you have in the editor now, saved or not.',
										'magic-linking'
									) }
								</span>
							</div>
							<AiBar ai={ state.ai } />
							{ outbound.error && (
								<ErrorState
									message={ outbound.error }
									onRetry={ state.refreshOutbound }
								/>
							) }
							{ outbound.loading && ! outbound.data && (
								<Loading />
							) }
							{ outbound.data &&
								( outbound.data.items.length === 0 ? (
									! outbound.loading && (
										<EmptyState
											state={ outbound.data.state }
											direction="outbound"
											reportUrl={ boot.reportUrl }
										/>
									)
								) : (
									<ul
										className="magiclinking-panel__cards"
										aria-busy={
											outbound.loading ||
											state.ai.phase === 'loading'
										}
									>
										{ state.ai
											.view( outbound.data.items )
											.map(
												( {
													item,
													original,
													mark,
													aiAnchor,
												} ) => (
													<SuggestionCard
														key={ suggestionKey(
															original
														) }
														suggestion={ item }
														ai={ mark }
														onUseAnchor={ (
															improved
														) =>
															state.ai.acceptAnchor(
																original,
																improved
															)
														}
														direction="outbound"
														status={
															statuses[
																suggestionKey(
																	item
																)
															]
														}
														historyUrl={
															boot.historyUrl
														}
														onLink={ ( card ) =>
															linkOutbound(
																card,
																// Si la IA la recomendaba, se anota para el Historial (F1-38).
																aiLinkInfo(
																	mark,
																	aiAnchor
																)
															)
														}
														onDismiss={ ( card ) =>
															dismiss(
																card,
																'outbound'
															)
														}
														onRestore={ ( card ) =>
															restore(
																card,
																'outbound'
															)
														}
														onUndo={ undo }
														focusRequested={
															focusKey ===
															suggestionKey(
																item
															)
														}
														onFocusDone={
															clearFocusKey
														}
														onHighlight={
															adapter.highlight
														}
													/>
												)
											) }
									</ul>
								) ) }
						</div>
					) : (
						<div className="magiclinking-panel__list">
							<div className="magiclinking-panel__toolbar">
								<Button
									variant="secondary"
									size="compact"
									onClick={ inbound.refresh }
									disabled={ inbound.loading }
									accessibleWhenDisabled
								>
									{ __( 'Refresh', 'magic-linking' ) }
								</Button>
								<span className="magiclinking-panel__hint">
									{ __(
										'Sentences in other entries where a link to this one fits.',
										'magic-linking'
									) }
								</span>
							</div>
							{ inbound.error && (
								<ErrorState
									message={ inbound.error }
									onRetry={ inbound.refresh }
								/>
							) }
							{ inbound.loading && ! inbound.data && <Loading /> }
							{ inbound.data &&
								( state.inboundItems.length === 0 ? (
									! inbound.loading &&
									( inbound.data.total > 0 ? (
										// La página pedida ya no existe (se enlazó la última de ella): se vuelve a pedir la última.
										<Loading />
									) : (
										<EmptyState
											state={ inbound.data.state }
											direction="inbound"
											reportUrl={ boot.reportUrl }
										/>
									) )
								) : (
									<>
										<ul
											className="magiclinking-panel__cards"
											aria-busy={ inbound.loading }
										>
											{ state.inboundItems.map(
												( item ) => (
													<SuggestionCard
														key={ suggestionKey(
															item
														) }
														suggestion={ item }
														direction="inbound"
														status={
															statuses[
																suggestionKey(
																	item
																)
															]
														}
														historyUrl={
															boot.historyUrl
														}
														onLink={ linkInbound }
														onDismiss={ ( card ) =>
															dismiss(
																card,
																'inbound'
															)
														}
														onRestore={ ( card ) =>
															restore(
																card,
																'inbound'
															)
														}
														onUndo={ undo }
														focusRequested={
															focusKey ===
															suggestionKey(
																item
															)
														}
														onFocusDone={
															clearFocusKey
														}
													/>
												)
											) }
										</ul>
										<Pager
											page={ state.page }
											totalPages={
												inbound.data.total_pages
											}
											disabled={ inbound.loading }
											onPage={ ( next ) => {
												state.setPage( next );
												speak(
													sprintf(
														/* translators: 1: current page, 2: total pages. */
														__(
															'Page %1$s of %2$s',
															'magic-linking'
														),
														String( next ),
														String(
															inbound.data
																?.total_pages ??
																next
														)
													),
													'polite'
												);
											} }
										/>
									</>
								) ) }
						</div>
					)
				}
			</TabPanel>
		</div>
	);
}
