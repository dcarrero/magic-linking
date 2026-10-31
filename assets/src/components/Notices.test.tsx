import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { api } from '../api';
import type { AdminNotice } from '../types';
import { Notices } from './Notices';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '@wordpress/components', () => ( {
	Notice: ( {
		children,
		onRemove,
		actions,
		status,
	}: {
		children?: unknown;
		onRemove?: () => void;
		status?: string;
		actions?: {
			label: string;
			url?: string;
			onClick?: () => void;
		}[];
	} ) => (
		<div data-status={ status }>
			{ children as string }
			{ actions?.map( ( a ) =>
				a.url ? (
					<a key={ a.label } href={ a.url } onClick={ a.onClick }>
						{ a.label }
					</a>
				) : (
					<button key={ a.label } type="button" onClick={ a.onClick }>
						{ a.label }
					</button>
				)
			) }
			<button type="button" aria-label="Close" onClick={ onRemove } />
		</div>
	),
} ) );
jest.mock( '../api', () => ( {
	api: { dismissNotice: jest.fn() },
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const dismiss = ( api as unknown as { dismissNotice: jest.Mock } )
	.dismissNotice;

const review: AdminNotice = {
	id: 'review',
	type: 'info',
	text: 'Do you like it?',
	action: { label: 'Write a review', url: 'https://wordpress.org/r' },
	dismiss_label: 'No, thanks',
};

let container: HTMLDivElement;
let root: Root;

beforeEach( () => {
	dismiss.mockReset().mockResolvedValue( { dismissed: 'review' } );
	document.body.innerHTML =
		'<div class="magiclinking-wrap"><h1>Title</h1><div id="r"></div></div>';
	container = document.getElementById( 'r' ) as HTMLDivElement;
	root = createRoot( container );
} );

afterEach( () => {
	act( () => root.unmount() );
} );

const render = ( notice?: AdminNotice | null ) =>
	act( () => root.render( <Notices notice={ notice } /> ) );

const click = ( el: Element | null ) =>
	act( () => {
		( el as HTMLElement ).click();
	} );

describe( 'Notices', () => {
	test( 'shows nothing without a notice', () => {
		render( null );
		expect( container.innerHTML ).toBe( '' );
		render( undefined );
		expect( container.innerHTML ).toBe( '' );
	} );

	test( 'shows the text, a plain link and the dismiss button', () => {
		render( review );
		expect( container.textContent ).toContain( 'Do you like it?' );
		const link = container.querySelector( 'a' ) as HTMLAnchorElement;
		expect( link.getAttribute( 'href' ) ).toBe( 'https://wordpress.org/r' );
		expect( container.textContent ).toContain( 'No, thanks' );
		expect(
			container.querySelector( '[aria-label="Close"]' )
		).not.toBeNull();
	} );

	test( 'the dismiss button closes it for good and moves focus to the title', () => {
		render( review );
		click( container.querySelector( 'button:not([aria-label])' ) );
		expect( dismiss ).toHaveBeenCalledWith( 'review' );
		expect( container.innerHTML ).toBe( '' );
		const heading = document.querySelector( 'h1' ) as HTMLElement;
		expect( heading.ownerDocument.activeElement ).toBe( heading );
	} );

	test( 'the X also closes it for good', () => {
		render( review );
		click( container.querySelector( '[aria-label="Close"]' ) );
		expect( dismiss ).toHaveBeenCalledWith( 'review' );
		expect( container.innerHTML ).toBe( '' );
	} );

	test( 'following the review link closes it for good too', () => {
		render( review );
		container
			.querySelector( 'a' )
			?.addEventListener( 'click', ( e ) => e.preventDefault() );
		click( container.querySelector( 'a' ) );
		expect( dismiss ).toHaveBeenCalledWith( 'review' );
	} );

	test( 'a failed save does not break the page', async () => {
		dismiss.mockRejectedValue( new Error( 'x' ) );
		render( review );
		click( container.querySelector( '[aria-label="Close"]' ) );
		await act( async () => {} );
		expect( container.innerHTML ).toBe( '' );
	} );
} );
