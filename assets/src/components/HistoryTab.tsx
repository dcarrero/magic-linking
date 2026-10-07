import { Button, Notice } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import type { HistoryGroup } from '../types';
import { HistoryGroupCard } from './HistoryGroupCard';

interface Props {
	/** Dirección de Ajustes (solo para quien puede administrar). */
	settingsUrl?: string;
}

const PER_PAGE = 20;

/**
 * Texto de cuánto se conserva el historial.
 * @param days Días (0 = sin caducidad).
 */
function retentionText( days: number ): string {
	return days > 0
		? sprintf(
				/* translators: %s: number of days. */
				__( 'Changes are kept for %s days.', 'magic-linking' ),
				days.toLocaleString()
		  )
		: __( 'Changes are kept with no expiry date.', 'magic-linking' );
}

/**
 * Pestaña *Historial*: cada enlace que Magic Linking ha escrito en el contenido, agrupado por acción, con
 * Deshacer y Rehacer para cualquier grupo conservado. Sin tope de número de acciones: se pagina con
 * «Mostrar anteriores».
 * @param root0
 * @param root0.settingsUrl
 */
export function HistoryTab( { settingsUrl }: Props ) {
	const [ groups, setGroups ] = useState< HistoryGroup[] >( [] );
	const [ next, setNext ] = useState< string | null >( null );
	const [ retention, setRetention ] = useState( 90 );
	const [ loading, setLoading ] = useState( true );
	const [ loadingMore, setLoadingMore ] = useState( false );
	const [ error, setError ] = useState( '' );
	const focusAfter = useRef< string | null >( null );

	useEffect( () => {
		const controller = new AbortController();
		api.history( '', PER_PAGE, controller.signal )
			.then( ( response ) => {
				setGroups( response.items );
				setNext( response.next );
				setRetention( response.retention_days );
				setLoading( false );
				speak(
					response.items.length === 0
						? __(
								'There are no changes in the history.',
								'magic-linking'
						  )
						: sprintf(
								/* translators: %s: number of batches. */
								_n(
									'%s batch in the history.',
									'%s batches in the history.',
									response.items.length,
									'magic-linking'
								),
								response.items.length.toLocaleString()
						  ),
					'polite'
				);
			} )
			.catch( ( e: unknown ) => {
				if ( ! controller.signal.aborted ) {
					setError(
						errorMessage(
							e,
							__( 'Could not load the history.', 'magic-linking' )
						)
					);
					setLoading( false );
				}
			} );
		return () => controller.abort();
	}, [] );

	// Tras «Mostrar anteriores» el foco pasa al primer grupo nuevo (el botón desaparece si no hay más).
	useEffect( () => {
		if ( focusAfter.current ) {
			document
				.getElementById( `magiclinking-batch-${ focusAfter.current }` )
				?.focus();
			focusAfter.current = null;
		}
	}, [ groups ] );

	const loadMore = async () => {
		if ( ! next ) {
			return;
		}
		setLoadingMore( true );
		setError( '' );
		try {
			const response = await api.history( next, PER_PAGE );
			const first = response.items[ 0 ];
			if ( first ) {
				focusAfter.current = first.batch_id;
			}
			setGroups( ( previous ) => [ ...previous, ...response.items ] );
			setNext( response.next );
			speak(
				sprintf(
					/* translators: %s: number of batches. */
					_n(
						'%s older batch shown.',
						'%s older batches shown.',
						response.items.length,
						'magic-linking'
					),
					response.items.length.toLocaleString()
				),
				'polite'
			);
		} catch ( e ) {
			setError(
				errorMessage(
					e,
					__( 'Could not load the history.', 'magic-linking' )
				)
			);
		} finally {
			setLoadingMore( false );
		}
	};

	const onChange = useCallback(
		( batchId: string ) => ( group: HistoryGroup | null ) =>
			setGroups( ( previous ) =>
				group
					? previous.map( ( item ) =>
							item.batch_id === batchId
								? { ...group, job: group.job ?? item.job }
								: item
					  )
					: previous.filter( ( item ) => item.batch_id !== batchId )
			),
		[]
	);

	if ( loading ) {
		return <p>{ __( 'Loading…', 'magic-linking' ) }</p>;
	}

	return (
		<>
			<p className="description">
				{ __(
					'Every link Magic Linking writes into your content is listed here, newest first, so you can undo it.',
					'magic-linking'
				) }{ ' ' }
				{ retentionText( retention ) }{ ' ' }
				{ settingsUrl && (
					<a href={ settingsUrl }>
						{ __(
							'Change how long they are kept',
							'magic-linking'
						) }
					</a>
				) }
			</p>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ groups.length === 0 && ! error && (
				<p>
					{ __(
						'No changes yet. When you add a link with Magic Linking it will appear here, and you will be able to undo it.',
						'magic-linking'
					) }
				</p>
			) }

			{ groups.length > 0 && (
				<ol className="magiclinking-history">
					{ groups.map( ( group ) => (
						<HistoryGroupCard
							key={ group.batch_id }
							group={ group }
							onChange={ onChange( group.batch_id ) }
						/>
					) ) }
				</ol>
			) }

			{ next && (
				<p>
					<Button
						variant="secondary"
						onClick={ loadMore }
						isBusy={ loadingMore }
						disabled={ loadingMore }
						accessibleWhenDisabled
					>
						{ __( 'Show older batches', 'magic-linking' ) }
					</Button>
				</p>
			) }
		</>
	);
}
