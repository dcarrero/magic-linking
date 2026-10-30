// The `test:js` script sets TZ=Asia/Tokyo (not UTC), so reading the server date as local time would change the hour.
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { api } from '../api';
import type { ReportItem, Summary } from '../types';
import { ReportTab } from './ReportTab';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( './SuggestionsDrawer', () => ( {
	SuggestionsDrawer: ( {
		onChanged,
	}: {
		onChanged: ( ids: number[] ) => void;
	} ) => (
		<button type="button" onClick={ () => onChanged( [ 1 ] ) }>
			changed
		</button>
	),
} ) );
jest.mock( '../api', () => ( {
	api: { report: jest.fn(), reportRows: jest.fn() },
	errorMessage: ( _e: unknown, fallback: string ) => fallback,
	errorStatus: () => 0,
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const mocked = api as unknown as {
	report: jest.Mock;
	reportRows: jest.Mock;
};

const summary = ( orphans: number ): Summary =>
	( {
		analyzed: 5,
		orphans,
		low: 0,
		over: 0,
		broken_posts: 0,
		internal_links: 3,
		broken_links: 0,
		types: [],
		langs: [],
		multilingual: false,
	} ) as Summary;

const row = ( inbound: number ): ReportItem =>
	( {
		id: 1,
		title: 'Entry',
		type: 'post',
		type_label: 'Post',
		lang: 'es',
		inbound,
		outbound: 0,
		external: 0,
		broken: 0,
		words: 100,
		status: inbound === 0 ? 'orphan' : 'ok',
		indexed_at: '2026-10-30T10:00:00',
		url: 'http://x/1',
		edit_url: 'http://x/edit/1',
	} ) as ReportItem;

let root: Root;
let host: HTMLElement;
let onSummary: jest.Mock;

const flush = () =>
	act( async () => {
		await Promise.resolve();
		await Promise.resolve();
	} );

const mount = async () => {
	await act( async () => {
		root.render(
			<ReportTab
				refreshKey={ 0 }
				exportUrl=""
				onBroken={ () => undefined }
				onSummary={ onSummary }
			/>
		);
	} );
	await flush();
};

const clickText = async ( text: string ) => {
	const target = Array.from( host.querySelectorAll( 'button' ) ).find(
		( b ) => ( b.textContent ?? '' ).includes( text )
	);
	if ( ! target ) {
		throw new Error( `No button ${ text }` );
	}
	await act( async () => {
		target.click();
	} );
	await flush();
};

const inboundCell = () =>
	host.querySelectorAll( 'tbody tr td' )[ 2 ]?.textContent;

beforeEach( () => {
	jest.clearAllMocks();
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	onSummary = jest.fn();
	mocked.report.mockResolvedValue( {
		items: [ row( 0 ) ],
		summary: summary( 5 ),
		total: 1,
		total_pages: 1,
		page: 1,
	} );
} );
afterEach( () => {
	act( () => root.unmount() );
	host.remove();
} );

describe( 'ReportTab refreshing rows after a change', () => {
	it( 'the filter tabs and the header read the same fresh summary', async () => {
		mocked.reportRows.mockResolvedValue( {
			items: [ row( 1 ) ],
			summary: summary( 4 ),
		} );
		await mount();
		expect( host.textContent ).toContain( 'Orphans (5)' );

		await clickText( 'Inbound suggestions' );
		await clickText( 'changed' );

		expect( inboundCell() ).toBe( '1' );
		expect( host.textContent ).toContain( 'Orphans (4)' );
		expect( onSummary ).toHaveBeenLastCalledWith( summary( 4 ) );
	} );

	it( 'a slow old response never overwrites a newer one', async () => {
		let slow: ( value: unknown ) => void = () => undefined;
		mocked.reportRows
			.mockReturnValueOnce(
				new Promise( ( resolve ) => {
					slow = resolve;
				} )
			)
			.mockResolvedValueOnce( {
				items: [ row( 2 ) ],
				summary: summary( 3 ),
			} );
		await mount();
		await clickText( 'Inbound suggestions' );
		await clickText( 'changed' );
		await clickText( 'changed' );
		expect( inboundCell() ).toBe( '2' );

		// La respuesta vieja llega la última.
		await act( async () =>
			slow( { items: [ row( 1 ) ], summary: summary( 4 ) } )
		);
		await flush();

		expect( inboundCell() ).toBe( '2' );
		expect( host.textContent ).toContain( 'Orphans (3)' );
	} );
} );

describe( 'ReportTab dates', () => {
	afterEach( () => {
		document.documentElement.lang = '';
	} );

	it.each( [
		[ 'es-ES', /^30 oct 2026,\s19:00$/ ],
		[ 'en-US', /^Oct 30, 2026,\s7:00\sPM$/ ],
	] )(
		'shows "Analyzed" in %s with the UTC instant converted to the browser zone',
		async ( lang, expected ) => {
			document.documentElement.lang = lang;
			await mount();
			expect( host.querySelector( 'tbody time' )?.textContent ).toMatch(
				expected
			);
		}
	);
} );
