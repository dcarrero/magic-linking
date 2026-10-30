import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import { useLoad } from '../hooks';
import type { Suggestion } from '../types';
import { suggestionKey } from '../editor/keys';
import { Pager } from '../editor/Pager';
import { SuggestionCard, type CardStatus } from '../editor/SuggestionCard';
import { EmptyState, ErrorState, Loading } from '../editor/States';

/** Sugerencias salientes por página (el servidor las da todas juntas). */
const OUTBOUND_PER_PAGE = 10;

export type SuggestionDirection = 'outbound' | 'inbound';

interface Props {
	/** Entrada de la fila del informe. */
	entry: { id: number; title: string };
	direction: SuggestionDirection;
	/** Dirección de la pantalla Historial. */
	historyUrl: string;
	/** Dirección del informe, para el estado vacío. */
	reportUrl: string;
	onClose: () => void;
	/** El grafo cambió (se añadió o deshizo un enlace): entradas cuyas cifras hay que refrescar. */
	onChanged: ( ids: number[] ) => void;
}

/**
 * Panel lateral del informe con las sugerencias entrantes o salientes de una entrada (docs/02 §4.3, docs/07 §4).
 * Cada «Enlazar» es una inserción individual en el servidor (`POST /links`) con su verificación, su diff en el
 * Historial y su *Deshacer*; no hay casillas ni «enlazar todas». El panel nunca manda HTML ni posiciones de
 * confianza: reenvía el `insert` que dio el servidor y este vuelve a localizar la frase en el contenido guardado.
 *
 * @param root0
 * @param root0.entry
 * @param root0.direction
 * @param root0.historyUrl
 * @param root0.reportUrl
 * @param root0.onClose
 * @param root0.onChanged
 */
