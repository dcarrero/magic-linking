import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { speak } from '@wordpress/a11y';
import { api } from '../../api';
import { AnchorAdjuster } from '../AnchorAdjuster';
import { byteLength } from '../variants';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '@wordpress/components', () => ( {
	Button: ( {
		children,
		onClick,
		disabled,
	}: {
		children?: unknown;
		onClick?: () => void;
		disabled?: boolean;
	} ) => (
		<button type="button" onClick={ onClick } disabled={ disabled }>
			{ children as string }
		</button>
	),
} ) );
jest.mock( '../../api', () => ( {
	api: { checkAnchor: jest.fn() },
	errorMessage: ( _e: unknown, fallback: string ) => fallback,
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const check = api.checkAnchor as unknown as jest.Mock;
const sentence =
	'La instalación de una bomba de calor aerotérmica reduce el consumo.';
const start = sentence.indexOf( 'bomba' );
const end = start + 'bomba de calor'.length;

let root: Root;
let host: HTMLElement;
const onApply = jest.fn();
const onCancel = jest.fn();

const mount = () => {
	document.body.appendChild( host );
	return act( () => {
		root.render(
			<AnchorAdjuster
				postId={ 5 }
				sentence={ sentence }
				start={ start }
				end={ end }
				onApply={ onApply }
				onCancel={ onCancel }
			/>
		);
	} );
};

const group = () =>
	host.querySelector< HTMLElement >( '[role="group"]' ) as HTMLElement;

const press = ( key: string, shiftKey = false ) => {
	const event = new KeyboardEvent( 'keydown', {
		key,
		shiftKey,
		bubbles: true,
		cancelable: true,
	} );
	act( () => {
		group().dispatchEvent( event );
	} );
	return event;
};

const state = () => host.querySelector( '[role="status"]' )?.textContent ?? '';

const wait = ( ms: number ) =>
	act( async () => {
		jest.advanceTimersByTime( ms );
		await Promise.resolve();
	} );

beforeEach( () => {
	jest.useFakeTimers();
	host = document.createElement( 'div' );
	root = createRoot( host );
	check.mockReset();
	onApply.mockReset();
	onCancel.mockReset();
	( speak as jest.Mock ).mockReset();
} );
afterEach( () => {
	act( () => root.unmount() );
	host.remove();
	jest.useRealTimers();
} );

describe( 'AnchorAdjuster keyboard', () => {
	it( 'is a labelled group that takes the focus and is a tab stop', async () => {
		await mount();
		expect( group().getAttribute( 'aria-label' ) ).toBe( 'Adjust anchor' );
		expect( group().tabIndex ).toBe( 0 );
		expect( host.ownerDocument.activeElement ).toBe( group() );
		expect( state() ).toContain( 'Anchor: bomba de calor, 3 words' );
	} );

	it( 'extends and shortens on the right and on the left with the arrows', async () => {
		await mount();
		press( 'ArrowRight' );
		expect( state() ).toContain( 'bomba de calor aerotérmica, 4 words' );
		press( 'ArrowLeft' );
		press( 'ArrowLeft' );
		expect( state() ).toContain( 'Anchor: bomba de, 2 words' );
		press( 'ArrowLeft', true );
		expect( state() ).toContain( 'Anchor: una bomba de, 3 words' );
		press( 'ArrowRight', true );
		expect( state() ).toContain( 'Anchor: bomba de, 2 words' );
	} );

	it( 'never leaves the anchor without words', async () => {
		await mount();
		press( 'ArrowLeft' );
		press( 'ArrowLeft' );
		press( 'ArrowLeft' );
		press( 'ArrowLeft' );
		expect( state() ).toContain( 'Anchor: bomba, 1 word' );
		press( 'ArrowRight', true );
		expect( state() ).toContain( 'Anchor: bomba, 1 word' );
	} );

	it( 'announces every change', async () => {
		await mount();
		press( 'ArrowRight' );
		expect( speak ).toHaveBeenCalledWith(
			'Anchor: bomba de calor aerotérmica, 4 words',
			'polite'
		);
	} );

	it( 'does not trap Tab', async () => {
		await mount();
		expect( press( 'Tab' ).defaultPrevented ).toBe( false );
		expect( press( 'Tab', true ).defaultPrevented ).toBe( false );
	} );

	it( 'cancels with Escape without letting the editor see it', async () => {
		await mount();
		const stop = jest.fn();
		const event = new KeyboardEvent( 'keydown', {
			key: 'Escape',
			bubbles: true,
			cancelable: true,
		} );
		event.stopPropagation = stop;
		act( () => {
			group().dispatchEvent( event );
		} );
		expect( onCancel ).toHaveBeenCalledTimes( 1 );
		expect( stop ).toHaveBeenCalled();
		expect( onApply ).not.toHaveBeenCalled();
	} );

	it( 'confirms with Enter after the server says it is valid', async () => {
		check.mockResolvedValue( { valid: true, errors: [], warnings: [] } );
		await mount();
		press( 'ArrowRight' );
		await wait( 300 );
		expect( check ).toHaveBeenCalledWith(
			5,
			sentence,
			byteLength( sentence.slice( 0, start ) ),
			'bomba de calor aerotérmica',
			expect.anything()
		);
		press( 'Enter' );
		await wait( 0 );
		expect( onApply ).toHaveBeenCalledWith( {
			start,
			end: sentence.indexOf( 'aerotérmica' ) + 'aerotérmica'.length,
		} );
	} );

	it( 'does not confirm an invalid anchor and says why in words', async () => {
		check.mockResolvedValue( {
			valid: false,
			errors: [
				{
					code: 'starts_with_filler',
					message:
						'The anchor cannot start with a filler word or a number.',
				},
			],
			warnings: [],
		} );
		await mount();
		press( 'ArrowLeft', true );
		await wait( 300 );
		expect( state() ).toContain( 'cannot start with a filler word' );
		const apply = Array.from( host.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent === 'Apply anchor'
		);
		expect( apply?.disabled ).toBe( true );
		press( 'Enter' );
		await wait( 0 );
		expect( onApply ).not.toHaveBeenCalled();
	} );

	it( 'shows warnings but still lets the user confirm', async () => {
		check.mockResolvedValue( {
			valid: true,
			errors: [],
			warnings: [
				{ code: 'generic', message: 'This anchor is too generic.' },
			],
		} );
		await mount();
		press( 'ArrowRight' );
		await wait( 300 );
		expect( state() ).toContain( 'too generic' );
		press( 'Enter' );
		await wait( 0 );
		expect( onApply ).toHaveBeenCalled();
	} );

	it( 'closes without asking the server when nothing changed', async () => {
		await mount();
		press( 'Enter' );
		await wait( 0 );
		expect( check ).not.toHaveBeenCalled();
		expect( onApply ).not.toHaveBeenCalled();
		expect( onCancel ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not confirm when the server cannot be reached', async () => {
		check.mockRejectedValue( new Error( 'offline' ) );
		await mount();
		press( 'ArrowRight' );
		await wait( 300 );
		expect( state() ).toContain( 'The anchor could not be checked' );
		press( 'Enter' );
		await wait( 0 );
		expect( onApply ).not.toHaveBeenCalled();
	} );

	it( 'extends or trims with the mouse', async () => {
		await mount();
		const word = ( text: string ) =>
			Array.from( host.querySelectorAll( 'button' ) ).find(
				( b ) => b.textContent === text
			) as HTMLButtonElement;
		act( () => word( 'aerotérmica' ).click() );
		expect( state() ).toContain( 'bomba de calor aerotérmica, 4 words' );
		act( () => word( 'bomba' ).click() );
		expect( state() ).toContain( 'Anchor: de calor aerotérmica, 3 words' );
		act( () => word( 'una' ).click() );
		// Pulsar fuera del ancla la amplía hasta esa palabra, sin dejar huecos.
		expect( state() ).toContain(
			'Anchor: una bomba de calor aerotérmica, 5 words'
		);
	} );
} );
