import { Button, Spinner } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FilterName, SuggestionState } from '../types';

interface Props {
	state: SuggestionState;
	direction: 'outbound' | 'inbound';
	reportUrl: string;
	/** Filtros en modo Exigir que están activos (docs/04 §4.3). */
	required?: FilterName[];
	/** Dirección de Ajustes → Sugerencias, si el usuario puede abrirla. */
	settingsUrl?: string;
}

/**
 * Nombres de los filtros exigidos como lista legible («categoría y etiqueta»).
 * @param required Filtros en modo Exigir.
 */
function requiredNames( required: FilterName[] ): string {
	const names: Record< FilterName, string > = {
		type: __( 'content type', 'magic-linking' ),
		category: __( 'category', 'magic-linking' ),
		tag: __( 'tag', 'magic-linking' ),
	};
	const list = required.map( ( name ) => names[ name ] );
	try {
		return new Intl.ListFormat( document.documentElement.lang || 'en', {
			style: 'long',
			type: 'conjunction',
		} ).format( list );
	} catch ( e ) {
		return list.join( ', ' );
	}
}

/**
 * Explica por qué no hay tarjetas que enseñar (docs/07 §3: estado vacío con una explicación útil).
 * @param root0
 * @param root0.state
 * @param root0.direction
 * @param root0.reportUrl
 * @param root0.required
 * @param root0.settingsUrl
 */
export function EmptyState( {
	state,
	direction,
	reportUrl,
	required = [],
	settingsUrl = '',
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
	if ( required.length > 0 ) {
		return (
			<p className="magiclinking-panel__empty">
				{ sprintf(
					/* translators: %s: the filters that are required, such as "category" or "category and tag". */
					__(
						'There are no suggestions: only entries that match this one in %s are searched.',
						'magic-linking'
					),
					requiredNames( required )
				) }{ ' ' }
				{ settingsUrl
					? createInterpolateElement(
							__(
								'If you want more, change the filter in <a>Settings → Suggestions</a>.',
								'magic-linking'
							),
							// eslint-disable-next-line jsx-a11y/anchor-has-content -- El contenido lo pone createInterpolateElement.
							{ a: <a href={ settingsUrl } /> }
					  )
					: __(
							'If you want more, ask an administrator to change the filter in Settings → Suggestions.',
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
