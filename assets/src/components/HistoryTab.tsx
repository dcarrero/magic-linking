import { useCallback, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { HistoryBatches } from './HistoryBatches';
import { HistoryEntries } from './HistoryEntries';

interface Props {
	/** Dirección de Ajustes (solo para quien puede administrar). */
	settingsUrl?: string;
}

type View = 'entry' | 'batch';

/**
 * Texto de cuánto se conserva el historial.
 * @param days Días (0 = sin caducidad).
 */
function retentionText( days: number ): string {
	return days > 0
		? sprintf(
				/* translators: %s: number of days. */
				_n(
					'Changes are kept for %s day.',
					'Changes are kept for %s days.',
					days,
					'magic-linking'
				),
				days.toLocaleString()
		  )
		: __( 'Changes are kept with no expiry date.', 'magic-linking' );
}

/**
 * Pestaña *Historial*: por defecto, los cambios agrupados por entrada (una cabecera por entrada y una fila compacta
 * por cambio); la vista por lote queda para deshacer de una vez lo que tocó varias entradas (D-59).
 * @param root0
 * @param root0.settingsUrl
 */
export function HistoryTab( { settingsUrl }: Props ) {
	const [ view, setView ] = useState< View >( 'entry' );
	const [ retention, setRetention ] = useState( 90 );
	const onRetention = useCallback(
		( days: number ) => setRetention( days ),
		[]
	);

	const choose = ( next: View ) => {
		if ( next === view ) {
			return;
		}
		setView( next );
		speak(
			next === 'entry'
				? __( 'Showing changes by entry.', 'magic-linking' )
				: __( 'Showing changes by batch.', 'magic-linking' ),
			'polite'
		);
	};

	const switcher = (
		<div className="magiclinking-viewswitch">
			<span id="magiclinking-viewswitch-label">
				{ __( 'View by:', 'magic-linking' ) }
			</span>
			<div
				className="magiclinking-segmented"
				role="group"
				aria-labelledby="magiclinking-viewswitch-label"
			>
				<button
					type="button"
					aria-pressed={ view === 'entry' }
					onClick={ () => choose( 'entry' ) }
				>
					{ __( 'Entry', 'magic-linking' ) }
				</button>
				<button
					type="button"
					aria-pressed={ view === 'batch' }
					onClick={ () => choose( 'batch' ) }
				>
					{ __( 'Batch', 'magic-linking' ) }
				</button>
			</div>
		</div>
	);

	return (
		<>
			<p className="description">
				{ __(
					'Every link Magic Linking changes is listed here so you can undo it. Links you add from the editor panel appear once you save the entry; Magic Linking only records them.',
					'magic-linking'
				) }{ ' ' }
				{ retentionText( retention ) }{ ' ' }
				{ settingsUrl && (
					<a href={ `${ settingsUrl }#tab=data` }>
						{ __(
							'Change how long they are kept',
							'magic-linking'
						) }
					</a>
				) }
			</p>

			{ view === 'entry' ? (
				<HistoryEntries
					onRetention={ onRetention }
					leading={ switcher }
				/>
			) : (
				<>
					<div className="magiclinking-toolbar">{ switcher }</div>
					<HistoryBatches onRetention={ onRetention } />
				</>
			) }
		</>
	);
}
