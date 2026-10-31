// The Jest globalSetup (tests/js/global-setup.js) sets TZ=Asia/Tokyo (not UTC), so reading the server date as local time would change the hour.
import { getSettings, setSettings } from '@wordpress/date';
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
	api: {
		report: jest.fn(),
		reportRows: jest.fn(),
		setExcluded: jest.fn(),
	},
	errorMessage: ( _e: unknown, fallback: string ) => fallback,
	errorStatus: () => 0,
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const mocked = api as unknown as {
	report: jest.Mock;
	reportRows: jest.Mock;
	setExcluded: jest.Mock;
};

const summary = ( orphans: number, excluded = 0 ): Summary =>
	( {
		analyzed: 5,
		orphans,
		low: 0,
		over: 0,
		broken_posts: 0,
		internal_links: 3,
		broken_links: 0,
		excluded,
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

describe( 'ReportTab exclude from the analysis', () => {
	const excludedRow = (): ReportItem =>
		( {
			...row( 0 ),
			status: 'excluded',
			lang: '',
			indexed_at: '',
		} ) as ReportItem;

	it( 'offers "Exclude from the analysis" on each row and posts it, then reloads', async () => {
		mocked.setExcluded.mockResolvedValue( { post_id: 1, excluded: true } );
		await mount();
		expect( host.textContent ).toContain( 'Exclude from the analysis' );
		mocked.report.mockResolvedValue( {
			items: [],
			summary: summary( 4, 1 ),
			total: 0,
			total_pages: 1,
			page: 1,
		} );

		await clickText( 'Exclude from the analysis' );

		expect( mocked.setExcluded ).toHaveBeenCalledWith( 1, true );
		expect( mocked.report ).toHaveBeenCalledTimes( 2 );
		expect( host.querySelector( 'tbody tr td' )?.textContent ).toContain(
			'No entries match these filters.'
		);
		expect( host.textContent ).toContain( 'Excluded (1)' );
	} );

	it( 'shows the Excluded tab only when there are excluded entries (or it is the open one)', async () => {
		await mount();
		expect( host.textContent ).not.toContain( 'Excluded (' );
	} );

	it( 'excluded rows offer "Include in the analysis" and no figures or suggestions', async () => {
		mocked.report.mockResolvedValue( {
			items: [ excludedRow() ],
			summary: summary( 0, 1 ),
			total: 1,
			total_pages: 1,
			page: 1,
		} );
		mocked.setExcluded.mockResolvedValue( { post_id: 1, excluded: false } );
		await mount();
		await act( async () => {
			(
				Array.from( host.querySelectorAll( '.subsubsub a' ) ).find(
					( a ) => ( a.textContent ?? '' ).includes( 'Excluded' )
				) as HTMLElement
			 ).click();
		} );
		await flush();

		expect( host.textContent ).toContain( 'Include in the analysis' );
		expect( host.textContent ).not.toContain( 'Exclude from the analysis' );
		expect( host.textContent ).not.toContain( 'Inbound suggestions' );
		expect( host.querySelector( '.magiclinking-badge' )?.textContent ).toBe(
			'Excluded'
		);

		await clickText( 'Include in the analysis' );
		expect( mocked.setExcluded ).toHaveBeenCalledWith( 1, false );
	} );

	it( 'tells the user when it fails and keeps the row', async () => {
		mocked.setExcluded.mockRejectedValue( new Error( 'x' ) );
		await mount();

		await clickText( 'Exclude from the analysis' );

		expect( host.querySelector( '[role="alert"]' )?.textContent ).toBe(
			'Could not exclude the entry from the analysis.'
		);
		expect( host.querySelectorAll( 'tbody tr' ) ).toHaveLength( 1 );
	} );
} );

describe( 'ReportTab dates', () => {
	let saved: ReturnType< typeof getSettings >;
	beforeEach( () => {
		saved = getSettings();
	} );
	afterEach( () => {
		setSettings( saved );
	} );

	it( 'shows "Analyzed" in the timezone and format of the site, not the browser (Asia/Tokyo)', async () => {
		setSettings( {
			...getSettings(),
			formats: { ...getSettings().formats, date: 'j/n/Y', time: 'H:i' },
			timezone: {
				offset: 0,
				offsetFormatted: '0',
				string: 'Europe/Madrid',
				abbr: '',
			},
		} );
		await mount();
		expect(
			host.querySelector( 'tbody time' )?.getAttribute( 'datetime' )
		).toMatch( /Z$/ );
		// 10:00 UTC del 30 de octubre = 11:00 en Madrid (CET, UTC+1); en Tokio serían las 19:00.
		expect( host.querySelector( 'tbody time' )?.textContent ).toBe(
			'30/10/2026 11:00'
		);
	} );
} );
