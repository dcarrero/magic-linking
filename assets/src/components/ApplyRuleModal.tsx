import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import type { RuleCandidate, RuleItem } from '../types';

interface Props {
	rule: RuleItem;
	onClose: () => void;
}

/** Estado de cada entrada de la lista. */
interface RowState {
	status: 'idle' | 'busy' | 'done' | 'undone' | 'failed';
	message: string;
	batchId: string;
}

/**
 * Aplicar una regla a las entradas que ya existen, **de una en una**: la búsqueda va por tandas (el servidor
 * devuelve un cursor y se puede seguir cuando se quiera), cada entrada muestra el enlace que se añadiría y dónde, y
 * *Add link* lo escribe con la inserción segura, con su entrada en el Historial y *Undo*. No hay «aplicar a todas»
 * (eso es de Pro).
 * @param root0
 * @param root0.rule
 * @param root0.onClose
 */
export function ApplyRuleModal( { rule, onClose }: Props ) {
	const [ items, setItems ] = useState< RuleCandidate[] >( [] );
	const [ cursor, setCursor ] = useState( 0 );
	const [ done, setDone ] = useState( false );
	const [ examined, setExamined ] = useState( 0 );
	const [ searching, setSearching ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ rows, setRows ] = useState< Record< number, RowState > >( {} );
	const statusRefs = useRef< Record< number, HTMLSpanElement | null > >( {} );
	const started = useRef( false );

	const search = async ( from: number ) => {
		setSearching( true );
		setError( '' );
		try {
			const response = await api.ruleCandidates( rule.id, from, 10 );
			setItems( ( previous ) => [ ...previous, ...response.items ] );
			setCursor( response.cursor );
			setDone( response.done );
			setExamined( ( previous ) => previous + response.examined );
			speak(
				response.items.length === 0 && response.done
					? __( 'No more entries fit this rule.', 'magic-linking' )
					: sprintf(
							/* translators: %d: number of entries found. */
							_n(
								'%d entry found',
								'%d entries found',
								response.items.length,
								'magic-linking'
							),
							response.items.length
					  ),
				'polite'
			);
		} catch ( e ) {
			setError(
				errorMessage(
					e,
					__( 'Could not search the entries.', 'magic-linking' )
				)
			);
		}
		setSearching( false );
	};

	useEffect( () => {
		if ( ! started.current ) {
			started.current = true;
			search( 0 );
		}
		// Una sola búsqueda al abrir.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const setRow = ( postId: number, state: RowState ) =>
		setRows( ( previous ) => ( { ...previous, [ postId ]: state } ) );

	const focusStatus = ( postId: number ) =>
		window.setTimeout( () => statusRefs.current[ postId ]?.focus(), 0 );

	const apply = async ( item: RuleCandidate ) => {
		setRow( item.post_id, { status: 'busy', message: '', batchId: '' } );
		try {
			const response = await api.applyRule( rule.id, item.post_id );
			if ( response.inserted > 0 ) {
				const text = sprintf(
					/* translators: %d: number of links added. */
					_n(
						'%d link added.',
						'%d links added.',
						response.inserted,
						'magic-linking'
					),
					response.inserted
				);
				setRow( item.post_id, {
					status: 'done',
					message: text,
					batchId: response.batch_id,
				} );
				speak( `${ item.title }: ${ text }`, 'polite' );
			} else {
				const text =
					response.failed[ 0 ]?.message ||
					response.skipped_message ||
					__( 'Nothing was added.', 'magic-linking' );
				setRow( item.post_id, {
					status: 'failed',
					message: text,
					batchId: '',
				} );
				speak( `${ item.title }: ${ text }`, 'assertive' );
			}
		} catch ( e ) {
			const text = errorMessage(
				e,
				__(
					'The link could not be added; nothing was changed.',
					'magic-linking'
				)
			);
			setRow( item.post_id, {
				status: 'failed',
				message: text,
				batchId: '',
			} );
			speak( `${ item.title }: ${ text }`, 'assertive' );
		}
		focusStatus( item.post_id );
	};

	const undo = async ( item: RuleCandidate ) => {
		const row = rows[ item.post_id ];
		if ( ! row?.batchId ) {
			return;
		}
		setRow( item.post_id, { ...row, status: 'busy' } );
		try {
			const response = await api.runHistory( 'undo', {
				batchId: row.batchId,
			} );
			const bad = response.results.some(
				( result ) =>
					result.status === 'manual' || result.status === 'failed'
			);
			const text = bad
				? __(
						'The link could not be put back automatically. Open the history to see why.',
						'magic-linking'
				  )
				: __( 'Undone: the entry is back as it was.', 'magic-linking' );
			setRow( item.post_id, {
				status: bad ? 'done' : 'undone',
				message: text,
				batchId: bad ? row.batchId : '',
			} );
			speak( `${ item.title }: ${ text }`, 'polite' );
		} catch ( e ) {
			setRow( item.post_id, {
				...row,
				status: 'done',
				message: errorMessage(
					e,
					__( 'The change could not be undone.', 'magic-linking' )
				),
			} );
		}
		focusStatus( item.post_id );
	};

	const phrase = rule.phrases[ 0 ] ?? '';

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: main phrase of the rule. */
				__( 'Apply “%s” to existing entries', 'magic-linking' ),
				phrase
			) }
			onRequestClose={ onClose }
			size="large"
		>
			<div className="magiclinking-apply">
				<p>
					{ __(
						'These are the entries where the rule fits. Check the link that would be added and add it entry by entry. Each one is written safely, shown in the history and can be undone.',
						'magic-linking'
					) }
				</p>

				<p role="status" aria-live="polite">
					{ searching
						? __( 'Searching…', 'magic-linking' )
						: sprintf(
								/* translators: 1: number of entries where the rule fits, 2: number of entries checked. */
								__(
									'%1$s where it fits, %2$s checked.',
									'magic-linking'
								),
								items.length.toLocaleString(),
								examined.toLocaleString()
						  ) }
				</p>

				{ error && (
					<p className="magiclinking-error" role="alert">
						{ error }
					</p>
				) }

				{ ! searching && items.length === 0 && done && ! error && (
					<p>
						{ __(
							'No entry needs this rule right now.',
							'magic-linking'
						) }
					</p>
				) }

				<ul className="magiclinking-candidates">
					{ items.map( ( item ) => {
						const row = rows[ item.post_id ] ?? {
							status: 'idle',
							message: '',
							batchId: '',
						};
						const added = row.status === 'done';
						return (
							<li key={ item.post_id }>
								<p className="magiclinking-candidate__title">
									<strong>{ item.title }</strong>
									{ item.edit_url && (
										<>
											{ ' · ' }
											<a href={ item.edit_url }>
												{ __(
													'Edit entry',
													'magic-linking'
												) }
												<span className="screen-reader-text">
													{ ' ' + item.title }
												</span>
											</a>
										</>
									) }
								</p>
								{ item.links.map( ( link, index ) => (
									<p
										key={ index }
										className="magiclinking-preview"
									>
										<span className="screen-reader-text">
											{ __(
												'The link would be added here:',
												'magic-linking'
											) }{ ' ' }
										</span>
										{ link.before }{ ' ' }
										<mark>{ link.anchor }</mark>{ ' ' }
										{ link.after }
									</p>
								) ) }
								<p className="magiclinking-actions">
									{ ( row.status === 'idle' ||
										row.status === 'busy' ||
										row.status === 'failed' ||
										row.status === 'undone' ) && (
										<Button
											variant="secondary"
											isBusy={ row.status === 'busy' }
											disabled={ row.status === 'busy' }
											accessibleWhenDisabled
											onClick={ () => apply( item ) }
										>
											{ __(
												'Add link',
												'magic-linking'
											) }
											<span className="screen-reader-text">
												{ ' ' + item.title }
											</span>
										</Button>
									) }
									{ added && row.batchId && (
										<Button
											variant="tertiary"
											onClick={ () => undo( item ) }
										>
											{ __( 'Undo', 'magic-linking' ) }
											<span className="screen-reader-text">
												{ ' ' + item.title }
											</span>
										</Button>
									) }
									<span
										ref={ ( node ) => {
											statusRefs.current[ item.post_id ] =
												node;
										} }
										tabIndex={ -1 }
										className={
											row.status === 'failed'
												? 'magiclinking-error'
												: ''
										}
									>
										{ row.message }
									</span>
								</p>
							</li>
						);
					} ) }
				</ul>

				<div className="magiclinking-actions">
					{ ! done && (
						<Button
							variant="secondary"
							onClick={ () => search( cursor ) }
							isBusy={ searching }
							disabled={ searching }
							accessibleWhenDisabled
						>
							{ items.length === 0
								? __( 'Keep searching', 'magic-linking' )
								: __( 'Find more entries', 'magic-linking' ) }
						</Button>
					) }
					<Button variant="primary" onClick={ onClose }>
						{ __( 'Close', 'magic-linking' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}
