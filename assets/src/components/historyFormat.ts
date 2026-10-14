import { __ } from '@wordpress/i18n';
import type { ChangeKind, HistoryOrigin } from '../types';

/**
 * Fecha del servidor (UTC, a veces sin zona) a un Date.
 * @param value Fecha RFC 3339.
 */
export function parseDate( value: string ): Date {
	return new Date(
		/(Z|[+-]\d\d:?\d\d)$/.test( value ) ? value : value + 'Z'
	);
}

/**
 * Fecha y hora en el idioma del usuario, con el formato de la pantalla.
 * @param value Fecha RFC 3339 del servidor.
 */
export function formatDate( value: string ): string {
	return new Intl.DateTimeFormat(
		document.documentElement.lang || undefined,
		{
			dateStyle: 'medium',
			timeStyle: 'short',
		}
	).format( parseDate( value ) );
}

/**
 * Fecha y hora completas (con segundos), para el atributo `title`.
 * @param value Fecha RFC 3339 del servidor.
 */
export function formatFullDate( value: string ): string {
	return new Intl.DateTimeFormat(
		document.documentElement.lang || undefined,
		{
			dateStyle: 'full',
			timeStyle: 'medium',
		}
	).format( parseDate( value ) );
}

/**
 * Título de una entrada, con texto para las que no tienen o ya no existen.
 * @param title Título (null = la entrada ya no existe).
 */
export function entryTitle( title: string | null ): string {
	if ( title === null ) {
		return __( '(deleted entry)', 'magic-linking' );
	}
	return title === '' ? __( '(no title)', 'magic-linking' ) : title;
}

/**
 * Qué hizo un cambio, en una palabra.
 * @param kind Acción.
 */
export function actionLabel( kind: ChangeKind ): string {
	switch ( kind ) {
		case 'retarget':
			return __( 'Destination changed', 'magic-linking' );
		case 'unlink':
			return __( 'Link removed', 'magic-linking' );
		default:
			return __( 'Added', 'magic-linking' );
	}
}

/**
 * De dónde viene un cambio: el editor, el arreglo de enlaces rotos o Magic Linking.
 * @param origin Origen.
 * @param kind   Acción.
 */
export function sourceLabel( origin: HistoryOrigin, kind: ChangeKind ): string {
	if ( origin === 'editor' ) {
		return __( 'Editor', 'magic-linking' );
	}
	return kind === 'insert'
		? __( 'Magic Linking', 'magic-linking' )
		: __( 'Broken links', 'magic-linking' );
}
