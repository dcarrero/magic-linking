import { Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { SuggestionState } from '../types';

interface Props {
	state: SuggestionState;
	direction: 'outbound' | 'inbound';
	reportUrl: string;
	/** Hay sugerencias descartadas en esta sesión que ocultan las demás. */
	dismissedAll?: boolean;
}

/**
 * Explica por qué no hay tarjetas que enseñar (docs/07 §3: estado vacío con una explicación útil).
 * @param root0
 * @param root0.state
 * @param root0.direction
 * @param root0.reportUrl
 * @param root0.dismissedAll
 */
export function EmptyState( {
	state,
	direction,
	reportUrl,
	dismissedAll = false,
}: Props ) {
	if ( state === 'index_not_ready' ) {
		return (
			<p className="magiclinking-panel__empty">
				{ __(
					'The site has not been analyzed yet, so there are no suggestions. Analyze it from the report and come back.',
					'magic-linking'
				) }{ ' ' }
				<a href={ reportUrl }>
					{ __( 'Open the report', 'magic-linking' ) }
				</a>
			</p>
		);
	}
	if ( state === 'not_analyzed' ) {
		return (
			<p className="magiclinking-panel__empty">
				{ __(
					'This entry is not analyzed yet. Save it and refresh in a moment, or check that its content type is analyzed in Settings.',
					'magic-linking'
				) }
			</p>
		);
	}
	if ( state === 'not_published' ) {
		return (
			<p className="magiclinking-panel__empty">
				{ __(
					'Other entries can only link to this one once it is published.',
					'magic-linking'
				) }
			</p>
		);
	}
	if ( dismissedAll ) {
		return (
			<p className="magiclinking-panel__empty">
				{ __(
					'You have dismissed all the suggestions. They come back when you refresh.',
					'magic-linking'
				) }
			</p>
		);
	}
	return (
		<p className="magiclinking-panel__empty">
			{ direction === 'outbound'
				? __(
						'There are no suggestions with enough confidence. This usually happens with very short entries or on a topic that has no other content on the site yet.',
						'magic-linking'
				  )
				: __(
						'No other entry has a sentence that fits a link to this one yet.',
						'magic-linking'
				  ) }
		</p>
	);
}

/**
 * Error de red o del servidor, con la forma de reintentar.
 *
 * @param root0
 * @param root0.message
 * @param root0.onRetry
 */
export function ErrorState( {
	message,
	onRetry,
}: {
	message: string;
	onRetry: () => void;
} ) {
	return (
		<div className="magiclinking-panel__error" role="alert">
			<p>{ message }</p>
			<Button variant="secondary" size="compact" onClick={ onRetry }>
				{ __( 'Try again', 'magic-linking' ) }
			</Button>
		</div>
	);
}

/**
 * Indicador de carga con texto.
 */
export function Loading() {
	return (
		<p className="magiclinking-panel__loading">
			<Spinner /> { __( 'Looking for suggestions…', 'magic-linking' ) }
		</p>
	);
}
