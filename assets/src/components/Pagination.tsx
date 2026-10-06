import { useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';

interface Props {
	page: number;
	totalPages: number;
	total: number;
	onPage: ( page: number ) => void;
	noun: 'entries' | 'links';
	/** Barra superior: lleva el campo de página actual, como en las tablas nativas. */
	top?: boolean;
}

/**
 * Paginación con el marcado de las tablas nativas del escritorio (`.tablenav-pages`).
 * @param root0
 * @param root0.page
 * @param root0.totalPages
 * @param root0.total
 * @param root0.onPage
 * @param root0.noun
 * @param root0.top
 */
export function Pagination( {
	page,
	totalPages,
	total,
	onPage,
	noun,
	top = false,
}: Props ) {
	const [ draft, setDraft ] = useState( String( page ) );
	const previous = useRef( page );

	useEffect( () => {
		setDraft( String( page ) );
	}, [ page ] );

	// Solo una de las dos barras anuncia el cambio.
	useEffect( () => {
		if ( top && previous.current !== page ) {
			speak(
				sprintf(
					/* translators: 1: current page, 2: total pages. */
					__( 'Page %1$s of %2$s', 'magic-linking' ),
					String( page ),
					String( totalPages )
				),
				'polite'
			);
		}
		previous.current = page;
	}, [ page, top, totalPages ] );

	const count =
		noun === 'entries'
			? sprintf(
					/* translators: %s: number of entries. */
					_n( '%s entry', '%s entries', total, 'magic-linking' ),
					total.toLocaleString()
			  )
			: sprintf(
					/* translators: %s: number of links. */
					_n( '%s link', '%s links', total, 'magic-linking' ),
					total.toLocaleString()
			  );

	const commit = () => {
		const next = Math.min(
			Math.max( 1, Number.parseInt( draft, 10 ) || page ),
			totalPages
		);
		setDraft( String( next ) );
		if ( next !== page ) {
			onPage( next );
		}
	};

	const nav = (
		target: number,
		enabled: boolean,
		className: string,
		label: string,
		glyph: string
	) =>
		enabled ? (
			<button
				type="button"
				className={ `${ className } button` }
				onClick={ () => onPage( target ) }
			>
				<span className="screen-reader-text">{ label }</span>
				<span aria-hidden="true">{ glyph }</span>
			</button>
		) : (
			<span
				className="tablenav-pages-navspan button disabled"
				aria-hidden="true"
			>
				{ glyph }
			</span>
		);

	const hasPrev = page > 1;
	const hasNext = page < totalPages;

	return (
		<div
			className={ `tablenav-pages${
				totalPages <= 1 ? ' one-page' : ''
			}` }
		>
			<span className="displaying-num">{ count }</span>
			<span className="pagination-links">
				{ nav(
					1,
					hasPrev,
					'first-page',
					__( 'First page', 'magic-linking' ),
					'«'
				) }{ ' ' }
				{ nav(
					page - 1,
					hasPrev,
					'prev-page',
					__( 'Previous page', 'magic-linking' ),
					'‹'
				) }{ ' ' }
				{ top ? (
					<span className="paging-input">
						<label
							htmlFor="magiclinking-current-page"
							className="screen-reader-text"
						>
							{ __( 'Current page', 'magic-linking' ) }
						</label>
						<input
							className="current-page"
							id="magiclinking-current-page"
							type="text"
							inputMode="numeric"
							size={ 2 }
							value={ draft }
							aria-describedby="magiclinking-table-paging"
							onChange={ ( event ) =>
								setDraft( event.target.value )
							}
							onBlur={ commit }
							onKeyDown={ ( event ) => {
								if ( event.key === 'Enter' ) {
									event.preventDefault();
									commit();
								}
							} }
						/>
						<span className="tablenav-paging-text">
							{ ' ' }
							{ sprintf(
								/* translators: %s: total number of pages. */
								__( 'of %s', 'magic-linking' ),
								totalPages.toLocaleString()
							) }
						</span>
					</span>
				) : (
					<span className="screen-reader-text">
						{ __( 'Current page', 'magic-linking' ) }
					</span>
				) }
				{ ! top && (
					<span
						id="magiclinking-table-paging"
						className="paging-input"
					>
						<span className="tablenav-paging-text">
							{ sprintf(
								/* translators: 1: current page, 2: total pages. */
								__( '%1$s of %2$s', 'magic-linking' ),
								String( page ),
								totalPages.toLocaleString()
							) }
						</span>
					</span>
				) }{ ' ' }
				{ nav(
					page + 1,
					hasNext,
					'next-page',
					__( 'Next page', 'magic-linking' ),
					'›'
				) }{ ' ' }
				{ nav(
					totalPages,
					hasNext,
					'last-page',
					__( 'Last page', 'magic-linking' ),
					'»'
				) }
			</span>
		</div>
	);
}
