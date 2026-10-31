import {
	LEGACY_ANCHORS,
	parseHash,
	SECTION_META,
	TAB_FIELDS,
} from './settingsTabs';

describe( 'parseHash', () => {
	it( 'opens the tab named in the address', () => {
		expect( parseHash( '#tab=links' ) ).toEqual( {
			tab: 'links',
			anchor: '',
		} );
		expect( parseHash( 'tab=data' )?.tab ).toBe( 'data' );
	} );

	it( 'maps the anchors of the first version to their tab', () => {
		expect( parseHash( '#magiclinking-s-exclusions' ) ).toEqual( {
			tab: 'links',
			anchor: 'magiclinking-s-exclusions',
		} );
		expect( parseHash( '#magiclinking-maintenance' )?.tab ).toBe(
			'general'
		);
	} );

	it( 'ignores any other fragment', () => {
		expect( parseHash( '' ) ).toBeNull();
		expect( parseHash( '#tab=nope' ) ).toBeNull();
		expect( parseHash( '#toString' ) ).toBeNull();
		expect( parseHash( '#magiclinking-root' ) ).toBeNull();
		expect( parseHash( '#magiclinking-settings-form' ) ).toBeNull();
		expect( parseHash( '#magiclinking-s-content-title' ) ).toBeNull();
	} );
} );

describe( 'tab data', () => {
	it( 'derives the legacy anchors from the cards', () => {
		expect( Object.keys( LEGACY_ANCHORS ).sort() ).toEqual(
			Object.values( SECTION_META )
				.map( ( section ) => section.id )
				.sort()
		);
	} );

	it( 'gives every setting exactly one tab', () => {
		const all = Object.values( TAB_FIELDS ).flat();
		expect( new Set( all ).size ).toBe( all.length );
		expect( all ).toHaveLength( 20 );
	} );
} );
