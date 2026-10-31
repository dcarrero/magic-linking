import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import { api, errorMessage } from '../api';
import type {
	EngineTimingResponse,
	EngineTimingRow,
	StatusResponse,
} from '../types';
import { formatDate } from './historyFormat';

interface Props {
	/** Estado del índice, ya leído por la pantalla (el progreso en marcha se ve arriba, en `IndexStatus`). */
	status: StatusResponse | null;
}

/**
 * Milisegundos con una décima y la unidad.
 * @param value Milisegundos.
 */
export function msText( value: number ): string {
	return sprintf(
		/* translators: %s: number of milliseconds. */
		_x( '%s ms', 'milliseconds', 'magic-linking' ),
		value.toLocaleString( undefined, {
			minimumFractionDigits: 1,
			maximumFractionDigits: 1,
		} )
	);
}

/**
 * Si el p95 cumple el presupuesto, dicho con palabras (no solo con color).
 * @param row Medidas de una dirección.
 */
export function budgetText( row: EngineTimingRow ): string {
	const target = sprintf(
		/* translators: %s: target time, e.g. "500 ms". */
		__( 'target under %s', 'magic-linking' ),
		msText( row.budget )
	);
	return row.p95 <= row.budget
		? sprintf(
				/* translators: %s: the target, e.g. "target under 500.0 ms". */
				__( 'Within budget (%s)', 'magic-linking' ),
				target
		  )
		: sprintf(
				/* translators: %s: the target, e.g. "target under 500.0 ms". */
				__( 'Over budget (%s)', 'magic-linking' ),
				target
		  );
}

/**
 * Pestaña *Estado* (D-30): cuántas entradas hay en el índice y cuánto tarda el motor en las últimas peticiones de
 * sugerencias. El progreso de un análisis en marcha ya se ve encima de todas las pestañas.
 * @param root0
 * @param root0.status
 */
