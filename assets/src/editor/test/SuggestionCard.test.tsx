import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { Suggestion } from '../../types';
import type { AiMark } from '../ai';
import { SuggestionCard, type CardStatus } from '../SuggestionCard';

jest.mock( '@wordpress/components', () => ( {
	Button: ( {
		children,
		onClick,
		disabled,
	}: {
		children?: unknown;
		onClick?: () => void;
		disabled?: boolean;
	} ) => (
		<button type="button" onClick={ onClick } disabled={ disabled }>
			{ children as string }
		</button>
	),
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const suggestion = {
	source: { id: 1 },
	target: { id: 2, title: 'Destino' },
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
} as Suggestion;

const mark: AiMark = {
	fit: true,
	confidence: 0.9,
	improved: {
		anchor: 'bomba de calor reduce',
		offset: 4,
		before: 'Una ',
		after: ' el consumo.',
		anchor_start: 4,
		anchor_end: 25,
	},
	model: 'gpt-4.1-mini',
};

let root: Root;
let host: HTMLElement;

const render = ( status?: CardStatus ) =>
	act( () => {
		root.render(
			<ul>
				<SuggestionCard
					suggestion={ suggestion }
					direction="outbound"
					status={ status }
					historyUrl=""
					ai={ mark }
					onUseAnchor={ () => undefined }
					onLink={ () => undefined }
					onDismiss={ () => undefined }
					onRestore={ () => undefined }
					onUndo={ () => undefined }
				/>
			</ul>
		);
	} );

beforeEach( () => {
	host = document.createElement( 'div' );
	root = createRoot( host );
} );
afterEach( () => act( () => root.unmount() ) );

const hasUseAnchor = () =>
	Array.from( host.querySelectorAll( 'button' ) ).some( ( b ) =>
		( b.textContent ?? '' ).includes( 'Use this anchor' )
	);

describe( 'SuggestionCard AI note', () => {
	it( 'offers the better anchor only while the card has no state', async () => {
		await render();
		expect( hasUseAnchor() ).toBe( true );

		await render( { kind: 'busy' } );
		expect( hasUseAnchor() ).toBe( false );

		await render( { kind: 'error', message: 'x' } );
		expect( hasUseAnchor() ).toBe( false );

		await render( { kind: 'dismissing' } );
		expect( hasUseAnchor() ).toBe( false );
	} );
} );
