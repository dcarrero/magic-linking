import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import { useDebounced, useLoad } from '../hooks';
import type { BrokenItem, FixResponse } from '../types';
import { ConfirmModal } from './ConfirmModal';
import { SearchBox } from './SearchBox';

interface Props {
	row: BrokenItem;
	mode: 'retarget' | 'unlink';
	onClose: () => void;
	/** El enlace se ha arreglado. */
	onFixed: ( response: FixResponse ) => void;
}

/**
 * Arreglar un enlace roto de la lista: elegir otro destino (buscador de entradas publicadas) o quitar el enlace
 * dejando el texto. Nada se escribe hasta confirmar; si el servidor se niega (la entrada cambió, está abierta en un
 * editor…) el mensaje se queda en el cuadro y no se ha tocado nada.
 * @param root0
 * @param root0.row
 * @param root0.mode
 * @param root0.onClose
 * @param root0.onFixed
 */
export function FixBrokenModal( { row, mode, onClose, onFixed }: Props ) {
	const [ search, setSearch ] = useState( '' );
	const [ selected, setSelected ] = useState< number | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ failure, setFailure ] = useState( '' );
	const debounced = useDebounced( search );
	const retarget = mode === 'retarget';

	const targets = useLoad(
		( signal ) =>
			retarget
				? api.brokenTargets( row.id, debounced, signal )
				: Promise.resolve( { items: [] } ),
		[ row.id, debounced, retarget ],
		__( 'Could not search the entries.', 'magic-linking' )
	);

	const confirm = async () => {
		if ( busy || ( retarget && selected === null ) ) {
			return;
		}
		setBusy( true );
		setFailure( '' );
		try {
			const response = await api.fixBroken(
				row.id,
				retarget && selected !== null
					? { mode: 'retarget', targetId: selected }
					: { mode: 'unlink' }
			);
			onFixed( response );
		} catch ( error ) {
			setFailure(
				errorMessage(
					error,
					__(
						'The link could not be fixed; nothing was changed.',
						'magic-linking'
					)
				)
			);
			setBusy( false );
		}
	};

	const anchor = row.anchor || __( '(no text)', 'magic-linking' );

	return (
		<ConfirmModal
			title={
				retarget
					? __( 'Change destination', 'magic-linking' )
					: __( 'Remove link', 'magic-linking' )
			}
			confirmLabel={
				retarget
					? __( 'Change destination', 'magic-linking' )
					: __( 'Remove link', 'magic-linking' )
			}
			busy={ busy }
			confirmDisabled={ retarget && selected === null }
			onConfirm={ confirm }
			onCancel={ onClose }
		>
			<p>
				{ sprintf(
					/* translators: 1: anchor text, 2: entry title. */
					__(
						'The link “%1$s” in “%2$s” leads nowhere.',
						'magic-linking'
					),
					anchor,
					row.source_title
				) }{ ' ' }
				<code className="magiclinking-url">{ row.url }</code>
			</p>

			{ retarget ? (
				<>
					<p>
						{ __(
							'Pick the entry it should lead to. Only the address changes: the text and the rest of the entry stay as they are, and you can undo it from the history.',
							'magic-linking'
						) }
					</p>
					<SearchBox
						id="magiclinking-fix-search"
						label={ __(
							'Search entries by title',
							'magic-linking'
						) }
						value={ search }
						onChange={ ( value ) => {
							setSearch( value );
							setSelected( null );
						} }
					/>
					<div role="status" className="screen-reader-text">
						{ ! targets.loading && targets.data
							? sprintf(
									/* translators: %d: number of entries found. */
									_n(
										'%d entry found',
										'%d entries found',
										targets.data.items.length,
										'magic-linking'
									),
									targets.data.items.length
							  )
							: '' }
					</div>
					{ targets.error && (
						<p className="magiclinking-error" role="alert">
							{ targets.error }
						</p>
					) }
					<fieldset
						className="magiclinking-targets"
						aria-busy={ targets.loading }
					>
						<legend className="screen-reader-text">
							{ __( 'New destination', 'magic-linking' ) }
						</legend>
						{ targets.data && targets.data.items.length === 0 && (
							<p>
								{ __(
									'No published entries match.',
									'magic-linking'
								) }
							</p>
						) }
						<ul>
							{ ( targets.data?.items ?? [] ).map( ( item ) => (
								<li key={ item.id }>
									<label
										htmlFor={ `magiclinking-fix-target-${ item.id }` }
									>
										<input
											id={ `magiclinking-fix-target-${ item.id }` }
											type="radio"
											name="magiclinking-fix-target"
											value={ item.id }
											checked={ selected === item.id }
											onChange={ () =>
												setSelected( item.id )
											}
										/>{ ' ' }
										<strong>{ item.title }</strong>
										{ ' · ' }
										{ item.type_label }
										<br />
										<span className="magiclinking-url">
											{ item.url }
										</span>
									</label>
								</li>
							) ) }
						</ul>
					</fieldset>
				</>
			) : (
				<p>
					{ __(
						'The link is taken out and its text stays exactly as it is. You can undo it from the history.',
						'magic-linking'
					) }
				</p>
			) }

			{ failure && (
				<p className="magiclinking-error" role="alert">
					{ failure }
				</p>
			) }
		</ConfirmModal>
	);
}
