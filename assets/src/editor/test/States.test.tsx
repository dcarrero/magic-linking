import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { EmptyState } from '../States';

jest.mock( '@wordpress/components', () => ( {
	Button: ( { children }: { children?: unknown } ) => (
		<button type="button">{ children as string }</button>
	),
	Spinner: () => null,
} ) );

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

describe( 'EmptyState con filtros Exigir', () => {
	it( 'explica el filtro y enlaza a Ajustes si el usuario puede abrirlos', () => {
		const host = mount(
			<EmptyState
				state="ok"
				direction="outbound"
				reportUrl="/report"
				required={ [ 'category' ] }
				settingsUrl="/settings#tab=suggestions"
			/>
		);
		expect( host.textContent ).toContain( 'match this one in category' );
		const link = host.querySelector( 'a' );
		expect( link?.getAttribute( 'href' ) ).toBe(
			'/settings#tab=suggestions'
		);
	} );

	it( 'sin permiso para los ajustes no enseña un enlace que no podría abrir', () => {
		const host = mount(
			<EmptyState
				state="ok"
				direction="inbound"
				reportUrl="/report"
				required={ [ 'category', 'tag' ] }
			/>
		);
		expect( host.querySelector( 'a' ) ).toBeNull();
		expect( host.textContent ).toContain( 'category' );
		expect( host.textContent ).toContain( 'tag' );
		expect( host.textContent ).toContain( 'administrator' );
	} );

	it( 'sin filtros exigidos conserva el texto de siempre', () => {
		const host = mount(
			<EmptyState state="ok" direction="outbound" reportUrl="/report" />
		);
		expect( host.textContent ).toContain( 'enough confidence' );
	} );
} );
