import { dateI18n, getSettings } from '@wordpress/date';
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

/** Lo que se muestra cuando no hay fecha (vacía, cero de MySQL o ilegible). */
const NO_DATE = '—';

/**
 * Fecha del servidor, o `null` si no es una fecha real: vacía, ilegible o la cero de MySQL
 * (`0000-00-00 00:00:00`, que no existe antes de 1970).
 * @param value Fecha del servidor.
 */
function validServerDate( value: string ): Date | null {
	const date = parseServerDate( value );
	return Number.isNaN( date.getTime() ) || date.getTime() <= 0 ? null : date;
}

/**
 * Valor del atributo `dateTime` de un `<time>`: el instante en ISO 8601, o nada si la fecha no es real.
 * @param value Fecha del servidor.
 */
export function dateTimeAttr( value: string ): string | undefined {
	return validServerDate( value )?.toISOString();
}

/**
 * Quita los caracteres escapados con `\` de un formato de fecha de PHP, para mirar solo sus códigos.
 * @param pattern Formato.
 */
function codes( pattern: string ): string {
	return pattern.replace( /\\./g, '' );
}

/**
 * Si el formato ya muestra la zona (`T`, `e`, `O`, `P`, `Z`, `c`, `r`).
 * @param pattern Formato.
 */
function hasZone( pattern: string ): boolean {
	return /[TeOPZcr]/.test( codes( pattern ) );
}

/**
 * Quita una barra final suelta, que escaparía lo que se pegue detrás.
 * @param pattern Formato.
 */
function closed( pattern: string ): string {
	return /(?:^|[^\\])(?:\\\\)*\\$/.test( pattern )
		? pattern.slice( 0, -1 )
		: pattern;
}

interface SitePatterns {
	/** Fecha y hora, como en wp-admin. */
	short: string;
	/** Lo mismo más la zona (salvo que el formato ya la muestre), para el `title`. */
	full: string;
	/** Desfase que hay que pasar a `dateI18n` cuando moment no tiene datos de la zona con nombre. */
	offset: number | undefined;
}

let cached:
	| {
			date: string;
			time: string;
			zone: string;
			offset: number;
			value: SitePatterns;
	  }
	| undefined;

/**
 * Formatos de fecha del sitio. Se construyen una vez por ajustes, porque se piden por cada fila de las tablas.
 */
export function sitePatterns(): SitePatterns {
	const { formats, timezone } = getSettings();
	if (
		cached?.date === formats.date &&
		cached.time === formats.time &&
		cached.zone === timezone.string &&
		cached.offset === timezone.offset
	) {
		return cached.value;
	}
	// Con una zona con nombre que moment no conoce (sin sus datos) `dateI18n` caería a la hora del navegador:
	// se usa el desfase de los ajustes, igual que con un desfase manual («UTC+2»), que no tiene abreviatura.
	const named = !! timezone.string && dateI18n( 'e', new Date( 0 ) ) !== '';
	const date = closed( formats.date );
	const time = closed( formats.time );
	let zone = named ? 'T' : 'P';
	if ( hasZone( date ) || hasZone( time ) ) {
		zone = '';
	}
	const join = ( ...parts: string[] ) => parts.filter( Boolean ).join( ' ' );
	const value = {
		short: join( date, time ),
		full: join( date, time, zone ),
		offset: timezone.string && ! named ? timezone.offset : undefined,
	};
	cached = {
		date: formats.date,
		time: formats.time,
		zone: timezone.string,
		offset: timezone.offset,
		value,
	};
	return value;
}

/**
 * Fecha y hora con la zona horaria y los formatos del sitio (Ajustes → Generales), no los del navegador, para que
 * coincida con wp-admin, WP-CLI y el CSV. `wp.date` recibe esos ajustes del núcleo.
 * @param value Fecha del servidor.
 * @param full  Con la zona (para el atributo `title`).
 */
function format( value: string, full: boolean ): string | undefined {
	const date = validServerDate( value );
	if ( ! date ) {
		return undefined;
	}
	const patterns = sitePatterns();
	return dateI18n(
		full ? patterns.full : patterns.short,
		date,
		patterns.offset
	);
}

/**
 * Fecha y hora en la zona y el formato del sitio.
 * @param value Fecha GMT del servidor.
 */
export function formatDate( value: string ): string {
	return format( value, false ) ?? NO_DATE;
}

/**
 * Fecha y hora completas (con la zona), para el atributo `title`; `undefined` si no hay fecha real, para
 * omitir el atributo.
 * @param value Fecha GMT del servidor.
 */
export function formatFullDate( value: string ): string | undefined {
	return format( value, true );
}

/**
 * Duración legible entre dos fechas.
 * @param from Inicio.
 * @param to   Fin.
 */
export function formatDuration( from: string, to: string ): string {
	const start = validServerDate( from );
	const end = validServerDate( to );
	if ( ! start || ! end ) {
		return NO_DATE;
	}
	const seconds = Math.max(
		0,
		Math.round( ( end.getTime() - start.getTime() ) / 1000 )
	);
	if ( seconds < 60 ) {
		return sprintf(
			/* translators: %s: number of seconds. */
			__( '%s s', 'magic-linking' ),
			String( seconds )
		);
	}
	return sprintf(
		/* translators: 1: minutes, 2: seconds. */
		__( '%1$s min %2$s s', 'magic-linking' ),
		String( Math.floor( seconds / 60 ) ),
		String( seconds % 60 )
	);
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
