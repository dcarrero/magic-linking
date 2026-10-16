/**
 * El adaptador de Gutenberg con un almacén de bloques simulado: bloque Clásico (F1-41), motivos de fallo y deshacer.
 */
import type { Suggestion } from '../../types';

interface FakeBlock {
	clientId: string;
	name: string;
	attributes: Record< string, unknown >;
	innerBlocks: FakeBlock[];
}

let mockBlocks: FakeBlock[] = [];
const mockUpdates: Array< [ string, Record< string, unknown > ] > = [];

function mockFind( list: FakeBlock[], id: string ): FakeBlock | null {
	for ( const block of list ) {
		if ( block.clientId === id ) {
			return block;
		}
		const inner = mockFind( block.innerBlocks, id );
		if ( inner ) {
			return inner;
		}
	}
	return null;
}

/** Almacén de bloques simulado, registrado en el `@wordpress/data` real. */
function mockBlockStore() {
	const { createReduxStore, register } =
		jest.requireActual( '@wordpress/data' );
	const store = createReduxStore( 'test/block-editor', {
		reducer: ( state: object = {} ) => state,
		selectors: {
			getBlocks: () => mockBlocks,
			getBlock: ( _state: object, id: string ) =>
				mockFind( mockBlocks, id ),
		},
		actions: {
			updateBlockAttributes:
				( id: string, attributes: Record< string, unknown > ) => () => {
					mockUpdates.push( [ id, attributes ] );
					const block = mockFind( mockBlocks, id );
					if ( block ) {
						block.attributes = {
							...block.attributes,
							...attributes,
						};
					}
				},
			__unstableMarkLastChangeAsPersistent: () => () => undefined,
		},
	} );
	register( store );
	return store;
}

jest.mock( '@wordpress/block-editor', () => ( {
	store: mockBlockStore(),
} ) );
// rich-text de verdad arrastra las API privadas del editor: aquí basta una versión mínima (el rich-text real lo
// cubren los e2e).
jest.mock( '@wordpress/rich-text', () => {
	const plain = ( html: string ) => html.replace( /<[^>]+>/g, '' );
	return {
		create: ( { html }: { html: string } ) => ( {
			text: plain( html ),
			formats: Array.from( { length: plain( html ).length } ),
		} ),
		applyFormat: (
			value: { text: string },
			format: { attributes: { url: string } },
			start: number,
			end: number
		) => ( {
			...value,
			html: `${ value.text.slice( 0, start ) }<a href="${
				format.attributes.url
			}">${ value.text.slice( start, end ) }</a>${ value.text.slice(
				end
			) }`,
		} ),
		toHTMLString: ( { value }: { value: { html: string } } ) => value.html,
		removeFormat: jest.fn(),
	};
} );
jest.mock( '../highlight', () => ( {
	clearHighlight: jest.fn(),
	showHighlight: jest.fn(),
} ) );
jest.mock( '@wordpress/editor', () => ( { store: 'core/editor' } ) );

// eslint-disable-next-line import/first
import { gutenbergAdapter } from '../adapters/gutenberg';
// eslint-disable-next-line import/first
import { clearHighlight, showHighlight } from '../highlight';

/** Como `RichTextData` de WordPress 6.5: un objeto que se convierte en HTML con `toString()`. */
class FakeRichText {
	html: string;

	constructor( html: string ) {
		this.html = html;
	}

	toString() {
		return this.html;
	}
}

const options = {
	linkIn: { headings: false, quotes: false, tables: false },
	linkAttrs: { target: '_blank', rel: 'noopener' },
};

const block = (
	clientId: string,
	name: string,
	attributes: Record< string, unknown >,
	innerBlocks: FakeBlock[] = []
): FakeBlock => ( { clientId, name, attributes, innerBlocks } );

const suggestion = (
	before: string,
	anchor: string,
	after: string
): Suggestion =>
	( {
		before,
		anchor,
		after,
		target: { url: 'https://ejemplo.test/destino/' },
	} ) as unknown as Suggestion;

const CLASSIC =
	'La fama de <strong>Budapest Hungría </strong>se debe a muchas razones, entre ellas sus balnearios.\n\n' +
	'Otro párrafo con <em>cursiva</em> &amp; más texto.\n\n<h2>Un encabezado propio</h2>';

beforeEach( () => {
	mockBlocks = [];
	mockUpdates.length = 0;
} );

