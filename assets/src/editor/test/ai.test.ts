import type { RerankResponse, Suggestion } from '../../types';
import {
	aiLinkInfo,
	arrange,
	candidates,
	MAX_CANDIDATES,
	MODEL_MAX,
	outcomeOf,
	rerankItems,
	unfitCount,
	withAnchor,
} from '../ai';
import { suggestionKey } from '../keys';

/**
 * Elemento de una lista que la prueba sabe que existe.
 * @param list
 * @param index
 */
function at< T >( list: T[], index: number ): T {
	const found = list[ index ];
	if ( found === undefined ) {
		throw new Error( `No hay elemento ${ index }` );
	}
	return found;
}

const make = ( id: number, over: Partial< Suggestion > = {} ): Suggestion =>
	( {
		source: { id: 1 },
		target: { id, title: `Destino ${ id }` },
		can_insert: true,
		reasons: [],
		alternatives: [],
		anchor: 'bomba de calor',
		sentence: 'Una bomba de calor reduce el consumo.',
		offset: 4,
		before: 'Una ',
		after: ' reduce el consumo.',
		anchor_start: 4,
		anchor_end: 18,
		paragraph: 0,
		score: 0.5,
		insert: {
			post_id: 1,
			target_id: id,
			sentence: 'Una bomba de calor reduce el consumo.',
			offset: 4,
			anchor: 'bomba de calor',
			block_path: null,
		},
		...over,
	} ) as Suggestion;

type Ok = Extract< RerankResponse, { ok: true } >;
const usage = {
	tokens_in: 1,
	tokens_out: 1,
	tokens: 2,
	cost: null,
	local: false,
	provider: 'p',
	provider_name: 'P',
	model: 'm',
	prices_date: '2026-10-15',
};
const ok = ( items: Ok[ 'items' ], skipped: number[] = [] ): Ok => ( {
	ok: true,
	items,
	skipped,
	usage,
} );

describe( 'candidates', () => {
	it( 'leaves out the suggestions that already have a state and cuts at 20', () => {
		const items = Array.from( { length: 25 }, ( _, i ) => make( i + 1 ) );
		const busy = new Set( [ suggestionKey( at( items, 0 ) ) ] );

		const sent = candidates( items, ( s ) =>
			busy.has( suggestionKey( s ) )
		);

		expect( sent ).toHaveLength( MAX_CANDIDATES );
		expect( at( sent, 0 ).target.id ).toBe( 2 );
	} );
} );

describe( 'rerankItems', () => {
	it( 'sends only what the server needs', () => {
		const items = rerankItems( [ make( 7 ) ] );

		expect( items ).toEqual( [
			{
				target_id: 7,
				sentence: 'Una bomba de calor reduce el consumo.',
				anchor: 'bomba de calor',
				offset: 4,
			},
		] );
	} );
} );

describe( 'outcomeOf and arrange', () => {
	const items = [ make( 1 ), make( 2 ), make( 3 ), make( 4 ) ];

	it( 'puts the AI order first and keeps the rest where they were', () => {
		const sent = items.slice( 0, 3 );
		const outcome = outcomeOf(
			sent,
			ok(
				[
					{
						index: 2,
						target_id: 3,
						fit: true,
						confidence: 0.9,
						improved: null,
					},
					{
						index: 0,
						target_id: 1,
						fit: false,
						confidence: 0.2,
						improved: null,
					},
				],
				[ 1 ]
			)
		);

		const views = arrange( items, outcome, {} );

		expect( views.map( ( v ) => v.item.target.id ) ).toEqual( [
			3, 1, 2, 4,
		] );
		expect( at( views, 0 ).mark?.fit ).toBe( true );
		expect( at( views, 1 ).mark?.fit ).toBe( false );
		expect( at( views, 2 ).mark ).toBeUndefined();
		expect( unfitCount( outcome ) ).toBe( 1 );
	} );

	it( 'ignores positions that do not exist and repeated ones', () => {
		const outcome = outcomeOf(
			items,
			ok( [
				{
					index: 9,
					target_id: 9,
					fit: true,
					confidence: 1,
					improved: null,
				},
				{
					index: 1,
					target_id: 2,
					fit: true,
					confidence: 1,
					improved: null,
				},
				{
					index: 1,
					target_id: 2,
					fit: false,
					confidence: 0,
					improved: null,
				},
			] )
		);

		expect( outcome.order ).toEqual( [ suggestionKey( at( items, 1 ) ) ] );
		expect( outcome.marks[ suggestionKey( at( items, 1 ) ) ]?.fit ).toBe(
			true
		);
	} );

	it( 'is the lexical order when there is no result', () => {
		const views = arrange( items, null, {} );

		expect( views.map( ( v ) => v.item.target.id ) ).toEqual( [
			1, 2, 3, 4,
		] );
		expect( views.every( ( v ) => v.mark === undefined ) ).toBe( true );
	} );
} );

