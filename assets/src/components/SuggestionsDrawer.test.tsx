import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { speak } from '@wordpress/a11y';
import { api } from '../api';
import type { Suggestion } from '../types';
import { SuggestionsDrawer } from './SuggestionsDrawer';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '@wordpress/components', () => ( {
	Modal: ( { children, title }: { children?: unknown; title: string } ) => (
		<div role="dialog" aria-label={ title }>
			{ children as string }
		</div>
	),
	Spinner: () => null,
	Button: ( {
		children,
		onClick,
		disabled,
		...rest
	}: {
		children?: unknown;
		onClick?: () => void;
		disabled?: boolean;
		'aria-label'?: string;
	} ) => (
		<button
			type="button"
			onClick={ onClick }
			disabled={ disabled }
			aria-label={ rest[ 'aria-label' ] }
		>
			{ children as string }
		</button>
	),
} ) );
jest.mock( '../api', () => ( {
	api: {
		inbound: jest.fn(),
		outbound: jest.fn(),
		insertLinks: jest.fn(),
		runHistory: jest.fn(),
		dismissSuggestion: jest.fn(),
		restoreSuggestion: jest.fn(),
	},
	errorMessage: ( _e: unknown, fallback: string ) => fallback,
	errorStatus: () => 0,
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const mocked = api as unknown as {
	inbound: jest.Mock;
	outbound: jest.Mock;
	insertLinks: jest.Mock;
	runHistory: jest.Mock;
	dismissSuggestion: jest.Mock;
	restoreSuggestion: jest.Mock;
};
const spoken = speak as jest.Mock;

const make = ( n: number ): Suggestion =>
	( {
		source: { id: 100 + n, title: `Source ${ n }` },
		target: { id: 1, title: `Target ${ n }` },
		can_insert: true,
		reasons: [],
		alternatives: [],
		anchor: `anchor ${ n }`,
		sentence: `A sentence with anchor ${ n } inside.`,
		offset: 16,
		before: 'A sentence with ',
		after: ' inside.',
		anchor_start: 16,
		anchor_end: 26,
		paragraph: 0,
		score: 0.5,
		insert: {
			post_id: 100 + n,
			target_id: 1,
			sentence: `A sentence with anchor ${ n } inside.`,
			offset: 16,
			anchor: `anchor ${ n }`,
			block_path: null,
		},
	} ) as Suggestion;

const list = ( from: number, count: number ) =>
	Array.from( { length: count }, ( _, i ) => make( from + i ) );

let root: Root;
let host: HTMLElement;
let onChanged: jest.Mock;

const flush = () =>
	act( async () => {
		await Promise.resolve();
		await Promise.resolve();
	} );

const mount = async ( direction: 'inbound' | 'outbound' ) => {
	await act( async () => {
		root.render(
			<SuggestionsDrawer
				entry={ { id: 1, title: 'Entry' } }
				direction={ direction }
				historyUrl="history.php"
				reportUrl="report.php"
				onClose={ () => undefined }
				onChanged={ onChanged }
			/>
		);
	} );
	await flush();
};

const button = ( label: RegExp | string ) =>
	Array.from( host.querySelectorAll( 'button' ) ).find( ( b ) =>
		typeof label === 'string'
			? b.textContent === label
			: label.test(
					b.getAttribute( 'aria-label' ) ?? b.textContent ?? ''
			  )
	);
const click = async ( label: RegExp | string ) => {
	const target = button( label );
	if ( ! target ) {
		throw new Error( `No button ${ String( label ) }` );
	}
	await act( async () => {
		target.click();
	} );
	await flush();
};

const inserted = ( batch = 'B1' ) => ( {
	batch_id: batch,
	inserted: 1,
	failed: 0,
	results: [ { index: 0, status: 'inserted' } ],
} );

beforeEach( () => {
	jest.clearAllMocks();
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	onChanged = jest.fn();
	mocked.dismissSuggestion.mockResolvedValue( {} );
	mocked.restoreSuggestion.mockResolvedValue( {} );
	mocked.insertLinks.mockResolvedValue( inserted() );
} );
afterEach( () => {
	act( () => root.unmount() );
	host.remove();
} );

const outboundPage = () =>
	mocked.outbound.mockResolvedValue( {
		ready: true,
		state: 'ok',
		total: 12,
		items: list( 1, 12 ),
	} );

describe( 'SuggestionsDrawer', () => {
	it( 'outbound: a linked card stays linked when going to another page and back (no duplicate POST)', async () => {
		outboundPage();
		await mount( 'outbound' );
		await click( /^Link to “Target 1”/ );
		expect( mocked.insertLinks ).toHaveBeenCalledTimes( 1 );

		await click( 'Next' );
		await click( 'Previous' );

		expect( button( /^Link to “Target 1”/ ) ).toBeUndefined();
		expect( host.textContent ).toContain( 'Linked' );
		expect( mocked.insertLinks ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'outbound: a dismissed card stays dismissed when going to another page and back', async () => {
		outboundPage();
		await mount( 'outbound' );
		await click( /^Dismiss the suggestion for “Target 1”/ );
		await click( 'Next' );
		await click( 'Previous' );

		expect( button( /^Link to “Target 1”/ ) ).toBeUndefined();
		expect( host.textContent ).toContain( 'Dismissed' );
	} );

	it( 'inbound: dismissing reloads the page and keeps the dismissed row in its place', async () => {
		const items = list( 1, 3 );
		mocked.inbound.mockResolvedValueOnce( {
			ready: true,
			state: 'ok',
			total: 3,
			total_pages: 1,
			page: 1,
			per_page: 10,
			items,
		} );
		// El servidor ya no devuelve la descartada.
		mocked.inbound.mockResolvedValue( {
			ready: true,
			state: 'ok',
			total: 2,
			total_pages: 1,
			page: 1,
			per_page: 10,
			items: items.slice( 1 ),
		} );
		await mount( 'inbound' );
		await click( /^Dismiss the suggestion for “Target 1”/ );

		expect( mocked.inbound ).toHaveBeenCalledTimes( 2 );
		const cards = host.querySelectorAll( 'li.magiclinking-card' );
		expect( cards ).toHaveLength( 3 );
		expect( cards[ 0 ]?.className ).toContain( 'is-dismissed' );
	} );

	it( 'while the list is being reloaded the old cards cannot be linked', async () => {
		outboundPage();
		await mount( 'outbound' );
		let resolve: ( value: unknown ) => void = () => undefined;
		mocked.outbound.mockReturnValue(
			new Promise( ( r ) => {
				resolve = r;
			} )
		);
		await click( 'Refresh' );

		const link = button( /^Link to “Target 1”/ );
		expect( ! link || link.disabled ).toBe( true );
		await act( async () =>
			resolve( { ready: true, state: 'ok', total: 0, items: [] } )
		);
	} );

	it( 'a page change is announced once (inbound reloads the page from the server)', async () => {
		mocked.inbound.mockImplementation( ( _id: number, page: number ) =>
			Promise.resolve( {
				ready: true,
				state: 'ok',
				total: 12,
				total_pages: 2,
				page,
				per_page: 10,
				items: list( page * 20, 10 ),
			} )
		);
		await mount( 'inbound' );
		spoken.mockClear();
		await click( 'Next' );

		expect( spoken ).toHaveBeenCalledTimes( 1 );
		expect( spoken.mock.calls[ 0 ]?.[ 0 ] ).toMatch( /Page 2 of 2/ );
	} );

	it( 'outbound: a linked card offers the link to the History', async () => {
		outboundPage();
		await mount( 'outbound' );
		await click( /^Link to “Target 1”/ );

		const link = host.querySelector( 'a.magiclinking-fold__history' );
		expect( link?.getAttribute( 'href' ) ).toBe( 'history.php' );
	} );

	it( 'inbound: linking reloads the page, keeps the linked row and undoing brings the card back', async () => {
		const items = list( 1, 3 );
		const page = ( shown: Suggestion[] ) => ( {
			ready: true,
			state: 'ok',
			total: shown.length,
			total_pages: 1,
			page: 1,
			per_page: 10,
			items: shown,
		} );
		mocked.inbound.mockResolvedValueOnce( page( items ) );
		mocked.inbound.mockResolvedValueOnce( page( items.slice( 1 ) ) );
		mocked.inbound.mockResolvedValue( page( items ) );
		mocked.runHistory.mockResolvedValue( {
			results: [ { status: 'link_removed' } ],
		} );
		await mount( 'inbound' );
		await click( /^Link to “Target 1”/ );

		expect( mocked.inbound ).toHaveBeenCalledTimes( 2 );
		const cards = host.querySelectorAll( 'li.magiclinking-card' );
		expect( cards ).toHaveLength( 3 );
		expect( cards[ 0 ]?.className ).toContain( 'is-added' );

		await click( /^Undo the link in/ );
		expect( mocked.inbound ).toHaveBeenCalledTimes( 3 );
		expect( button( /^Link to “Target 1”/ ) ).toBeDefined();
	} );

	it( 'a failed undo says so and points to the History', async () => {
		outboundPage();
		mocked.runHistory.mockResolvedValue( {
			results: [ { status: 'manual' } ],
		} );
		await mount( 'outbound' );
		await click( /^Link to “Target 1”/ );
		await click( /^Undo the link to/ );

		expect( host.textContent ).toContain( 'Open the history to see why' );
	} );
} );
