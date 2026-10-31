import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import type { ReactNode } from 'react';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { useDebounced, useIsFirstRender, useLoad } from '../hooks';
import type { ReportFilter, ReportItem, SortKey, Summary } from '../types';
import { Column, DataTable } from './DataTable';
import { dateTimeAttr, formatDate } from './historyFormat';
import {
	SuggestionsDrawer,
	type SuggestionDirection,
} from './SuggestionsDrawer';
import { SearchBox } from './SearchBox';

interface Props {
	refreshKey: number;
	exportUrl: string;
	onBroken: ( postId: number, title: string ) => void;
	onSummary: ( summary: Summary ) => void;
	/** Filtro con el que se abre la lista. */
	initialFilter?: ReportFilter;
	/** Dirección de la pantalla Historial (para el panel de sugerencias). */
	historyUrl?: string;
	/** Dirección del informe (para el estado vacío del panel de sugerencias). */
	reportUrl?: string;
}

// Celda sin cifra (las excluidas no están en el índice); un guion largo que un lector de pantalla lee como «no disponible».
const NO_FIGURE = (
	<>
		<span aria-hidden="true">{ '–' }</span>
		<span className="screen-reader-text">
			{ __( 'Not analyzed', 'magic-linking' ) }
		</span>
	</>
);

// Columnas por las que el servidor ordena las excluidas (no están en el índice: no tienen cifras).
const EXCLUDED_SORTS: SortKey[] = [ 'title', 'type' ];

const PER_PAGE = window.magiclinking?.perPage ?? 20;

const STATUS_LABEL: Record< ReportItem[ 'status' ], () => string > = {
	orphan: () => __( 'Orphan', 'magic-linking' ),
	low: () => __( 'Under-linked', 'magic-linking' ),
	over: () => __( 'Over-linked', 'magic-linking' ),
	ok: () => __( 'OK', 'magic-linking' ),
	excluded: () => __( 'Excluded', 'magic-linking' ),
};

/**
 * Pestaña del informe: filtros rápidos, búsqueda, tabla ordenable, paginación y CSV.
 * @param root0
 * @param root0.refreshKey
 * @param root0.exportUrl
 * @param root0.onBroken
 * @param root0.onSummary
 * @param root0.initialFilter
 * @param root0.historyUrl
 * @param root0.reportUrl
 */
