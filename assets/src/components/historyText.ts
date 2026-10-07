import { __, _n, sprintf } from '@wordpress/i18n';
import type { ChangeResult, ChangeStatus, HistoryIssue } from '../types';

/**
 * Etiqueta corta del resultado de un cambio (siempre texto, nunca solo color).
 * @param status Estado devuelto por el servidor.
 */
export function statusLabel( status: ChangeStatus ): string {
	switch ( status ) {
		case 'restored':
			return __( 'Undone', 'magic-linking' );
		case 'link_removed':
			return __( 'Link removed', 'magic-linking' );
		case 'already_gone':
			return __( 'Already gone', 'magic-linking' );
		case 'already_undone':
			return __( 'Already undone', 'magic-linking' );
		case 'redone':
			return __( 'Added again', 'magic-linking' );
		case 'manual':
			return __( 'Needs your attention', 'magic-linking' );
		default:
			return __( 'Not changed', 'magic-linking' );
	}
}

/**
 * Si el resultado necesita que el usuario haga algo.
 * @param status Estado.
 */
export function needsAttention( status: ChangeStatus ): boolean {
	return status === 'manual' || status === 'failed';
}

/**
 * Frase que resume una operación a partir del recuento por estado.
 * @param counts Cambios por estado.
 * @param redo   Si se rehacía.
 */
export function summaryText(
	counts: Partial< Record< ChangeStatus, number > >,
	redo: boolean
): string {
	const bad = ( counts.manual ?? 0 ) + ( counts.failed ?? 0 );
	const good = redo
		? counts.redone ?? 0
		: ( counts.restored ?? 0 ) +
		  ( counts.link_removed ?? 0 ) +
		  ( counts.already_gone ?? 0 ) +
		  ( counts.already_undone ?? 0 );

	if ( bad === 0 ) {
		return redo
			? sprintf(
					/* translators: %s: number of links. */
					_n(
						'%s link added again.',
						'%s links added again.',
						good,
						'magic-linking'
					),
					good.toLocaleString()
			  )
			: sprintf(
					/* translators: %s: number of links. */
					_n(
						'%s link undone.',
						'%s links undone.',
						good,
						'magic-linking'
					),
					good.toLocaleString()
			  );
	}

	return redo
		? sprintf(
				/* translators: 1: links added again, 2: links that need attention. */
				__(
					'Added again: %1$s. Need your attention: %2$s.',
					'magic-linking'
				),
				good.toLocaleString(),
				bad.toLocaleString()
		  )
		: sprintf(
				/* translators: 1: links undone, 2: links that need attention. */
				__(
					'Undone: %1$s. Need your attention: %2$s.',
					'magic-linking'
				),
				good.toLocaleString(),
				bad.toLocaleString()
		  );
}

/**
 * Cuenta los resultados por estado.
 * @param results Resultados de una operación.
 */
export function countStatuses(
	results: ReadonlyArray< ChangeResult | HistoryIssue >
): Partial< Record< ChangeStatus, number > > {
	const counts: Partial< Record< ChangeStatus, number > > = {};
	results.forEach( ( result ) => {
		counts[ result.status ] = ( counts[ result.status ] ?? 0 ) + 1;
	} );
	return counts;
}