describe( 'bloque Clásico (core/freeform)', () => {
	it( 'enlaza dentro del HTML en bruto y no toca el resto', () => {
		mockBlocks = [ block( 'c1', 'core/freeform', { content: CLASSIC } ) ];
		const adapter = gutenbergAdapter( 1, options );

		const result = adapter.apply(
			suggestion( 'se debe a ', 'muchas razones', ', entre ellas' )
		);

		expect( result.ok ).toBe( true );
		expect( mockUpdates ).toHaveLength( 1 );
		const written = String( mockUpdates[ 0 ]?.[ 1 ].content );
		expect( written ).toBe(
			CLASSIC.replace(
				'muchas razones',
				'<a href="https://ejemplo.test/destino/" target="_blank" rel="noopener">muchas razones</a>'
			)
		);
		if ( result.ok ) {
			expect( result.anchor ).toBe( 'muchas razones' );
			expect( result.undo() ).toBe( true );
			expect( mockFind( mockBlocks, 'c1' )?.attributes.content ).toBe(
				CLASSIC
			);
			// Ya no está: un segundo deshacer no toca nada.
			expect( result.undo() ).toBe( false );
		}
	} );

	it( 'no deshace si el usuario cambió el enlace después', () => {
		mockBlocks = [ block( 'c1', 'core/freeform', { content: CLASSIC } ) ];
		const result = gutenbergAdapter( 1, options ).apply(
			suggestion( 'se debe a ', 'muchas razones', ', entre ellas' )
		);
		const block1 = mockFind( mockBlocks, 'c1' );
		if ( ! block1 || ! result.ok ) {
			throw new Error( 'debería enlazar' );
		}
		const edited = String( block1.attributes.content ).replace(
			'muchas razones</a>',
			'muchísimas razones</a>'
		);
		block1.attributes.content = edited;
		expect( result.undo() ).toBe( false );
		expect( block1.attributes.content ).toBe( edited );
	} );

	it( 'un ancla que cruza formato se rechaza y no escribe nada', () => {
		mockBlocks = [ block( 'c1', 'core/freeform', { content: CLASSIC } ) ];
		const result = gutenbergAdapter( 1, options ).apply(
			suggestion( 'La fama ', 'de Budapest', ' Hungría' )
		);
		expect( result ).toEqual( { ok: false, reason: 'crosses_format' } );
		expect( mockUpdates ).toHaveLength( 0 );
	} );

	it( 'un encabezado dentro del bloque Clásico es «unsupported» y no «not_found»', () => {
		mockBlocks = [ block( 'c1', 'core/freeform', { content: CLASSIC } ) ];
		const result = gutenbergAdapter( 1, options ).apply(
			suggestion( 'Un ', 'encabezado propio', '' )
		);
		expect( result ).toEqual( { ok: false, reason: 'unsupported' } );
		expect( mockUpdates ).toHaveLength( 0 );
	} );

	it( 'si la frase ya no está, sigue siendo not_found', () => {
		mockBlocks = [ block( 'c1', 'core/freeform', { content: CLASSIC } ) ];
		expect(
			gutenbergAdapter( 1, options ).apply(
				suggestion( 'nada de ', 'esto existe', ' aquí' )
			)
		).toEqual( { ok: false, reason: 'not_found' } );
	} );

	it( 'un enlace que ya está se dice como tal', () => {
		mockBlocks = [
			block( 'c1', 'core/freeform', {
				content:
					'Mira <a href="https://x.test">este enlace viejo</a> otra vez.',
			} ),
		];
		expect(
			gutenbergAdapter( 1, options ).apply(
				suggestion( 'Mira este ', 'enlace viejo', ' otra vez' )
			)
		).toEqual( { ok: false, reason: 'already_linked' } );
	} );

	it( 'entre otros bloques el servidor no lo toca y el panel tampoco', () => {
		mockBlocks = [
			block( 'p1', 'core/paragraph', { content: 'Un párrafo normal.' } ),
			block( 'c1', 'core/freeform', { content: CLASSIC } ),
		];
		expect(
			gutenbergAdapter( 1, options ).apply(
				suggestion( 'se debe a ', 'muchas razones', ', entre ellas' )
			)
		).toEqual( { ok: false, reason: 'unsupported' } );
		expect( mockUpdates ).toHaveLength( 0 );
	} );

	it( 'varios bloques Clásicos seguidos cuentan como un documento clásico', () => {
		mockBlocks = [
			block( 'c1', 'core/freeform', {
				content: 'Primero un texto largo y variado.',
			} ),
			block( 'c2', 'core/freeform', { content: CLASSIC } ),
		];
		const result = gutenbergAdapter( 1, options ).apply(
			suggestion( 'se debe a ', 'muchas razones', ', entre ellas' )
		);
		expect( result.ok ).toBe( true );
		expect( mockUpdates[ 0 ]?.[ 0 ] ).toBe( 'c2' );
	} );
} );

describe( 'motivos de fallo en los demás bloques', () => {
	it( 'un encabezado con encabezados desactivados es «unsupported»', () => {
		mockBlocks = [
			block( 'h1', 'core/heading', {
				content: new FakeRichText( 'Una sección interesante' ),
			} ),
		];
		expect(
			gutenbergAdapter( 1, options ).apply(
				suggestion( 'Una ', 'sección interesante', '' )
			)
		).toEqual( { ok: false, reason: 'unsupported' } );
	} );

	it( 'un párrafo dentro de una cita desactivada es «unsupported»', () => {
		mockBlocks = [
			block( 'q1', 'core/quote', {}, [
				block( 'p1', 'core/paragraph', {
					content: new FakeRichText(
						'Una cita muy conocida de alguien.'
					),
				} ),
			] ),
		];
		expect(
			gutenbergAdapter( 1, options ).apply(
				suggestion( 'Una cita muy ', 'conocida', ' de alguien' )
			)
		).toEqual( { ok: false, reason: 'unsupported' } );
	} );

	it( 'un párrafo normal se enlaza como siempre', () => {
		mockBlocks = [
			block( 'p1', 'core/paragraph', {
				content: 'Una bomba de calor eficiente.',
			} ),
		];
		const result = gutenbergAdapter( 1, options ).apply(
			suggestion( 'Una ', 'bomba de calor', ' eficiente' )
		);
		expect( result.ok ).toBe( true );
		expect( String( mockUpdates[ 0 ]?.[ 1 ].content ) ).toContain(
			'<a href="https://ejemplo.test/destino/"'
		);
	} );
} );

