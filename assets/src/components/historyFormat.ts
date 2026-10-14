import { __, _x } from '@wordpress/i18n';
import type { ChangeKind, HistoryOrigin } from '../types';

/**
 * Fecha del servidor a un Date: RFC 3339 (con o sin zona; sin zona es UTC) o el formato de MySQL
 * `YYYY-MM-DD HH:MM:SS` (UTC). Es la única función de fechas del servidor de la pantalla.
 * @param value Fecha.
 */
export function parseServerDate( value: string ): Date {
	const iso = value.replace( /^(\d{4}-\d\d-\d\d) (\d)/, '$1T$2' );
	return new Date( /(Z|[+-]\d\d:?\d\d)$/.test( iso ) ? iso : iso + 'Z' );
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
	).format( parseServerDate( value ) );
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
	).format( parseServerDate( value ) );
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
 * De dónde viene un cambio: el panel del editor (enlace saliente de la entrada que se editaba), el panel de otra entrada (enlace entrante) o el arreglo de enlaces rotos.
 * @param origin Origen.
 * @param kind   Acción.
 */
export function sourceLabel( origin: HistoryOrigin, kind: ChangeKind ): string {
	if ( origin === 'editor' ) {
		return _x( 'Panel (outbound)', 'history origin', 'magic-linking' );
	}
	return kind === 'insert'
		? _x( 'Panel (inbound)', 'history origin', 'magic-linking' )
		: _x( 'Broken links', 'history origin', 'magic-linking' );
}
