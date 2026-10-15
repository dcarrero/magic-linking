jest.mock( '@wordpress/components', () => ( {} ) );
jest.mock( '../api', () => ( { api: {} } ) );

import type { AiStatus, AiStatusEntry } from '../types';
import { modelGroups } from './AiSettingsCards';

const entry = ( over: Partial< AiStatusEntry > ): AiStatusEntry => ( {
	provider: 'openrouter',
	provider_name: 'OpenRouter',
	model: 'x',
	name: 'x',
	local: false,
	price: null,
	recommended: false,
	...over,
} );

const status = {
	models: [
		entry( {
			model: 'openai/gpt-4o-mini',
			recommended: true,
			price: [ 0.15, 0.6 ],
		} ),
		entry( { model: 'openai/gpt-4o-mini', recommended: true } ),
		entry( {
			provider: 'ollama',
			provider_name: 'Ollama',
			model: 'llama3.2:3b',
			recommended: true,
			local: true,
		} ),
		entry( { model: 'meta/muse-spark-open' } ),
	],
} as AiStatus;

describe( 'modelGroups', () => {
	it( 'puts automatic first, then the recommended ones with their price, then the rest', () => {
		const groups = modelGroups( status, '' );

		expect( groups.map( ( g ) => g.label ) ).toEqual( [
			'',
			'Recommended',
			'All models',
		] );
		expect( groups[ 0 ]?.options[ 0 ]?.value ).toBe( '' );
		expect( groups[ 1 ]?.options.map( ( o ) => o.value ) ).toEqual( [
			'openai/gpt-4o-mini',
			'llama3.2:3b',
		] );
		expect( groups[ 1 ]?.options[ 0 ]?.label ).toContain(
			'OpenRouter · openai/gpt-4o-mini ('
		);
		expect( groups[ 1 ]?.options[ 1 ]?.label ).toBe(
			'Ollama · llama3.2:3b (no cost (local))'
		);
		expect( groups[ 2 ]?.options.map( ( o ) => o.value ) ).toEqual( [
			'meta/muse-spark-open',
		] );
	} );

	it( 'does not repeat a model offered twice', () => {
		const values = modelGroups( status, '' )
			.flatMap( ( g ) => g.options )
			.map( ( o ) => o.value );

		expect(
			values.filter( ( v ) => v === 'openai/gpt-4o-mini' )
		).toHaveLength( 1 );
	} );

	it( 'keeps a saved model that is no longer offered, marked as unavailable', () => {
		const last = modelGroups( status, 'viejo:1b' ).at( -1 );

		expect( last?.options[ 0 ] ).toEqual( {
			value: 'viejo:1b',
			label: 'viejo:1b (not available now)',
		} );
	} );
} );
