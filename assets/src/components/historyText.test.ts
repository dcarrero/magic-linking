import { summaryText } from './historyText';

describe( 'summaryText', () => {
	it( 'counts a fix put back after later edits as undone', () => {
		expect( summaryText( { fix_reverted: 2 }, false ) ).toBe(
			'2 links undone.'
		);
	} );
} );
