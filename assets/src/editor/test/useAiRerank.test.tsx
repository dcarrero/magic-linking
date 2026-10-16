import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { speak } from '@wordpress/a11y';
import { api } from '../../api';
import type {
	AiCapabilities,
	OutboundResponse,
	RerankResponse,
	Suggestion,
} from '../../types';
import { suggestionKey } from '../keys';
import { useAiRerank, type AiRerank } from '../useAiRerank';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '../../api', () => ( {
	api: { aiCapabilities: jest.fn(), rerank: jest.fn() },
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const mocked = api as unknown as {
	aiCapabilities: jest.Mock;
	rerank: jest.Mock;
};

const suggestion = ( id: number ): Suggestion =>
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
	} ) as Suggestion;

const caps: AiCapabilities = {
	available: true,
	text: true,
	enabled: true,
	ready: true,
	budget: 'ok',
	limit: 2000000,
	needs: 9000,
	provider: { id: 'p', name: 'P' },
	model: 'm',
	local: false,
	timeout: 50,
	estimate: {
		tokens_in: 1,
		tokens_out: 1,
		tokens: 2,
		cost: 0.001,
		prices_date: '2026-10-15',
	},
	connectors_url: 'options-connectors.php',
};

const usage = {
	tokens_in: 100,
	tokens_out: 50,
	tokens: 150,
	cost: 0.0004,
	local: false,
	provider: 'p',
	provider_name: 'P',
	model: 'm',
	prices_date: '2026-10-15',
};

const data = ( ...ids: number[] ): OutboundResponse => ( {
	ready: true,
	state: 'ok',
	total: ids.length,
	items: ids.map( suggestion ),
} );

const getContent = () => '<p>contenido</p>';
let root: Root;
let current: AiRerank;

function Probe( {
	payload,
	busy,
}: {
	payload: OutboundResponse | null;
	busy: ( s: Suggestion ) => boolean;
} ) {
	current = useAiRerank( 7, payload, busy, getContent );
	return null;
}

const never = () => false;
const mount = async (
	payload: OutboundResponse | null,
	busy: ( s: Suggestion ) => boolean = never
) => {
	await act( async () => {
		root.render( <Probe payload={ payload } busy={ busy } /> );
	} );
};

beforeEach( () => {
	root = createRoot( document.createElement( 'div' ) );
	mocked.aiCapabilities.mockReset().mockResolvedValue( caps );
	mocked.rerank.mockReset();
	( speak as jest.Mock ).mockClear();
} );
afterEach( () => {
	act( () => root.unmount() );
} );

