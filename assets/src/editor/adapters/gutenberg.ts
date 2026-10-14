/**
 * Editor de bloques: localiza la frase en el estado del editor y pone el formato de enlace (docs/06 §2).
 *
 * El enlace se escribe en el cliente con `core/block-editor`, en el bloque que contiene la frase y sin tocar
 * ningún otro; el usuario guarda y deshace con el editor. Nada se escribe en el servidor.
 */
import { select, dispatch, subscribe } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import {
	applyFormat,
	create,
	removeFormat,
	toHTMLString,
} from '@wordpress/rich-text';
import type { Suggestion } from '../../types';
import { locate, rangeFromOffsets } from '../locate';
import type { TextUnit } from '../locate';
import { ownEditable } from '../dom';
import { clearHighlight, showHighlight } from '../highlight';
import { findOwnLink } from '../richLink';
import type { AdapterOptions, ApplyResult, EditorAdapter } from './types';

/** Bloques de texto donde se pone el enlace: los mismos que admite el servidor (docs/06 §3). */
const LINKABLE = [ 'core/paragraph', 'core/list-item' ];
/** Secciones de una tabla y su elemento HTML. */
const SECTIONS = { head: 'thead', body: 'tbody', foot: 'tfoot' } as const;
type Section = keyof typeof SECTIONS;

interface Block {
	clientId: string;
	name: string;
	attributes: Record< string, unknown > & { content?: unknown };
	innerBlocks: Block[];
}

interface TableCell {
	content?: unknown;
}

interface TableRow {
	cells?: TableCell[];
}

/** Un tramo de texto editable: el contenido de un bloque o de una celda de tabla. */
interface Target {
	/** Identifica el tramo (el bloque, o el bloque con sección, fila y celda). */
	key: string;
	clientId: string;
	/** HTML del tramo en el bloque tal como está ahora. */
	read: ( block: Block ) => string;
	/** Atributos del bloque con el HTML nuevo del tramo. */
	write: ( block: Block, html: string ) => Record< string, unknown >;
	/** Elemento editable del tramo en el lienzo. */
	element: ( doc: Document ) => Element | null;
}

function collect(
	blocks: Block[],
	scope: AdapterOptions[ 'linkIn' ],
	out: Target[] = [],
	skip = false
): Target[] {
	const names = scope.headings ? [ ...LINKABLE, 'core/heading' ] : LINKABLE;
	for ( const block of blocks ) {
		// Dentro de una cita o una tabla el servidor no escribe sin que el usuario lo active en Ajustes.
		const skipChildren =
			skip ||
			( ! scope.quotes && block.name === 'core/quote' ) ||
			( ! scope.quotes && block.name === 'core/pullquote' ) ||
			block.name === 'core/table';
		if (
			! skip &&
			names.includes( block.name ) &&
			block.attributes.content !== undefined
		) {
			out.push( {
				key: block.clientId,
				clientId: block.clientId,
				read: ( current ) => String( current.attributes.content ?? '' ),
				write: ( _current, html ) => ( { content: html } ),
				element: ( doc ) => {
					const element = doc.querySelector(
						`[data-block="${ block.clientId }"]`
					);
					return element ? ownEditable( element ) : null;
				},
			} );
		}
		if ( ! skip && scope.tables && block.name === 'core/table' ) {
			tableTargets( block, out );
		}
		collect( block.innerBlocks, scope, out, skipChildren );
	}
	return out;
}

/**
 * Las celdas de una tabla como tramos de texto.
 * @param block Bloque `core/table`.
 * @param out   Dónde se añaden.
 */
function tableTargets( block: Block, out: Target[] ): void {
	( Object.keys( SECTIONS ) as Section[] ).forEach( ( section ) => {
		const rows = block.attributes[ section ] as TableRow[] | undefined;
		( rows ?? [] ).forEach( ( row, r ) => {
			( row.cells ?? [] ).forEach( ( _cell, c ) => {
				out.push( {
					key: `${ block.clientId }:${ section }:${ r }:${ c }`,
					clientId: block.clientId,
					read: ( current ) =>
						String(
							(
								current.attributes[ section ] as
									| TableRow[]
									| undefined
							 )?.[ r ]?.cells?.[ c ]?.content ?? ''
						),
					write: ( current, html ) => ( {
						[ section ]: (
							( current.attributes[ section ] as
								| TableRow[]
								| undefined ) ?? []
						).map( ( currentRow, i ) =>
							i === r
								? {
										...currentRow,
										cells: ( currentRow.cells ?? [] ).map(
											( cell, j ) =>
												j === c
													? {
															...cell,
															content: html,
													  }
													: cell
										),
								  }
								: currentRow
						),
					} ),
					element: ( doc ) => {
						const cell = doc
							.querySelector(
								`[data-block="${ block.clientId }"]`
							)
							?.querySelector(
								`${ SECTIONS[ section ] } > tr:nth-child(${
									r + 1
								}) > :nth-child(${ c + 1 })`
							);
						return cell ? ownEditable( cell ) : null;
					},
				} );
			} );
		} );
	} );
}

