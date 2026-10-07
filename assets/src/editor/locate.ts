/**
 * Localiza el ancla de una sugerencia en el texto que el usuario tiene abierto.
 *
 * El servidor propone una frase con el ancla marcada (`before` + ancla + `after`); aquí se busca esa frase en
 * el texto de cada bloque (o párrafo del editor clásico). Como en el servidor (docs/06 §3, D-48), manda el
 * contexto —30 caracteres a cada lado— y debe aparecer exactamente una vez: si no, no se toca nada.
 * Las secuencias de espacios (también `&nbsp;`) cuentan como uno solo, con la misma clase de espacios que el
 * servidor (`[\s\x{00A0}]` de PCRE: espacio, tabulador, saltos y NBSP; no U+2028, U+2029 ni otros espacios Unicode),
 * y los caracteres invisibles U+FEFF y U+200B no cuentan.
 *
 * Todas las posiciones son unidades UTF-16, como los índices de `String.prototype.slice`.
 */

/** Caracteres de contexto a cada lado del ancla. */
export const CONTEXT = 30;

/** Un trozo de texto donde puede estar el ancla: un bloque, un párrafo. */
export interface TextUnit {
	key: string;
	text: string;
}

/** La frase de una sugerencia, con el ancla en medio. */
export interface Target {
	before: string;
	anchor: string;
	after: string;
}

export type Located =
	| { status: 'found'; key: string; start: number; end: number }
	| { status: 'not_found' }
	| { status: 'ambiguous' };

interface Normalized {
	/** Texto con cada secuencia de espacios convertida en un solo espacio. */
	norm: string;
	/** Posición del texto original de cada carácter normalizado. */
	map: number[];
}

/** Espacios que el servidor colapsa: ASCII y NBSP (docs/06 §3). */
const SPACE = /[ \t\n\v\f\r\u00a0]/;
/** Invisibles que se ignoran. */
const INVISIBLE = /[\ufeff\u200b]/;

/**
 * Colapsa los espacios y recuerda de dónde sale cada carácter.
 *
 * @param text Texto original.
 */
export function normalize( text: string ): Normalized {
	let norm = '';
	const map: number[] = [];
	let inSpace = false;
	for ( let i = 0; i < text.length; i++ ) {
		const char = text.charAt( i );
		if ( INVISIBLE.test( char ) ) {
			continue;
		}
		if ( SPACE.test( char ) ) {
			if ( ! inSpace ) {
				norm += ' ';
				map.push( i );
			}
			inSpace = true;
		} else {
			norm += char;
			map.push( i );
			inSpace = false;
		}
	}
	return { norm, map };
}

/**
 * Busca el ancla en las unidades de texto.
 *
 * @param units  Dónde buscar.
 * @param target Frase y ancla de la sugerencia.
 */
export function locate( units: TextUnit[], target: Target ): Located {
	const anchor = normalize( target.anchor ).norm;
	if ( anchor.trim() === '' ) {
		return { status: 'not_found' };
	}
	const before = normalize( target.before ).norm.slice( -CONTEXT );
	const after = normalize( target.after ).norm.slice( 0, CONTEXT );
	const pattern = before + anchor + after;

	let found: Located = { status: 'not_found' };
	let count = 0;

	for ( const unit of units ) {
		const { norm, map } = normalize( unit.text );
		let from = 0;
		for (;;) {
			const at = norm.indexOf( pattern, from );
			if ( at === -1 ) {
				break;
			}
			count++;
			const first = at + before.length;
			const last = first + anchor.length - 1;
			found = {
				status: 'found',
				key: unit.key,
				start: map[ first ] ?? 0,
				end: ( map[ last ] ?? 0 ) + 1,
			};
			from = at + 1;
		}
	}

	if ( count > 1 ) {
		return { status: 'ambiguous' };
	}
	return found;
}

/**
 * Rango del DOM para un tramo del texto de un elemento (`textContent`).
 *
 * @param root  Elemento que contiene el texto.
 * @param start Inicio en `root.textContent`.
 * @param end   Final (exclusivo).
 */
export function rangeFromOffsets(
	root: Element,
	start: number,
	end: number
): Range | null {
	const doc = root.ownerDocument;
	const walker = doc.createTreeWalker( root, 4 /* NodeFilter.SHOW_TEXT */ );
	const range = doc.createRange();
	let position = 0;
	let started = false;
	for ( let node = walker.nextNode(); node; node = walker.nextNode() ) {
		const length = node.nodeValue?.length ?? 0;
		if ( ! started && start < position + length ) {
			range.setStart( node, start - position );
			started = true;
		}
		if ( started && end <= position + length ) {
			range.setEnd( node, end - position );
			return range;
		}
		position += length;
	}
	return null;
}
