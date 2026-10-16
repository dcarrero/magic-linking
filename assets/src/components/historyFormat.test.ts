import {
	aiDetails,
	batchAiLabel,
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

describe( 'AI marks', () => {
	it( 'adds the AI to the source with text, not only colour', () => {
		expect( sourceLabel( 'editor', 'insert', true ) ).toBe(
			'Editor panel · AI'
		);
		expect( sourceLabel( 'plugin', 'insert', true ) ).toBe(
			"Another entry's panel · AI"
		);
		expect( sourceLabel( 'editor', 'insert', false ) ).toBe(
			'Editor panel'
		);
		expect( sourceLabel( 'plugin', 'unlink', true ) ).toBe(
			'Broken links'
		);
	} );

	it( 'details the model and the anchor of the AI', () => {
		expect(
			aiDetails( { model: 'openai/gpt-4.1-mini', anchor: true } )
		).toEqual( [
			'AI model: openai/gpt-4.1-mini',
			'Anchor suggested by the AI',
		] );
		expect( aiDetails( { model: '', anchor: false } ) ).toEqual( [] );
	} );

	it( 'counts the links of a batch that the AI recommended', () => {
		expect( batchAiLabel( 0 ) ).toBe( '' );
		expect( batchAiLabel() ).toBe( '' );
		expect( batchAiLabel( 1, 1 ) ).toBe(
			'1 of 1 link recommended by the AI'
		);
		expect( batchAiLabel( 2, 3 ) ).toBe(
			'2 of 3 links recommended by the AI'
		);
	} );
} );
