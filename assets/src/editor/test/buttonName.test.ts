import { buttonName } from '../buttonName';

describe( 'buttonName', () => {
	it( 'keeps the count in the accessible name of the toolbar button', () => {
		expect( buttonName( 'Internal links', 8 ) ).toBe(
			'Internal links, 8 outbound suggestions'
		);
		expect( buttonName( 'Internal links', 1 ) ).toBe(
			'Internal links, 1 outbound suggestion'
		);
	} );
	it( 'is just the title with no suggestions', () => {
		expect( buttonName( 'Internal links', 0 ) ).toBe( 'Internal links' );
		expect( buttonName( 'Internal links', null ) ).toBe( 'Internal links' );
	} );
} );
