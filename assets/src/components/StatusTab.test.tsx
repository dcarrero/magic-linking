import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { api } from '../api';
import type {
	EngineTimingResponse,
	EngineTimingRow,
	StatusResponse,
} from '../types';
import { budgetText, msText, StatusTab } from './StatusTab';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '@wordpress/components', () => ( {
	Spinner: () => null,
	Notice: ( { children }: { children?: unknown } ) => (
		<div role="alert">{ children as string }</div>
	),
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
jest.mock( '../api', () => ( {
	api: { engineTiming: jest.fn() },
	errorMessage: ( _e: unknown, fallback: string ) => fallback,
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const mocked = api as unknown as { engineTiming: jest.Mock };

const row = ( over: Partial< EngineTimingRow > = {} ): EngineTimingRow => ( {
	count: 20,
	cached: 5,
	median: 120.5,
	p95: 310,
	retrieval: 40,
	load: 8,
	phrases: 60,
	scoring: 15,
	candidates: 87.5,
	budget: 500,
	...over,
} );

const empty = (): EngineTimingRow =>
	row( {
		count: 0,
		cached: 0,
		median: 0,
		p95: 0,
		retrieval: 0,
		load: 0,
		phrases: 0,
		scoring: 0,
		candidates: 0,
	} );

const status = {
	indexed: 10,
	eligible: 12,
	pending: 2,
	job: null,
	stalled: false,
	eta: null,
	last_job: null,
	last_done: null,
} as StatusResponse;

let container: HTMLDivElement;
let root: Root;

async function mount( response: EngineTimingResponse ) {
	mocked.engineTiming.mockResolvedValue( response );
	await act( async () => {
		root.render( <StatusTab status={ status } /> );
	} );
}

beforeEach( () => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
} );

afterEach( () => {
	act( () => root.unmount() );
	container.remove();
	jest.resetAllMocks();
} );

describe( 'budgetText', () => {
	it( 'says within or over budget in words, by the 95th percentile', () => {
		expect( budgetText( row( { p95: 499.9 } ) ) ).toContain(
			'Within budget'
		);
		expect( budgetText( row( { p95: 500 } ) ) ).toContain( 'Over budget' );
	} );
} );

describe( 'msText', () => {
	it( 'always shows one decimal', () => {
		expect( msText( 12 ) ).toMatch( /^12[.,]0 ms$/ );
	} );
} );

describe( 'StatusTab', () => {
	it( 'shows the index counts and a table with both directions', async () => {
		await mount( {
			keep: 20,
			outgoing: row(),
			incoming: row( { p95: 900, budget: 800, count: 3 } ),
		} );
		expect( container.textContent ).toContain(
			'10 of 12 entries are in the index.'
		);
		// Nada de «Entries analyzed» ni «New or modified»: ya salen en el resto de la pantalla.
		expect( container.textContent ).not.toContain( 'Entries analyzed' );
		expect( container.textContent ).not.toContain( 'New or modified' );
		const table = container.querySelector( 'table' );
		expect( table ).not.toBeNull();
		expect( table?.querySelector( 'caption' )?.textContent ).toContain(
			'Time of the suggestions engine'
		);
		const rows = Array.from( table?.querySelectorAll( 'tbody tr' ) ?? [] );
		expect( rows ).toHaveLength( 2 );
		expect( rows[ 0 ]?.textContent ).toMatch( /120[.,]5 ms/ );
		expect( rows[ 0 ]?.textContent ).toContain( 'Within budget' );
		expect( rows[ 1 ]?.textContent ).toContain( 'Over budget' );
		expect( rows[ 0 ]?.querySelector( 'th[scope="row"]' ) ).not.toBeNull();
		// Las respuestas de caché se cuentan en su columna.
		expect( rows[ 0 ]?.textContent ).toMatch( /5/ );
	} );

	it( 'explains the empty state instead of an empty table', async () => {
		await mount( { keep: 20, outgoing: empty(), incoming: empty() } );
		expect( container.querySelector( 'table' ) ).toBeNull();
		expect( container.textContent ).toContain(
			'No suggestions have been calculated yet.'
		);
	} );

	it( 'says when one direction has no requests', async () => {
		await mount( { keep: 20, outgoing: row(), incoming: empty() } );
		expect( container.textContent ).toContain( 'No requests yet.' );
	} );

	it( 'shows an error when the times cannot be read', async () => {
		mocked.engineTiming.mockRejectedValue( new Error( 'x' ) );
		await act( async () => {
			root.render( <StatusTab status={ status } /> );
		} );
		expect( container.textContent ).toContain(
			'Could not read the engine times.'
		);
	} );
} );
