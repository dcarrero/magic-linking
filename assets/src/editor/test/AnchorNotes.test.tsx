import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { Suggestion } from '../../types';
import { api } from '../../api';
import { SuggestionCard } from '../SuggestionCard';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
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
jest.mock( '../../api', () => ( {
	api: { checkAnchor: jest.fn() },
	errorMessage: ( _e: unknown, fallback: string ) => fallback,
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const CANNIBAL = {
	code: 'cannibalization',
	text: 'This anchor already links to “Bombas de calor: precios” in 3 entries.',
};
const OVERUSED = {
	code: 'overused',
	text: 'This anchor is already used in 6 entries for this destination.',
};

const make = ( extra: Partial< Suggestion > = {} ): Suggestion =>
	( {
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
		...extra,
	} ) as Suggestion;

let root: Root;
let host: HTMLElement;

const render = ( item: Suggestion ) =>
	act( async () => {
		root.render(
			<ul>
				<SuggestionCard
					suggestion={ item }
					direction="outbound"
					historyUrl=""
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
	document.body.appendChild( host );
	root = createRoot( host );
	( api.checkAnchor as jest.Mock ).mockReset();
} );
afterEach( () => {
	act( () => root.unmount() );
	host.remove();
} );

describe( 'SuggestionCard anchor notes', () => {
	it( 'shows no notice when the anchor is not repeated', async () => {
		await render( make( { anchor_notes: [] } ) );
		expect( host.querySelector( '.magiclinking-card__notes' ) ).toBeNull();
	} );

	it( 'says it in words, with an icon that is hidden from assistive technology', async () => {
		await render( make( { anchor_notes: [ CANNIBAL, OVERUSED ] } ) );

		const list = host.querySelector( '.magiclinking-card__notes' );
		expect( list ).not.toBeNull();
		const items = Array.from( list?.querySelectorAll( 'li' ) ?? [] );
		expect( items.map( ( li ) => li.textContent ) ).toEqual( [
			`⚠ ${ CANNIBAL.text }`,
			`⚠ ${ OVERUSED.text }`,
		] );
		items.forEach( ( li ) =>
			expect(
				li.querySelector( '[aria-hidden="true"]' )?.textContent
			).toBe( '⚠ ' )
		);
		expect( list?.getAttribute( 'aria-label' ) ).toBe( 'Anchor notices' );
	} );

	it( 'asks the server for the notes of an anchor it does not know yet', async () => {
		( api.checkAnchor as jest.Mock ).mockResolvedValue( {
			valid: true,
			errors: [],
			warnings: [],
			notes: [ OVERUSED ],
		} );

		await render( make( { anchor_notes: null } ) );

		expect( api.checkAnchor ).toHaveBeenCalledTimes( 1 );
		expect( api.checkAnchor ).toHaveBeenCalledWith(
			1,
			'Una bomba de calor reduce el consumo.',
			4,
			'bomba de calor',
			2,
			[],
			expect.any( AbortSignal )
		);
		expect( host.textContent ).toContain( OVERUSED.text );
	} );

	it( 'shows nothing, and does not fail, if the notes cannot be read', async () => {
		( api.checkAnchor as jest.Mock ).mockRejectedValue( new Error( 'x' ) );

		await render( make( { anchor_notes: null } ) );

		expect( host.querySelector( '.magiclinking-card__notes' ) ).toBeNull();
	} );

	it( 'does not ask when the notes are already known or the user cannot edit the entry', async () => {
		await render( make( { anchor_notes: [] } ) );
		await render( make( { anchor_notes: null, can_insert: false } ) );
		await render( make() );

		expect( api.checkAnchor ).not.toHaveBeenCalled();
	} );
} );
