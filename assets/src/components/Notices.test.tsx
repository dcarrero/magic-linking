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
		className,
	}: {
		children?: unknown;
		onRemove?: () => void;
		status?: string;
		className?: string;
		actions?: { label: string; onClick?: () => void }[];
	} ) => (
		<div data-status={ status } className={ className }>
			{ children as string }
			{ actions?.map( ( a ) => (
				<button key={ a.label } type="button" onClick={ a.onClick }>
					{ a.label }
				</button>
			) ) }
			<button type="button" aria-label="Close" onClick={ onRemove } />
		</div>
	),
} ) );
jest.mock( '../api', () => ( {
	api: { dismissNotice: jest.fn(), seenNotice: jest.fn() },
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const seen = ( api as unknown as { seenNotice: jest.Mock } ).seenNotice;
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
	seen.mockReset().mockResolvedValue( { seen: 'review' } );
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

	const flush = () =>
		act( async () => {
			await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		} );

	const stopNavigation = () =>
		container
			.querySelector( 'a' )
			?.addEventListener( 'click', ( e ) => e.preventDefault() );

	test( 'a plain click on the review link saves the close without cancelling the navigation or waiting for it', async () => {
		render( review );
		dismiss.mockReturnValue( new Promise( () => {} ) );
		const event = new MouseEvent( 'click', {
			bubbles: true,
			cancelable: true,
			button: 0,
		} );
		// Un oyente más arriba que React ve si el componente canceló la navegación (y evita que jsdom intente navegar).
		let cancelledByTheComponent = true;
		document.body.addEventListener(
			'click',
			( e ) => {
				cancelledByTheComponent = e.defaultPrevented;
				e.preventDefault();
			},
			{ once: true }
		);
		act( () => {
			container.querySelector( 'a' )?.dispatchEvent( event );
		} );
		expect( cancelledByTheComponent ).toBe( false );
		expect( dismiss ).toHaveBeenCalledTimes( 1 );
		expect( dismiss ).toHaveBeenCalledWith( 'review' );
		await flush();
		expect( container.innerHTML ).toBe( '' );
	} );

	test( 'a right click on the link closes nothing', async () => {
		render( review );
		act( () => {
			container.querySelector( 'a' )?.dispatchEvent(
				new MouseEvent( 'auxclick', {
					bubbles: true,
					cancelable: true,
					button: 2,
				} )
			);
		} );
		await flush();
		expect( dismiss ).not.toHaveBeenCalled();
		expect( container.textContent ).toContain( 'Do you like it?' );
	} );

	test( 'tells the server the notice was really shown, once', () => {
		render( review );
		expect( seen ).toHaveBeenCalledTimes( 1 );
		expect( seen ).toHaveBeenCalledWith( 'review' );
		render( review );
		expect( seen ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'does not tell it when there is no notice', () => {
		render( null );
		expect( seen ).not.toHaveBeenCalled();
	} );

	test( 'a Cmd or Ctrl click on the link closes it too', async () => {
		render( review );
		stopNavigation();
		act( () => {
			container.querySelector( 'a' )?.dispatchEvent(
				new MouseEvent( 'click', {
					bubbles: true,
					cancelable: true,
					ctrlKey: true,
					metaKey: true,
				} )
			);
		} );
		await flush();
		expect( dismiss ).toHaveBeenCalledWith( 'review' );
		expect( container.innerHTML ).toBe( '' );
	} );

	test( 'a middle click on the link closes it too', async () => {
		render( review );
		act( () => {
			container.querySelector( 'a' )?.dispatchEvent(
				new MouseEvent( 'auxclick', {
					bubbles: true,
					cancelable: true,
					button: 1,
				} )
			);
		} );
		await flush();
		expect( dismiss ).toHaveBeenCalledWith( 'review' );
		expect( container.innerHTML ).toBe( '' );
	} );

	test( 'a failed save brings the notice back with an error message', async () => {
		dismiss.mockRejectedValue( new Error( 'x' ) );
		render( review );
		click( container.querySelector( '[aria-label="Close"]' ) );
		await act( async () => {} );
		expect( container.textContent ).toContain( 'Do you like it?' );
		expect(
			container.querySelector( '[role="alert"]' )?.textContent
		).toContain( 'could not be closed' );
	} );

	test( 'the AI connector notice keeps its own class', () => {
		render( { ...review, id: 'ai-connector', action: null } );
		expect(
			container.querySelector( '.magiclinking-ai-notice' )
		).not.toBeNull();
	} );
} );
