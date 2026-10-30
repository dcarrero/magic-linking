import { __, _n, _x, sprintf } from '@wordpress/i18n';
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

const formatters = new Map< string, Intl.DateTimeFormat >();

/**
 * Idioma de la página como etiqueta BCP 47 válida; los locales de WordPress que no lo son
 * (p. ej. `pt-PT-ao90`) caen al idioma del navegador en vez de lanzar.
 */
function pageLocale(): string | undefined {
	const lang = document.documentElement.lang;
	if ( ! lang ) {
		return undefined;
	}
	try {
		return Intl.getCanonicalLocales( lang )[ 0 ];
	} catch {
		return undefined;
	}
}

/**
 * Formateador de fechas cacheado por idioma y estilo (se llama una vez por fila en cada render).
 * @param style           Estilos de fecha y hora.
 * @param style.dateStyle
 * @param style.timeStyle
 */
function formatter( style: {
	dateStyle: 'medium' | 'full';
	timeStyle: 'short' | 'medium';
} ): Intl.DateTimeFormat {
	const locale = pageLocale();
	const key = `${ locale ?? '' }|${ style.dateStyle }|${ style.timeStyle }`;
	let cached = formatters.get( key );
	if ( ! cached ) {
		cached = new Intl.DateTimeFormat( locale, style );
		formatters.set( key, cached );
	}
	return cached;
}

/**
 * Da formato a una fecha del servidor; si no es válida devuelve el texto tal cual.
 * @param value           Fecha del servidor.
 * @param style           Estilos de fecha y hora.
 * @param style.dateStyle
 * @param style.timeStyle
 */
function format(
	value: string,
	style: Parameters< typeof formatter >[ 0 ]
): string {
	const date = parseServerDate( value );
	return Number.isNaN( date.getTime() )
		? value
		: formatter( style ).format( date );
}

/**
 * Fecha y hora en el idioma del usuario, con el formato de la pantalla.
 * @param value Fecha RFC 3339 del servidor.
 */
export function formatDate( value: string ): string {
	return format( value, { dateStyle: 'medium', timeStyle: 'short' } );
}

/**
 * Fecha y hora completas (con segundos), para el atributo `title`.
 * @param value Fecha RFC 3339 del servidor.
 */
export function formatFullDate( value: string ): string {
	return format( value, { dateStyle: 'full', timeStyle: 'medium' } );
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

export type OriginKey = 'panel' | 'other_panel' | 'broken' | 'rule';

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
	if ( origin === 'rule' ) {
		return 'rule';
	}
	return kind === 'insert' ? 'other_panel' : 'broken';
}

/**
 * De dónde viene un cambio, dicho sin dirección (en la vista por entrada todas las filas son enlaces escritos en
 * esa entrada): el panel del editor, el panel de otra entrada o el arreglo de enlaces rotos. Si la IA recomendó el
 * enlace (F1-38) lo dice con texto, no solo con color: «Panel del editor · IA».
 * @param origin Origen.
 * @param kind   Acción.
 * @param ai     Si la IA recomendó el enlace.
 */
export function sourceLabel(
	origin: HistoryOrigin,
	kind: ChangeKind,
	ai = false
): string {
	switch ( originKey( origin, kind ) ) {
		case 'panel':
			return ai
				? _x( 'Editor panel · AI', 'history origin', 'magic-linking' )
				: _x( 'Editor panel', 'history origin', 'magic-linking' );
		case 'other_panel':
			return ai
				? _x(
						"Another entry's panel · AI",
						'history origin',
						'magic-linking'
				  )
				: _x(
						"Another entry's panel",
						'history origin',
						'magic-linking'
				  );
		case 'rule':
			return ai
				? _x( 'Automatic rule · AI', 'history origin', 'magic-linking' )
				: _x( 'Automatic rule', 'history origin', 'magic-linking' );
		default:
			return _x( 'Broken links', 'history origin', 'magic-linking' );
	}
}

/**
 * Detalle de un enlace que recomendó la IA: el modelo (si se registró) y si se usó su ancla.
 * @param ai        Lo que se sabe de la IA en el cambio.
 * @param ai.model
 * @param ai.anchor
 * @return Líneas de texto, en orden; vacío si no hay nada que añadir.
 */
export function aiDetails( ai: { model: string; anchor: boolean } ): string[] {
	const lines: string[] = [];
	if ( ai.model ) {
		lines.push(
			sprintf(
				/* translators: %s: exact identifier of the AI model that recommended the link. */
				__( 'AI model: %s', 'magic-linking' ),
				ai.model
			)
		);
	}
	if ( ai.anchor ) {
		lines.push( __( 'Anchor suggested by the AI', 'magic-linking' ) );
	}
	return lines;
}

/**
 * Cuántos enlaces de un lote recomendó la IA, dicho como parte del total («2 de 3 enlaces recomendados por la IA»);
 * vacío si ninguno. Cuenta los mismos enlaces que el lote, estén puestos o deshechos.
 * @param aiLinks Enlaces del lote que recomendó la IA.
 * @param links   Enlaces del lote.
 */
export function batchAiLabel( aiLinks = 0, links = 0 ): string {
	return aiLinks > 0
		? sprintf(
				/* translators: 1: number of links in the batch that the AI recommended, 2: number of links in the batch. */
				_n(
					'%1$s of %2$s link recommended by the AI',
					'%1$s of %2$s links recommended by the AI',
					links,
					'magic-linking'
				),
				aiLinks.toLocaleString(),
				links.toLocaleString()
		  )
		: '';
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
		case 'rule':
			return __( 'Added by an automatic rule', 'magic-linking' );
		default:
			return __( 'Fixed from Broken links', 'magic-linking' );
	}
}
