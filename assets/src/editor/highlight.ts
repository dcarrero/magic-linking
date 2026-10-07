/**
 * Resaltado de una frase en el lienzo del editor sin tocar su contenido, con la API CSS Custom Highlight
 * (el resalte lo pinta el navegador sobre un rango; no hay marcas en el DOM ni en el contenido).
 * Sin esa API solo se desplaza la vista: el texto de la tarjeta es el equivalente (docs/07 §7).
 */

const NAME = 'magiclinking';
const STYLE_ID = 'magiclinking-highlight-style';

interface HighlightRegistry {
	set: ( name: string, value: unknown ) => void;
	delete: ( name: string ) => void;
}

interface HighlightWindow extends Window {
	Highlight?: new ( ...ranges: Range[] ) => unknown;
	CSS: typeof CSS & { highlights?: HighlightRegistry };
}

/** Documento donde el resalte está puesto ahora, para quitarlo de ahí. */
let current: Document | null = null;

function ensureStyle( doc: Document ): void {
	if ( doc.getElementById( STYLE_ID ) ) {
		return;
	}
	const style = doc.createElement( 'style' );
	style.id = STYLE_ID;
	style.textContent =
		`::highlight(${ NAME }){background-color:#ffd54a;color:#1e1e1e;}` +
		`@media (forced-colors: active){::highlight(${ NAME }){background-color:Highlight;color:HighlightText;}}`;
	doc.head.appendChild( style );
}

/**
 * Quita el resalte.
 */
export function clearHighlight(): void {
	const win = current?.defaultView as HighlightWindow | null | undefined;
	win?.CSS?.highlights?.delete( NAME );
	current = null;
}

/**
 * Resalta un rango y desplaza la vista hasta él.
 *
 * @param range Rango del DOM.
 */
export function showHighlight( range: Range ): void {
	clearHighlight();
	const doc = range.startContainer.ownerDocument;
	const win = doc?.defaultView as HighlightWindow | null | undefined;
	if ( ! doc || ! win ) {
		return;
	}

	if ( win.Highlight && win.CSS?.highlights ) {
		ensureStyle( doc );
		win.CSS.highlights.set( NAME, new win.Highlight( range ) );
		current = doc;
	}

	const element =
		range.startContainer.nodeType === 1
			? ( range.startContainer as Element )
			: range.startContainer.parentElement;
	const reduce = win.matchMedia?.( '(prefers-reduced-motion: reduce)' );
	element?.scrollIntoView( {
		block: 'center',
		behavior: reduce?.matches ? 'auto' : 'smooth',
	} );
}
