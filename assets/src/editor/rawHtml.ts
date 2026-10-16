/**
 * Texto y enlaces dentro de HTML en bruto, sin pasar por el DOM (F1-41).
 *
 * El bloque Clásico (`core/freeform`) guarda su contenido como una cadena de HTML escrita a mano o por TinyMCE:
 * párrafos sin `<p>` separados por líneas en blanco, `<strong>`, entidades, dobles espacios, `[caption]`… Reescribirlo
 * con un DOM o con rich-text cambiaría comillas, entidades y espacios de todo el bloque. Aquí el enlace se inserta
 * **a nivel de cadena**: se construye la vista de texto normalizado con la posición de cada carácter en el HTML
 * original, se localiza la frase en esa vista y se añaden solo `<a …>` y `</a>`. El resto de la cadena no se toca.
 *
 * Es el mismo algoritmo, regla por regla, que `MagicLinking\Content\TextView`, `Linker` y `ClassicEditor` del
 * servidor (docs/06 §3 y §4, D-48): lo que el servidor sugiere es lo que este código puede insertar.
 * Todas las posiciones son unidades UTF-16 de la cadena de JavaScript.
 */
import { decodeEntities } from '@wordpress/html-entities';

/** Carácter que ocupa el lugar de algo que corta el texto. Nunca forma parte de un ancla ni de un contexto. */
export const BREAK = '\x1F';
/** Marca de orden de bytes: `\s` de JavaScript la cuenta como espacio y la de PCRE no. */
const BOM = String.fromCharCode( 0xfeff );
/**
 * Espacios que colapsa el servidor: `[\s\x{00A0}]` con el modificador `u` de PCRE, que en PHP activa las propiedades de
 * Unicode (también U+202F, U+2028, U+2003…). `\s` de JavaScript es casi lo mismo; falta U+0085.
 */
const SPACE = /^[\s\u0085]$/u;
/** Separador que el extractor del motor pone en lugar de un código en línea o una imagen. */
export const SEPARATOR = '·';

/** Elementos vacíos de HTML. */
const VOID = [
	'area',
	'base',
	'br',
	'col',
	'embed',
	'hr',
	'img',
	'input',
	'link',
	'meta',
	'param',
	'source',
	'track',
	'wbr',
];
/** Elementos vacíos que cortan el texto. */
const VOID_BREAKS = [ 'br', 'hr', 'img', 'input', 'embed' ];
/** Elementos que el extractor sustituye por un separador en lugar de cortar el párrafo. */
const SEPARATORS = [ 'code', 'kbd', 'samp', 'img' ];
/** Elementos cuyo contenido no es texto del artículo. */
const OPAQUE = [
	'code',
	'kbd',
	'samp',
	'pre',
	'script',
	'style',
	'textarea',
	'svg',
	'math',
	'template',
	'select',
	'noscript',
];
/** Elementos con contenido que no se puede recorrer con seguridad. */
const UNSAFE = [
	'script',
	'style',
	'textarea',
	'svg',
	'math',
	'template',
	'title',
	'iframe',
	'xmp',
	'plaintext',
];
/** Elementos en línea que pueden quedar dentro del enlace. */
const INLINE = [
	'strong',
	'em',
	'b',
	'i',
	'u',
	's',
	'strike',
	'mark',
	'sub',
	'sup',
	'span',
	'abbr',
	'cite',
	'del',
	'ins',
	'small',
	'bdi',
	'bdo',
	'q',
	'dfn',
	'var',
	'time',
	'data',
	'font',
];
/** Elementos dentro de los cuales no se enlaza nunca. */
const NEVER = [
	'button',
	'label',
	'figcaption',
	'caption',
	'summary',
	'option',
];
const HEADINGS = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];

/** Dónde más se enlaza además de párrafos y listas (Ajustes). */
export interface Scope {
	headings: boolean;
	quotes: boolean;
	tables: boolean;
}

/** Tipo de cada carácter de la vista. */
const TEXT = 0;
const SHORTCODE = 1;
const CUT = 2;

/** Etiqueta no vacía: inicio, fin (exclusivo), nombre y si cierra. */
type Tag = [ number, number, string, boolean ];

