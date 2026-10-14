import { useEffect, useRef, useState } from '@wordpress/element';
import type { ReactNode } from 'react';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import { useDebounced, useIsFirstRender, useLoad } from '../hooks';
import type { BrokenItem, FixResponse } from '../types';
import { Column, DataTable } from './DataTable';
import { FixBrokenModal } from './FixBrokenModal';
import { SearchBox } from './SearchBox';

interface Props {
	refreshKey: number;
	exportUrl: string;
	/** Entrada cuyos rotos se muestran; null para todos. */
	only: { id: number; title: string } | null;
	onClearOnly: () => void;
}

/**
 * Lista completa de enlaces internos rotos, con motivo y enlace para editar la entrada.
 * @param root0
 * @param root0.refreshKey
 * @param root0.exportUrl
 * @param root0.only
 * @param root0.onClearOnly
 */
export function BrokenTab( {
	refreshKey,
	exportUrl,
	only,
	onClearOnly,
}: Props ) {
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const perPage = window.magiclinking?.perPage ?? 20;
	const debounced = useDebounced( search );
	const first = useIsFirstRender();
	const postId = only?.id ?? 0;
	// Se sube al arreglar o deshacer un enlace, para volver a pedir la lista sin recargar la página.
	const [ version, setVersion ] = useState( 0 );
	const [ fixing, setFixing ] = useState< {
		row: BrokenItem;
		mode: 'retarget' | 'unlink';
	} | null >( null );
	const [ notice, setNotice ] = useState< {
		text: string;
		batchId: string | null;
		error?: boolean;
	} | null >( null );
	const [ undoing, setUndoing ] = useState( false );
	const noticeRef = useRef< HTMLDivElement >( null );

	const { data, loading, error } = useLoad(
		( signal ) =>
			api.broken(
				{ post_id: postId, search: debounced, page, per_page: perPage },
				signal
			),
		[ postId, debounced, page, perPage, refreshKey, version ],
		__( 'Could not load the broken links.', 'magic-linking' )
	);

	useEffect( () => {
		setPage( 1 );
	}, [ postId ] );

	useEffect( () => {
		if ( ! data || first ) {
			return;
		}
		speak(
			data.total === 0
				? __( 'No broken links found.', 'magic-linking' )
				: sprintf(
						/* translators: %d: number of broken links. */
						_n(
							'%d broken link',
							'%d broken links',
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

	const onFixed = ( row: BrokenItem, response: FixResponse ) => {
		setFixing( null );
		setNotice( {
			text:
				response.mode === 'retarget'
					? sprintf(
							/* translators: 1: anchor text, 2: entry title. */
							__(
								'Destination changed for “%1$s” in “%2$s”.',
								'magic-linking'
							),
							row.anchor || __( '(no text)', 'magic-linking' ),
							row.source_title
					  )
					: sprintf(
							/* translators: 1: anchor text, 2: entry title. */
							__(
								'Link removed from “%1$s” in “%2$s”; its text stays.',
								'magic-linking'
							),
							row.anchor || __( '(no text)', 'magic-linking' ),
							row.source_title
					  ),
			batchId: response.batch_id,
		} );
		setVersion( ( value ) => value + 1 );
	};

	// Al cerrarse el cuadro el botón de la fila ya no existe: el foco va al aviso, que se anuncia solo.
	useEffect( () => {
		if ( notice ) {
			noticeRef.current?.focus();
		}
	}, [ notice ] );

	const undo = async () => {
		if ( ! notice?.batchId || undoing ) {
			return;
		}
		setUndoing( true );
		try {
			const response = await api.runHistory( 'undo', {
				batchId: notice.batchId,
			} );
			const bad = response.results.some(
				( result ) =>
					result.status === 'manual' || result.status === 'failed'
			);
			setNotice( {
				text: bad
					? __(
							'The link could not be put back automatically. Open the history to see why.',
							'magic-linking'
					  )
					: __(
							'Done: the link is back as it was.',
							'magic-linking'
					  ),
				batchId: null,
				error: bad,
			} );
			setVersion( ( value ) => value + 1 );
		} catch ( e ) {
			setNotice( {
				text: errorMessage(
					e,
					__( 'The change could not be undone.', 'magic-linking' )
				),
				batchId: notice.batchId,
				error: true,
			} );
		}
		setUndoing( false );
	};

	const columns: Column< BrokenItem, never >[] = [
		{
			id: 'source',
			header: __( 'Entry', 'magic-linking' ),
			render: ( row ) =>
				row.edit_url ? (
					<strong>
						<a href={ row.edit_url }>{ row.source_title }</a>
					</strong>
				) : (
					<strong>{ row.source_title }</strong>
				),
		},
		{
			id: 'url',
			header: __( 'Broken URL', 'magic-linking' ),
			render: ( row ) => (
				<code className="magiclinking-url">{ row.url }</code>
			),
		},
		{
			id: 'anchor',
			header: __( 'Anchor text', 'magic-linking' ),
			render: ( row ) =>
				row.anchor || <em>{ __( '(no text)', 'magic-linking' ) }</em>,
		},
		{
			id: 'reason',
			header: __( 'Reason', 'magic-linking' ),
			render: ( row ) => row.reason,
		},
	];

	const exportParams = new URLSearchParams( { dataset: 'broken' } );
	if ( postId ) {
		exportParams.set( 'post_id', String( postId ) );
	}
	if ( debounced ) {
		exportParams.set( 'search', debounced );
	}

	const rowActions = ( row: BrokenItem ) => {
		const items: { id: string; node: ReactNode }[] = [];
		if ( row.edit_url ) {
			items.push( {
				id: 'edit',
				node: (
					<a href={ row.edit_url }>
						{ __( 'Edit entry', 'magic-linking' ) }
						<span className="screen-reader-text">
							{ ' ' + row.source_title }
						</span>
					</a>
				),
			} );
		}
		if ( row.can_fix ) {
			items.push(
				{
					id: 'retarget',
					node: (
						<button
							type="button"
							className="button-link"
							onClick={ () =>
								setFixing( { row, mode: 'retarget' } )
							}
						>
							{ __( 'Change destination', 'magic-linking' ) }
							<span className="screen-reader-text">
								{ ' ' + row.anchor + ' ' + row.source_title }
							</span>
						</button>
					),
				},
				{
					id: 'unlink',
					node: (
						<button
							type="button"
							className="button-link"
							onClick={ () =>
								setFixing( { row, mode: 'unlink' } )
							}
						>
							{ __( 'Remove link', 'magic-linking' ) }
							<span className="screen-reader-text">
								{ ' ' + row.anchor + ' ' + row.source_title }
							</span>
						</button>
					),
				}
			);
		}
		return items;
	};

	return (
		<div className="magiclinking-broken">
			<p className="description">
				{ __(
					'Internal links that lead nowhere: the destination is in the trash, is not published, is private or does not exist. Nothing is checked over the network. Fix each one by changing its destination or removing the link, or by editing the entry.',
					'magic-linking'
				) }
			</p>

			<div
				ref={ noticeRef }
				tabIndex={ -1 }
				role="status"
				aria-live="polite"
				className="magiclinking-fix-notice"
			>
				{ notice && (
					<p className={ notice.error ? 'magiclinking-warn' : '' }>
						{ notice.text }
						{ notice.batchId && (
							<>
								{ ' ' }
								<button
									type="button"
									className="button-link"
									onClick={ undo }
									disabled={ undoing }
									aria-disabled={ undoing }
								>
									{ __( 'Undo', 'magic-linking' ) }
								</button>
							</>
						) }
					</p>
				) }
			</div>

			{ only && (
				<p className="magiclinking-only">
					{ sprintf(
						/* translators: %s: entry title. */
						__(
							'Showing only the broken links in “%s”.',
							'magic-linking'
						),
						only.title
					) }{ ' ' }
					<button
						type="button"
						className="button-link"
						onClick={ onClearOnly }
					>
						{ __( 'Show all', 'magic-linking' ) }
					</button>
				</p>
			) }

			<SearchBox
				id="magiclinking-broken-search"
				label={ __( 'Search broken links', 'magic-linking' ) }
				value={ search }
				onChange={ ( value ) => {
					setSearch( value );
					setPage( 1 );
				} }
			/>

			{ error && (
				<p className="magiclinking-error" role="alert">
					{ error }
				</p>
			) }

			<DataTable< BrokenItem, never >
				caption={ __( 'Broken internal links', 'magic-linking' ) }
				columns={ columns }
				rows={ data?.items ?? [] }
				rowKey={ ( row ) => row.id }
				rowActions={ rowActions }
				busy={ loading }
				emptyMessage={ __(
					'There are no broken internal links. Well done.',
					'magic-linking'
				) }
				actions={
					<a
						className="button"
						href={ `${ exportUrl }&${ exportParams.toString() }` }
					>
						{ __( 'Export CSV', 'magic-linking' ) }
					</a>
				}
				noun="links"
				page={ data?.page ?? page }
				totalPages={ data?.total_pages ?? 1 }
				total={ data?.total ?? 0 }
				onPage={ setPage }
			/>

			{ fixing && (
				<FixBrokenModal
					row={ fixing.row }
					mode={ fixing.mode }
					onClose={ () => setFixing( null ) }
					onFixed={ ( response ) => onFixed( fixing.row, response ) }
				/>
			) }
		</div>
	);
}
