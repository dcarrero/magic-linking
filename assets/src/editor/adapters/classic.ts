/**
 * Editor clásico: localiza la frase en el cuerpo de TinyMCE y envuelve el ancla en un enlace (docs/06 §2).
 *
 * Solo funciona en la pestaña «Visual», donde hay un documento del que tomar el tramo: en «Texto» el contenido
 * es código y se pide cambiar de pestaña, en vez de adivinar posiciones dentro de etiquetas. El enlace se pone
 * dentro de una transacción de TinyMCE, así que el deshacer del editor lo quita de una vez.
 */
import type { Suggestion } from '../../types';
import { locate, rangeFromOffsets } from '../locate';
import type { TextUnit } from '../locate';
import { classicTextElements } from '../dom';
import { clearHighlight, showHighlight } from '../highlight';
import type { AdapterOptions, ApplyResult, EditorAdapter } from './types';

interface TinyMceEditor {
	isHidden: () => boolean;
	getContent: () => string;
	getBody: () => HTMLElement;
	nodeChanged: () => void;
	undoManager: {
		transact: ( callback: () => void ) => void;
	};
}

declare global {
	interface Window {
		tinymce?: { get: ( id: string ) => TinyMceEditor | null };
	}
}

const EDITOR_ID = 'content';

function visual(): TinyMceEditor | null {
	const editor = window.tinymce?.get( EDITOR_ID ) ?? null;
	return editor && ! editor.isHidden() ? editor : null;
}

/**
 * Elementos de texto hoja del cuerpo (los que lee el motor, sin contar dos veces un `p` dentro de un `li`).
 * @param body  Cuerpo del documento de TinyMCE.
 * @param scope Dónde más se enlaza.
 */
function units(
	body: HTMLElement,
	scope: AdapterOptions[ 'linkIn' ]
): {
	units: TextUnit[];
	elements: Element[];
} {
	const elements = classicTextElements( body, scope );
	return {
		elements,
		units: elements.map( ( element, index ) => ( {
			key: String( index ),
			text: element.textContent ?? '',
		} ) ),
	};
}

/**
 * Adaptador del editor clásico.
 *
 * @param postId  Entrada que se edita.
 * @param options Ajustes del usuario: dónde se enlaza y con qué atributos.
 */
export function classicAdapter(
	postId: number,
	options: AdapterOptions
): EditorAdapter {
	return {
		postId,

		getContent: () => {
			const editor = visual();
			if ( editor ) {
				return editor.getContent();
			}
			const area = document.getElementById(
				EDITOR_ID
			) as HTMLTextAreaElement | null;
			return area?.value ?? '';
		},

		getTitle: () =>
			( document.getElementById( 'title' ) as HTMLInputElement | null )
				?.value ?? '',

		// Al guardar, el editor clásico recarga la página y el panel se vuelve a montar con lo guardado.
		onSaved: () => () => undefined,

		// Idem: el panel envía lo pendiente al cargar la página que sigue al guardado.
		onSaveCycle: () => () => undefined,

		apply: ( suggestion: Suggestion ): ApplyResult => {
			const editor = visual();
			if ( ! editor ) {
				return { ok: false, reason: 'text_mode' };
			}
			const body = editor.getBody();
			const found = units( body, options.linkIn );
			const result = locate( found.units, suggestion );
			if ( result.status !== 'found' ) {
				return { ok: false, reason: result.status };
			}
			const element = found.elements[ Number( result.key ) ];
			const range = element
				? rangeFromOffsets( element, result.start, result.end )
				: null;
			if ( ! element || ! range ) {
				return { ok: false, reason: 'not_found' };
			}

			const inside = range.commonAncestorContainer;
			const host =
				inside instanceof Element ? inside : inside.parentElement;
			if (
				host?.closest( 'a' ) ||
				range.cloneContents().querySelector( 'a' )
			) {
				return { ok: false, reason: 'already_linked' };
			}

			const url = suggestion.target.url ?? '';
			const applied = range.toString();
			const link = body.ownerDocument.createElement( 'a' );
			editor.undoManager.transact( () => {
				link.setAttribute( 'href', url );
				link.setAttribute( 'data-mce-href', url );
				if ( options.linkAttrs.target ) {
					link.setAttribute( 'target', options.linkAttrs.target );
				}
				if ( options.linkAttrs.rel ) {
					link.setAttribute( 'rel', options.linkAttrs.rel );
				}
				link.appendChild( range.extractContents() );
				range.insertNode( link );
			} );
			editor.nodeChanged();
			clearHighlight();

			return {
				ok: true,
				anchor: applied,
				// Quita solo este `<a>`, si sigue en el documento tal como se puso: no usa el deshacer de
				// TinyMCE, que podría llevarse texto escrito después.
				undo: () => {
					const current = visual();
					if (
						! current ||
						! link.isConnected ||
						link.getAttribute( 'href' ) !== url ||
						link.textContent !== applied ||
						link.querySelector( 'a' )
					) {
						return false;
					}
					current.undoManager.transact( () => {
						const parent = link.parentNode;
						while ( parent && link.firstChild ) {
							parent.insertBefore( link.firstChild, link );
						}
						link.remove();
					} );
					current.nodeChanged();
					return true;
				},
			};
		},

		highlight: ( suggestion ) => {
			const editor = visual();
			if ( ! suggestion || ! editor ) {
				clearHighlight();
				return;
			}
			const found = units( editor.getBody(), options.linkIn );
			const result = locate( found.units, suggestion );
			const element =
				result.status === 'found'
					? found.elements[ Number( result.key ) ]
					: null;
			if ( result.status !== 'found' || ! element ) {
				clearHighlight();
				return;
			}
			const range = rangeFromOffsets( element, result.start, result.end );
			if ( range ) {
				showHighlight( range );
			}
		},
	};
}
