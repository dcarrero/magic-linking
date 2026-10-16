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
import { autosaveWritesPost } from '../autosave';
import { findOwnLink } from '../richLink';
import {
	blocker,
	buildView,
	forbidden,
	openTag,
	unwrap,
	wrap,
} from '../rawHtml';
import type { RawView } from '../rawHtml';
import type {
	AdapterOptions,
	ApplyFailure,
	ApplyResult,
	EditorAdapter,
} from './types';

/** Bloques de texto donde se pone el enlace: los mismos que admite el servidor (docs/06 §3). */
const LINKABLE = [ 'core/paragraph', 'core/list-item' ];
/** Bloque Clásico: su contenido es HTML en bruto y se enlaza a nivel de cadena (F1-41, `rawHtml.ts`). */
const FREEFORM = 'core/freeform';
/** Bloques cuyo contenido es HTML en bruto (solo se leen, para decir que la frase existe pero no se enlaza). */
const RAW = [ FREEFORM, 'core/html' ];
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
	/** El contenido es HTML en bruto (bloque Clásico): se inserta sobre la cadena, no con rich-text. */
	raw?: boolean;
	clientId: string;
	/** HTML del tramo en el bloque tal como está ahora. */
	read: ( block: Block ) => string;
	/** Atributos del bloque con el HTML nuevo del tramo. */
	write: ( block: Block, html: string ) => Record< string, unknown >;
	/** Elemento editable del tramo en el lienzo. */
	element: ( doc: Document ) => Element | null;
}

/**
 * Si todo el documento es de bloques Clásicos: entonces se guarda sin comentarios de bloque y el servidor lo trata como
 * contenido del editor clásico (`ClassicEditor`). Con otros bloques en medio el servidor no toca el HTML suelto
 * (`BlockEditor` no recorre `freeform`), así que el panel tampoco.
 *
 * @param blocks Bloques de primer nivel.
 */
function classicDocument( blocks: Block[] ): boolean {
	return (
		blocks.length > 0 &&
		blocks.every(
			( block ) =>
				block.name === FREEFORM &&
				! String( block.attributes.content ?? '' ).includes(
					'<!-- wp:'
				)
		)
	);
}

function collect(
	blocks: Block[],
	scope: AdapterOptions[ 'linkIn' ],
	classic = false,
	out: Target[] = [],
	skip = false
): Target[] {
	const names = [
		...LINKABLE,
		...( scope.headings ? [ 'core/heading' ] : [] ),
		...( classic ? [ FREEFORM ] : [] ),
	];
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
				raw: block.name === FREEFORM,
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
		collect( block.innerBlocks, scope, classic, out, skipChildren );
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
	const blocks = select( blockEditorStore ).getBlocks() as unknown as Block[];
	return collect( blocks, scope, classicDocument( blocks ) );
}

/**
 * El texto de los bloques donde el panel no inserta (encabezados, citas y tablas desactivados en Ajustes, HTML
 * personalizado, el bloque Clásico entre otros bloques…), para poder decir que la frase existe pero no se puede
 * enlazar desde aquí en vez de «el texto ha cambiado».
 *
 * @param blocks  Bloques del editor.
 * @param claimed Claves de los tramos donde sí se inserta.
 * @param scope   Dónde más se enlaza.
 * @param out     Dónde se añaden.
 */
