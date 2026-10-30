import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Pager de una lista de sugerencias: Anterior, «Página 2 de 4», Siguiente.
 *
 * @param root0
 * @param root0.label
 * @param root0.page
 * @param root0.totalPages
 * @param root0.onPage
 * @param root0.disabled
 */
export function Pager( {
	label,
	page,
	totalPages,
	onPage,
	disabled,
}: {
	/** Nombre accesible de la navegación. */
	label: string;
	page: number;
	totalPages: number;
	onPage: ( page: number ) => void;
	disabled: boolean;
} ) {
	if ( totalPages <= 1 ) {
		return null;
	}
	return (
		<nav className="magiclinking-pager" aria-label={ label }>
			<Button
				variant="secondary"
				size="compact"
				disabled={ disabled || page <= 1 }
				accessibleWhenDisabled
				onClick={ () => onPage( page - 1 ) }
			>
				{ __( 'Previous', 'magic-linking' ) }
			</Button>
			<span aria-live="off">
				{ sprintf(
					/* translators: 1: current page, 2: total pages. */
					__( 'Page %1$s of %2$s', 'magic-linking' ),
					String( page ),
					String( totalPages )
				) }
			</span>
			<Button
				variant="secondary"
				size="compact"
				disabled={ disabled || page >= totalPages }
				accessibleWhenDisabled
				onClick={ () => onPage( page + 1 ) }
			>
				{ __( 'Next', 'magic-linking' ) }
			</Button>
		</nav>
	);
}