function linkable( scope: AdapterOptions[ 'linkIn' ] ): Target[] {
	return collect(
		select( blockEditorStore ).getBlocks() as unknown as Block[],
		scope
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
 * Adaptador del editor de bloques.
 *
 * @param postId  Entrada que se edita.
 * @param options Ajustes del usuario: dónde se enlaza y con qué atributos.
 */
export function gutenbergAdapter(
	postId: number,
	options: AdapterOptions
): EditorAdapter {
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

		onSaveCycle: ( handlers ) => {
			let saving = false;
			let auto = false;
			// El autoguardado de un borrador propio escribe `post_content`; el de una entrada publicada va a una
			// revisión aparte y no cuenta.
			const writes = () =>
				! auto ||
				[ 'draft', 'auto-draft', 'pending' ].includes(
					String(
						select( editorStore ).getCurrentPostAttribute(
							'status'
						) ?? ''
					)
				);
			return subscribe( () => {
				const store = select( editorStore );
				const now = store.isSavingPost();
				if ( ! saving && now ) {
					auto = store.isAutosavingPost();
					if ( writes() ) {
						handlers.begin();
					}
				}
				if (
					saving &&
					! now &&
					store.didPostSaveRequestSucceed() &&
					writes()
				) {
					handlers.finish( auto );
				}
				saving = now;
			}, editorStore );
		},

		apply: ( suggestion: Suggestion ): ApplyResult => {
			const targets = linkable( options.linkIn );
			const current = ( target: Target ): Block | null =>
				select( blockEditorStore ).getBlock(
					target.clientId
				) as Block | null;
			const values = new Map< string, ReturnType< typeof create > >();
			for ( const target of targets ) {
				const block = current( target );
				if ( block ) {
					values.set(
						target.key,
						create( { html: target.read( block ) } )
					);
				}
			}
			const units: TextUnit[] = targets.map( ( target ) => ( {
				key: target.key,
				text: values.get( target.key )?.text ?? '',
			} ) );

			const found = locate( units, suggestion );
			if ( found.status !== 'found' ) {
				return { ok: false, reason: found.status };
			}
			const value = values.get( found.key );
			const target = targets.find( ( t ) => t.key === found.key );
			const block = target ? current( target ) : null;
			if ( ! value || ! target || ! block ) {
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

			const url = suggestion.target.url ?? '';
			const applied = value.text.slice( found.start, found.end );
			const next = applyFormat(
				value,
				{
					type: 'core/link',
					attributes: { url, ...options.linkAttrs },
				},
				found.start,
				found.end
			);
			// El enlace es un nivel de deshacer propio: lo escrito antes y después no se mezcla con él.
			markPersistent();
			dispatch( blockEditorStore ).updateBlockAttributes(
				target.clientId,
				target.write( block, toHTMLString( { value: next } ) )
			);
			markPersistent();
			clearHighlight();

			return {
				ok: true,
				anchor: applied,
				// Quita solo este enlace, si sigue tal como se puso, como una edición nueva: no usa el
				// `undo()` global, que podría deshacer otro cambio (título, categorías, texto escrito).
				undo: () => {
					const latest = select( blockEditorStore ).getBlock(
						target.clientId
					) as Block | null;
					if ( ! latest ) {
						return false;
					}
					const now = create( { html: target.read( latest ) } );
					const run = findOwnLink(
						now.text,
						now.formats,
						url,
						applied
					);
					if ( ! run ) {
						return false;
					}
					markPersistent();
					dispatch( blockEditorStore ).updateBlockAttributes(
						target.clientId,
						target.write(
							latest,
							toHTMLString( {
								value: removeFormat(
									now,
									'core/link',
									run.start,
									run.end
								),
							} )
						)
					);
					markPersistent();
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
			for ( const target of linkable( options.linkIn ) ) {
				// Solo el texto propio del tramo: un `li` padre incluiría el de sus listas anidadas.
				const editable = target.element( doc );
				if ( editable ) {
					elements.set( target.key, editable );
					units.push( {
						key: target.key,
						text: editable.textContent ?? '',
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

/**
 * Cierra el nivel de deshacer en curso: el cambio siguiente (o el anterior) no se fusiona con el último. Es la
 * misma función que usa el propio editor al guardar; no hay una pública con otro nombre (`createUndoLevel` de
 * `core/editor` está caducada).
 */
function markPersistent(): void {
	(
		dispatch( blockEditorStore ) as {
			__unstableMarkLastChangeAsPersistent?: () => void;
		}
	 ).__unstableMarkLastChangeAsPersistent?.();
}

/** Contenido actual del editor, serializado como se guardaría. */
function serialized(): string {
	return String( select( editorStore ).getEditedPostContent() ?? '' );
}