export function ReportTab( {
	refreshKey,
	exportUrl,
	onBroken,
	onSummary,
	initialFilter = 'all',
	historyUrl = 'admin.php?page=magic-linking-history',
	reportUrl = 'admin.php?page=magic-linking',
}: Props ) {
	const [ filter, setFilter ] = useState< ReportFilter >( initialFilter );
	const [ search, setSearch ] = useState( '' );
	const [ postType, setPostType ] = useState( '' );
	const [ lang, setLang ] = useState( '' );
	const [ orderby, setOrderby ] = useState< SortKey >(
		initialFilter === 'excluded' ? 'title' : 'inbound'
	);
	const [ order, setOrder ] = useState< 'asc' | 'desc' >( 'asc' );
	const [ page, setPage ] = useState( 1 );
	const perPage = PER_PAGE;
	const debounced = useDebounced( search );
	const first = useIsFirstRender();
	const root = useRef< HTMLDivElement >( null );
	// Entrada cuya exclusión (o vuelta) se está guardando, y el aviso si falla; `reload` repite la carga tras un cambio.
	const [ toggling, setToggling ] = useState< ReadonlySet< number > >(
		new Set()
	);
	// Tras una acción de fila se anuncia ella sola: el «N entradas» de la recarga la pisaría.
	const quiet = useRef( false );
	const [ toggleError, setToggleError ] = useState( '' );
	const [ reload, setReload ] = useState( 0 );
	// Panel lateral de sugerencias de una fila y filas refrescadas desde el servidor tras añadir o deshacer un enlace.
	const [ panel, setPanel ] = useState< {
		row: ReportItem;
		direction: SuggestionDirection;
	} | null >( null );
	// Filas y resumen releídos del servidor tras un cambio; hasta la siguiente carga, mandan sobre los de la página.
	const [ fresh, setFresh ] = useState< {
		rows: Record< number, ReportItem >;
		summary: Summary | null;
	} >( { rows: {}, summary: null } );

	// En «Excluded» no hay cifras ni idioma: solo se ordena por título o tipo y no se filtra por idioma.
	const excludedView = filter === 'excluded';

	const query = {
		filter,
		search: debounced,
		post_type: postType,
		lang: excludedView ? '' : lang,
		orderby,
		order,
		page,
		per_page: perPage,
	};

	const { data, loading, error } = useLoad(
		( signal ) => api.report( query, signal ),
		[
			filter,
			debounced,
			postType,
			excludedView ? '' : lang,
			orderby,
			order,
			page,
			perPage,
			refreshKey,
			reload,
		],
		__( 'Could not load the report.', 'magic-linking' )
	);

	// Una sola fuente para el resumen: lo leen la cabecera (onSummary) y las pestañas de filtro.
	const summary = fresh.summary ?? data?.summary;
	useEffect( () => {
		if ( summary ) {
			onSummary( summary );
		}
	}, [ summary, onSummary ] );

	// Si tras excluir o incluir la página ya no existe, se va a la última que quede.
	useEffect( () => {
		if ( data && page > data.total_pages ) {
			setPage( Math.max( 1, data.total_pages ) );
		}
	}, [ data, page ] );

	const rowIds = new Set( ( data?.items ?? [] ).map( ( row ) => row.id ) );
	const rowIdsRef = useRef( rowIds );
	rowIdsRef.current = rowIds;
	const pending = useRef( new Set< number >() );
	const inFlight = useRef< AbortController | null >( null );

	// Una carga nueva trae las cifras al día: lo releído a mano y lo que estuviera en vuelo ya no hacen falta.
	useEffect( () => {
		inFlight.current?.abort();
		pending.current.clear();
		setFresh( { rows: {}, summary: null } );
	}, [ data ] );
	useEffect( () => () => inFlight.current?.abort(), [] );

	const rows = ( data?.items ?? [] ).map(
		( row ) => fresh.rows[ row.id ] ?? row
	);

	/**
	 * Vuelve a leer del servidor, en una sola petición, las filas visibles que cambiaron (la del panel y el origen o
	 * destino del enlace). No se vuelve a pedir la página: reordenaría las filas y se perdería el sitio del usuario.
	 * Cada petición cancela la anterior y solo la última puede escribir; lo que la anterior no llegó a traer se pide
	 * de nuevo, así una respuesta vieja nunca pisa a una nueva.
	 */
	const refreshRows = useCallback( ( ids: number[] ) => {
		ids.filter( ( id ) => rowIdsRef.current.has( id ) ).forEach( ( id ) =>
			pending.current.add( id )
		);
		const wanted = Array.from( pending.current );
		if ( wanted.length === 0 ) {
			return;
		}
		inFlight.current?.abort();
		const controller = new AbortController();
		inFlight.current = controller;
		api.reportRows( wanted, controller.signal )
			.then( ( response ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				wanted.forEach( ( id ) => pending.current.delete( id ) );
				setFresh( ( previous ) => ( {
					rows: {
						...previous.rows,
						...Object.fromEntries(
							response.items.map( ( item ) => [ item.id, item ] )
						),
					},
					summary: response.summary,
				} ) );
			} )
			.catch( () => undefined );
	}, [] );

	useEffect( () => {
		if ( ! data || first ) {
			return;
		}
		if ( quiet.current ) {
			quiet.current = false;
			return;
		}
		speak(
			data.total === 0
				? __( 'No entries found.', 'magic-linking' )
				: sprintf(
						/* translators: %d: number of entries. */
						_n(
							'%d entry',
							'%d entries',
							data.total,
							'magic-linking'
						),
						data.total
				  ),
			'polite'
		);
		// Solo al llegar datos nuevos.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ data ] );

	// Al acabar cualquier carga (con datos nuevos, iguales o con error) el silencio de la acción de fila termina; va
	// después del efecto que anuncia el recuento, que es quien lo consulta.
	useEffect( () => {
		if ( ! loading ) {
			quiet.current = false;
		}
	}, [ loading ] );

	/**
	 * Excluye la entrada del análisis o la vuelve a incluir. La fila sale de la lista (no se reordena nada más: se
	 * vuelve a cargar la página), se anuncia y el foco pasa a la pestaña activa, porque el botón pulsado desaparece.
	 * @param row     Fila.
	 * @param exclude Excluir (true) o volver a incluir (false).
	 */
	const toggleExcluded = ( row: ReportItem, exclude: boolean ) => {
		setToggling( ( ids ) => new Set( ids ).add( row.id ) );
		setToggleError( '' );
		api.setExcluded( row.id, exclude )
			.then( () => {
				if ( panel?.row.id === row.id ) {
					setPanel( null );
				}
				speak(
					exclude
						? sprintf(
								/* translators: %s: entry title. */
								__(
									'%s excluded from the analysis.',
									'magic-linking'
								),
								row.title
						  )
						: sprintf(
								/* translators: %s: entry title. */
								__(
									'%s included in the analysis again.',
									'magic-linking'
								),
								row.title
						  ),
					'polite'
				);
				quiet.current = true;
				setReload( ( value ) => value + 1 );
				root.current
					?.querySelector< HTMLElement >( '.subsubsub a.current' )
					?.focus();
			} )
			.catch( () =>
				setToggleError(
					exclude
						? __(
								'Could not exclude the entry from the analysis.',
								'magic-linking'
						  )
						: __(
								'Could not include the entry in the analysis again.',
								'magic-linking'
						  )
				)
			)
			.finally( () =>
				setToggling( ( ids ) => {
					const rest = new Set( ids );
					rest.delete( row.id );
					return rest;
				} )
			);
	};

	const multilingual = summary?.multilingual ?? false;
	const views: { id: ReportFilter; label: string; count?: number }[] = [
		{
			id: 'all',
			label: __( 'All', 'magic-linking' ),
			count: summary?.analyzed,
		},
		{
			id: 'orphans',
			label: __( 'Orphans', 'magic-linking' ),
			count: summary?.orphans,
		},
		{
			id: 'low',
			label: __( 'Under-linked', 'magic-linking' ),
			count: summary?.low,
		},
		{
			id: 'over',
			label: __( 'Over-linked', 'magic-linking' ),
			count: summary?.over,
		},
		{
			id: 'broken',
			label: __( 'With broken links', 'magic-linking' ),
			count: summary?.broken_posts,
		},
	];
	// Solo hay pestaña de excluidas si hay alguna (o si es la que está abierta).
	if ( ( summary?.excluded ?? 0 ) > 0 || filter === 'excluded' ) {
		views.push( {
			id: 'excluded',
			label: __( 'Excluded', 'magic-linking' ),
			count: summary?.excluded ?? 0,
		} );
	}

	const change =
		< T, >( setter: ( value: T ) => void ) =>
		( value: T ) => {
			setter( value );
			setPage( 1 );
		};

	const changeFilter = ( next: ReportFilter ) => {
		// «Excluded» solo se ordena por título o tipo: el orden que se pinta es el que se pide.
		if ( next === 'excluded' && ! EXCLUDED_SORTS.includes( orderby ) ) {
			setOrderby( 'title' );
			setOrder( 'asc' );
		}
		change( setFilter )( next );
	};

	const onSort = ( key: SortKey ) => {
		if ( key === orderby ) {
			setOrder( order === 'asc' ? 'desc' : 'asc' );
		} else {
			setOrderby( key );
			setOrder( 'asc' );
		}
		setPage( 1 );
	};

	const columns: Column< ReportItem, SortKey >[] = [
		{
			id: 'title',
			header: __( 'Title', 'magic-linking' ),
			sortKey: 'title',
			render: ( row ) => (
				<strong>
					{ row.edit_url ? (
						<a href={ row.edit_url }>{ row.title }</a>
					) : (
						row.title
					) }
				</strong>
			),
		},
		{
			id: 'type',
			header: multilingual
				? __( 'Type · Language', 'magic-linking' )
				: __( 'Type', 'magic-linking' ),
			sortKey: 'type',
			render: ( row ) =>
				multilingual && row.lang
					? `${ row.type_label } · ${ row.lang }`
					: row.type_label,
		},
		{
			id: 'inbound',
			header: __( 'Inbound', 'magic-linking' ),
			sortKey: excludedView ? undefined : 'inbound',
			numeric: true,
			render: ( row ) =>
				row.status === 'excluded' ? (
					NO_FIGURE
				) : (
					<span
						className={
							row.inbound === 0 ? 'magiclinking-zero' : undefined
						}
					>
						{ row.inbound }
					</span>
				),
		},
		{
			id: 'outbound',
			header: __( 'Internal outbound', 'magic-linking' ),
			sortKey: excludedView ? undefined : 'outbound',
			numeric: true,
			render: ( row ) =>
				row.status === 'excluded' ? (
					NO_FIGURE
				) : (
					<span
						className={
							row.status === 'over'
								? 'magiclinking-warn'
								: undefined
						}
					>
						{ row.outbound }
					</span>
				),
		},
		{
			id: 'external',
			header: __( 'External', 'magic-linking' ),
			sortKey: excludedView ? undefined : 'external',
			numeric: true,
			render: ( row ) =>
				row.status === 'excluded' ? NO_FIGURE : row.external,
		},
		{
			id: 'broken',
			header: __( 'Broken', 'magic-linking' ),
			sortKey: excludedView ? undefined : 'broken',
			numeric: true,
			render: ( row ) => {
				if ( row.status === 'excluded' ) {
					return NO_FIGURE;
				}
				return row.broken > 0 ? (
					<button
						type="button"
						className="button-link"
						onClick={ () => onBroken( row.id, row.title ) }
					>
						{ row.broken }
						<span className="screen-reader-text">
							{ ' ' +
								sprintf(
									/* translators: %s: entry title. */
									__( 'broken links in %s', 'magic-linking' ),
									row.title
								) }
						</span>
					</button>
				) : (
					0
				);
			},
		},
		{
			id: 'status',
			header: __( 'Status', 'magic-linking' ),
			render: ( row ) => (
				<span
					className={ `magiclinking-badge magiclinking-badge--${ row.status }` }
				>
					{ STATUS_LABEL[ row.status ]() }
					{ row.status === 'excluded' &&
						row.post_status !== 'publish' &&
						row.post_status_label &&
						` · ${ row.post_status_label }` }
					{ row.excluded_by === 'category' &&
						` · ${ __( 'category excluded', 'magic-linking' ) }` }
				</span>
			),
		},
		{
			id: 'indexed_at',
			header: __( 'Analyzed', 'magic-linking' ),
			sortKey: excludedView ? undefined : 'indexed_at',
			render: ( row ) =>
				row.status === 'excluded' ? (
					NO_FIGURE
				) : (
					<time dateTime={ dateTimeAttr( row.indexed_at ) }>
						{ formatDate( row.indexed_at ) }
					</time>
				),
		},
	];

	const exportParams = new URLSearchParams( {
		dataset: 'report',
		filter,
		orderby,
		order,
	} );
	if ( debounced ) {
		exportParams.set( 'search', debounced );
	}
	if ( postType ) {
		exportParams.set( 'post_type', postType );
	}
	if ( lang && ! excludedView ) {
		exportParams.set( 'lang', lang );
	}

	const rowActions = ( row: ReportItem ) => {
		const title = (
			<span className="screen-reader-text">{ ' ' + row.title }</span>
		);
		const items: { id: string; node: ReactNode }[] = [];
		if ( row.edit_url ) {
			items.push( {
				id: 'edit',
				node: (
					<a href={ row.edit_url }>
						{ __( 'Edit', 'magic-linking' ) }
						{ title }
					</a>
				),
			} );
		}
		if ( row.url ) {
			items.push( {
				id: 'view',
				node: (
					<a href={ row.url }>
						{ __( 'View', 'magic-linking' ) }
						{ title }
					</a>
				),
			} );
		}
		const excluded = row.status === 'excluded';
		if ( row.edit_url && ! excluded ) {
			// Hace falta poder editar la entrada de la fila (las rutas de sugerencias lo exigen).
			( [ 'inbound', 'outbound' ] as const ).forEach( ( direction ) =>
				items.push( {
					id: `suggestions-${ direction }`,
					node: (
						<button
							type="button"
							className="button-link"
							onClick={ () => setPanel( { row, direction } ) }
						>
							{ direction === 'inbound'
								? __( 'Inbound suggestions', 'magic-linking' )
								: __(
										'Outbound suggestions',
										'magic-linking'
								  ) }
							{ title }
						</button>
					),
				} )
			);
		}
		if ( excluded && row.excluded_by === 'category' ) {
			// Fuera por una categoría de Ajustes: no hay marca que quitar aquí; se cambia en Ajustes.
			const settings = window.magiclinking?.tabUrls?.settings;
			if ( settings ) {
				items.push( {
					id: 'category-settings',
					node: (
						<a href={ `${ settings }#tab=general` }>
							{ __(
								'Change excluded categories',
								'magic-linking'
							) }
							{ title }
						</a>
					),
				} );
			}
		} else if ( row.edit_url ) {
			// Hace falta poder editar la entrada; el servidor lo vuelve a comprobar.
			items.push( {
				id: excluded ? 'include' : 'exclude',
				node: (
					<button
						type="button"
						className="button-link"
						disabled={ toggling.has( row.id ) }
						onClick={ () => toggleExcluded( row, ! excluded ) }
					>
						{ excluded
							? __( 'Include in the analysis', 'magic-linking' )
							: __(
									'Exclude from the analysis',
									'magic-linking'
							  ) }
						{ title }
					</button>
				),
			} );
		}
		if ( row.broken > 0 && ! excluded ) {
			items.push( {
				id: 'broken',
				node: (
					<button
						type="button"
						className="button-link"
						onClick={ () => onBroken( row.id, row.title ) }
					>
						{ __( 'View broken links', 'magic-linking' ) }
						{ title }
					</button>
				),
			} );
		}
		return items;
	};

	return (
		<div className="magiclinking-report" ref={ root }>
			<ul className="subsubsub">
				{ views.map( ( view, index ) => (
					<li key={ view.id } className={ view.id }>
						<a
							href={ `#${ view.id }` }
							className={ filter === view.id ? 'current' : '' }
							aria-current={
								filter === view.id ? 'true' : undefined
							}
							onClick={ ( event ) => {
								event.preventDefault();
								changeFilter( view.id );
							} }
						>
							{ view.label }
							{ view.count !== undefined && (
								<>
									{ ' ' }
									<span className="count">
										{ '(' +
											view.count.toLocaleString() +
											')' }
									</span>
								</>
							) }
						</a>
						{ index < views.length - 1 ? ' |' : '' }
					</li>
				) ) }
			</ul>

			<SearchBox
				id="magiclinking-report-search"
				label={ __( 'Search entries by title', 'magic-linking' ) }
				value={ search }
				onChange={ change( setSearch ) }
			/>

			{ ( error || toggleError ) && (
				<p className="magiclinking-error" role="alert">
					{ error || toggleError }
				</p>
			) }

			<DataTable< ReportItem, SortKey >
				caption={ __( 'Internal links by entry', 'magic-linking' ) }
				columns={ columns }
				rows={ rows }
				rowKey={ ( row ) => row.id }
				rowActions={ rowActions }
				orderby={ orderby }
				order={ order }
				onSort={ onSort }
				busy={ loading }
				emptyMessage={
					filter === 'excluded'
						? __(
								'No entries are excluded from the analysis.',
								'magic-linking'
						  )
						: __(
								'No entries match these filters.',
								'magic-linking'
						  )
				}
				actions={
					<>
						{ ( summary?.types.length ?? 0 ) > 1 && (
							<>
								<label
									htmlFor="magiclinking-filter-type"
									className="screen-reader-text"
								>
									{ __( 'Content type', 'magic-linking' ) }
								</label>
								<select
									id="magiclinking-filter-type"
									value={ postType }
									onChange={ ( event ) =>
										change( setPostType )(
											event.target.value
										)
									}
								>
									<option value="">
										{ __( 'All types', 'magic-linking' ) }
									</option>
									{ ( summary?.types ?? [] ).map(
										( type ) => (
											<option
												key={ type.name }
												value={ type.name }
											>
												{ `${ type.label } (${ type.count })` }
											</option>
										)
									) }
								</select>
							</>
						) }
						{ multilingual &&
							! excludedView &&
							( summary?.langs.length ?? 0 ) > 1 && (
								<>
									<label
										htmlFor="magiclinking-filter-lang"
										className="screen-reader-text"
									>
										{ __( 'Language', 'magic-linking' ) }
									</label>
									<select
										id="magiclinking-filter-lang"
										value={ lang }
										onChange={ ( event ) =>
											change( setLang )(
												event.target.value
											)
										}
									>
										<option value="">
											{ __(
												'All languages',
												'magic-linking'
											) }
										</option>
										{ ( summary?.langs ?? [] ).map(
											( code ) => (
												<option
													key={ code }
													value={ code }
												>
													{ code }
												</option>
											)
										) }
									</select>
								</>
							) }
						<a
							className="button"
							href={ `${ exportUrl }&${ exportParams.toString() }` }
						>
							{ __( 'Export CSV', 'magic-linking' ) }
						</a>
					</>
				}
				noun="entries"
				page={ data?.page ?? page }
				totalPages={ data?.total_pages ?? 1 }
				total={ data?.total ?? 0 }
				onPage={ setPage }
			/>

			{ panel && (
				<SuggestionsDrawer
					entry={ { id: panel.row.id, title: panel.row.title } }
					direction={ panel.direction }
					historyUrl={ historyUrl }
					reportUrl={ reportUrl }
					onClose={ () => setPanel( null ) }
					onChanged={ refreshRows }
				/>
			) }
		</div>
	);
}
