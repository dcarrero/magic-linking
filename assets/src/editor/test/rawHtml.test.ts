import { locate } from '../locate';
import {
	blocker,
	buildView,
	forbidden,
	onlyLinkAdded,
	openTag,
	unwrap,
	wrap,
} from '../rawHtml';

const OFF = { headings: false, quotes: false, tables: false };
const URL = 'https://ejemplo.test/destino/';

/**
 * Enlaza `anchor` (con su contexto) en `html` como lo hace el adaptador de Gutenberg.
 * @param html
 * @param anchor
 * @param before
 * @param after
 * @param scope
 * @param attrs
 * @param attrs.target
 * @param attrs.rel
 */
function link(
	html: string,
	anchor: string,
	before = '',
	after = '',
	scope = OFF,
	attrs: { target?: string; rel?: string } = {}
) {
	const view = buildView( html );
	const noLink = forbidden( scope );
	const found = locate(
		[
			{
				key: 'b',
				text: view.text,
				accepts: ( s, e ) => blocker( view, s, e, noLink ) === null,
			},
		],
		{ before, anchor, after }
	);
	if ( found.status !== 'found' ) {
		return { status: found.status } as const;
	}
	const open = openTag( URL, attrs );
	const result = wrap( html, view, found.start, found.end, open );
	return {
		status: 'found' as const,
		result,
		applied: view.text.slice( found.start, found.end ),
		open,
	};
}

describe( 'buildView', () => {
	it( 'lee el texto sin etiquetas, con entidades decodificadas y espacios colapsados', () => {
		const view = buildView(
			'Caf&eacute;  &amp; t&#233; &#8230;&nbsp;fin <em>uno</em>   dos'
		);
		expect( view.text ).toBe( 'Café & té … fin uno dos' );
	} );

	it( 'una línea en blanco corta el texto y un salto simple no', () => {
		const view = buildView( 'uno\ndos\n\ntres' );
		expect( view.text ).toBe( 'uno dos\x1Ftres' );
	} );

	it( 'no lee dentro de comentarios, de código ni de atributos', () => {
		const view = buildView(
			'a <!-- oculto --> b <code>x = 1</code> c <img alt="no cuenta" src="a.jpg"> d <span title="tampoco">e</span>'
		);
		expect( view.text ).toBe( 'a b · c · d e' ); // comentario, código e imagen
		expect( view.text ).not.toContain( 'oculto' );
		expect( view.text ).not.toContain( 'x = 1' );
		expect( view.text ).not.toContain( 'no cuenta' );
		expect( view.text ).not.toContain( 'tampoco' );
	} );

	it( 'marca como no seguro lo que no recorre', () => {
		expect( buildView( '<p>a</p><script>var x;</script>' ).unsafe ).toBe(
			'script'
		);
		expect( buildView( '<p>a</p>' ).unsafe ).toBeNull();
	} );
} );

