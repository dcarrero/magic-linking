import { useEffect, useState } from '@wordpress/element';
import type { ReactNode } from 'react';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { useDebounced, useIsFirstRender, useLoad } from '../hooks';
import type { ReportFilter, ReportItem, SortKey, Summary } from '../types';
import { Column, DataTable } from './DataTable';
import { SearchBox } from './SearchBox';

interface Props {
	refreshKey: number;
	exportUrl: string;
	onBroken: ( postId: number, title: string ) => void;
	onSummary: ( summary: Summary ) => void;
}

const PER_PAGE = window.magiclinking?.perPage ?? 20;

const STATUS_LABEL: Record< ReportItem[ 'status' ], () => string > = {
	orphan: () => __( 'Orphan', 'magic-linking' ),
	low: () => __( 'Under-linked', 'magic-linking' ),
	over: () => __( 'Over-linked', 'magic-linking' ),
	ok: () => __( 'OK', 'magic-linking' ),
};

/**
 * Pestaña del informe: filtros rápidos, búsqueda, tabla ordenable, paginación y CSV.
 * @param root0
 * @param root0.refreshKey
 * @param root0.exportUrl
 * @param root0.onBroken
 * @param root0.onSummary
 */
export function ReportTab( {
	refreshKey,
	exportUrl,
	onBroken,
	onSummary,
}: Props ) {
	const [ filter, setFilter ] = useState< ReportFilter >( 'all' );
	const [ search, setSearch ] = useState( '' );
	const [ postType, setPostType ] = useState( '' );
	const [ lang, setLang ] = useState( '' );
	const [ orderby, setOrderby ] = useState< SortKey >( 'inbound' );
	const [ order, setOrder ] = useState< 'asc' | 'desc' >( 'asc' );
	const [ page, setPage ] = useState( 1 );
	const perPage = PER_PAGE;
	const debounced = useDebounced( search );
	const first = useIsFirstRender();

	const query = {
		filter,
		search: debounced,
		post_type: postType,
		lang,
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
			lang,
			orderby,
			order,
			page,
			perPage,
			refreshKey,
		],
		__( 'Could not load the report.', 'magic-linking' )
	);

	useEffect( () => {
		if ( data ) {
			onSummary( data.summary );
		}
	}, [ data, onSummary ] );

	useEffect( () => {
		if ( ! data || first ) {
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

	const summary = data?.summary;
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

	const change =
		< T, >( setter: ( value: T ) => void ) =>
		( value: T ) => {
			setter( value );
			setPage( 1 );
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
				multilingual
					? `${ row.type_label } · ${ row.lang }`
					: row.type_label,
		},
		{
			id: 'inbound',
			header: __( 'Inbound', 'magic-linking' ),
			sortKey: 'inbound',
			numeric: true,
			render: ( row ) => (
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
			sortKey: 'outbound',
			numeric: true,
			render: ( row ) => (
				<span
					className={
						row.status === 'over' ? 'magiclinking-warn' : undefined
					}
				>
					{ row.outbound }
				</span>
			),
		},
		{
			id: 'external',
			header: __( 'External', 'magic-linking' ),
			sortKey: 'external',
			numeric: true,
			render: ( row ) => row.external,
		},
		{
			id: 'broken',
			header: __( 'Broken', 'magic-linking' ),
			sortKey: 'broken',
			numeric: true,
			render: ( row ) =>
				row.broken > 0 ? (
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
				),
		},
		{
			id: 'status',
			header: __( 'Status', 'magic-linking' ),
			render: ( row ) => (
				<span
					className={ `magiclinking-badge magiclinking-badge--${ row.status }` }
				>
					{ STATUS_LABEL[ row.status ]() }
				</span>
			),
		},
		{
			id: 'indexed_at',
			header: __( 'Analyzed', 'magic-linking' ),
			sortKey: 'indexed_at',
			render: ( row ) => (
				<time dateTime={ row.indexed_at }>
					{ new Date( row.indexed_at ).toLocaleDateString() }
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
	if ( lang ) {
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
		if ( row.broken > 0 ) {
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
		<div className="magiclinking-report">
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
								change( setFilter )( view.id );
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

			{ error && (
				<p className="magiclinking-error" role="alert">
					{ error }
				</p>
			) }

			<DataTable< ReportItem, SortKey >
				caption={ __( 'Internal links by entry', 'magic-linking' ) }
				columns={ columns }
				rows={ data?.items ?? [] }
				rowKey={ ( row ) => row.id }
				rowActions={ rowActions }
				orderby={ orderby }
				order={ order }
				onSort={ onSort }
				busy={ loading }
				emptyMessage={ __(
					'No entries match these filters.',
					'magic-linking'
				) }
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
		</div>
	);
}