describe( 'texto enriquecido (RichTextData) fuera de lo enlazable', () => {
	it( 'una cita destacada con la cita desactivada es «unsupported»', () => {
		mockBlocks = [
			block( 'pq', 'core/pullquote', {
				value: new FakeRichText(
					'Una frase célebre de alguien famoso'
				),
			} ),
		];
		expect(
			gutenbergAdapter( 1, options ).apply(
				suggestion( 'Una frase ', 'célebre', ' de alguien' )
			)
		).toEqual( { ok: false, reason: 'unsupported' } );
	} );

	it( 'la cita de una cita (citation) también', () => {
		mockBlocks = [
			block( 'q', 'core/quote', {
				citation: new FakeRichText( 'Autora muy conocida del siglo' ),
			} ),
		];
		expect(
			gutenbergAdapter( 1, options ).apply(
				suggestion( 'Autora ', 'muy conocida', ' del siglo' )
			)
		).toEqual( { ok: false, reason: 'unsupported' } );
	} );

	it( 'si la frase no está en ninguna parte sigue siendo not_found', () => {
		mockBlocks = [
			block( 'h1', 'core/heading', {
				content: new FakeRichText( 'Otro titular distinto' ),
			} ),
		];
		expect(
			gutenbergAdapter( 1, options ).apply(
				suggestion( 'Una ', 'sección interesante', '' )
			)
		).toEqual( { ok: false, reason: 'not_found' } );
	} );
} );

describe( 'deshacer con un enlace manual idéntico', () => {
	it( 'quita el suyo aunque ya hubiera otro con el mismo destino y atributos', () => {
		const manual =
			'<a href="https://ejemplo.test/destino/" target="_blank" rel="noopener">otra cosa</a>';
		mockBlocks = [
			block( 'c1', 'core/freeform', {
				content: `Ya está ${ manual } y aquí una frase nueva que enlazar hoy.`,
			} ),
		];
		const result = gutenbergAdapter( 1, options ).apply(
			suggestion( 'aquí una ', 'frase nueva', ' que enlazar' )
		);
		expect( result.ok ).toBe( true );
		if ( result.ok ) {
			expect( result.undo() ).toBe( true );
			expect( mockFind( mockBlocks, 'c1' )?.attributes.content ).toBe(
				`Ya está ${ manual } y aquí una frase nueva que enlazar hoy.`
			);
		}
	} );
} );

describe( 'resaltado: mismo criterio que enlazar', () => {
	const show = (
		html: string,
		sentence: [ string, string, string ],
		inner = html
	) => {
		mockBlocks = [ block( 'c1', 'core/freeform', { content: html } ) ];
		document.body.innerHTML = `<div data-block="c1"><div contenteditable="true">${ inner }</div></div>`;
		( showHighlight as jest.Mock ).mockClear();
		( clearHighlight as jest.Mock ).mockClear();
		gutenbergAdapter( 1, options ).highlight( suggestion( ...sentence ) );
	};

	it( 'una aparición dentro de un <a> no cuenta: se resalta la enlazable', () => {
		show(
			'Mira <a href="https://x.test">la frase buena</a> y también la frase buena aquí.',
			[ '', 'frase buena', '' ]
		);
		expect( showHighlight ).toHaveBeenCalledTimes( 1 );
		const range = ( showHighlight as jest.Mock ).mock
			.calls[ 0 ][ 0 ] as Range;
		expect( range.toString() ).toBe( 'frase buena' );
		expect( range.startContainer.textContent ).toContain( 'también' );
	} );

	it( 'una aparición en un encabezado desactivado no cuenta', () => {
		show(
			'<h2>Aerotermia eficiente</h2>\nLa aerotermia eficiente ahorra.',
			[ '', 'eficiente', '' ]
		);
		expect( showHighlight ).toHaveBeenCalledTimes( 1 );
		const range = ( showHighlight as jest.Mock ).mock
			.calls[ 0 ][ 0 ] as Range;
		expect( range.startContainer.textContent ).toContain( 'ahorra' );
	} );

	it( 'si no se puede enlazar en ningún sitio, no resalta', () => {
		show( '<h2>Aerotermia eficiente</h2>\nOtro texto.', [
			'',
			'eficiente',
			'',
		] );
		expect( showHighlight ).not.toHaveBeenCalled();
		expect( clearHighlight ).toHaveBeenCalled();
	} );
} );