describe( 'enlazar sobre la cadena', () => {
	const real =
		'La fama de <strong>Budapest Hungría </strong>se debe a muchas razones, entre ellas sus balnearios.\n\n' +
		'Otro párrafo con <em>cursiva</em> y “comillas” &amp; más.';

	it( 'inserta solo <a> y </a> y el resto queda idéntico byte a byte', () => {
		const out = link(
			real,
			'muchas razones',
			'se debe a ',
			', entre ellas'
		);
		expect( out.status ).toBe( 'found' );
		if ( out.status !== 'found' || ! out.result.ok ) {
			throw new Error( 'debería enlazar' );
		}
		expect( out.result.html ).toBe(
			real.replace( 'muchas razones', `${ out.open }muchas razones</a>` )
		);
		// Quitar exactamente las dos piezas devuelve el original.
		expect(
			out.result.html.replace( out.open, '' ).replace( '</a>', '' )
		).toBe( real );
		expect( unwrap( out.result.html, out.result ) ).toBe( real );
	} );

	it( 'respeta entidades, comillas tipográficas y dobles espacios fuera del ancla', () => {
		const html =
			'Texto  con  dobles &nbsp;espacios, caf&eacute; &#8230; y una frase   enlazable aquí &amp; allá.';
		const out = link( html, 'frase enlazable', 'y una ', ' aquí' );
		if ( out.status !== 'found' || ! out.result.ok ) {
			throw new Error( 'debería enlazar' );
		}
		// El ancla cubre los tres espacios del original tal cual.
		expect( out.result.html ).toContain(
			`${ out.open }frase   enlazable</a> aquí &amp; allá.`
		);
		expect(
			out.result.html.startsWith(
				'Texto  con  dobles &nbsp;espacios, caf&eacute; &#8230; y una '
			)
		).toBe( true );
	} );

	it( 'mapea un ancla que contiene entidades', () => {
		const html = 'Visita caf&eacute; &amp; bar&#8230; hoy mismo';
		const out = link( html, 'café & bar…', 'Visita ', ' hoy' );
		if ( out.status !== 'found' || ! out.result.ok ) {
			throw new Error( 'debería enlazar' );
		}
		expect( out.result.html ).toBe(
			`Visita ${ out.open }caf&eacute; &amp; bar&#8230;</a> hoy mismo`
		);
		expect( out.applied ).toBe( 'café & bar…' );
	} );

	it( 'cuenta bien tras caracteres fuera del plano básico', () => {
		const html = '😀 hola 🎉 mundo bonito y grande';
		const out = link( html, 'mundo bonito', 'hola 🎉 ', ' y grande' );
		if ( out.status !== 'found' || ! out.result.ok ) {
			throw new Error( 'debería enlazar' );
		}
		expect( out.result.html ).toBe(
			`😀 hola 🎉 ${ out.open }mundo bonito</a> y grande`
		);
	} );

	it( 'enlaza texto sin <p> en el segundo párrafo, separado por una línea en blanco', () => {
		const out = link( real, 'Otro párrafo', '', ' con cursiva' );
		if ( out.status !== 'found' || ! out.result.ok ) {
			throw new Error( 'debería enlazar' );
		}
		expect( out.result.html ).toContain(
			`\n\n${ out.open }Otro párrafo</a> con <em>`
		);
	} );

	it( 'una frase que cruza la línea en blanco no se encuentra', () => {
		expect(
			link( real, 'sus balnearios. Otro', 'entre ellas ', ' párrafo' )
				.status
		).toBe( 'not_found' );
	} );

	it( 'añade target y rel solo si están en los Ajustes', () => {
		expect( openTag( URL, {} ) ).toBe( `<a href="${ URL }">` );
		expect(
			openTag( 'https://x.test/?a=1&b="2"', {
				target: '_blank',
				rel: 'noopener',
			} )
		).toBe(
			'<a href="https://x.test/?a=1&amp;b=&quot;2&quot;" target="_blank" rel="noopener">'
		);
	} );

	describe( 'ancla que cruza etiquetas', () => {
		it( 'si falta el otro extremo y está justo al lado, el enlace lo incluye', () => {
			const html = 'La fama de <strong>Budapest </strong>se debe a todo.';
			const out = link( html, 'Budapest se', 'fama de ', ' debe' );
			if ( out.status !== 'found' || ! out.result.ok ) {
				throw new Error( 'debería enlazar' );
			}
			expect( out.result.html ).toBe(
				`La fama de ${ out.open }<strong>Budapest </strong>se</a> debe a todo.`
			);
			expect( unwrap( out.result.html, out.result ) ).toBe( html );
		} );

		it( 'si queda mal anidado, se rechaza y no se recorta', () => {
			const html =
				'La fama de <strong>Budapest Hungría</strong> es grande.';
			const out = link( html, 'de Budapest', 'La fama ', ' Hungría' );
			expect( out.status ).toBe( 'found' );
			if ( out.status === 'found' ) {
				expect( out.result ).toEqual( {
					ok: false,
					reason: 'crosses_format',
				} );
			}
		} );

		it( 'un ancla entera dentro de la negrita se envuelve dentro', () => {
			const html =
				'La fama de <strong>Budapest Hungría </strong>es grande.';
			const out = link(
				html,
				'Budapest Hungría',
				'fama de ',
				' es grande'
			);
			if ( out.status !== 'found' || ! out.result.ok ) {
				throw new Error( 'debería enlazar' );
			}
			expect( out.result.html ).toBe(
				`La fama de <strong>${ out.open }Budapest Hungría</a> </strong>es grande.`
			);
		} );
	} );

	describe( 'dónde no se enlaza', () => {
		it( 'no cuenta lo que ya es un enlace', () => {
			const html =
				'Mira <a href="https://otro.test">este enlace viejo</a> y este texto.';
			const view = buildView( html );
			const at = view.text.indexOf( 'enlace viejo' );
			expect( blocker( view, at, at + 12, forbidden( OFF ) ) ).toBe(
				'link'
			);
			expect(
				link( html, 'enlace viejo', 'este ', ' y este' ).status
			).toBe( 'not_found' );
		} );

		it( 'no enlaza un ancla que contiene un shortcode ni una imagen, pero sí el texto de al lado', () => {
			const html =
				'Antes del código [caption id="a" align="aligncenter"]<img src="a.jpg"> Pie de foto[/caption] y después más texto.';
			expect(
				link( html, 'código Pie', 'Antes del ', ' de foto' ).status
			).toBe( 'not_found' );
			expect(
				link( html, 'foto y después', 'Pie de ', ' más texto' ).status
			).toBe( 'not_found' );
			expect( link( html, 'después más', 'y ', ' texto' ).status ).toBe(
				'found'
			);
		} );

		it( 'respeta Ajustes con encabezados, citas y tablas', () => {
			const html =
				'<h2>Título de sección</h2>\nCuerpo normal.\n\n<blockquote>Una cita célebre aquí</blockquote>\n\n<table><tr><td>Celda de tabla</td></tr></table>';
			expect( link( html, 'Título de sección' ).status ).toBe(
				'not_found'
			);
			expect( link( html, 'cita célebre' ).status ).toBe( 'not_found' );
			expect( link( html, 'Celda de tabla' ).status ).toBe( 'not_found' );
			expect(
				link( html, 'Título de sección', '', '', {
					...OFF,
					headings: true,
				} ).status
			).toBe( 'found' );
			expect(
				link( html, 'cita célebre', '', '', {
					...OFF,
					quotes: true,
				} ).status
			).toBe( 'found' );
			expect(
				link( html, 'Celda de tabla', '', '', {
					...OFF,
					tables: true,
				} ).status
			).toBe( 'found' );
		} );

		it( 'una aparición no enlazable no hace ambigua la enlazable', () => {
			const html =
				'<h2>Aerotermia eficiente</h2>\nLa aerotermia eficiente ahorra.';
			const out = link( html, 'aerotermia eficiente', 'La ', ' ahorra' );
			expect( out.status ).toBe( 'found' );
			// Sin contexto, la del encabezado tampoco cuenta.
			expect( link( html, 'ahorra' ).status ).toBe( 'found' );
		} );

		it( 'dos apariciones enlazables son ambiguas', () => {
			expect(
				link( 'uno dos tres.\n\nuno dos tres.', 'dos', 'uno ', ' tres' )
					.status
			).toBe( 'ambiguous' );
		} );
	} );
} );

