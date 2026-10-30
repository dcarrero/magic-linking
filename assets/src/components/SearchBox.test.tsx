import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { SearchBox } from './SearchBox';

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

function mount( node: React.ReactElement ): HTMLElement {
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	act( () => {
		createRoot( host ).render( node );
	} );
	return host;
}

describe( 'SearchBox', () => {
	it( 'por defecto pinta su formulario de búsqueda con botón', () => {
		const host = mount(
			<SearchBox id="s" label="Buscar" value="" onChange={ jest.fn() } />
		);
		expect( host.querySelectorAll( 'form[role="search"]' ) ).toHaveLength(
			1
		);
		expect( host.querySelector( 'input[type="submit"]' ) ).not.toBeNull();
	} );

	it( 'anidado no mete un form dentro de otro ni un botón de envío', () => {
		const host = mount(
			<form>
				<SearchBox
					id="s"
					label="Buscar"
					value=""
					onChange={ jest.fn() }
					nested
				/>
			</form>
		);
		expect( host.querySelectorAll( 'form' ) ).toHaveLength( 1 );
		expect( host.querySelector( 'form form' ) ).toBeNull();
		expect( host.querySelector( 'input[type="submit"]' ) ).toBeNull();
		expect( host.querySelector( '[role="search"]' ) ).not.toBeNull();
	} );

	it( 'anidado, Intro no se deja llegar al formulario de fuera', () => {
		const host = mount(
			<form>
				<SearchBox
					id="s"
					label="Buscar"
					value="algo"
					onChange={ jest.fn() }
					nested
				/>
			</form>
		);
		const input = host.querySelector( 'input' ) as HTMLInputElement;
		const event = new KeyboardEvent( 'keydown', {
			key: 'Enter',
			bubbles: true,
			cancelable: true,
		} );
		act( () => {
			input.dispatchEvent( event );
		} );
		expect( event.defaultPrevented ).toBe( true );
	} );

	it( 'anidado, Intro durante una composición IME no se cancela', () => {
		const host = mount(
			<form>
				<SearchBox
					id="s"
					label="Buscar"
					value="に"
					onChange={ jest.fn() }
					nested
				/>
			</form>
		);
		const input = host.querySelector( 'input' ) as HTMLInputElement;
		const event = new KeyboardEvent( 'keydown', {
			key: 'Enter',
			isComposing: true,
			bubbles: true,
			cancelable: true,
		} );
		act( () => {
			input.dispatchEvent( event );
		} );
		expect( event.defaultPrevented ).toBe( false );
	} );
} );
