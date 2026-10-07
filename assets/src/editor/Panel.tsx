import { useEffect, useRef } from '@wordpress/element';
import { Button, TabPanel } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
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
 */
export function Panel( { adapter, boot, state }: Props ) {
	const { outbound, inbound, statuses, setStatus } = state;

	// Anuncia el resultado cuando llegan sugerencias nuevas después de las primeras (no la carga inicial, que en
	// el editor clásico ocurre con el panel ya montado y se leería en cada carga de la pantalla).
	const announced = useRef< unknown >( outbound.data );
	useEffect( () => {
		if ( outbound.data && outbound.data !== announced.current ) {
			const initial = announced.current === null;
			announced.current = outbound.data;
			if ( initial ) {
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
	}, [ outbound.data ] );

	// Al descartar, el botón pulsado desaparece con la tarjeta: el foco vuelve a la lista.
	const listRef = useRef< HTMLDivElement >( null );

	const linkOutbound = ( suggestion: Suggestion ) => {
		const result = adapter.apply( suggestion );
		if ( result.ok ) {
			const message = __( 'Link added.', 'magic-linking' );
			setStatus( suggestion, {
				kind: 'added',
				message,
				undo: () =>
					Promise.resolve(
						result.undo()
							? __( 'Link removed.', 'magic-linking' )
							: null
					),
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

	const dismiss = (
		suggestion: Suggestion,
		direction: 'outbound' | 'inbound'
	) => {
		state.dismiss( suggestion, direction );
		speak( __( 'Suggestion dismissed.', 'magic-linking' ), 'polite' );
		listRef.current?.focus();
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
						<div
							className="magiclinking-panel__list"
							ref={ listRef }
							tabIndex={ -1 }
						>
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
								( outboundCount === 0 ? (
									! outbound.loading && (
										<EmptyState
											state={ outbound.data.state }
											direction="outbound"
											reportUrl={ boot.reportUrl }
											dismissedAll={
												outbound.data.items.length > 0
											}
										/>
									)
								) : (
									<ul
										className="magiclinking-panel__cards"
										aria-busy={ outbound.loading }
									>
										{ state.visibleOutbound.map(
											( item ) => (
												<SuggestionCard
													key={ suggestionKey(
														item
													) }
													suggestion={ item }
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
													onLink={ linkOutbound }
													onDismiss={ ( card ) =>
														dismiss(
															card,
															'outbound'
														)
													}
													onUndo={ undo }
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
						<div
							className="magiclinking-panel__list"
							ref={ listRef }
							tabIndex={ -1 }
						>
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
								( state.visibleInbound.length === 0 ? (
									! inbound.loading &&
									( inbound.data.total > 0 &&
									inbound.data.items.length === 0 ? (
										// La página pedida ya no existe (se enlazó la última de ella): se vuelve a pedir la última.
										<Loading />
									) : (
										<EmptyState
											state={ inbound.data.state }
											direction="inbound"
											reportUrl={ boot.reportUrl }
											dismissedAll={
												inbound.data.items.length > 0
											}
										/>
									) )
								) : (
									<>
										<ul
											className="magiclinking-panel__cards"
											aria-busy={ inbound.loading }
										>
											{ state.visibleInbound.map(
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
														onUndo={ undo }
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