/** Vista de texto de un tramo de HTML, con el HTML que ocupa cada carácter. */
export interface RawView {
	/** Texto normalizado: entidades decodificadas, espacios colapsados, shortcodes como un espacio. */
	text: string;
	/** Primer índice del HTML de cada carácter de `text`. */
	from: number[];
	/** Índice siguiente al último del HTML de cada carácter. */
	to: number[];
	/** 0 texto, 1 shortcode, 2 corte. */
	kind: number[];
	/** Tramo de texto (índice en `ancestors`) de cada carácter. */
	run: number[];
	/** Elementos abiertos en cada tramo de texto. */
	ancestors: string[][];
	tags: Tag[];
	tagByEnd: Map< number, number >;
	tagByStart: Map< number, number >;
	/** Nombre del primer elemento que este analizador no recorre con seguridad, o null. */
	unsafe: string | null;
}

const TOKEN =
	/<!--[\s\S]*?-->|<\?[\s\S]*?>|<![^>]*>|<\/?[a-zA-Z][^\s/>]*(?:"[^"]*"|'[^']*'|[^>"'])*>/g;
const BLANK_LINE = /\r?\n[ \t\r\f]*\r?\n/;
const BLANK_LINES = /\r?\n[ \t\r\f]*\r?\n\s*/g;
const SHORTCODE_TAG = /(\[\/?[a-z][\w-]*(?:\s[^\]]*)?\])/gi;
const ENTITY = /&(?:#[0-9]+|#[xX][0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);/g;

/**
 * Construye la vista de texto de un tramo de HTML del editor clásico: el salto de línea simple se conserva y una
 * línea en blanco corta el texto (un párrafo nuevo).
 *
 * @param html HTML del bloque tal como está.
 */
export function buildView( html: string ): RawView {
	const view: RawView = {
		text: '',
		from: [],
		to: [],
		kind: [],
		run: [],
		ancestors: [],
		tags: [],
		tagByEnd: new Map(),
		tagByStart: new Map(),
		unsafe: null,
	};
	const chars: string[] = [];
	const stack: string[] = [];
	let cursor = 0;
	let opaque = 0;

	const emit = (
		char: string,
		start: number,
		end: number,
		kind: number,
		open: string[]
	) => {
		const last = chars.length - 1;
		// Un corte seguido de otro es un solo corte; un espacio de shortcode junto a otro espacio, uno.
		if (
			last >= 0 &&
			char === BREAK &&
			chars[ last ] === BREAK &&
			view.kind[ last ] === CUT
		) {
			view.to[ last ] = end;
			return;
		}
		if (
			last >= 0 &&
			kind === SHORTCODE &&
			chars[ last ] === ' ' &&
			view.kind[ last ] === TEXT
		) {
			view.kind[ last ] = SHORTCODE;
			view.to[ last ] = end;
			return;
		}
		const lastRun = view.ancestors[ view.ancestors.length - 1 ];
		if (
			lastRun === undefined ||
			lastRun.length !== open.length ||
			lastRun.some( ( name, i ) => name !== open[ i ] )
		) {
			view.ancestors.push( [ ...open ] );
		}
		chars.push( char );
		view.from.push( start );
		view.to.push( end );
		view.kind.push( kind );
		view.run.push( view.ancestors.length - 1 );
	};

	const space = ( start: number, end: number, open: string[] ) => {
		const last = chars.length - 1;
		if (
			last >= 0 &&
			( view.kind[ last ] ?? TEXT ) < CUT &&
			chars[ last ] === ' '
		) {
			view.to[ last ] = Math.max( view.to[ last ] ?? 0, end );
			return;
		}
		emit( ' ', start, end, TEXT, open );
	};

	/**
	 * Una unidad del HTML (un carácter o una entidad ya decodificada) que ocupa [start, end).
	 * @param text
	 * @param start
	 * @param end
	 * @param open
	 */
	const plain = (
		text: string,
		start: number,
		end: number,
		open: string[]
	) => {
		for ( const char of text ) {
			if ( SPACE.test( char ) && char !== BOM ) {
				space( start, end, open );
				continue;
			}
			// Un carácter fuera del plano básico son dos unidades UTF-16: las dos apuntan al mismo tramo.
			for ( let i = 0; i < char.length; i++ ) {
				emit( char.charAt( i ), start, end, TEXT, open );
			}
		}
	};

	const decode = ( raw: string, start: number, open: string[] ) => {
		let at = 0;
		const literal = ( piece: string, base: number ) => {
			let offset = 0;
			for ( const char of piece ) {
				plain( char, base + offset, base + offset + char.length, open );
				offset += char.length;
			}
		};
		for ( const match of raw.matchAll( ENTITY ) ) {
			const index = match.index ?? 0;
			literal( raw.slice( at, index ), start + at );
			const decoded = decodeEntities( match[ 0 ] );
			if ( decoded !== match[ 0 ] ) {
				plain(
					decoded,
					start + index,
					start + index + match[ 0 ].length,
					open
				);
			} else {
				literal( match[ 0 ], start + index );
			}
			at = index + match[ 0 ].length;
		}
		literal( raw.slice( at ), start + at );
	};

	/**
	 * Texto entre dos etiquetas, sin líneas en blanco: separa los shortcodes y decodifica el resto.
	 * @param raw
	 * @param start
	 * @param open
	 */
	const plainGap = ( raw: string, start: number, open: string[] ) => {
		if ( raw === '' ) {
			return;
		}
		let at = 0;
		for ( const match of raw.matchAll( SHORTCODE_TAG ) ) {
			const index = match.index ?? 0;
			decode( raw.slice( at, index ), start + at, open );
			emit(
				' ',
				start + index,
				start + index + match[ 0 ].length,
				SHORTCODE,
				open
			);
			at = index + match[ 0 ].length;
		}
		decode( raw.slice( at ), start + at, open );
	};

	const gap = (
		start: number,
		end: number,
		open: string[],
		hidden: boolean
	) => {
		if ( hidden ) {
			return;
		}
		const raw = html.slice( start, end );
		if ( BLANK_LINE.test( raw ) ) {
			let at = 0;
			for ( const blank of raw.matchAll( BLANK_LINES ) ) {
				const index = blank.index ?? 0;
				plainGap( raw.slice( at, index ), start + at, open );
				emit(
					BREAK,
					start + index,
					start + index + blank[ 0 ].length,
					CUT,
					open
				);
				at = index + blank[ 0 ].length;
			}
			plainGap( raw.slice( at ), start + at, open );
			return;
		}
		plainGap( raw, start, open );
	};

	/**
	 * Un elemento que corta el texto: el separador del extractor o un corte que no casa con nada.
	 * @param name
	 * @param start
	 * @param end
	 * @param open
	 */
	const cut = (
		name: string,
		start: number,
		end: number,
		open: string[]
	) => {
		if ( ! SEPARATORS.includes( name ) ) {
			emit( BREAK, start, end, CUT, open );
			return;
		}
		const last = chars.length - 1;
		if ( last >= 0 && chars[ last ] !== ' ' ) {
			emit( ' ', start, end, TEXT, open );
		}
		emit( SEPARATOR, start, end, CUT, open );
	};

	for ( const match of html.matchAll( TOKEN ) ) {
		const token = match[ 0 ];
		const at = match.index ?? 0;
		if ( at > cursor ) {
			gap( cursor, at, stack, opaque > 0 );
		}
		cursor = at + token.length;

		const parts = /^<(\/?)([a-zA-Z][^\s/>]*)/.exec( token );
		if ( ! parts ) {
			// Un comentario (`<!--more-->`, `<!--nextpage-->`…) corta el texto: ni el ancla ni su contexto lo cruzan,
			// y un enlace nunca lo envuelve. Igual que `TextView` en el servidor.
			if ( token.startsWith( '<!--' ) && opaque === 0 ) {
				emit( BREAK, at, cursor, CUT, stack );
			}
			continue;
		}
		const name = ( parts[ 2 ] ?? '' ).toLowerCase();
		const closer = parts[ 1 ] === '/';

		if ( UNSAFE.includes( name ) ) {
			view.unsafe = name;
		}
		if ( VOID.includes( name ) ) {
			if ( ! closer && VOID_BREAKS.includes( name ) ) {
				cut( name, at, cursor, stack );
			}
			continue;
		}

		const index = view.tags.length;
		view.tags.push( [ at, cursor, name, closer ] );
		view.tagByEnd.set( cursor, index );
		view.tagByStart.set( at, index );

		if ( ! closer ) {
			stack.push( name );
			if ( OPAQUE.includes( name ) ) {
				if ( opaque === 0 ) {
					cut( name, at, cursor, stack );
				}
				opaque++;
			}
			continue;
		}

		const depth = stack.lastIndexOf( name );
		if ( depth !== -1 ) {
			stack.length = depth;
			if ( OPAQUE.includes( name ) && opaque > 0 ) {
				opaque--;
			}
		}
	}
	if ( cursor < html.length ) {
		gap( cursor, html.length, stack, opaque > 0 );
	}

	view.text = chars.join( '' );
	return view;
}

/**
 * Elementos dentro de los cuales no se enlaza, según los Ajustes.
 *
 * @param scope Dónde más se enlaza.
 */
export function forbidden( scope: Scope ): string[] {
	return [
		...NEVER,
		...( scope.headings ? [] : HEADINGS ),
		...( scope.quotes ? [] : [ 'blockquote' ] ),
		...( scope.tables ? [] : [ 'table' ] ),
	];
}

/**
 * Por qué no se puede enlazar el tramo de caracteres [from, to) de la vista, o null si se puede.
 *
 * @param view  Vista.
 * @param from  Primer carácter.
 * @param to    Carácter siguiente al último.
 * @param never Elementos dentro de los cuales no se enlaza (`forbidden()`).
 * @return `link` (ya hay un enlace), `shortcode`, `break` o `element:<nombre>`.
 */
export function blocker(
	view: RawView,
	from: number,
	to: number,
	never: string[]
): string | null {
	for ( let i = from; i < to; i++ ) {
		if ( view.kind[ i ] === SHORTCODE ) {
			return 'shortcode';
		}
		if ( view.kind[ i ] === CUT ) {
			return 'break';
		}
		const names = view.ancestors[ view.run[ i ] ?? 0 ] ?? [];
		// La cita de una cita (`<blockquote>…<cite>`) no es texto enlazable, igual que en el motor.
		if (
			names.includes( 'cite' ) &&
			names.includes( 'blockquote' ) &&
			! names.includes( 'a' )
		) {
			return 'element:cite';
		}
		for ( const name of names ) {
			if ( name === 'a' ) {
				return 'link';
			}
			if ( never.includes( name ) ) {
				return 'element:' + name;
			}
		}
	}
	return null;
}

/**
 * Ajusta el tramo [start, end) del HTML para que sea una sucesión de etiquetas bien anidada: lo que se abre dentro
 * se cierra dentro y viceversa. Si falta el otro extremo de una etiqueta y está justo al lado (el ancla empieza tras
 * `<strong>` y acaba tras `</strong>`), el tramo lo incluye; si no, no hay forma de enlazarlo sin mal anidar y se
 * devuelve null: **un ancla que cruza formato se rechaza, nunca se recorta** (D-48.3).
 *
 * @param view  Vista.
 * @param start Primer índice del HTML.
 * @param end   Índice siguiente al último.
 */
export function balanced(
	view: RawView,
	start: number,
	end: number
): [ number, number ] | null {
	const stack: string[] = [];
	const pending: string[] = [];

	for ( const [ tagStart, tagEnd, name, closer ] of view.tags ) {
		if ( tagStart < start || tagEnd > end ) {
			continue;
		}
		if ( ! INLINE.includes( name ) ) {
			return null;
		}
		if ( ! closer ) {
			stack.push( name );
			continue;
		}
		if ( stack.length > 0 && stack[ stack.length - 1 ] === name ) {
			stack.pop();
		} else if ( stack.includes( name ) ) {
			return null;
		} else {
			pending.push( name );
		}
	}

	for ( const name of pending ) {
		const index = view.tagByEnd.get( start );
		const tag = index === undefined ? undefined : view.tags[ index ];
		if ( ! tag || tag[ 3 ] || tag[ 2 ] !== name ) {
			return null;
		}
		start = tag[ 0 ];
	}

	while ( stack.length > 0 ) {
		const name = stack.pop();
		const index = view.tagByStart.get( end );
		const tag = index === undefined ? undefined : view.tags[ index ];
		if ( ! tag || ! tag[ 3 ] || tag[ 2 ] !== name ) {
			return null;
		}
		end = tag[ 1 ];
	}

	return [ start, end ];
}

/**
 * Escapa un valor para ponerlo entre comillas dobles en un atributo.
 *
 * @param value Valor.
 */
function attribute( value: string ): string {
	return value
		.replace( /&/g, '&amp;' )
		.replace( /"/g, '&quot;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' );
}

/**
 * Etiqueta de apertura del enlace: `href` y, solo si están configurados, `target` y `rel` (los mismos atributos que
 * en los demás bloques).
 *
 * @param url          Dirección del destino.
 * @param attrs        `target` y `rel` de los Ajustes.
 * @param attrs.target
 * @param attrs.rel
 */
export function openTag(
	url: string,
	attrs: { target?: string; rel?: string }
): string {
	let tag = `<a href="${ attribute( url ) }"`;
	if ( attrs.target ) {
		tag += ` target="${ attribute( attrs.target ) }"`;
	}
	if ( attrs.rel ) {
		tag += ` rel="${ attribute( attrs.rel ) }"`;
	}
	return tag + '>';
}

export type WrapResult =
	| {
			ok: true;
			/** HTML nuevo, con las dos piezas. */
			html: string;
			/** Lugar de la etiqueta de apertura en el HTML nuevo (y de lo que había en el original). */
			start: number;
			/** Lo que quedó dentro del enlace, tal como estaba en el original. */
			inner: string;
			open: string;
	  }
	| { ok: false; reason: 'crosses_format' | 'unsafe' };

/**
 * Pone el enlace sobre el tramo de caracteres [from, to) de la vista, tocando solo dos puntos de la cadena: inserta
 * la etiqueta de apertura y `</a>`. Después comprueba que quitar exactamente esas dos piezas devuelve el HTML original
 * y que el texto que ve el lector no cambia; si algo falla, no devuelve nada que escribir.
 *
 * @param html Contenido del bloque tal como está.
 * @param view Su vista de texto.
 * @param from Primer carácter del ancla.
 * @param to   Carácter siguiente al último.
 * @param open Etiqueta de apertura (`openTag()`).
 */
export function wrap(
	html: string,
	view: RawView,
	from: number,
	to: number,
	open: string
): WrapResult {
	const range = balanced(
		view,
		view.from[ from ] ?? 0,
		view.to[ to - 1 ] ?? 0
	);
	if ( ! range ) {
		return { ok: false, reason: 'crosses_format' };
	}
	const [ start, end ] = range;
	const inner = html.slice( start, end );
	const next =
		html.slice( 0, start ) + open + inner + '</a>' + html.slice( end );
	if ( ! onlyLinkAdded( html, next, start, end, open, view.text ) ) {
		return { ok: false, reason: 'unsafe' };
	}
	return { ok: true, html: next, start, inner, open };
}

/**
 * Comprueba que `next` es `html` con una etiqueta de apertura en `start` y un `</a>` en `end` y nada más: las dos piezas
 * están donde tienen que estar, quitarlas devuelve el original y el texto que ve el lector no ha cambiado.
 *
 * @param html  Original.
 * @param next  Con el enlace.
 * @param start Índice de la apertura en el original.
 * @param end   Índice del cierre en el original.
 * @param open  Etiqueta de apertura.
 * @param text  Texto de la vista del original.
 */
export function onlyLinkAdded(
	html: string,
	next: string,
	start: number,
	end: number,
	open: string,
	text: string
): boolean {
	const closeAt = end + open.length;
	if (
		next.slice( start, start + open.length ) !== open ||
		next.slice( closeAt, closeAt + 4 ) !== '</a>'
	) {
		return false;
	}
	const stripped =
		next.slice( 0, start ) +
		next.slice( start + open.length, closeAt ) +
		next.slice( closeAt + 4 );
	return stripped === html && buildView( next ).text === text;
}

/**
 * Quita el enlace que `wrap()` puso, si sigue tal como estaba. Se busca el tramo exacto `apertura + interior + </a>`:
 * primero donde se puso; si el usuario escribió delante y se movió, donde aparezca una sola vez. Otro enlace igual que
 * ya existía (mismo destino y mismos atributos) no estorba, salvo que sea idéntico también por dentro y no se sepa
 * cuál es. Si no, no se toca nada.
 *
 * @param html       Contenido actual del bloque.
 * @param link       Lo que devolvió `wrap()`.
 * @param link.start
 * @param link.inner
 * @param link.open
 * @return El HTML sin el enlace, o null si no se puede quitar con seguridad.
 */
export function unwrap(
	html: string,
	link: { start: number; inner: string; open: string }
): string | null {
	const whole = link.open + link.inner + '</a>';
	let at = html.startsWith( whole, link.start ) ? link.start : -1;
	if ( at === -1 ) {
		at = html.indexOf( whole );
		if ( at === -1 || html.indexOf( whole, at + 1 ) !== -1 ) {
			return null;
		}
	}
	return html.slice( 0, at ) + link.inner + html.slice( at + whole.length );
}

/**
 * Si la dirección del destino sirve para un enlace: http o https y sin espacios. El servidor rechaza la vacía o la que
 * `esc_url()` descarta (`Linker::open_tag`); aquí se pide además http(s), que es lo que son los permalinks.
 *
 * @param url Dirección.
 */
export function isLinkUrl( url: string ): boolean {
	return /^https?:\/\/[^\s]+$/i.test( url );
}
