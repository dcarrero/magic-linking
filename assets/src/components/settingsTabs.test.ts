import { tabFromHash } from './settingsTabs';

describe( 'tabFromHash', () => {
	it( 'opens the tab named in the address', () => {
		expect( tabFromHash( '#tab=links' ) ).toEqual( {
			tab: 'links',
			anchor: '',
		} );
		expect( tabFromHash( 'tab=data' ).tab ).toBe( 'data' );
	} );

	it( 'maps the anchors of the first version to their tab', () => {
		expect( tabFromHash( '#magiclinking-s-exclusions' ) ).toEqual( {
			tab: 'links',
			anchor: 'magiclinking-s-exclusions',
		} );
		expect( tabFromHash( '#magiclinking-maintenance' ).tab ).toBe(
			'general'
		);
	} );

	it( 'falls back to General for anything else', () => {
		expect( tabFromHash( '' ).tab ).toBe( 'general' );
		expect( tabFromHash( '#tab=nope' ).tab ).toBe( 'general' );
		expect( tabFromHash( '#toString' ).tab ).toBe( 'general' );
	} );
} );
