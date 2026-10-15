jest.mock( '../api', () => ( { api: {} } ) );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

import { waitingText } from './useAiRerank';

describe( 'waitingText', () => {
	it( 'says how long the wait can last', () => {
		expect( waitingText( 50 ) ).toBe(
			'Asking the AI… it can take up to 50 seconds.'
		);
	} );

	it( 'does not invent a figure when the server did not say', () => {
		expect( waitingText() ).toBe( 'Asking the AI…' );
	} );
} );
