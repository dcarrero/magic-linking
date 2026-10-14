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

export type OriginKey = 'panel' | 'other_panel' | 'broken';

/**
 * Desde dónde se puso un cambio (la única clasificación de origen de la pantalla): el panel de la propia entrada,
 * el panel de otra entrada (el servidor lo escribió) o el arreglo de enlaces rotos.
 * @param origin Origen.
 * @param kind   Acción (sin ella, una inserción).
 */
export function originKey(
	origin: HistoryOrigin,
	kind: ChangeKind = 'insert'
): OriginKey {
	if ( origin === 'editor' ) {
		return 'panel';
	}
	return kind === 'insert' ? 'other_panel' : 'broken';
}

/**
 * De dónde viene un cambio, dicho sin dirección (en la vista por entrada todas las filas son enlaces escritos en
 * esa entrada): el panel del editor, el panel de otra entrada o el arreglo de enlaces rotos.
 * @param origin Origen.
 * @param kind   Acción.
 */
export function sourceLabel( origin: HistoryOrigin, kind: ChangeKind ): string {
	switch ( originKey( origin, kind ) ) {
		case 'panel':
			return _x( 'Editor panel', 'history origin', 'magic-linking' );
		case 'other_panel':
			return _x(
				"Another entry's panel",
				'history origin',
				'magic-linking'
			);
		default:
			return _x( 'Broken links', 'history origin', 'magic-linking' );
	}
}

/**
 * Texto de origen de la tarjeta de un lote.
 * @param origin Origen.
 * @param kind   Acción del primer cambio del lote.
 */
export function batchOriginLabel(
	origin: HistoryOrigin,
	kind?: ChangeKind
): string {
	switch ( originKey( origin, kind ) ) {
		case 'panel':
			return __( 'Added from the editor panel', 'magic-linking' );
		case 'other_panel':
			return __( "Added from another entry's panel", 'magic-linking' );
		default:
			return __( 'Fixed from Broken links', 'magic-linking' );
	}
}
