import {
	batchOriginLabel,
	originKey,
	parseServerDate,
	sourceLabel,
} from './historyFormat';

describe( 'parseServerDate', () => {
	it( 'reads RFC 3339 with and without a zone', () => {
		expect( parseServerDate( '2026-10-14T12:30:00Z' ).toISOString() ).toBe(
			'2026-10-14T12:30:00.000Z'
		);
		expect( parseServerDate( '2026-10-14T12:30:00' ).toISOString() ).toBe(
			'2026-10-14T12:30:00.000Z'
		);
		expect(
			parseServerDate( '2026-10-14T14:30:00+02:00' ).toISOString()
		).toBe( '2026-10-14T12:30:00.000Z' );
	} );

	it( 'reads the MySQL format as UTC', () => {
		expect( parseServerDate( '2026-10-14 12:30:00' ).toISOString() ).toBe(
			'2026-10-14T12:30:00.000Z'
		);
	} );
} );

describe( 'originKey', () => {
	it( 'classifies a change by where it was written from', () => {
		expect( originKey( 'editor', 'insert' ) ).toBe( 'panel' );
		expect( originKey( 'plugin', 'insert' ) ).toBe( 'other_panel' );
		expect( originKey( 'plugin' ) ).toBe( 'other_panel' );
		expect( originKey( 'plugin', 'unlink' ) ).toBe( 'broken' );
		expect( originKey( 'plugin', 'retarget' ) ).toBe( 'broken' );
	} );
} );

describe( 'sourceLabel', () => {
	it( 'says where the link was put from, not which entry received it', () => {
		expect( sourceLabel( 'editor', 'insert' ) ).toBe( 'Editor panel' );
		expect( sourceLabel( 'plugin', 'insert' ) ).toBe(
			"Another entry's panel"
		);
		expect( sourceLabel( 'plugin', 'unlink' ) ).toBe( 'Broken links' );
		expect( sourceLabel( 'plugin', 'retarget' ) ).toBe( 'Broken links' );
	} );
} );

describe( 'batchOriginLabel', () => {
	it( 'uses the same classification for the batch card', () => {
		expect( batchOriginLabel( 'editor', 'insert' ) ).toBe(
			'Added from the editor panel'
		);
		expect( batchOriginLabel( 'plugin', undefined ) ).toBe(
			"Added from another entry's panel"
		);
		expect( batchOriginLabel( 'plugin', 'unlink' ) ).toBe(
			'Fixed from Broken links'
		);
	} );
} );
