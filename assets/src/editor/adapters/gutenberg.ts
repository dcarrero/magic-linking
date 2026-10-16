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
import { locate, occurrences, rangeFromOffsets } from '../locate';
import type { TextUnit } from '../locate';
import { ownEditable } from '../dom';
import { clearHighlight, showHighlight } from '../highlight';
import { autosaveWritesPost } from '../autosave';
import { findOwnLink } from '../richLink';
import {
	blocker,
	buildView,
	forbidden,
	isLinkUrl,
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

/** Qué tramos se pueden enlazar ahora mismo y en qué elementos no se enlaza: la misma selección para enlazar y resaltar. */
interface Plan {
	blocks: Block[];
	targets: Target[];
	/** Elementos HTML dentro de los cuales no se enlaza (`rawHtml.forbidden()`). */
	noLink: string[];
}

function plan( scope: AdapterOptions[ 'linkIn' ] ): Plan {
	const blocks = select( blockEditorStore ).getBlocks() as unknown as Block[];
	return {
		blocks,
		targets: collect( blocks, scope, classicDocument( blocks ) ),
		noLink: forbidden( scope ),
	};
}

/**
 * El bloque tal como está ahora en el editor.
 * @param target
 */
function blockOf( target: Target ): Block | null {
	return select( blockEditorStore ).getBlock(
		target.clientId
	) as Block | null;
}

interface Prepared {
	units: TextUnit[];
	/** Valor de rich-text de los tramos con formato. */
	values: Map< string, ReturnType< typeof create > >;
	/** Vista de texto de los tramos de HTML en bruto. */
	views: Map< string, RawView >;
}

/**
 * Los tramos como unidades de texto para `locate()`. Los de HTML en bruto llevan el filtro de enlazables, como el
 * servidor, y los que tienen marcas que no se recorren con seguridad (`<script>`…) se dejan fuera.
 *
 * @param selection Tramos y zonas excluidas.
 */
function prepare( selection: Plan ): Prepared {
	const values = new Map< string, ReturnType< typeof create > >();
	const views = new Map< string, RawView >();
	const units: TextUnit[] = [];
	const live = new Set< string >();
	for ( const target of selection.targets ) {
		const block = blockOf( target );
		if ( ! block ) {
			continue;
		}
		live.add( target.key );
		const html = target.read( block );
		// El análisis de un bloque solo se rehace si su contenido cambió (el resaltado lo pide a cada paso del ratón).
		let cached = analysis.get( target.key );
		if ( cached?.html !== html ) {
			cached = target.raw
				? { html, view: buildView( html ) }
				: { html, value: create( { html } ) };
			analysis.set( target.key, cached );
		}
		if ( cached.value ) {
			values.set( target.key, cached.value );
			units.push( { key: target.key, text: cached.value.text } );
			continue;
		}
		const view = cached.view;
		if ( ! view ) {
			continue;
		}
		views.set( target.key, view );
		if ( view.unsafe === null ) {
			units.push( {
				key: target.key,
				text: view.text,
				accepts: ( start, end ) =>
					blocker( view, start, end, selection.noLink ) === null,
			} );
		}
	}
	for ( const key of Array.from( analysis.keys() ) ) {
		if ( ! live.has( key ) ) {
			analysis.delete( key );
		}
	}
	return { units, values, views };
}

/** Análisis de cada tramo mientras su contenido no cambia. */
const analysis = new Map<
	string,
	{
		html: string;
		value?: ReturnType< typeof create >;
		view?: RawView;
	}
>();

/**
 * Texto de un atributo de texto enriquecido: una cadena, o un `RichTextData` (desde WordPress 6.5 lo son
 * `content`, `value` y `citation` de párrafos, encabezados, listas y citas), que se convierte con `String()`.
 *
 * @param value Valor del atributo.
 * @return HTML del atributo, o null si no es texto.
 */
function richText( value: unknown ): string | null {
	if ( typeof value === 'string' ) {
		return value;
	}
	if ( value !== null && typeof value === 'object' ) {
		const text = String( value );
		return text === '[object Object]' ? null : text;
	}
	return null;
}

/** Atributos de texto de los bloques donde el panel no inserta. */
const TEXT_ATTRIBUTES = [ 'content', 'value', 'citation' ];

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
		for ( const name of TEXT_ATTRIBUTES ) {
			const html = richText( block.attributes[ name ] );
			if (
				html === null ||
				( name === 'content' && claimed.has( block.clientId ) )
			) {
				continue;
			}
			out.push( {
				key: `${ block.clientId }:${ name }`,
				text:
					name === 'content' && RAW.includes( block.name )
						? buildView( html ).text
						: create( { html } ).text,
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
			// Como el servidor (`Linker::open_tag`), no se enlaza a una dirección vacía o que no sea http(s).
			if ( ! isLinkUrl( suggestion.target.url ?? '' ) ) {
				return { ok: false, reason: 'bad_target' };
			}
			const selection = plan( options.linkIn );
			const { targets } = selection;
			const prepared = prepare( selection );
			const { units, values, views } = prepared;

			const found = locate( units, suggestion );
			if ( found.status !== 'found' ) {
				return {
					ok: false,
					reason:
						found.status === 'ambiguous'
							? 'ambiguous'
							: whyNot(
									suggestion,
									selection,
									prepared,
									options.linkIn
							  ),
				};
			}
			const target = targets.find( ( t ) => t.key === found.key );
			const block = target ? blockOf( target ) : null;
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
			// Dónde está la frase lo decide lo mismo que decide dónde se enlazará (mismos tramos y mismo filtro de
			// enlazables); el DOM solo sirve para situar el rango.
			const selection = plan( options.linkIn );
			const { units } = prepare( selection );
			const found = locate( units, suggestion );
			const target =
				found.status === 'found'
					? selection.targets.find( ( t ) => t.key === found.key )
					: undefined;
			// Solo el texto propio del tramo: un `li` padre incluiría el de sus listas anidadas.
			const element = target?.element( canvas() );
			const unit =
				found.status === 'found'
					? units.find( ( u ) => u.key === found.key )
					: undefined;
			if ( found.status !== 'found' || ! element || ! unit ) {
				clearHighlight();
				return;
			}
			// La aparición con el mismo número de orden en el texto del DOM (que no tiene los cortes ni los
			// separadores del texto de datos).
			const inData = occurrences( unit.text, suggestion );
			const inDom = occurrences( element.textContent ?? '', suggestion );
			const at = inData.findIndex( ( o ) => o.start === found.start );
			const place =
				inDom.length === inData.length ? inDom[ at ] : undefined;
			const range = place
				? rangeFromOffsets( element, place.start, place.end )
				: null;
			if ( range ) {
				showHighlight( range );
			} else {
				clearHighlight();
			}
		},
	};
}

/**
 * Por qué no hay dónde enlazar la frase: si existe en un sitio donde el panel no inserta, `unsupported` (o
 * `already_linked` si es un enlace que ya está); `not_found` solo si de verdad ya no está.
 *
 * @param suggestion Sugerencia.
 * @param selection  Tramos donde se inserta y zonas excluidas.
 * @param prepared   Los mismos como unidades de texto.
 * @param scope      Dónde más se enlaza.
 */
function whyNot(
	suggestion: Suggestion,
	selection: Plan,
	prepared: Prepared,
	scope: AdapterOptions[ 'linkIn' ]
): ApplyFailure {
	const { units, views } = prepared;
	// También las vistas descartadas por no seguras: si no, su texto se contaría dos veces y todo sería ambiguo.
	const claimed = new Set( [
		...units.map( ( unit ) => unit.key ),
		...views.keys(),
	] );
	// Sin el filtro de enlazables: se busca la frase en cualquier sitio.
	const everything: TextUnit[] = [
		...Array.from( views, ( [ key, view ] ) => ( {
			key,
			text: view.text,
		} ) ),
		...units
			.filter( ( unit ) => ! views.has( unit.key ) )
			.map( ( { key, text } ) => ( { key, text } ) ),
		...elsewhere( selection.blocks, claimed, scope ),
	];
	const where = locate( everything, suggestion );
	if ( where.status === 'not_found' ) {
		return 'not_found';
	}
	const view = where.status === 'found' ? views.get( where.key ) : undefined;
	if (
		where.status === 'found' &&
		view &&
		blocker( view, where.start, where.end, selection.noLink ) === 'link'
	) {
		return 'already_linked';
	}
	return 'unsupported';
}

/**
 * Si el editor TinyMCE del bloque Clásico tiene texto escrito que aún no ha pasado al atributo del bloque.
 *
 * @param clientId Bloque.
 */
function hasPendingEdit( clientId: string ): boolean {
	const tinymce = (
		window as unknown as {
			tinymce?: {
				get?: ( id: string ) => { isDirty?: () => boolean } | null;
			};
		}
	 ).tinymce;
	return tinymce?.get?.( `editor-${ clientId }` )?.isDirty?.() === true;
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
	// El bloque Clásico se edita en su propio editor (TinyMCE) y solo pasa el texto al atributo al aplicarlo: si hay
	// cambios sin aplicar, escribir aquí los borraría. Se avisa en vez de arriesgarlo.
	if ( hasPendingEdit( target.clientId ) ) {
		return { ok: false, reason: 'unsynced' };
	}
	const open = openTag( suggestion.target.url ?? '', options.linkAttrs );
	const wrapped = wrap( target.read( block ), view, start, end, open );
	if ( ! wrapped.ok ) {
		return { ok: false, reason: wrapped.reason };
	}

	// `wrap()` ya comprobó que el resultado es el original más las dos piezas: se escribe tal cual.
	markPersistent();
	dispatch( blockEditorStore ).updateBlockAttributes(
		target.clientId,
		target.write( block, wrapped.html )
	);
	markPersistent();
	clearHighlight();

	return {
		ok: true,
		anchor: view.text.slice( start, end ),
		// Quita solo este `<a>`, si sigue tal como se puso (una sola vez, con el mismo ancla dentro), como una
		// edición nueva; el resto del HTML del bloque queda como esté.
		undo: () => {
			const latest = select( blockEditorStore ).getBlock(
				target.clientId
			) as Block | null;
			if ( ! latest ) {
				return false;
			}
			const without = unwrap( target.read( latest ), wrapped );
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