describe( 'withAnchor', () => {
	const improved = {
		anchor: 'bomba de calor reduce',
		offset: 4,
		before: 'Una ',
		after: ' el consumo.',
		anchor_start: 4,
		anchor_end: 25,
	};

	it( 'changes the anchor everywhere it is used and keeps the rest', () => {
		const original = make( 5 );

		const changed = withAnchor( original, improved );

		expect( changed.anchor ).toBe( 'bomba de calor reduce' );
		expect( changed.before + changed.anchor + changed.after ).toBe(
			'Una bomba de calor reduce el consumo.'
		);
		expect( changed.insert?.anchor ).toBe( 'bomba de calor reduce' );
		expect( changed.target ).toBe( original.target );
		expect( original.anchor ).toBe( 'bomba de calor' );
	} );

	it( 'takes the notes of the improved anchor from the AI response, so the card asks nothing', () => {
		const notes = [ { code: 'overused', text: 'Used in 6 entries.' } ];

		expect(
			withAnchor( make( 5 ), { ...improved, anchor_notes: notes } )
				.anchor_notes
		).toEqual( notes );
	} );

	it( 'does not keep the notes of the old anchor: they belong to another anchor', () => {
		const original = {
			...make( 5 ),
			anchor_notes: [ { code: 'overused', text: 'Used in 6 entries.' } ],
		};

		expect( withAnchor( original, improved ).anchor_notes ).toBeNull();
	} );

	it( 'shows the accepted anchor in place and drops the proposal', () => {
		const original = make( 5 );
		const outcome = outcomeOf(
			[ original ],
			ok( [
				{
					index: 0,
					target_id: 5,
					fit: true,
					confidence: 0.8,
					improved,
				},
			] )
		);
		const key = suggestionKey( original );

		const views = arrange( [ original ], outcome, {
			[ key ]: withAnchor( original, improved ),
		} );

		expect( at( views, 0 ).item.anchor ).toBe( 'bomba de calor reduce' );
		expect( at( views, 0 ).original ).toBe( original );
		expect( at( views, 0 ).mark?.improved ).toBeNull();
		expect( at( views, 0 ).mark?.fit ).toBe( true );
	} );
} );

describe( 'aiLinkInfo', () => {
	const improved = {
		anchor: 'bomba de calor reduce',
		offset: 4,
		before: 'Una ',
		after: ' el consumo.',
		anchor_start: 4,
		anchor_end: 25,
	};

	it( 'records the model of the call on every rated card', () => {
		const original = make( 5 );
		const outcome = outcomeOf(
			[ original ],
			ok( [
				{
					index: 0,
					target_id: 5,
					fit: true,
					confidence: 0.8,
					improved,
				},
			] )
		);
		const mark = outcome.marks[ suggestionKey( original ) ];

		expect( mark?.model ).toBe( 'm' );
		expect( aiLinkInfo( mark, false ) ).toEqual( {
			ai_recommended: true,
			ai_anchor: false,
			ai_model: 'm',
		} );
		expect( aiLinkInfo( mark, true )?.ai_anchor ).toBe( true );
	} );

	it( 'cuts a model id longer than the server accepts', () => {
		const mark = {
			fit: true,
			confidence: 0.5,
			improved: null,
			model: 'x'.repeat( MODEL_MAX + 40 ),
		};

		expect( aiLinkInfo( mark, false )?.ai_model ).toHaveLength( MODEL_MAX );

		// Por puntos de código: un emoji (dos unidades UTF-16) no se parte por la mitad.
		const emoji = aiLinkInfo(
			{ ...mark, model: '😀'.repeat( 150 ) },
			false
		);
		expect( Array.from( emoji?.ai_model ?? '' ) ).toHaveLength( MODEL_MAX );
		expect( emoji?.ai_model ).toBe( '😀'.repeat( MODEL_MAX ) );
	} );

	it( 'says nothing when the AI did not recommend the card', () => {
		const base = { confidence: 0.5, improved: null, model: 'm' };

		expect( aiLinkInfo( undefined, false ) ).toBeNull();
		expect( aiLinkInfo( { ...base, fit: false }, true ) ).toBeNull();
		expect( aiLinkInfo( { ...base, fit: null }, false ) ).toBeNull();
	} );
} );

describe( 'aiAnchor', () => {
	const first = {
		anchor: 'bomba de calor reduce',
		offset: 4,
		before: 'Una ',
		after: ' el consumo.',
		anchor_start: 4,
		anchor_end: 25,
	};

	const run = ( original: Suggestion, improved: typeof first | null ) =>
		outcomeOf(
			[ original ],
			ok( [
				{
					index: 0,
					target_id: 5,
					fit: true,
					confidence: 0.8,
					improved,
				},
			] )
		);

	it( 'is true when the accepted anchor is the one the current answer proposed', () => {
		const original = make( 5 );
		const key = suggestionKey( original );

		const views = arrange( [ original ], run( original, first ), {
			[ key ]: withAnchor( original, first ),
		} );

		expect( at( views, 0 ).aiAnchor ).toBe( true );
	} );

	it( 'is false when the changed anchor comes from an earlier run', () => {
		const original = make( 5 );
		const key = suggestionKey( original );
		const second = {
			...first,
			anchor: 'una bomba de calor',
			anchor_end: 18,
		};

		// Se aceptó el ancla de la primera ejecución y la segunda propone otra (o ninguna).
		const views = arrange( [ original ], run( original, second ), {
			[ key ]: withAnchor( original, first ),
		} );
		const none = arrange( [ original ], run( original, null ), {
			[ key ]: withAnchor( original, first ),
		} );

		expect( at( views, 0 ).aiAnchor ).toBe( false );
		expect( at( none, 0 ).aiAnchor ).toBe( false );
	} );

	it( 'does not mark the AI at all once the original order is restored', () => {
		const original = make( 5 );
		const key = suggestionKey( original );

		// «Volver al orden original»: sin respuesta vigente no hay marca, aunque quede un ancla aceptada.
		const view = at(
			arrange( [ original ], null, {
				[ key ]: withAnchor( original, first ),
			} ),
			0
		);

		expect( view.aiAnchor ).toBe( false );
		expect( aiLinkInfo( view.mark, view.aiAnchor ) ).toBeNull();
	} );
} );
