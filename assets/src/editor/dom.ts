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
 * de citas y tablas salvo que el usuario lo permita en Ajustes, y los encabezados igual.
 *
 * @param body           Cuerpo del documento de TinyMCE.
 * @param scope          Dónde más se enlaza.
 * @param scope.headings
 * @param scope.quotes
 * @param scope.tables
 */
export function classicTextElements(
	body: Element,
	scope: { headings: boolean; quotes: boolean; tables: boolean } = {
		headings: false,
		quotes: false,
		tables: false,
	}
): Element[] {
	const selector = [
		CLASSIC_TEXT_BLOCKS,
		scope.headings ? 'h1, h2, h3, h4, h5, h6' : '',
		scope.tables ? 'td, th' : '',
		scope.quotes ? 'blockquote' : '',
	]
		.filter( Boolean )
		.join( ', ' );
	const excluded = [
		'pre',
		scope.quotes ? '' : 'blockquote',
		scope.tables ? '' : 'table',
	]
		.filter( Boolean )
		.join( ', ' );

	// Como el servidor, un `figure` solo se lee si envuelve una tabla o una cita que el usuario permite.
	const readable = ( figure: Element ) =>
		( scope.tables && figure.querySelector( 'table' ) !== null ) ||
		( scope.quotes && figure.querySelector( 'blockquote' ) !== null );

	return Array.from( body.querySelectorAll( selector ) ).filter(
		( element ) => {
			const figure = element.closest( 'figure' );
			return (
				( ! figure || readable( figure ) ) &&
				! element.closest( excluded ) &&
				! element.querySelector( selector ) &&
				! element.closest( 'cite' )
			);
		}
	);
}
