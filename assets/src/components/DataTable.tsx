import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { Pagination } from './Pagination';

export interface Column< Row, Key extends string > {
	id: string;
	header: string;
	/** Clave de orden en la API; sin ella la columna no se ordena. */
	sortKey?: Key;
	numeric?: boolean;
	render: ( row: Row ) => ReactNode;
}

interface Props< Row, Key extends string > {
	caption: string;
	columns: Column< Row, Key >[];
	rows: Row[];
	rowKey: ( row: Row ) => string | number;
	/** Acciones de fila de la columna principal («Editar | Ver | …»). */
	rowActions?: ( row: Row ) => { id: string; node: ReactNode }[];
	orderby?: Key;
	order?: 'asc' | 'desc';
	onSort?: ( key: Key ) => void;
	busy: boolean;
	emptyMessage: string;
	/** Contenido de `.alignleft.actions` en las barras de la tabla. */
	actions?: ReactNode;
	page: number;
	totalPages: number;
	total: number;
	onPage: ( page: number ) => void;
	noun: 'entries' | 'links';
}

/**
 * Tabla con el marcado de WP_List_Table: barras superior e inferior, cabeceras ordenables,
 * columna principal con acciones de fila y vista móvil nativa (`toggle-row`).
 * @param root0
 * @param root0.caption
 * @param root0.columns
 * @param root0.rows
 * @param root0.rowKey
 * @param root0.rowActions
 * @param root0.orderby
 * @param root0.order
 * @param root0.onSort
 * @param root0.busy
 * @param root0.emptyMessage
 * @param root0.actions
 * @param root0.page
 * @param root0.totalPages
 * @param root0.total
 * @param root0.onPage
 * @param root0.noun
 */
export function DataTable< Row, Key extends string >( {
	caption,
	columns,
	rows,
	rowKey,
	rowActions,
	orderby,
	order,
	onSort,
	busy,
	emptyMessage,
	actions,
	page,
	totalPages,
	total,
	onPage,
	noun,
}: Props< Row, Key > ) {
	const cellClass = ( column: Column< Row, Key >, index: number ) =>
		[
			`column-${ column.id }`,
			column.numeric ? 'num' : '',
			index === 0 ? 'column-primary' : '',
		]
			.filter( Boolean )
			.join( ' ' );

	const headers = columns.map( ( column, index ) => {
		const sortable = column.sortKey !== undefined && !! onSort;
		const sorted = sortable && column.sortKey === orderby;
		const direction = order === 'desc' ? 'desc' : 'asc';
		let ariaSort: 'ascending' | 'descending' | 'none' | undefined;
		if ( sortable ) {
			ariaSort = 'none';
			if ( sorted ) {
				ariaSort = direction === 'desc' ? 'descending' : 'ascending';
			}
		}
		let sortClass = '';
		if ( sortable ) {
			sortClass = sorted ? `sorted ${ direction }` : 'sortable asc';
		}
		return (
			<th
				key={ column.id }
				scope="col"
				aria-sort={ ariaSort }
				className={ `manage-column ${ cellClass(
					column,
					index
				) } ${ sortClass }` }
			>
				{ sortable ? (
					<a
						href="#sort"
						onClick={ ( event ) => {
							event.preventDefault();
							onSort?.( column.sortKey as Key );
						} }
					>
						<span>{ column.header }</span>
						<span className="sorting-indicators">
							<span
								className="sorting-indicator asc"
								aria-hidden="true"
							/>
							<span
								className="sorting-indicator desc"
								aria-hidden="true"
							/>
						</span>
						{ sorted && (
							<span className="screen-reader-text">
								{ direction === 'desc'
									? __(
											'Sorted descending.',
											'magic-linking'
									  )
									: __(
											'Sorted ascending.',
											'magic-linking'
									  ) }
							</span>
						) }
					</a>
				) : (
					column.header
				) }
			</th>
		);
	} );

	const bar = ( position: 'top' | 'bottom' ) => (
		<div className={ `tablenav ${ position }` }>
			{ actions && position === 'top' && (
				<div className="alignleft actions">{ actions }</div>
			) }
			<Pagination
				noun={ noun }
				top={ position === 'top' }
				page={ page }
				totalPages={ totalPages }
				total={ total }
				onPage={ onPage }
			/>
			<br className="clear" />
		</div>
	);

	return (
		<>
			{ bar( 'top' ) }
			<table
				className="wp-list-table widefat fixed striped table-view-list"
				aria-busy={ busy }
			>
				<caption className="screen-reader-text">{ caption }</caption>
				<thead>
					<tr>{ headers }</tr>
				</thead>
				<tbody>
					{ rows.length === 0 ? (
						<tr className="no-items">
							<td
								className="colspanchange"
								colSpan={ columns.length }
							>
								{ busy
									? __( 'Loading…', 'magic-linking' )
									: emptyMessage }
							</td>
						</tr>
					) : (
						rows.map( ( row ) => {
							const items = rowActions?.( row ) ?? [];
							return (
								<tr key={ rowKey( row ) }>
									{ columns.map( ( column, index ) => (
										<td
											key={ column.id }
											className={ `${ cellClass(
												column,
												index
											) }${
												index === 0 && items.length
													? ' has-row-actions'
													: ''
											}` }
											data-colname={
												index === 0
													? undefined
													: column.header
											}
										>
											{ column.render( row ) }
											{ index === 0 && (
												<>
													{ items.length > 0 && (
														<div className="row-actions">
															{ items.map(
																(
																	item,
																	position
																) => (
																	<span
																		key={
																			item.id
																		}
																		className={
																			item.id
																		}
																	>
																		{
																			item.node
																		}
																		{ position <
																		items.length -
																			1
																			? ' | '
																			: '' }
																	</span>
																)
															) }
														</div>
													) }
													<button
														type="button"
														className="toggle-row"
													>
														<span className="screen-reader-text">
															{ __(
																'Show more details',
																'magic-linking'
															) }
														</span>
													</button>
												</>
											) }
										</td>
									) ) }
								</tr>
							);
						} )
					) }
				</tbody>
				<tfoot>
					<tr>{ headers }</tr>
				</tfoot>
			</table>
			{ bar( 'bottom' ) }
		</>
	);
}