function elsewhere(
	blocks: Block[],
	claimed: Set< string >,
	scope: AdapterOptions[ 'linkIn' ],
	out: TextUnit[] = []
): TextUnit[] {
	for ( const block of blocks ) {
		const content = block.attributes.content;
		if ( typeof content === 'string' && ! claimed.has( block.clientId ) ) {
			out.push( {
				key: block.clientId,
				text: RAW.includes( block.name )
					? buildView( content ).text
					: create( { html: content } ).text,
			} );
		}
		if ( block.name === 'core/table' && ! scope.tables ) {
			const cells: Target[] = [];
			tableTargets( block, cells );
			for ( const cell of cells ) {
				out.push( {
					key: cell.key,
					text: create( { html: cell.read( block ) } ).text,
				} );
			}
		}
		elsewhere( block.innerBlocks, claimed, scope, out );
	}
	return out;
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

		onSaved: ( callback ) => watchSaves( { saved: callback } ),

		onSaveCycle: ( handlers ) =>
			watchSaves( {
				begin: handlers.begin,
				finish: handlers.finish,
			} ),

		apply: ( suggestion: Suggestion ): ApplyResult => {
			const blocks = select(
				blockEditorStore
			).getBlocks() as unknown as Block[];
			const targets = collect(
				blocks,
				options.linkIn,
				classicDocument( blocks )
			);
			const current = ( target: Target ): Block | null =>
				select( blockEditorStore ).getBlock(
					target.clientId
				) as Block | null;
			const values = new Map< string, ReturnType< typeof create > >();
			const views = new Map< string, RawView >();
			for ( const target of targets ) {
				const block = current( target );
				if ( ! block ) {
					continue;
				}
				if ( target.raw ) {
					views.set( target.key, buildView( target.read( block ) ) );
				} else {
					values.set(
						target.key,
						create( { html: target.read( block ) } )
					);
				}
			}
			// Un tramo de HTML en bruto con marcas que no se recorren con seguridad (`<script>`…) no se toca.
			const usable = ( target: Target ): boolean =>
				! target.raw || views.get( target.key )?.unsafe === null;
			const noLink = forbidden( options.linkIn );
			const units: TextUnit[] = targets
				.filter( usable )
				.map( ( target ): TextUnit => {
					const view = views.get( target.key );
					if ( view ) {
						return {
							key: target.key,
							text: view.text,
							// Como el servidor, solo cuentan las apariciones que se pueden enlazar.
							accepts: ( start, end ) =>
								blocker( view, start, end, noLink ) === null,
						};
					}
					return {
						key: target.key,
						text: values.get( target.key )?.text ?? '',
					};
				} );

			const found = locate( units, suggestion );
			if ( found.status !== 'found' ) {
				return {
					ok: false,
					reason:
						found.status === 'ambiguous'
							? 'ambiguous'
							: whyNot(
									suggestion,
									blocks,
									targets,
									units,
									views,
									options.linkIn
							  ),
				};
			}
			const target = targets.find( ( t ) => t.key === found.key );
			const block = target ? current( target ) : null;
			if ( ! target || ! block ) {
				return { ok: false, reason: 'not_found' };
			}
			const rawView = views.get( found.key );
			if ( rawView ) {
				return applyRaw(
					suggestion,
					target,
					block,
					rawView,
					found.start,
					found.end,
					options
				);
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
 * Por qué no hay dónde enlazar la frase: si existe en un sitio donde el panel no inserta, `unsupported` (o
 * `already_linked` si es un enlace que ya está); `not_found` solo si de verdad ya no está.
 *
 * @param suggestion Sugerencia.
 * @param blocks     Bloques del editor.
 * @param targets    Tramos donde se inserta.
 * @param units      Los mismos como unidades de texto (con su filtro de enlazables).
 * @param views      Vista de texto de los tramos en bruto.
 * @param scope      Dónde más se enlaza.
 */
function whyNot(
	suggestion: Suggestion,
	blocks: Block[],
	targets: Target[],
	units: TextUnit[],
	views: Map< string, RawView >,
	scope: AdapterOptions[ 'linkIn' ]
): ApplyFailure {
	const claimed = new Set( units.map( ( unit ) => unit.key ) );
	const everything: TextUnit[] = [
		...units.map( ( { key, text } ) => ( { key, text } ) ),
		...elsewhere( blocks, claimed, scope ),
	];
	const where = locate( everything, suggestion );
	if ( where.status === 'not_found' ) {
		return 'not_found';
	}
	if ( where.status === 'found' ) {
		const view = views.get( where.key );
		if (
			view &&
			targets.some( ( t ) => t.key === where.key ) &&
			blocker( view, where.start, where.end, forbidden( scope ) ) ===
				'link'
		) {
			return 'already_linked';
		}
	}
	return 'unsupported';
}

/**
 * Enlaza dentro del HTML en bruto de un bloque Clásico, sin reserializar nada: solo se insertan `<a …>` y `</a>`
 * (`rawHtml.wrap`, que verifica que quitar esas dos piezas devuelve el original byte a byte). El cambio es un nivel
 * de deshacer propio, igual que en los demás bloques.
 *
 * @param suggestion Sugerencia.
 * @param target     Tramo.
 * @param block      Bloque tal como está ahora.
 * @param view       Vista de texto de su HTML.
 * @param start      Primer carácter del ancla en la vista.
 * @param end        Carácter siguiente al último.
 * @param options    Ajustes.
 */
function applyRaw(
	suggestion: Suggestion,
	target: Target,
	block: Block,
	view: RawView,
	start: number,
	end: number,
	options: AdapterOptions
): ApplyResult {
	const open = openTag( suggestion.target.url ?? '', options.linkAttrs );
	const wrapped = wrap( target.read( block ), view, start, end, open );
	if ( ! wrapped.ok ) {
		return { ok: false, reason: wrapped.reason };
	}
	const original = target.read( block );

	markPersistent();
	dispatch( blockEditorStore ).updateBlockAttributes(
		target.clientId,
		target.write( block, wrapped.html )
	);
	const written = select( blockEditorStore ).getBlock(
		target.clientId
	) as Block | null;
	if ( ! written || target.read( written ) !== wrapped.html ) {
		// El editor no guardó exactamente lo calculado: se devuelve lo que había y no se da por hecho nada.
		dispatch( blockEditorStore ).updateBlockAttributes(
			target.clientId,
			target.write( block, original )
		);
		markPersistent();
		return { ok: false, reason: 'unsafe' };
	}
	markPersistent();
	clearHighlight();
	const applied = view.text.slice( start, end );

	return {
		ok: true,
		anchor: applied,
		// Quita solo este `<a>`, si sigue tal como se puso (una sola vez, con el mismo ancla dentro), como una
		// edición nueva; el resto del HTML del bloque queda como esté.
		undo: () => {
			const latest = select( blockEditorStore ).getBlock(
				target.clientId
			) as Block | null;
			if ( ! latest ) {
				return false;
			}
			const without = unwrap( target.read( latest ), open, applied );
			if ( without === null ) {
				return false;
			}
			markPersistent();
			dispatch( blockEditorStore ).updateBlockAttributes(
				target.clientId,
				target.write( latest, without )
			);
			markPersistent();
			return true;
		},
	};
}

interface SaveHandlers {
	/** Empieza un guardado que escribe la entrada. */
	begin?: () => void;
	/** Termina con éxito un guardado que escribe la entrada. */
	finish?: ( autosave: boolean ) => void;
	/** Termina con éxito un guardado manual (no un autoguardado). */
	saved?: () => void;
}

const watchers = new Set< SaveHandlers >();
let stopWatching: ( () => void ) | null = null;

/**
 * Una sola suscripción al almacén del editor para todos los que quieren saber de los guardados.
 *
 * @param handlers Qué hacer en cada momento.
 * @return Función que deja de avisar.
 */
function watchSaves( handlers: SaveHandlers ): () => void {
	watchers.add( handlers );
	if ( ! stopWatching ) {
		let saving = false;
		let auto = false;
		let writes = false;
		stopWatching = subscribe( () => {
			const store = select( editorStore );
			const now = store.isSavingPost();
			if ( ! saving && now ) {
				auto = store.isAutosavingPost();
				// El autoguardado solo escribe la entrada en un borrador propio; si no, va a una revisión aparte.
				const user = (
					select( 'core' ) as {
						getCurrentUser?: () => { id?: number } | undefined;
					}
				 ).getCurrentUser?.();
				const post = store.getCurrentPost() as {
					status?: string;
					author?: number;
				};
				writes =
					! auto ||
					autosaveWritesPost(
						String( post.status ?? '' ),
						Number( post.author ?? 0 ),
						Number( user?.id ?? 0 )
					);
				if ( writes ) {
					watchers.forEach( ( watcher ) => watcher.begin?.() );
				}
			}
			if ( saving && ! now && store.didPostSaveRequestSucceed() ) {
				if ( ! auto ) {
					watchers.forEach( ( watcher ) => watcher.saved?.() );
				}
				if ( writes ) {
					watchers.forEach( ( watcher ) => watcher.finish?.( auto ) );
				}
			}
			saving = now;
		}, editorStore );
	}
	return () => {
		watchers.delete( handlers );
		if ( watchers.size === 0 && stopWatching ) {
			stopWatching();
			stopWatching = null;
		}
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
