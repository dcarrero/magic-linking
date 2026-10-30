import { pageAfterChange, rulesTotalPages } from './rulesPaging';

describe( 'rulesPaging', () => {
	it( 'counts pages from the total and the page size', () => {
		expect( rulesTotalPages( 0, 50 ) ).toBe( 1 );
		expect( rulesTotalPages( 50, 50 ) ).toBe( 1 );
		expect( rulesTotalPages( 51, 50 ) ).toBe( 2 );
		expect( rulesTotalPages( 120, 25 ) ).toBe( 5 );
	} );

	it( 'steps back when the current page is left empty', () => {
		// 51 reglas, en la página 2; se borra la última: queda 1 página.
		expect( pageAfterChange( 2, 50, 50 ) ).toBe( 1 );
		expect( pageAfterChange( 3, 100, 50 ) ).toBe( 2 );
		expect( pageAfterChange( 2, 51, 50 ) ).toBe( 2 );
		expect( pageAfterChange( 1, 0, 50 ) ).toBe( 1 );
	} );
} );
