/**
 * Editor de bloques: localiza la frase en el estado del editor y pone el formato de enlace (docs/06 §2).
 *
 * El enlace se escribe en el cliente con `core/block-editor`, en el bloque que contiene la frase y sin tocar
 * ningún otro; el usuario guarda y deshace con el editor. Nada se escribe en el servidor.
 */
import { select, dispatch, subscribe } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import { applyFormat, create, toHTMLString } from '@wordpress/rich-text';
import type { Suggestion } from '../../types';
import { locate, rangeFromOffsets } from '../locate';
import type { TextUnit } from '../locate';
import { clearHighlight, showHighlight } from '../highlight';
import type { ApplyResult, EditorAdapter } from './types';

/** Bloques de texto donde se pone el enlace: los mismos que admite el servidor por defecto (docs/06 §3). */
const LINKABLE = [ 'core/paragraph', 'core/list-item' ];
/** Dentro de estos bloques el servidor no escribe sin que el usuario lo active en Ajustes. */
const SKIPPED_PARENTS = [ 'core/quote', 'core/pullquote', 'core/table' ];

interface Block {
	clientId: string;
	name: string;
	attributes: { content?: unknown };
	innerBlocks: Block[];
}

function collect( blocks: Block[], out: Block[] = [], skip = false ): Block[] {
	for ( const block of blocks ) {
		const skipChildren = skip || SKIPPED_PARENTS.includes( block.name );
		if (
			! skip &&
			LINKABLE.includes( block.name ) &&
			block.attributes.content !== undefined
		) {
			out.push( block );
		}
		collect( block.innerBlocks, out, skipChildren );
	}
	return out;
}

function linkable(): Block[] {
	return collect(
		select( blockEditorStore ).getBlocks() as unknown as Block[]
	);
}

/** Documento donde se pinta el lienzo: el del iframe del editor o el de la página. */
function canvas(): Document {
	const frame = document.querySelector< HTMLIFrameElement >(
		'iframe[name="editor-canvas"]'
	);
	return frame?.contentDocument ?? document;
}

/**
 * Contenido de un bloque como cadena HTML (en WordPress 7 es un `RichTextData`).
 * @param block
 */
function html( block: Block ): string {
	return String( block.attributes.content ?? '' );
}

/**
 * Adaptador del editor de bloques.
 *
 * @param postId Entrada que se edita.
 */
export function gutenbergAdapter( postId: number ): EditorAdapter {
	return {
		postId,

		getContent: serialized,

		getTitle: () =>
			String(
				select( editorStore ).getEditedPostAttribute( 'title' ) ?? ''
			),

		onSaved: ( callback ) => {
			let saving = false;
			return subscribe( () => {
				const store = select( editorStore );
				const now = store.isSavingPost() && ! store.isAutosavingPost();
				if ( saving && ! now && store.didPostSaveRequestSucceed() ) {
					callback();
				}
				saving = now;
			}, editorStore );
		},

		apply: ( suggestion: Suggestion ): ApplyResult => {
			const blocks = linkable();
			const values = new Map(
				blocks.map( ( block ) => [
					block.clientId,
					create( { html: html( block ) } ),
				] )
			);
			const units: TextUnit[] = blocks.map( ( block ) => ( {
				key: block.clientId,
				text: values.get( block.clientId )?.text ?? '',
			} ) );

			const found = locate( units, suggestion );
			if ( found.status !== 'found' ) {
				return { ok: false, reason: found.status };
			}
			const value = values.get( found.key );
			if ( ! value ) {
				return { ok: false, reason: 'not_found' };
			}

			const linked = value.formats
				.slice( found.start, found.end )
				.some(
					( formats ) =>
						formats?.some(
							( format ) => format.type === 'core/link'
						)
				);
			if ( linked ) {
				return { ok: false, reason: 'already_linked' };
			}

			const next = applyFormat(
				value,
				{
					type: 'core/link',
					attributes: { url: suggestion.target.url ?? '' },
				},
				found.start,
				found.end
			);
			const after = toHTMLString( { value: next } );
			const content = serialized();
			dispatch( blockEditorStore ).updateBlockAttributes( found.key, {
				content: after,
			} );
			const afterContent = serialized();
			clearHighlight();

			return {
				ok: true,
				undo: () => {
					// Solo si no se ha tocado nada desde entonces: si no, el usuario usa el deshacer del editor.
					if (
						serialized() !== afterContent ||
						content === afterContent
					) {
						return false;
					}
					dispatch( editorStore ).undo();
					return true;
				},
			};
		},

		highlight: ( suggestion ) => {
			if ( ! suggestion ) {
				clearHighlight();
				return;
			}
			const doc = canvas();
			const units: TextUnit[] = [];
			const elements = new Map< string, Element >();
			for ( const block of linkable() ) {
				const element = doc.querySelector(
					`[data-block="${ block.clientId }"]`
				);
				if ( element ) {
					elements.set( block.clientId, element );
					units.push( {
						key: block.clientId,
						text: element.textContent ?? '',
					} );
				}
			}
			const found = locate( units, suggestion );
			const element =
				found.status === 'found' ? elements.get( found.key ) : null;
			if ( found.status !== 'found' || ! element ) {
				clearHighlight();
				return;
			}
			const range = rangeFromOffsets( element, found.start, found.end );
			if ( range ) {
				showHighlight( range );
			}
		},
	};
}

/** Contenido actual del editor, serializado como se guardaría. */
function serialized(): string {
	return String( select( editorStore ).getEditedPostContent() ?? '' );
}
