import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { Suggestion } from '../../types';
import { SuggestionCard, type CardVariants } from '../SuggestionCard';
import { optionsOf } from '../variants';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '@wordpress/components', () => {
	const { forwardRef } = jest.requireActual( 'react' );
	return {
		Button: forwardRef(
			(
				{
					children,
					variant,
					size,
					isBusy,
					accessibleWhenDisabled,
					...rest
				}: Record< string, unknown >,
				ref: unknown
			) => {
				void variant;
				void size;
				void isBusy;
				void accessibleWhenDisabled;
				return (
					<button
						type="button"
						ref={ ref as never }
						{ ...( rest as object ) }
					>
						{ children as string }
					</button>
				);
			}
		),
	};
} );
jest.mock( '../../api', () => ( {
	api: { checkAnchor: jest.fn() },
	errorMessage: ( _e: unknown, fallback: string ) => fallback,
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const main = {
	anchor: 'bomba de calor',
	sentence: 'Una bomba de calor reduce el consumo.',
	offset: 4,
	before: 'Una ',
	after: ' reduce el consumo.',
	anchor_start: 4,
	anchor_end: 18,
	paragraph: 0,
	score: 0.5,
};
const other = {
	...main,
	sentence: 'Con una bomba de calor se ahorra.',
	offset: 8,
	before: 'Con una ',
	after: ' se ahorra.',
	anchor_start: 8,
	anchor_end: 22,
	paragraph: 1,
};
const suggestion = ( alternatives: Suggestion[ 'alternatives' ] ) =>
	( {
		...main,
		source: { id: 1 },
		target: { id: 2, title: 'Destino' },
		can_insert: true,
		reasons: [],
		alternatives,
	} ) as Suggestion;

let root: Root;
let host: HTMLElement;
const onSelect = jest.fn();

const render = ( item: Suggestion, withVariants = true, selected = 0 ) => {
	const variants: CardVariants = {
		options: optionsOf( item ),
		selected,
		onSelect,
		onAdjust: jest.fn(),
	};
	return act( () => {
		root.render(
			<ul>
				<SuggestionCard
					suggestion={ item }
					variants={ withVariants ? variants : undefined }
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
};

const button = ( text: string ) =>
	Array.from( host.querySelectorAll( 'button' ) ).find( ( b ) =>
		( b.textContent ?? '' ).startsWith( text )
	);

beforeEach( () => {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	onSelect.mockReset();
} );
afterEach( () => {
	act( () => root.unmount() );
	host.remove();
} );

describe( 'SuggestionCard sentences and anchor', () => {
	it( 'is the card of always without variants', async () => {
		await render( suggestion( [ other ] ), false );
		expect( button( 'Adjust anchor' ) ).toBeUndefined();
		expect( button( 'More sentences' ) ).toBeUndefined();
	} );

	it( 'offers More sentences only when there are alternatives', async () => {
		await render( suggestion( [] ) );
		expect( button( 'Adjust anchor' ) ).toBeDefined();
		expect( button( 'More sentences' ) ).toBeUndefined();

		await render( suggestion( [ other ] ) );
		const more = button( 'More sentences (2)' );
		expect( more?.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		const region = document.getElementById(
			more?.getAttribute( 'aria-controls' ) ?? ''
		);
		expect( region?.hasAttribute( 'hidden' ) ).toBe( true );
	} );

	it( 'opens a group of radio buttons with the main sentence selected', async () => {
		await render( suggestion( [ other ] ) );
		await act( async () => button( 'More sentences' )?.click() );
		expect(
			button( 'More sentences' )?.getAttribute( 'aria-expanded' )
		).toBe( 'true' );
		const radios = Array.from(
			host.querySelectorAll< HTMLInputElement >( 'input[type="radio"]' )
		) as [ HTMLInputElement, HTMLInputElement ];
		expect( radios ).toHaveLength( 2 );
		expect( radios[ 0 ].checked ).toBe( true );
		expect( radios[ 1 ].checked ).toBe( false );
		expect( host.querySelector( 'legend' )?.textContent ).toBe(
			'Link from this sentence'
		);
		// Cada opción tiene su etiqueta con la frase.
		const label = host.querySelector( `label[for="${ radios[ 1 ].id }"]` );
		expect( label?.textContent ).toContain(
			'Con una bomba de calor se ahorra.'
		);
		await act( async () => radios[ 1 ].click() );
		expect( onSelect ).toHaveBeenCalledWith( 1 );
	} );

	it( 'swaps the sentence for the adjuster, disables Link and returns the focus when cancelled', async () => {
		await render( suggestion( [] ) );
		await act( async () => button( 'Adjust anchor' )?.click() );
		const group = host.querySelector< HTMLElement >( '[role="group"]' );
		expect( group?.getAttribute( 'aria-label' ) ).toBe( 'Adjust anchor' );
		expect(
			host.querySelector( '.magiclinking-card__sentence' )
		).toBeNull();
		expect( button( 'Link' )?.hasAttribute( 'disabled' ) ).toBe( true );

		await act( async () => button( 'Cancel' )?.click() );
		expect(
			host.querySelector( '.magiclinking-card__sentence' )
		).not.toBeNull();
		expect( host.ownerDocument.activeElement?.textContent ).toBe(
			'Adjust anchor'
		);
		expect( button( 'Link' )?.hasAttribute( 'disabled' ) ).toBe( false );
	} );

	it( 'does not offer the changes once the card has its own state', async () => {
		const item = suggestion( [ other ] );
		await act( async () => {
			root.render(
				<ul>
					<SuggestionCard
						suggestion={ item }
						variants={ {
							options: optionsOf( item ),
							selected: 0,
							onSelect,
							onAdjust: jest.fn(),
						} }
						status={ { kind: 'busy' } }
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
		expect( button( 'Adjust anchor' ) ).toBeUndefined();
		expect( button( 'More sentences' ) ).toBeUndefined();
	} );
} );