export function SuggestionsDrawer( {
	entry,
	direction,
	historyUrl,
	reportUrl,
	onClose,
	onChanged,
}: Props ) {
	const inbound = direction === 'inbound';
	const [ page, setPage ] = useState( 1 );
	const [ version, setVersion ] = useState( 0 );
	const [ statuses, setStatuses ] = useState< Record< string, CardStatus > >(
		{}
	);
	const [ focusKey, setFocusKey ] = useState< string | null >( null );
	const clearFocusKey = useCallback( () => setFocusKey( null ), [] );

	const { data, loading, error } = useLoad(
		( signal ) =>
			inbound
				? api.inbound( entry.id, page, 0, signal )
				: api.outbound( entry.id, signal ),
		[ inbound, entry.id, page, version ],
		__( 'The suggestions could not be loaded.', 'magic-linking' )
	);

	// Anuncia el resultado cuando llega una carga nueva.
	const announced = useRef< unknown >( null );
	useEffect( () => {
		if ( ! data || data === announced.current ) {
			return;
		}
		announced.current = data;
		speak(
			sprintf(
				/* translators: %d: number of suggestions. */
				_n(
					'%d suggestion',
					'%d suggestions',
					data.total,
					'magic-linking'
				),
				data.total
			),
			'polite'
		);
	}, [ data ] );

	// Si se enlaza o descarta lo último de una página y esta deja de existir, se vuelve a la última.
	const totalPages = inbound
		? ( data as { total_pages?: number } | null )?.total_pages ?? 1
		: Math.max(
				1,
				Math.ceil( ( data?.items.length ?? 0 ) / OUTBOUND_PER_PAGE )
		  );
	useEffect( () => {
		if ( ! loading && page > totalPages ) {
			setPage( totalPages );
		}
	}, [ loading, page, totalPages ] );

	const setStatus = useCallback(
		( suggestion: Suggestion, status: CardStatus | null ) => {
			const key = suggestionKey( suggestion );
			setStatuses( ( previous ) => {
				const next = { ...previous };
				if ( status ) {
					next[ key ] = status;
				} else {
					delete next[ key ];
				}
				return next;
			} );
		},
		[]
	);

	const items = ( data?.items ?? [] ).slice(
		inbound ? 0 : ( page - 1 ) * OUTBOUND_PER_PAGE,
		inbound ? undefined : page * OUTBOUND_PER_PAGE
	);

	const link = async ( suggestion: Suggestion ) => {
		if ( ! suggestion.insert ) {
			return;
		}
		setStatus( suggestion, { kind: 'busy' } );
		try {
			const response = await api.insertLinks( [ suggestion.insert ] );
			const result = response.results[ 0 ];
			if ( result?.status === 'inserted' && response.batch_id ) {
				const batch = response.batch_id;
				const message = inbound
					? sprintf(
							/* translators: %s: title of the entry that got the link. */
							__( 'Link added in “%s”.', 'magic-linking' ),
							suggestion.source.title ?? ''
					  )
					: sprintf(
							/* translators: %s: title of the destination. */
							__( 'Link to “%s” added.', 'magic-linking' ),
							suggestion.target.title ?? ''
					  );
				const ids = [ suggestion.source.id, suggestion.target.id ];
				setStatus( suggestion, {
					kind: 'added',
					message,
					undo: async () => {
						const undone = await api.runHistory( 'undo', {
							batchId: batch,
						} );
						const first = undone.results[ 0 ];
						const ok =
							!! first &&
							[
								'restored',
								'link_removed',
								'already_gone',
							].includes( first.status );
						// Haya salido bien o no, el servidor puede haber cambiado: se vuelven a leer las cifras.
						onChanged( ids );
						return ok
							? __( 'Link removed.', 'magic-linking' )
							: null;
					},
				} );
				onChanged( ids );
				speak( message, 'polite' );
				return;
			}
			const message =
				result?.message ||
				__( 'The link could not be added.', 'magic-linking' );
			setStatus( suggestion, { kind: 'error', message } );
			speak( message, 'assertive' );
		} catch ( failure ) {
			const message = errorMessage(
				failure,
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
				'The text has changed since the link was added, so it could not be removed automatically. Open the history to see why.',
				'magic-linking'
			);
			setStatus( suggestion, { kind: 'error', message: fallback } );
			speak( fallback, 'assertive' );
		} catch ( failure ) {
			const message = errorMessage(
				failure,
				__( 'The link could not be removed.', 'magic-linking' )
			);
			setStatus( suggestion, { kind: 'error', message } );
			speak( message, 'assertive' );
		}
	};

	const dismiss = async ( suggestion: Suggestion ) => {
		setStatus( suggestion, { kind: 'dismissing' } );
		try {
			await api.dismissSuggestion(
				suggestion.source.id,
				suggestion.target.id
			);
		} catch ( failure ) {
			const message = errorMessage(
				failure,
				__(
					'The suggestion could not be dismissed. Check your connection and try again.',
					'magic-linking'
				)
			);
			setStatus( suggestion, { kind: 'error', message } );
			speak( message, 'assertive' );
			return;
		}
		setStatus( suggestion, { kind: 'dismissed' } );
		speak(
			__(
				'Suggestion dismissed. It will not be proposed again.',
				'magic-linking'
			),
			'polite'
		);
	};

	const restore = async ( suggestion: Suggestion ) => {
		setStatus( suggestion, { kind: 'dismissed', busy: true } );
		try {
			await api.restoreSuggestion(
				suggestion.source.id,
				suggestion.target.id
			);
		} catch ( failure ) {
			setStatus( suggestion, { kind: 'dismissed' } );
			speak(
				errorMessage(
					failure,
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
		speak( __( 'Suggestion restored.', 'magic-linking' ), 'polite' );
	};

	const refresh = () => {
		setStatuses( {} );
		setVersion( ( value ) => value + 1 );
	};

	const changePage = ( next: number ) => {
		setStatuses( {} );
		setPage( next );
		speak(
			sprintf(
				/* translators: 1: current page, 2: total pages. */
				__( 'Page %1$s of %2$s', 'magic-linking' ),
				String( next ),
				String( totalPages )
			),
			'polite'
		);
	};

	const title = entry.title || __( '(no title)', 'magic-linking' );

	return (
		<Modal
			title={
				inbound
					? sprintf(
							/* translators: %s: entry title. */
							__( 'Inbound suggestions: %s', 'magic-linking' ),
							title
					  )
					: sprintf(
							/* translators: %s: entry title. */
							__( 'Outbound suggestions: %s', 'magic-linking' ),
							title
					  )
			}
			onRequestClose={ onClose }
			className="magiclinking-drawer"
			overlayClassName="magiclinking-drawer-overlay"
		>
			<div className="magiclinking-drawer__body">
				<p className="description">
					{ inbound
						? __(
								'Sentences in other entries where a link to this one fits. Each link is added right away, one at a time, without opening the editor of that entry, and you can undo it.',
								'magic-linking'
						  )
						: __(
								'Sentences in this entry where a link to another entry fits. Each link is added right away, one at a time, without opening the editor, and you can undo it.',
								'magic-linking'
						  ) }
				</p>
				<div className="magiclinking-panel__toolbar">
					<Button
						variant="secondary"
						size="compact"
						onClick={ refresh }
						disabled={ loading }
						accessibleWhenDisabled
					>
						{ __( 'Refresh', 'magic-linking' ) }
					</Button>
					{ data && data.total > 0 && (
						<span className="magiclinking-panel__hint">
							{ sprintf(
								/* translators: %d: number of suggestions. */
								_n(
									'%d suggestion',
									'%d suggestions',
									data.total,
									'magic-linking'
								),
								data.total
							) }
						</span>
					) }
				</div>
				{ error && (
					<ErrorState message={ error } onRetry={ refresh } />
				) }
				{ loading && ! data && <Loading /> }
				{ data &&
					( data.items.length === 0 ? (
						! loading && (
							<EmptyState
								state={ data.state }
								direction={ direction }
								reportUrl={ reportUrl }
							/>
						)
					) : (
						<>
							<ul
								className="magiclinking-panel__cards"
								aria-busy={ loading }
							>
								{ items.map( ( item ) => (
									<SuggestionCard
										key={ suggestionKey( item ) }
										suggestion={ item }
										direction={ direction }
										status={
											statuses[ suggestionKey( item ) ]
										}
										historyUrl={ historyUrl }
										onLink={ link }
										onDismiss={ dismiss }
										onRestore={ restore }
										onUndo={ undo }
										focusRequested={
											focusKey === suggestionKey( item )
										}
										onFocusDone={ clearFocusKey }
									/>
								) ) }
							</ul>
							<Pager
								label={
									inbound
										? __(
												'Inbound suggestions pages',
												'magic-linking'
										  )
										: __(
												'Outbound suggestions pages',
												'magic-linking'
										  )
								}
								page={ page }
								totalPages={ totalPages }
								disabled={ loading }
								onPage={ changePage }
							/>
						</>
					) ) }
			</div>
		</Modal>
	);
}
