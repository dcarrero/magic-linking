import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { CurrentLink } from '../../types';
import { CurrentLinks } from '../CurrentLinks';

jest.mock( '@wordpress/components', () => ( {
	Button: ( {
		children,
		onClick,
		...rest
	}: {
		children?: unknown;
		onClick?: () => void;
	} ) => (
		<button
			type="button"
			onClick={ onClick }
			{ ...( rest as Record< string, unknown > ) }
		>
			{ children as string }
		</button>
	),
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const guide: CurrentLink = {
	id: 7,
	title: 'Guía de ahorro',
	url: 'https://example.org/guia/',
	anchors: [ 'la guía de ahorro', 'esta guía' ],
};

let root: Root;
let host: HTMLElement;

beforeEach( () => {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
} );
afterEach( () => {
	act( () => root.unmount() );
	host.remove();
} );

describe( 'CurrentLinks', () => {
	it( 'draws nothing when the entry links to nobody', async () => {
		await act( () => root.render( <CurrentLinks links={ [] } /> ) );
		expect( host.innerHTML ).toBe( '' );
	} );

	it( 'is a collapsed block with the count in its name', async () => {
		await act( () => root.render( <CurrentLinks links={ [ guide ] } /> ) );

		const toggle = host.querySelector( 'button' );
		expect( toggle?.textContent ).toContain( 'Current links (1)' );
		expect( toggle?.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		const region = document.getElementById(
			toggle?.getAttribute( 'aria-controls' ) ?? ''
		);
		expect( region?.hasAttribute( 'hidden' ) ).toBe( true );
	} );

	it( 'opens with the mark Already linked as text, not only as colour', async () => {
		await act( () => root.render( <CurrentLinks links={ [ guide ] } /> ) );
		await act( () => host.querySelector( 'button' )?.click() );

		const toggle = host.querySelector( 'button' );
		expect( toggle?.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		const row = host.querySelector( 'li' );
		expect( row?.textContent ).toContain( 'Guía de ahorro' );
		expect( row?.textContent ).toContain( 'Already linked' );
		expect(
			row?.querySelector( '[aria-hidden="true"]' )?.textContent
		).toBe( '✓ ' );
		expect( row?.textContent ).toContain( '“la guía de ahorro”' );
		expect( row?.textContent ).toContain( '“esta guía”' );
		const link = row?.querySelector( 'a' );
		expect( link?.getAttribute( 'href' ) ).toBe( guide.url );
		expect( link?.getAttribute( 'rel' ) ).toBe( 'noopener noreferrer' );
	} );

	it( 'shows only that there is an internal link, without title or address, when the user cannot read the destination', async () => {
		await act( () =>
			root.render(
				<CurrentLinks
					links={ [
						{
							id: 9,
							restricted: true,
							anchors: [ 'la oferta' ],
						},
					] }
				/>
			)
		);
		await act( () => host.querySelector( 'button' )?.click() );

		const row = host.querySelector( 'li' );
		expect( row?.textContent ).toContain( 'Internal link (no access)' );
		expect( row?.textContent ).toContain( 'Already linked' );
		expect( row?.querySelector( 'a' ) ).toBeNull();
	} );

	it( 'shows the state of a draft or private destination the user can read', async () => {
		await act( () =>
			root.render(
				<CurrentLinks
					links={ [
						{
							id: 4,
							title: 'Nota privada',
							url: 'https://example.org/nota/',
							status: 'private',
							status_label: 'Private',
							anchors: [],
						},
					] }
				/>
			)
		);
		await act( () => host.querySelector( 'button' )?.click() );

		expect( host.querySelector( 'li' )?.textContent ).toContain(
			'Nota privada'
		);
		expect( host.querySelector( 'li' )?.textContent ).toContain(
			'(Private)'
		);
	} );
} );
