import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { useLoad } from '../hooks';
import type { Suggestion } from '../types';
import { withGhosts, withoutGhosts } from '../editor/ghosts';
import { suggestionKey } from '../editor/keys';
import { Pager } from '../editor/Pager';
import { SuggestionCard, type CardStatus } from '../editor/SuggestionCard';
import { EmptyState, ErrorState, Loading } from '../editor/States';
import { useServerLinkActions } from '../editor/useServerLinkActions';

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
	// Qué anunciar cuando lleguen datos nuevos: el total (al abrir y con *Refresh*) o la página (al cambiarla).
	const announce = useRef< 'count' | 'page' | null >( 'count' );
	// El estado de las tarjetas se limpia cuando llega la lista nueva, no antes: mientras tanto siguen las viejas.
	const clearOnArrival = useRef( false );

	const { data, loading, error } = useLoad(
		( signal ) =>
			inbound
				? api.inbound( entry.id, page, 0, signal )
				: api.outbound( entry.id, signal ),
		[ inbound, entry.id, page, version ],
		__( 'The suggestions could not be loaded.', 'magic-linking' )
	);

	// Una sola frase por carga: «Página 2 de 3» al cambiar de página y el total al abrir o refrescar. Lo que
	// cambia al enlazar o descartar ya lo dice su propio anuncio.
	const seen = useRef< unknown >( null );
	useEffect( () => {
		if ( ! data || data === seen.current ) {
			return;
		}
		seen.current = data;
		if ( clearOnArrival.current ) {
			clearOnArrival.current = false;
			setStatuses( {} );
		}
		const kind = announce.current;
		announce.current = null;
		if ( kind === 'count' ) {
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
		} else if ( kind === 'page' ) {
			const pages = inbound
				? ( data as { total_pages?: number } ).total_pages ?? 1
				: Math.max(
						1,
						Math.ceil( data.items.length / OUTBOUND_PER_PAGE )
				  );
			speak(
				sprintf(
					/* translators: 1: current page, 2: total pages. */
					__( 'Page %1$s of %2$s', 'magic-linking' ),
					String( page ),
					String( pages )
				),
				'polite'
			);
		}
	}, [ data, inbound, page ] );

	const totalPages = inbound
		? ( data as { total_pages?: number } | null )?.total_pages ?? 1
		: Math.max(
				1,
				Math.ceil( ( data?.items.length ?? 0 ) / OUTBOUND_PER_PAGE )
		  );
	// Si se enlaza o descarta lo último de una página y esta deja de existir, se vuelve a la última.
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

	// Entrantes: lo enlazado o descartado ya no lo devuelve el servidor y se pinta donde estaba. Salientes: la lista
	// es del cliente y se parte en páginas; el estado de cada tarjeta es el de su par origen-destino en todas ellas.
	const all = inbound
		? withGhosts( data?.items ?? [], statuses )
		: data?.items ?? [];
	const items = inbound
		? all
		: all.slice(
				( page - 1 ) * OUTBOUND_PER_PAGE,
				page * OUTBOUND_PER_PAGE
		  );

	const refreshInbound = useCallback(
		() => setVersion( ( v ) => v + 1 ),
		[]
	);
	const actions = useServerLinkActions( {
		statuses,
		setStatus,
		requestFocus: setFocusKey,
		undoFailedMessage: __(
			'The text has changed since the link was added, so it could not be removed automatically. Open the history to see why.',
			'magic-linking'
		),
		inboundItems: all,
		refreshInbound,
		ghostOnLink: true,
		onChanged,
	} );

	const refresh = () => {
		clearOnArrival.current = true;
		announce.current = 'count';
		setVersion( ( value ) => value + 1 );
	};

	const changePage = ( next: number ) => {
		if ( inbound ) {
			setStatuses( withoutGhosts );
		}
		announce.current = 'page';
		setPage( next );
	};

	const title = entry.title || __( '(no title)', 'magic-linking' );
	// Mientras se vuelve a pedir la lista las tarjetas que se ven pueden estar ya hechas: no se pueden accionar.
	const locked = loading;

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
					( all.length === 0 ? (
						! loading &&
						( data.total > 0 ? (
							// La página pedida ya no existe (se enlazó o descartó la última de ella): se vuelve a la última.
							<Loading />
						) : (
							<EmptyState
								state={ data.state }
								direction={ direction }
								reportUrl={ reportUrl }
							/>
						) )
					) : (
						<>
							<ul
								className="magiclinking-panel__cards"
								aria-busy={ loading }
							>
								{ items.map( ( item ) => (
									<SuggestionCard
										locked={ locked }
										key={ suggestionKey( item ) }
										suggestion={ item }
										direction={ direction }
										status={
											statuses[ suggestionKey( item ) ]
										}
										historyUrl={ historyUrl }
										onLink={ ( card ) =>
											actions.link( card, direction )
										}
										onDismiss={ ( card ) =>
											actions.dismiss( card, direction )
										}
										onRestore={ ( card ) =>
											actions.restore( card, direction )
										}
										onUndo={ actions.undo }
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