export function StatusTab( { status: initial }: Props ) {
	const [ status, setStatus ] = useState< StatusResponse | null >( initial );
	const [ statusError, setStatusError ] = useState( '' );
	const [ timing, setTiming ] = useState< EngineTimingResponse | null >(
		null
	);
	const [ error, setError ] = useState( '' );
	const [ loading, setLoading ] = useState( true );

	// Recarga el estado del índice y los tiempos; cada uno falla por su cuenta y «Refresh» reintenta los dos.
	const load = useCallback( async ( announce: boolean ) => {
		setLoading( true );
		const [ index, times ] = await Promise.allSettled( [
			api.status(),
			api.engineTiming(),
		] );
		if ( index.status === 'fulfilled' ) {
			setStatus( index.value );
			setStatusError( '' );
		} else {
			setStatusError(
				errorMessage(
					index.reason,
					__( 'Could not read the index status.', 'magic-linking' )
				)
			);
		}
		if ( times.status === 'fulfilled' ) {
			setTiming( times.value );
			setError( '' );
		} else {
			setError(
				errorMessage(
					times.reason,
					__( 'Could not read the engine times.', 'magic-linking' )
				)
			);
		}
		if (
			announce &&
			index.status === 'fulfilled' &&
			times.status === 'fulfilled'
		) {
			speak( __( 'Engine times refreshed.', 'magic-linking' ), 'polite' );
		}
		setLoading( false );
	}, [] );

	useEffect( () => {
		load( false );
	}, [ load ] );

	const rows: Array< [ string, EngineTimingRow ] > = timing
		? [
				[ __( 'Outgoing', 'magic-linking' ), timing.outgoing ],
				[ __( 'Incoming', 'magic-linking' ), timing.incoming ],
		  ]
		: [];
	const any = rows.some( ( [ , row ] ) => row.count > 0 );

	return (
		<div className="magiclinking-status-tab">
			<h2>{ __( 'Index', 'magic-linking' ) }</h2>
			{ status && (
				<p>
					{ sprintf(
						/* translators: 1: entries in the index, 2: entries that can be analyzed. */
						__(
							'%1$s of %2$s entries are in the index.',
							'magic-linking'
						),
						status.indexed.toLocaleString(),
						status.eligible.toLocaleString()
					) }
				</p>
			) }
			{ ! status && statusError && (
				<Notice status="error" isDismissible={ false }>
					{ statusError }
				</Notice>
			) }
			{ ! status && ! statusError && <Spinner /> }
			{ status?.last_done && (
				<p className="description">
					{ sprintf(
						/* translators: %s: date and time of the last analysis. */
						__( 'Last analysis: %s', 'magic-linking' ),
						formatDate( status.last_done.updated_at )
					) }
				</p>
			) }

			<h2>{ __( 'Engine time', 'magic-linking' ) }</h2>
			<p className="description">
				{ sprintf(
					/* translators: %s: number of requests kept per direction. */
					_n(
						'Measured on the last %s suggestions request recorded for each direction. To avoid writing on every keystroke in the editor, at most one write every few seconds is kept, so some requests are not recorded. The times are stored on this site only and are never sent anywhere.',
						'Measured on the last %s suggestions requests recorded for each direction. To avoid writing on every keystroke in the editor, at most one write every few seconds is kept, so some requests are not recorded. The times are stored on this site only and are never sent anywhere.',
						timing?.keep ?? 20,
						'magic-linking'
					),
					( timing?.keep ?? 20 ).toLocaleString()
				) }
			</p>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ timing && ! any && (
				<p>
					{ __(
						'No suggestions have been calculated yet. Open an entry in the editor or the Report to see the times here.',
						'magic-linking'
					) }
				</p>
			) }
			{ timing && any && (
				<>
					<table className="widefat striped magiclinking-timing">
						<caption className="screen-reader-text">
							{ __(
								'Time of the suggestions engine, in milliseconds',
								'magic-linking'
							) }
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Direction', 'magic-linking' ) }
								</th>
								<th scope="col">
									{ __( 'Requests', 'magic-linking' ) }
								</th>
								<th scope="col">
									{ __( 'Median', 'magic-linking' ) }
								</th>
								<th scope="col">
									{ __( '95th percentile', 'magic-linking' ) }
								</th>
								<th scope="col">
									{ __( 'Budget', 'magic-linking' ) }
								</th>
								<th scope="col">
									{ __( 'From cache', 'magic-linking' ) }
								</th>
								<th scope="col">
									{ __( 'Candidates', 'magic-linking' ) }
								</th>
								<th scope="col">
									{ __( 'Retrieval', 'magic-linking' ) }
								</th>
								<th scope="col">
									{ __( 'Loading', 'magic-linking' ) }
								</th>
								<th scope="col">
									{ __( 'Phrases', 'magic-linking' ) }
								</th>
								<th scope="col">
									{ __( 'Scoring', 'magic-linking' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( [ label, row ] ) => (
								<tr key={ label }>
									<th scope="row">{ label }</th>
									{ row.count === 0 ? (
										<>
											<td>0</td>
											<td colSpan={ 9 }>
												{ __(
													'No requests yet.',
													'magic-linking'
												) }
											</td>
										</>
									) : (
										<>
											<td>
												{ row.count.toLocaleString() }
											</td>
											<td>{ msText( row.median ) }</td>
											<td>{ msText( row.p95 ) }</td>
											<td>{ budgetText( row ) }</td>
											<td>
												{ row.cached.toLocaleString() }
											</td>
											<td>
												{ row.candidates.toLocaleString(
													undefined,
													{
														maximumFractionDigits: 1,
													}
												) }
											</td>
											<td>{ msText( row.retrieval ) }</td>
											<td>{ msText( row.load ) }</td>
											<td>{ msText( row.phrases ) }</td>
											<td>{ msText( row.scoring ) }</td>
										</>
									) }
								</tr>
							) ) }
						</tbody>
					</table>
					<p className="description">
						{ __(
							'Median and 95th percentile cover every recorded request, including those answered from the cache. Candidates and phases cover only the requests the engine calculated. Retrieval is analyzing the entry and finding candidate targets; loading is reading the targets or sources; phrases is looking for where to place the anchor; scoring is rating each one. Sorting, reasons and removing ignored words are not in any phase, and the phases are medians, so they do not have to add up to the total.',
							'magic-linking'
						) }
					</p>
				</>
			) }
			<p>
				<Button
					variant="secondary"
					disabled={ loading }
					accessibleWhenDisabled
					onClick={ () => load( true ) }
				>
					{ __( 'Refresh', 'magic-linking' ) }
				</Button>
			</p>
		</div>
	);
}
