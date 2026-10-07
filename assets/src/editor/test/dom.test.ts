import { classicTextElements, ownEditable } from '../dom';

function html( markup: string ): HTMLElement {
	const root = document.createElement( 'div' );
	root.innerHTML = markup;
	return root;
}

describe( 'ownEditable', () => {
	it( 'excluye las listas anidadas de un li padre', () => {
		const root = html(
			'<ul><li data-block="a"><div contenteditable="true">Padre</div><ul><li data-block="b"><div contenteditable="true">Hijo</div></li></ul></li></ul>'
		);
		const parent = root.querySelector( '[data-block="a"]' ) as Element;
		expect( parent.textContent ).toBe( 'PadreHijo' );
		expect( ownEditable( parent ).textContent ).toBe( 'Padre' );
	} );

	it( 'usa el propio elemento si es el editable (párrafo)', () => {
		const root = html(
			'<p data-block="p" contenteditable="true">Texto</p>'
		);
		const block = root.querySelector( 'p' ) as Element;
		expect( ownEditable( block ) ).toBe( block );
	} );
} );

describe( 'classicTextElements', () => {
	it( 'cuenta una vez un p dentro de un li y un div que envuelve párrafos', () => {
		const root = html(
			'<ul><li><p>Uno</p></li><li>Dos</li></ul><div><p>Tres</p><p>Cuatro</p></div><div>Cinco</div>'
		);
		expect(
			classicTextElements( root ).map( ( e ) => e.textContent )
		).toEqual( [ 'Uno', 'Dos', 'Tres', 'Cuatro', 'Cinco' ] );
	} );

	it( 'ignora citas y tablas', () => {
		const root = html(
			'<blockquote><p>Cita</p></blockquote><table><tr><td><p>Celda</p></td></tr></table><p>Fuera</p>'
		);
		expect(
			classicTextElements( root ).map( ( e ) => e.textContent )
		).toEqual( [ 'Fuera' ] );
	} );
} );
