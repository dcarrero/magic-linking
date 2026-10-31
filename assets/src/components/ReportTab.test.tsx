// The Jest globalSetup (tests/js/global-setup.js) sets TZ=Asia/Tokyo (not UTC), so reading the server date as local time would change the hour.
import { getSettings, setSettings } from '@wordpress/date';
import { speak } from '@wordpress/a11y';
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

	it( 'announces only the exclusion, not the entry count of the reload', async () => {
		mocked.setExcluded.mockResolvedValue( { post_id: 1, excluded: true } );
		await mount();
		( speak as jest.Mock ).mockClear();
		mocked.report.mockResolvedValue( {
			items: [],
			summary: summary( 4, 1 ),
			total: 0,
			total_pages: 1,
			page: 1,
		} );

		await clickText( 'Exclude from the analysis' );

		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect( speak ).toHaveBeenCalledWith(
			'Entry excluded from the analysis.',
			'polite'
		);
	} );

	it( 'keeps every row being saved disabled, not just the last one', async () => {
		const two = [ row( 0 ), { ...row( 0 ), id: 2, title: 'Second' } ];
		mocked.report.mockResolvedValue( {
			items: two,
			summary: summary( 5 ),
			total: 2,
			total_pages: 1,
			page: 1,
		} );
		mocked.setExcluded.mockReturnValue( new Promise( () => undefined ) );
		await mount();
		const buttons = () =>
			Array.from( host.querySelectorAll( 'button' ) ).filter( ( b ) =>
				( b.textContent ?? '' ).includes( 'Exclude from the analysis' )
			);
		await act( async () => buttons()[ 0 ]?.click() );
		await act( async () => buttons()[ 1 ]?.click() );

		expect( buttons().map( ( b ) => b.disabled ) ).toEqual( [
			true,
			true,
		] );
	} );

	it( 'in the Excluded tab only title and type can be sorted', async () => {
		mocked.report.mockResolvedValue( {
			items: [ excludedRow() ],
			summary: summary( 0, 1 ),
			total: 1,
			total_pages: 1,
			page: 1,
		} );
		await mount();
		await act( async () => {
			(
				Array.from( host.querySelectorAll( '.subsubsub a' ) ).find(
					( a ) => ( a.textContent ?? '' ).includes( 'Excluded' )
				) as HTMLElement
			 ).click();
		} );
		await flush();

		const sortable = Array.from(
			host.querySelectorAll( 'thead th a[href="#sort"]' )
		).map( ( a ) => a.textContent );
		expect( sortable.join( '|' ) ).toMatch( /^Title.*\|Type/ );
		expect( sortable ).toHaveLength( 2 );
		// La flecha dice el orden real: por título, ascendente.
		expect(
			host.querySelector( 'thead th.sorted' )?.getAttribute( 'aria-sort' )
		).toBe( 'ascending' );
		expect(
			host.querySelector( 'thead th.sorted' )?.textContent
		).toContain( 'Title' );
		expect( mocked.report.mock.calls.at( -1 )[ 0 ] ).toMatchObject( {
			orderby: 'title',
			order: 'asc',
		} );
	} );

	const openExcludedTab = async () => {
		await act( async () => {
			(
				Array.from( host.querySelectorAll( '.subsubsub a' ) ).find(
					( a ) => ( a.textContent ?? '' ).includes( 'Excluded' )
				) as HTMLElement
			 ).click();
		} );
		await flush();
	};

	it( 'the first click on Title in the Excluded tab flips the order', async () => {
		mocked.report.mockResolvedValue( {
			items: [ excludedRow() ],
			summary: summary( 0, 1 ),
			total: 1,
			total_pages: 1,
			page: 1,
		} );
		await mount();
		await openExcludedTab();
		expect( mocked.report.mock.calls.at( -1 )[ 0 ] ).toMatchObject( {
			orderby: 'title',
			order: 'asc',
		} );

		await act( async () => {
			(
				host.querySelector( 'thead th a[href="#sort"]' ) as HTMLElement
			 ).click();
		} );
		await flush();

		expect( mocked.report.mock.calls.at( -1 )[ 0 ] ).toMatchObject( {
			orderby: 'title',
			order: 'desc',
		} );
		expect(
			host.querySelector( 'thead th.sorted' )?.getAttribute( 'aria-sort' )
		).toBe( 'descending' );
	} );

	it( 'goes to the last page left when the reload has fewer pages', async () => {
		mocked.report.mockImplementation(
			async ( query: { page: number } ) => ( {
				items: [ row( 0 ) ],
				summary: summary( 5 ),
				total: 40,
				total_pages: query.page === 2 ? 2 : 2,
				page: query.page,
			} )
		);
		mocked.setExcluded.mockResolvedValue( { post_id: 1, excluded: true } );
		await mount();
		await act( async () => {
			(
				Array.from( host.querySelectorAll( 'a, button' ) ).find(
					( b ) =>
						b.getAttribute( 'aria-label' ) === 'Next page' ||
						( b.textContent ?? '' ).includes( 'Next page' )
				) as HTMLElement
			 ).click();
		} );
		await flush();
		expect( mocked.report.mock.calls.at( -1 )[ 0 ].page ).toBe( 2 );
		mocked.report.mockImplementation(
			async ( query: { page: number } ) => ( {
				items: [],
				summary: summary( 4 ),
				total: 20,
				total_pages: 1,
				page: query.page,
			} )
		);

		await clickText( 'Exclude from the analysis' );

		expect( mocked.report.mock.calls.at( -1 )[ 0 ].page ).toBe( 1 );
	} );

	it( 'in the Excluded tab there is no language filter, no empty language and the query carries none', async () => {
		const multilingual = {
			...summary( 0, 1 ),
			multilingual: true,
			langs: [ 'es', 'en' ],
		};
		mocked.report.mockResolvedValue( {
			items: [ { ...row( 0 ), lang: 'es' } ],
			summary: multilingual,
			total: 1,
			total_pages: 1,
			page: 1,
		} );
		await mount();
		const select = host.querySelector< HTMLSelectElement >(
			'#magiclinking-filter-lang'
		);
		await act( async () => {
			if ( select ) {
				select.value = 'es';
				select.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			}
		} );
		await flush();
		mocked.report.mockResolvedValue( {
			items: [ excludedRow() ],
			summary: multilingual,
			total: 1,
			total_pages: 1,
			page: 1,
		} );
		await openExcludedTab();

		expect( host.querySelector( '#magiclinking-filter-lang' ) ).toBeNull();
		expect( mocked.report.mock.calls.at( -1 )[ 0 ].lang ).toBe( '' );
		expect(
			host.querySelector( 'tbody td:nth-child(2)' )?.textContent
		).toBe( 'Post' );
		expect(
			host.querySelector( 'a.button' )?.getAttribute( 'href' )
		).not.toContain( 'lang=' );
	} );

	it( 'still announces the count after a reload that failed', async () => {
		mocked.setExcluded.mockResolvedValue( { post_id: 1, excluded: true } );
		await mount();
		mocked.report.mockRejectedValueOnce( new Error( 'x' ) );
		await clickText( 'Exclude from the analysis' );
		( speak as jest.Mock ).mockClear();
		mocked.report.mockImplementation( async () => ( {
			items: [ row( 0 ) ],
			summary: summary( 5 ),
			total: 1,
			total_pages: 1,
			page: 1,
		} ) );

		await act( async () => {
			(
				Array.from( host.querySelectorAll( '.subsubsub a' ) ).find(
					( a ) => ( a.textContent ?? '' ).includes( 'Orphans' )
				) as HTMLElement
			 ).click();
		} );
		await flush();

		expect( speak ).toHaveBeenCalledWith( '1 entry', 'polite' );
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
