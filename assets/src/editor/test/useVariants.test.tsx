import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { Suggestion } from '../../types';
import { useVariants, type Variants } from '../useVariants';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const item = {
	source: { id: 1 },
	target: { id: 2, title: 'Destino' },
	anchor: 'bomba',
	sentence: 'Una bomba de calor.',
	offset: 4,
	before: 'Una ',
	after: ' de calor.',
	anchor_start: 4,
	anchor_end: 9,
	paragraph: 0,
	score: 0.5,
	alternatives: [],
} as unknown as Suggestion;
const items = [ item ];

let root: Root;
const seen: Variants[] = [];

function Probe() {
	seen.push( useVariants( items ) );
	return null;
}

beforeEach( () => {
	seen.length = 0;
	root = createRoot( document.createElement( 'div' ) );
} );
afterEach( () => act( () => root.unmount() ) );

describe( 'useVariants', () => {
	it( 'returns the same object while nothing changes, so consumers can memoize on it', async () => {
		await act( () => root.render( <Probe /> ) );
		await act( () => root.render( <Probe /> ) );
		expect( seen.length ).toBeGreaterThanOrEqual( 2 );
		expect( seen[ seen.length - 1 ] ).toBe( seen[ 0 ] );
	} );
} );
