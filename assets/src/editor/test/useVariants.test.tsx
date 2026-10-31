import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { Suggestion } from '../../types';
import { speak } from '@wordpress/a11y';
import { suggestionKey } from '../keys';
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

const alt = ( sentence: string, anchor: string, paragraph: number ) => {
	const start = sentence.indexOf( anchor );
	return {
		anchor,
		sentence,
		offset: start,
		before: sentence.slice( 0, start ),
		after: sentence.slice( start + anchor.length ),
		anchor_start: start,
		anchor_end: start + anchor.length,
		paragraph,
		score: 0.5,
	};
};
const card = (
	target: number,
	main: ReturnType< typeof alt >,
	alts: ReturnType< typeof alt >[] = []
) =>
	( {
		...main,
		source: { id: 1, title: `S${ target }` },
		target: { id: target, title: `T${ target }` },
		can_insert: true,
		reasons: [],
		alternatives: alts,
	} ) as unknown as Suggestion;
const T1 = 'La bomba de calor ahorra mucho en casa.';
const T3 = 'Revisar la caldera cada año evita averías.';
const linked = card( 9, alt( T1, 'bomba de calor', 0 ) );
const wants = card( 8, alt( T3, 'caldera', 2 ), [
	alt( T1, 'bomba de calor', 0 ),
] );
const pair = [ linked, wants ];

let root: Root;
const seen: Variants[] = [];
const last = () => seen[ seen.length - 1 ] as Variants;
let probeItems: Suggestion[] = items;
let probeFrozen: ReadonlySet< string > = new Set();
let probeDirection: 'outbound' | 'inbound' = 'outbound';

function Probe() {
	seen.push( useVariants( probeItems, probeFrozen, probeDirection ) );
	return null;
}

beforeEach( () => {
	seen.length = 0;
	probeItems = items;
	probeFrozen = new Set();
	probeDirection = 'outbound';
	( speak as jest.Mock ).mockReset();
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

	it( 'rejects a sentence that a linked card keeps, with a warning, and accepts it when nothing keeps it', async () => {
		probeItems = pair;
		probeFrozen = new Set( [ suggestionKey( linked ) ] );
		await act( () => root.render( <Probe /> ) );
		let applied = true;
		await act( () => {
			applied = last().choose( wants, 1 );
		} );
		expect( applied ).toBe( false );
		expect( speak ).toHaveBeenCalledWith(
			expect.stringContaining( 'cannot be used' ),
			'assertive'
		);
		expect( last().view( wants, wants )?.option ).toBe( 0 );

		probeFrozen = new Set();
		await act( () => root.render( <Probe /> ) );
		await act( () => {
			applied = last().choose( wants, 1 );
		} );
		expect( applied ).toBe( true );
		expect( last().hidden( linked ) ).toBe( true );
	} );

	it( 'keeps the key of the engine suggestion in the card it shows', async () => {
		probeItems = pair;
		await act( () => root.render( <Probe /> ) );
		await act( () => {
			last().choose( wants, 1 );
		} );
		const shown = last().view( wants, wants );
		expect( shown?.item.sentence ).toBe( T1 );
		expect( suggestionKey( shown?.item as Suggestion ) ).toBe(
			suggestionKey( wants )
		);
	} );

	it( 'names the destination in the outbound notices and the source entry in the inbound ones', async () => {
		probeItems = pair;
		await act( () => root.render( <Probe /> ) );
		await act( () => {
			last().choose( wants, 1 );
		} );
		expect( speak ).toHaveBeenCalledWith(
			expect.stringContaining( '“T9”' ),
			'polite'
		);

		( speak as jest.Mock ).mockReset();
		probeDirection = 'inbound';
		await act( () => root.render( <Probe /> ) );
		await act( () => {
			last().reset();
		} );
		await act( () => {
			last().choose( wants, 1 );
		} );
		const said = ( speak as jest.Mock ).mock.calls.map( ( c ) => c[ 0 ] );
		expect( said.some( ( text: string ) => text.includes( '“S9”' ) ) ).toBe(
			true
		);
		expect( said.some( ( text: string ) => text.includes( '“T9”' ) ) ).toBe(
			false
		);
	} );
} );
