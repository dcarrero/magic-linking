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
import { clearHighlight, showHighlight } from '../highlight';
import type { ApplyResult, EditorAdapter } from './types';

interface TinyMceEditor {
	isHidden: () => boolean;
	getContent: () => string;
	getBody: () => HTMLElement;
	nodeChanged: () => void;
	undoManager: {
		transact: ( callback: () => void ) => void;
		undo: () => void;
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
 * Párrafos y elementos de lista que no están dentro de citas ni tablas (donde el servidor no escribe por defecto).
 * @param body
 */
function units( body: HTMLElement ): {
	units: TextUnit[];
	elements: Element[];
} {
	const elements = Array.from( body.querySelectorAll( 'p, li' ) ).filter(
		( element ) =>
			! element.closest( 'blockquote, table' ) &&
			! element.querySelector( 'ul, ol' )
	);
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
 * @param postId Entrada que se edita.
 */
export function classicAdapter( postId: number ): EditorAdapter {
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

		apply: ( suggestion: Suggestion ): ApplyResult => {
			const editor = visual();
			if ( ! editor ) {
				return { ok: false, reason: 'text_mode' };
			}
			const body = editor.getBody();
			const found = units( body );
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
			const before = editor.getContent();
			editor.undoManager.transact( () => {
				const link = body.ownerDocument.createElement( 'a' );
				link.setAttribute( 'href', url );
				link.setAttribute( 'data-mce-href', url );
				link.appendChild( range.extractContents() );
				range.insertNode( link );
			} );
			editor.nodeChanged();
			clearHighlight();
			const after = editor.getContent();

			return {
				ok: true,
				undo: () => {
					const current = visual();
					if (
						! current ||
						current.getContent() !== after ||
						before === after
					) {
						return false;
					}
					current.undoManager.undo();
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
			const found = units( editor.getBody() );
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