describe( 'useAiRerank', () => {
	it( 'asks what the connector can do without calling the model', async () => {
		await mount( data( 1, 2, 3 ) );

		expect( mocked.aiCapabilities ).toHaveBeenCalledWith(
			3,
			expect.any( AbortSignal )
		);
		expect( current.capabilities ).toEqual( caps );
		expect( mocked.rerank ).not.toHaveBeenCalled();
		expect( current.phase ).toBe( 'idle' );
	} );

	it( 'has no capabilities, and so no button, when the request fails', async () => {
		mocked.aiCapabilities.mockRejectedValue( new Error( 'x' ) );

		await mount( data( 1 ) );

		expect( current.capabilities ).toBeNull();
	} );

	it( 'does not ask anything when there are no suggestions', async () => {
		await mount( data() );

		expect( mocked.aiCapabilities ).not.toHaveBeenCalled();
	} );

	it( 'reorders on a good answer and keeps the cost of the call', async () => {
		const response: RerankResponse = {
			ok: true,
			skipped: [],
			usage,
			items: [
				{
					index: 1,
					target_id: 2,
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
		};
		mocked.rerank.mockResolvedValue( response );
		const payload = data( 1, 2 );
		await mount( payload );

		await act( async () => current.run() );

		expect( mocked.rerank ).toHaveBeenCalledTimes( 1 );
		expect( mocked.rerank.mock.calls[ 0 ][ 0 ] ).toBe( 7 );
		expect( mocked.rerank.mock.calls[ 0 ][ 1 ] ).toHaveLength( 2 );
		expect( mocked.rerank.mock.calls[ 0 ][ 2 ] ).toBe( '<p>contenido</p>' );
		expect( current.phase ).toBe( 'done' );
		expect( current.usage ).toEqual( usage );
		expect( current.unfit ).toBe( 1 );
		expect(
			current.view( payload.items ).map( ( v ) => v.item.target.id )
		).toEqual( [ 2, 1 ] );
		expect( speak ).toHaveBeenCalled();
	} );

	it( 'falls back to the lexical order with the plain message when the AI does not answer', async () => {
		mocked.rerank.mockResolvedValue( {
			ok: false,
			reason: 'network',
			message: 'The AI did not respond; showing suggestions without AI.',
		} );
		const payload = data( 1, 2 );
		await mount( payload );

		await act( async () => current.run() );

		expect( current.phase ).toBe( 'failed' );
		expect( current.message ).toBe(
			'The AI did not respond; showing suggestions without AI.'
		);
		expect(
			current.view( payload.items ).map( ( v ) => v.item.target.id )
		).toEqual( [ 1, 2 ] );
	} );

	it( 'treats a thrown error like no answer and never shows its text', async () => {
		mocked.rerank.mockRejectedValue(
			new Error( 'HTTP 500 secret detail' )
		);
		await mount( data( 1 ) );

		await act( async () => current.run() );

		expect( current.phase ).toBe( 'failed' );
		expect( current.message ).toBe(
			'The AI did not respond; showing suggestions without AI.'
		);
	} );

	it( 'does not send the suggestions that already have a state', async () => {
		mocked.rerank.mockResolvedValue( {
			ok: true,
			skipped: [],
			usage,
			items: [],
		} );
		const payload = data( 1, 2, 3 );
		const linked = suggestionKey( payload.items[ 0 ] as Suggestion );
		await mount( payload, ( s ) => suggestionKey( s ) === linked );

		await act( async () => current.run() );

		const sent = mocked.rerank.mock.calls[ 0 ][ 1 ] as {
			target_id: number;
		}[];
		expect( sent.map( ( s ) => s.target_id ) ).toEqual( [ 2, 3 ] );
	} );

	it( 'sends one request only while another is running', async () => {
		let finish: ( value: RerankResponse ) => void = () => undefined;
		mocked.rerank.mockReturnValue(
			new Promise< RerankResponse >( ( resolve ) => {
				finish = resolve;
			} )
		);
		await mount( data( 1 ) );

		await act( async () => current.run() );
		expect( current.phase ).toBe( 'loading' );
		await act( async () => current.run() );

		expect( mocked.rerank ).toHaveBeenCalledTimes( 1 );
		await act( async () =>
			finish( { ok: true, skipped: [], usage, items: [] } )
		);
		expect( current.phase ).toBe( 'done' );
	} );

	it( 'forgets the result when the suggestions are asked again', async () => {
		mocked.rerank.mockResolvedValue( {
			ok: true,
			skipped: [],
			usage,
			items: [
				{
					index: 1,
					target_id: 2,
					fit: true,
					confidence: 0.9,
					improved: null,
				},
			],
		} );
		await mount( data( 1, 2 ) );
		await act( async () => current.run() );
		expect( current.phase ).toBe( 'done' );

		await mount( data( 1, 2 ) );

		expect( current.phase ).toBe( 'idle' );
		expect( current.unfit ).toBe( 0 );
	} );

	it( 'goes back to the original order on request', async () => {
		mocked.rerank.mockResolvedValue( {
			ok: true,
			skipped: [],
			usage,
			items: [
				{
					index: 1,
					target_id: 2,
					fit: true,
					confidence: 0.9,
					improved: null,
				},
			],
		} );
		const payload = data( 1, 2 );
		await mount( payload );
		await act( async () => current.run() );

		act( () => current.reset() );

		expect(
			current.view( payload.items ).map( ( v ) => v.item.target.id )
		).toEqual( [ 1, 2 ] );
	} );

	it( 'accepts an improved anchor only when asked', async () => {
		const improved = {
			anchor: 'bomba de calor reduce',
			offset: 4,
			before: 'Una ',
			after: ' el consumo.',
			anchor_start: 4,
			anchor_end: 25,
		};
		mocked.rerank.mockResolvedValue( {
			ok: true,
			skipped: [],
			usage,
			items: [
				{
					index: 0,
					target_id: 1,
					fit: true,
					confidence: 0.9,
					improved,
				},
			],
		} );
		const payload = data( 1 );
		await mount( payload );
		await act( async () => current.run() );

		const before = current.view( payload.items )[ 0 ];
		expect( before?.item.anchor ).toBe( 'bomba de calor' );
		expect( before?.mark?.improved?.anchor ).toBe(
			'bomba de calor reduce'
		);

		act( () =>
			current.acceptAnchor( payload.items[ 0 ] as Suggestion, improved )
		);

		const after = current.view( payload.items )[ 0 ];
		expect( after?.item.anchor ).toBe( 'bomba de calor reduce' );
		expect( after?.mark?.improved ).toBeNull();
	} );

	describe( 'accepted anchors', () => {
		const improved = {
			anchor: 'bomba de calor reduce',
			offset: 4,
			before: 'Una ',
			after: ' el consumo.',
			anchor_start: 4,
			anchor_end: 25,
		};
		const answer = ( proposal: typeof improved | null ) => ( {
			ok: true,
			skipped: [],
			usage,
			items: [
				{
					index: 0,
					target_id: 1,
					fit: true,
					confidence: 0.9,
					improved: proposal,
				},
			],
		} );

		const acceptFirst = async ( payload: OutboundResponse ) => {
			mocked.rerank.mockResolvedValueOnce( answer( improved ) );
			await mount( payload );
			await act( async () => current.run() );
			act( () =>
				current.acceptAnchor(
					payload.items[ 0 ] as Suggestion,
					improved
				)
			);
			expect( current.view( payload.items )[ 0 ]?.item.anchor ).toBe(
				'bomba de calor reduce'
			);
		};

		it( 'drops the accepted anchor when going back to the original order', async () => {
			const payload = data( 1 );
			await acceptFirst( payload );

			act( () => current.reset() );

			const view = current.view( payload.items )[ 0 ];
			expect( view?.item.anchor ).toBe( 'bomba de calor' );
			expect( view?.mark ).toBeUndefined();
		} );

		it( 'drops an accepted anchor that a new run does not propose again', async () => {
			const payload = data( 1 );
			await acceptFirst( payload );

			mocked.rerank.mockResolvedValueOnce( answer( null ) );
			await act( async () => current.run() );

			const view = current.view( payload.items )[ 0 ];
			expect( view?.item.anchor ).toBe( 'bomba de calor' );
			expect( view?.aiAnchor ).toBe( false );
		} );

		it( 'keeps it when the new run proposes the same anchor', async () => {
			const payload = data( 1 );
			await acceptFirst( payload );

			mocked.rerank.mockResolvedValueOnce( answer( improved ) );
			await act( async () => current.run() );

			const view = current.view( payload.items )[ 0 ];
			expect( view?.item.anchor ).toBe( 'bomba de calor reduce' );
			expect( view?.aiAnchor ).toBe( true );
		} );
	} );

	it( 'does not report a failure when the list changes while the request is running', async () => {
		mocked.rerank.mockImplementation(
			(
				_post: number,
				_items: unknown,
				_content: string,
				signal: AbortSignal
			) =>
				new Promise( ( _resolve, reject ) => {
					signal.addEventListener( 'abort', () =>
						reject( new Error( 'aborted' ) )
					);
				} )
		);
		await mount( data( 1, 2 ) );
		await act( async () => current.run() );
		expect( current.phase ).toBe( 'loading' );
		( speak as jest.Mock ).mockClear();

		await mount( data( 1, 2 ) );

		expect( current.phase ).toBe( 'idle' );
		expect( current.message ).toBe( '' );
		expect( speak ).not.toHaveBeenCalled();
	} );

	it( 'asks for the capabilities once per list, not after every link or dismissal', async () => {
		const payload = data( 1, 2, 3 );
		await mount( payload );
		expect( mocked.aiCapabilities ).toHaveBeenCalledTimes( 1 );

		const first = suggestionKey( payload.items[ 0 ] as Suggestion );
		await mount( payload, ( s ) => suggestionKey( s ) === first );

		expect( mocked.aiCapabilities ).toHaveBeenCalledTimes( 1 );
	} );
} );
