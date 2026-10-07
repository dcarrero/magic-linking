/**
 * Ayudas de DOM del panel: de qué elemento se lee el texto propio de un bloque o párrafo.
 */

/**
 * Elemento que contiene el texto propio de un bloque del editor de bloques: el rich-text editable. Un `li` padre
 * contiene también sus listas anidadas, cuyo texto es de otros bloques y no se cuenta.
 *
 * @param block Elemento `[data-block]` del lienzo.
 */
export function ownEditable( block: Element ): Element {
	if ( block.getAttribute( 'contenteditable' ) === 'true' ) {
		return block;
	}
	return block.querySelector( '[contenteditable="true"]' ) ?? block;
}

/** Elementos de bloque del editor clásico donde puede haber texto (los que lee el extractor del servidor). */
const CLASSIC_TEXT_BLOCKS =
	'p, li, div, dd, dt, summary, details, section, article, header, footer, aside, main, address, center';

/**
 * Elementos de texto «hoja» del cuerpo del editor clásico: sin descendientes que sean también de texto, de modo
 * que un `p` dentro de un `li` (o un `div` que envuelve párrafos) cuenta una sola vez. Se ignora lo que está dentro
 * de citas y tablas, que el motor no lee.
 *
 * @param body Cuerpo del documento de TinyMCE.
 */
export function classicTextElements( body: Element ): Element[] {
	return Array.from( body.querySelectorAll( CLASSIC_TEXT_BLOCKS ) ).filter(
		( element ) =>
			! element.closest( 'blockquote, table, figure, pre' ) &&
			! element.querySelector( CLASSIC_TEXT_BLOCKS )
	);
}