describe( 'verificación byte a byte', () => {
	const html = 'Uno <b>dos</b> tres cuatro';
	const text = buildView( html ).text;
	const open = openTag( URL, {} );
	const good = `Uno <b>dos</b> ${ open }tres</a> cuatro`;

	it( 'acepta exactamente las dos piezas', () => {
		expect( onlyLinkAdded( html, good, 15, 19, open, text ) ).toBe( true );
	} );

	it( 'rechaza cualquier otro cambio', () => {
		// Un espacio menos en otro sitio.
		expect(
			onlyLinkAdded(
				html,
				good.replace( 'Uno ', 'Uno' ),
				15,
				19,
				open,
				text
			)
		).toBe( false );
		// Una entidad reserializada.
		expect(
			onlyLinkAdded(
				'a &amp; tres',
				`a & ${ open }tres</a>`,
				6,
				10,
				open,
				buildView( 'a &amp; tres' ).text
			)
		).toBe( false );
		// Piezas en otro sitio.
		expect( onlyLinkAdded( html, good, 14, 19, open, text ) ).toBe( false );
		// El texto que ve el lector cambia.
		expect( onlyLinkAdded( html, good, 15, 19, open, 'otro texto' ) ).toBe(
			false
		);
	} );
} );

describe( 'unwrap', () => {
	const open = openTag( URL, {} );
	const wrapOf = (
		html: string,
		anchor: string,
		before: string,
		after: string
	) => {
		const out = link( html, anchor, before, after );
		if ( out.status !== 'found' || ! out.result.ok ) {
			throw new Error( 'debería enlazar' );
		}
		return out.result;
	};

	it( 'devuelve el original y no toca nada si el enlace cambió', () => {
		const html = 'Uno <b>dos</b> tres cuatro';
		const done = wrapOf( html, 'tres', 'dos ', ' cuatro' );
		expect( unwrap( done.html, done ) ).toBe( html );
		// Texto distinto dentro, o ya no está.
		expect(
			unwrap( done.html.replace( 'tres', 'seis' ), done )
		).toBeNull();
		expect( unwrap( html, done ) ).toBeNull();
	} );

	it( 'no se niega por un enlace manual idéntico (mismo destino y mismos atributos) que ya existía', () => {
		const html = `Ya enlazado: ${ open }otra cosa</a> y aquí tres cuatro.`;
		const done = wrapOf( html, 'tres', 'aquí ', ' cuatro' );
		expect( done.html.split( open ).length - 1 ).toBe( 2 );
		expect( unwrap( done.html, done ) ).toBe( html );
	} );

	it( 'lo encuentra aunque el usuario haya escrito delante y se haya movido', () => {
		const html = 'Uno dos tres cuatro';
		const done = wrapOf( html, 'tres', 'dos ', ' cuatro' );
		const moved = 'Algo nuevo. ' + done.html;
		expect( unwrap( moved, done ) ).toBe( 'Algo nuevo. ' + html );
	} );

	it( 'con dos enlaces idénticos, el de su sitio es el suyo y, movido, no se sabe cuál', () => {
		const html = `Antes ${ open }tres</a> y luego tres cuatro`;
		const done = wrapOf( html, 'tres', 'luego ', ' cuatro' );
		expect( unwrap( done.html, done ) ).toBe( html );
		expect( unwrap( 'X' + done.html, done ) ).toBeNull();
	} );
} );
