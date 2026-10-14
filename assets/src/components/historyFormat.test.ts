import { parseServerDate, sourceLabel } from './historyFormat';

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

describe( 'sourceLabel', () => {
	it( 'names the panel direction instead of the product', () => {
		expect( sourceLabel( 'editor', 'insert' ) ).toBe( 'Panel (outbound)' );
		expect( sourceLabel( 'plugin', 'insert' ) ).toBe( 'Panel (inbound)' );
		expect( sourceLabel( 'plugin', 'unlink' ) ).toBe( 'Broken links' );
		expect( sourceLabel( 'plugin', 'retarget' ) ).toBe( 'Broken links' );
	} );
} );
