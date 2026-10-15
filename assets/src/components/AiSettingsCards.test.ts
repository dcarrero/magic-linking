jest.mock( '@wordpress/components', () => ( {} ) );
jest.mock( '../api', () => ( { api: {} } ) );

import type { AiStatus } from '../types';
import { modelOptions } from './AiSettingsCards';

const status = {
	models: [
		{
			provider: 'openai',
			provider_name: 'OpenAI',
			model: 'gpt-4o-mini',
			name: 'GPT',
		},
		{
			provider: 'openai',
			provider_name: 'OpenAI',
			model: 'gpt-4o-mini',
			name: 'GPT',
		},
	],
} as AiStatus;

describe( 'modelOptions', () => {
	it( 'names the model first and the provider by its visible name, once each', () => {
		const options = modelOptions( status, '' );

		expect( options.map( ( o ) => o.value ) ).toEqual( [
			'',
			'gpt-4o-mini',
		] );
		expect( options[ 1 ]?.label ).toBe( 'OpenAI · gpt-4o-mini' );
	} );

	it( 'keeps a saved model that is no longer offered, marked as unavailable', () => {
		const options = modelOptions( status, 'viejo:1b' );

		expect( options[ 2 ] ).toEqual( {
			value: 'viejo:1b',
			label: 'viejo:1b (not available now)',
		} );
	} );
} );
