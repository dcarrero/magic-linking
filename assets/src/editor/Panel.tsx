import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, TabPanel } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { AiBar } from './AiBar';
import { aiLinkInfo, type AiLinkInfo } from './ai';
import type { Suggestion } from '../types';
import type { ApplyFailure, EditorAdapter } from './adapters/types';
import type { EditorBoot } from './boot';
import { suggestionKey } from './keys';
import { Pager } from './Pager';
import { PillarBox } from './PillarBox';
import { SuggestionCard, type CardVariants } from './SuggestionCard';
import type { VariantView, Variants } from './useVariants';
import { optionsOf } from './variants';
import { useServerLinkActions } from './useServerLinkActions';
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
		case 'unsupported':
			return __(
				'This sentence is somewhere the panel cannot add a link (a block it does not edit, or a heading, quote, table or shortcode). Add the link by hand.',
				'magic-linking'
			);
		case 'crosses_format':
			return __(
				'The anchor crosses bold, italics or other formatting, so the link would break it. Add the link by hand.',
				'magic-linking'
			);
		case 'unsafe':
			return __(
				'The link could not be added without risking the rest of the block, so nothing was changed. Add it by hand.',
				'magic-linking'
			);
		case 'bad_target':
			return __(
				'The address of the destination is not valid, so the link was not added.',
				'magic-linking'
			);
		case 'unsynced':
			return __(
				'The Classic block has changes that are not applied yet. Save or leave the block and try again.',
				'magic-linking'
			);
		case 'text_mode':
			return __(
				'Switch to the Visual tab to add the link, or add it by hand.',
				'magic-linking'
			);
	}
}

/**
 * Lo que la tarjeta necesita para ofrecer *Más frases* y *Ajustar ancla*, con su anuncio al elegir.
 *
 * @param shown    La tarjeta con las elecciones aplicadas.
 * @param original La sugerencia tal como la dio el servidor.
 * @param variants Elecciones de la lista.
 */
function cardVariants(
	shown: VariantView,
	original: Suggestion,
	variants: Variants
): CardVariants {
	return {
		options: optionsOf( shown.base ),
		selected: shown.option,
		onSelect: ( option ) => {
			variants.choose( original, option );
			const chosen = optionsOf( shown.base )[ option ];
			speak(
				sprintf(
					/* translators: 1: position of the sentence, 2: number of sentences, 3: anchor text. */
					__(
						'Sentence %1$d of %2$d selected. Anchor: %3$s.',
						'magic-linking'
					),
					option + 1,
					optionsOf( shown.base ).length,
					chosen?.anchor ?? ''
				),
				'polite'
			);
		},
		onAdjust: ( span ) => {
			variants.adjust( original, span );
			speak(
				sprintf(
					/* translators: %s: anchor text. */
					__( 'Anchor set: %s.', 'magic-linking' ),
					shown.item.sentence.slice( span.start, span.end )
				),
				'polite'
			);
		},
	};
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

	const { link, undo, dismiss, restore } = useServerLinkActions( {
		statuses,
		setStatus,
		requestFocus: setFocusKey,
		undoFailedMessage: __(
			'The text has changed since the link was added. Use Undo in the editor or remove the link by hand.',
			'magic-linking'
		),
		inboundItems: state.inboundItems,
		refreshInbound: inbound.refresh,
		onDismissed: () => adapter.highlight( null ),
	} );
	const linkInbound = ( suggestion: Suggestion ) =>
		link( suggestion, 'inbound' );

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
											required={ outbound.data.required }
											settingsUrl={ boot.settingsUrl }
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
												} ) => {
													const shown =
														state.outVariants.view(
															item,
															original
														);
													if ( ! shown ) {
														return null;
													}
													// Con otra frase u otro ancla elegidos, lo que opinó la IA ya no se refiere a lo que se ve.
													const aiMark = shown.changed
														? undefined
														: mark;
													return (
														<SuggestionCard
															key={ suggestionKey(
																original
															) }
															suggestion={
																shown.item
															}
															variants={ cardVariants(
																shown,
																original,
																state.outVariants
															) }
															ai={ aiMark }
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
																		shown.item
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
																	shown.changed
																		? null
																		: aiLinkInfo(
																				mark,
																				aiAnchor
																		  )
																)
															}
															onDismiss={ (
																card
															) =>
																dismiss(
																	card,
																	'outbound'
																)
															}
															onRestore={ (
																card
															) =>
																restore(
																	card,
																	'outbound'
																)
															}
															onUndo={ undo }
															focusRequested={
																focusKey ===
																suggestionKey(
																	shown.item
																)
															}
															onFocusDone={
																clearFocusKey
															}
															onHighlight={
																adapter.highlight
															}
														/>
													);
												}
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
											required={ inbound.data.required }
											settingsUrl={ boot.settingsUrl }
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
												( item ) => {
													const row =
														state.inboundRowOf.get(
															suggestionKey(
																item
															)
														);
													return (
														<SuggestionCard
															key={ suggestionKey(
																row?.original ??
																	item
															) }
															suggestion={ item }
															variants={
																row
																	? cardVariants(
																			row,
																			row.original,
																			state.inVariants
																	  )
																	: undefined
															}
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
															onLink={
																linkInbound
															}
															onDismiss={ (
																card
															) =>
																dismiss(
																	card,
																	'inbound'
																)
															}
															onRestore={ (
																card
															) =>
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
													);
												}
											) }
										</ul>
										<Pager
											label={ __(
												'Inbound suggestions pages',
												'magic-linking'
											) }
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
			<PillarBox postId={ boot.postId } />
		</div>
	);
}
