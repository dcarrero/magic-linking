/**
 * @jest-environment jsdom
 */
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { HistoryJob } from '../types';
import { useHistoryJob } from './useHistoryJob';
import { api } from '../api';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '../api', () => ( {
	api: { historyJob: jest.fn(), controlHistoryJob: jest.fn() },
	errorMessage: ( _e: unknown, fallback: string ) => fallback,
	errorStatus: () => 0,
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const mocked = api as unknown as {
	historyJob: jest.Mock;
	controlHistoryJob: jest.Mock;
};

const make = ( over: Partial< HistoryJob > = {} ): HistoryJob =>
	( {
		id: 7,
		status: 'running',
		mode: 'undo',
		total: 10,
		done: 2,
		counts: {},
		issues: [],
		stalled: false,
		can_control: true,
		...over,
	} ) as HistoryJob;

type Hook = ReturnType< typeof useHistoryJob >;
let root: Root;
let current: Hook;
let refresh: jest.Mock;
let setFailure: jest.Mock;
let extra: Record< string, unknown >;

function Probe( { initial }: { initial: HistoryJob | null } ) {
	// Callbacks nuevos en cada render, como los de un padre real.
	current = useHistoryJob( {
		initial,
		refresh: () => refresh(),
		setFailure: ( m ) => setFailure( m ),
		...extra,
	} );
	return null;
}

const mount = ( initial: HistoryJob | null ) =>
	act( () => {
		root.render( <Probe initial={ initial } /> );
	} );
const tick = async ( ms: number ) => {
	await act( async () => {
		jest.advanceTimersByTime( ms );
	} );
};
const flush = () => act( async () => {} );

beforeEach( () => {
	jest.useFakeTimers();
	refresh = jest.fn().mockResolvedValue( undefined );
	setFailure = jest.fn();
	extra = {};
	mocked.historyJob.mockReset();
	mocked.controlHistoryJob.mockReset();
	root = createRoot( document.createElement( 'div' ) );
} );
afterEach( () => {
	act( () => root.unmount() );
	jest.useRealTimers();
} );

describe( 'useHistoryJob', () => {
	it( 'ignores a poll response that arrives after cancelling', async () => {
		let late: ( v: unknown ) => void = () => {};
		mocked.historyJob.mockReturnValue(
			new Promise( ( resolve ) => ( late = resolve ) )
		);
		mocked.controlHistoryJob.mockResolvedValue( {
			job: make( { status: 'cancelled' } ),
			group: null,
			entry: null,
		} );
		await mount( make() );
		await tick( 3000 );
		await act( async () => {
			await current.control( 'cancel' );
		} );
		expect( current.job?.status ).toBe( 'cancelled' );
		await act( async () => late( { job: make( { done: 5 } ) } ) );
		expect( current.job?.status ).toBe( 'cancelled' );
	} );

	it( 'never has two polls in flight and processes the end once', async () => {
		const resolvers: Array< ( v: unknown ) => void > = [];
		mocked.historyJob.mockImplementation(
			() => new Promise( ( resolve ) => resolvers.push( resolve ) )
		);
		await mount( make() );
		await tick( 3000 );
		await tick( 9000 );
		expect( mocked.historyJob ).toHaveBeenCalledTimes( 1 );
		await act( async () =>
			resolvers[ 0 ]( { job: make( { status: 'done', done: 10 } ) } )
		);
		await tick( 9000 );
		expect( mocked.historyJob ).toHaveBeenCalledTimes( 1 );
		expect( refresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps its timer when the parent re-renders with new callbacks', async () => {
		mocked.historyJob.mockResolvedValue( { job: make() } );
		await mount( make() );
		await tick( 2000 );
		await mount( make() );
		await mount( make() );
		await tick( 1000 );
		expect( mocked.historyJob ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows the partial result when cancelling', async () => {
		mocked.controlHistoryJob.mockResolvedValue( {
			job: make( { status: 'cancelled', counts: { restored: 3 } } ),
			group: null,
			entry: null,
		} );
		await mount( make() );
		await act( async () => {
			await current.control( 'cancel' );
		} );
		expect( current.outcome?.counts ).toEqual( { restored: 3 } );
		expect( refresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'follows a new job when the initial one changes id', async () => {
		await mount( make( { id: 7 } ) );
		await mount( make( { id: 8 } ) );
		expect( current.job?.id ).toBe( 8 );
	} );

	it( 'does not set state after unmounting', async () => {
		const error = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		let late: ( v: unknown ) => void = () => {};
		mocked.historyJob.mockReturnValue(
			new Promise( ( resolve ) => ( late = resolve ) )
		);
		await mount( make() );
		await tick( 3000 );
		act( () => root.unmount() );
		await act( async () => late( { job: make( { status: 'done' } ) } ) );
		await flush();
		expect( refresh ).not.toHaveBeenCalled();
		expect( error ).not.toHaveBeenCalled();
		error.mockRestore();
		root = createRoot( document.createElement( 'div' ) );
	} );

	it( 'start and showResult replace the job and the result', async () => {
		await mount( null );
		await act( async () => {
			current.showResult( { redo: false, counts: {}, lines: [] } );
		} );
		await act( async () => {
			current.start( make( { id: 9 } ) );
		} );
		expect( current.job?.id ).toBe( 9 );
		expect( current.outcome ).toBeNull();
	} );
} );
