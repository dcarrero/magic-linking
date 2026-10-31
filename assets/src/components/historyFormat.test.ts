import { getSettings, setSettings } from '@wordpress/date';
import {
	aiDetails,
	batchAiLabel,
	batchOriginLabel,
	dateTimeAttr,
	formatDate,
	formatDuration,
	formatFullDate,
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
		expect( originKey( 'rule', 'insert' ) ).toBe( 'rule' );
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
		expect( sourceLabel( 'rule', 'insert' ) ).toBe( 'Automatic rule' );
		expect( sourceLabel( 'rule', 'insert', true ) ).toBe(
			'Automatic rule · AI'
		);
		expect( batchOriginLabel( 'rule', 'insert' ) ).toBe(
			'Added by an automatic rule'
		);
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

/**
 * Ajustes que el núcleo inyecta en `wp.date` (Ajustes → Generales): zona, formato de fecha y de hora.
 * @param timezone Zona del sitio.
 * @param date     `date_format`.
 * @param time     `time_format`.
 */
function siteSettings( timezone: string, date: string, time: string ): void {
	setSettings( {
		...getSettings(),
		formats: { ...getSettings().formats, date, time },
		timezone: {
			offset: 0,
			offsetFormatted: '0',
			string: timezone,
			abbr: '',
		},
	} );
}

describe( 'formatDate', () => {
	it( 'uses the timezone and the formats of the site, not the browser (Asia/Tokyo)', () => {
		siteSettings( 'Europe/Madrid', 'j/n/Y', 'H:i' );
		// 22:30 UTC del 30 es 23:30 en Madrid (CET), no las 07:30 del 31 de Tokio.
		expect( formatDate( '2026-10-30T22:30:00' ) ).toBe(
			'30/10/2026 23:30'
		);
		// A las 23:30 UTC ya es el día siguiente en Madrid, y en Nueva York todavía el mismo.
		expect( formatDate( '2026-10-30T23:30:00' ) ).toBe(
			'31/10/2026 00:30'
		);
		siteSettings( 'America/New_York', 'F j, Y', 'g:i a' );
		expect( formatDate( '2026-10-30T23:30:00' ) ).toBe(
			'October 30, 2026 7:30 pm'
		);
	} );

	it( 'reads the MySQL format as GMT before moving it to the site zone', () => {
		siteSettings( 'Europe/Madrid', 'Y-m-d', 'H:i' );
		expect( formatDate( '2026-07-01 10:00:00' ) ).toBe(
			'2026-07-01 12:00'
		);
	} );

	it( 'adds seconds and the zone to the full date', () => {
		siteSettings( 'Europe/Madrid', 'Y-m-d', 'H:i' );
		expect( formatFullDate( '2026-10-30T22:30:45' ) ).toBe(
			'2026-10-30 23:30:45 CET'
		);
	} );

	it( 'shows a dash for an empty, invalid or zero date', () => {
		expect( formatDate( '' ) ).toBe( '—' );
		expect( formatDate( '0000-00-00 00:00:00' ) ).toBe( '—' );
		expect( formatDate( '-0001-11-30T00:00:00' ) ).toBe( '—' );
		expect( formatFullDate( '0000-00-00 00:00:00' ) ).toBe( '—' );
	} );
} );

describe( 'dateTimeAttr', () => {
	it( 'gives an ISO instant, or nothing for a zero date', () => {
		expect( dateTimeAttr( '2026-10-14 12:30:00' ) ).toBe(
			'2026-10-14T12:30:00.000Z'
		);
		expect( dateTimeAttr( '0000-00-00 00:00:00' ) ).toBeUndefined();
	} );
} );

describe( 'formatDuration', () => {
	it( 'says seconds and minutes', () => {
		expect(
			formatDuration( '2026-10-14 12:00:00', '2026-10-14 12:00:42' )
		).toBe( '42 s' );
		expect(
			formatDuration( '2026-10-14 12:00:00', '2026-10-14 12:02:05' )
		).toBe( '2 min 5 s' );
	} );

	it( 'shows a dash when a date is zero or invalid, never NaN', () => {
		expect(
			formatDuration( '0000-00-00 00:00:00', '2026-10-14 12:00:42' )
		).toBe( '—' );
		expect(
			formatDuration( '2026-10-14 12:00:00', '0000-00-00 00:00:00' )
		).toBe( '—' );
		expect( formatDuration( '', '' ) ).toBe( '—' );
	} );
} );
