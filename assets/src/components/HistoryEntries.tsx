import { Notice } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { api } from '../api';
import { useDebounced, useLoad } from '../hooks';
import type { HistoryEntriesResponse, HistoryStatusFilter } from '../types';
import { HistoryEntryGroup } from './HistoryEntryGroup';
import { Pagination } from './Pagination';

const PER_PAGE = 10;

interface Props {
	/** Conmutador «Ver por», que va al principio de la barra de herramientas. */
	leading: ReactNode;
	/** Avisa de cuánto se conserva el historial (lo trae la lista). */
	onRetention: ( days: number ) => void;
}

/**
 * Vista por entrada del Historial: los cambios agrupados por entrada, con filtros y paginación del servidor.
 * @param root0
 * @param root0.onRetention
 * @param root0.leading
 */
export function HistoryEntries( { onRetention, leading }: Props ) {
	const [ page, setPage ] = useState( 1 );
	const [ status, setStatus ] = useState< HistoryStatusFilter >( 'all' );
	const [ origin, setOrigin ] = useState( '' );
	const [ ai, setAi ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ version, setVersion ] = useState( 0 );
	const debounced = useDebounced( search );
	const list = useRef< HTMLDivElement >( null );
	const announce = useRef( false );

	const { data, loading, error } = useLoad(
		( signal ) =>
			api.historyEntries(
				{
					page,
					perPage: PER_PAGE,
					status,
					origin,
					ai,
					search: debounced,
				},
				signal
			),
		[ page, status, origin, ai, debounced, version ],
		__( 'Could not load the history.', 'magic-linking' )
	);

	// Los cambios hechos desde una entrada se reflejan en memoria; el resto llega con la lista del servidor.
	const [ local, setLocal ] = useState< HistoryEntriesResponse | null >(
		null
	);
	useEffect( () => {
		setLocal( data );
		if ( data ) {
			onRetention( data.retention_days );
		}
	}, [ data, onRetention ] );

	// Al cambiar de página o de filtros se anuncia el resultado; la carga inicial no.
	useEffect( () => {
		if ( ! data || ! announce.current ) {
			return;
		}
		announce.current = false;
		speak(
			data.total === 0
				? __( 'No entries found.', 'magic-linking' )
				: sprintf(
						/* translators: %s: number of entries. */
						_n(
							'%s entry with changes.',
							'%s entries with changes.',
							data.total,
							'magic-linking'
						),
						data.total.toLocaleString()
				  ),
			'polite'
		);
	}, [ data ] );

	const filter =
		< T, >( setter: ( value: T ) => void ) =>
		( value: T ) => {
			announce.current = true;
			setPage( 1 );
			setter( value );
		};

	const goTo = ( next: number ) => {
		announce.current = false;
		setPage( next );
		// El foco pasa al principio de la lista nueva.
		requestAnimationFrame( () => list.current?.focus() );
	};

	const onEntry = useCallback(
		(
			postId: number,
			entry: HistoryEntriesResponse[ 'items' ][ number ] | null
		) => {
			// Con un filtro de estado, una entrada puede dejar de cumplirlo: se vuelve a pedir la página.
			if ( ! entry || status !== 'all' ) {
				setVersion( ( previous ) => previous + 1 );
				requestAnimationFrame( () => list.current?.focus() );
				return;
			}
			setLocal( ( previous ) =>
				previous
					? {
							...previous,
							items: previous.items.map( ( item ) =>
								item.post_id === postId ? entry : item
							),
					  }
					: previous
			);
		},
		[ status ]
	);

	// Si tras deshacer o rehacer con filtro la página ya no existe, se va a la última que quede.
	useEffect( () => {
		if ( data && page > data.total_pages ) {
			setPage( Math.max( 1, data.total_pages ) );
		}
	}, [ data, page ] );

	const items = local?.items ?? [];
	const total = local?.total ?? 0;
	const totalPages = local?.total_pages ?? 1;
	const filtered =
		status !== 'all' || origin !== '' || ai || debounced !== '';

	const toolbar = (
		<div className="magiclinking-toolbar">
			{ leading }
			<div className="magiclinking-toolbar__filters">
				<label
					htmlFor="magiclinking-history-status"
					className="screen-reader-text"
				>
					{ __( 'Show entries with', 'magic-linking' ) }
				</label>
				<select
					id="magiclinking-history-status"
					value={ status }
					onChange={ ( event ) =>
						filter( setStatus )(
							event.target.value as HistoryStatusFilter
						)
					}
				>
					<option value="all">
						{ __( 'All changes', 'magic-linking' ) }
					</option>
					<option value="active">
						{ __( 'With changes in place', 'magic-linking' ) }
					</option>
					<option value="undone">
						{ __( 'With undone changes', 'magic-linking' ) }
					</option>
				</select>
				<label
					htmlFor="magiclinking-history-origin"
					className="screen-reader-text"
				>
					{ __( 'Source', 'magic-linking' ) }
				</label>
				<select
					id="magiclinking-history-origin"
					value={ origin }
					onChange={ ( event ) =>
						filter( setOrigin )( event.target.value )
					}
				>
					<option value="">
						{ __( 'All sources', 'magic-linking' ) }
					</option>
					<option value="editor">
						{ _x(
							'Editor panel',
							'history origin',
							'magic-linking'
						) }
					</option>
					<option value="plugin">
						{ _x(
							"Another entry's panel and broken links",
							'history origin filter',
							'magic-linking'
						) }
					</option>
				</select>
				<label htmlFor="magiclinking-history-ai">
					<input
						type="checkbox"
						id="magiclinking-history-ai"
						checked={ ai }
						onChange={ ( event ) =>
							filter( setAi )( event.target.checked )
						}
					/>{ ' ' }
					{ _x(
						'Recommended by the AI',
						'history origin filter',
						'magic-linking'
					) }
				</label>
				<div role="search" className="magiclinking-toolbar__search">
					<label
						htmlFor="magiclinking-history-search"
						className="screen-reader-text"
					>
						{ __( 'Search entries by title', 'magic-linking' ) }
					</label>
					<input
						type="search"
						id="magiclinking-history-search"
						placeholder={ __( 'Search entries…', 'magic-linking' ) }
						value={ search }
						onChange={ ( event ) =>
							filter( setSearch )( event.target.value )
						}
					/>
				</div>
			</div>
		</div>
	);

	const bar = ( position: 'top' | 'bottom' ) => (
		<div className={ `tablenav ${ position }` }>
			<Pagination
				noun="entries"
				top={ position === 'top' }
				page={ page }
				totalPages={ totalPages }
				total={ total }
				onPage={ goTo }
			/>
			<br className="clear" />
		</div>
	);

	return (
		<div className="magiclinking-entries">
			{ toolbar }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ bar( 'top' ) }

			{ items.length > 0 && (
				<div className="magiclinking-entries__head" aria-hidden="true">
					<span>{ __( 'Date', 'magic-linking' ) }</span>
					<span>{ __( 'Change', 'magic-linking' ) }</span>
					<span>{ __( 'Anchor text', 'magic-linking' ) }</span>
					<span>{ __( 'Links to', 'magic-linking' ) }</span>
					<span>{ __( 'Source', 'magic-linking' ) }</span>
					<span>{ __( 'User', 'magic-linking' ) }</span>
					<span>{ __( 'Status', 'magic-linking' ) }</span>
					<span />
				</div>
			) }

			<div
				className="magiclinking-entries__list"
				ref={ list }
				tabIndex={ -1 }
				aria-busy={ loading }
			>
				{ loading && items.length === 0 && (
					<p>{ __( 'Loading…', 'magic-linking' ) }</p>
				) }
				{ ! loading && items.length === 0 && ! error && (
					<p>
						{ filtered
							? __(
									'No entries match these filters.',
									'magic-linking'
							  )
							: __(
									'No changes yet. When you add a link with Magic Linking it will appear here, and you will be able to undo it.',
									'magic-linking'
							  ) }
					</p>
				) }
				{ items.map( ( entry ) => (
					<HistoryEntryGroup
						key={ entry.post_id }
						entry={ entry }
						origin={ origin }
						ai={ ai }
						onChange={ onEntry }
					/>
				) ) }
			</div>

			{ items.length > 0 && bar( 'bottom' ) }
		</div>
	);
}
