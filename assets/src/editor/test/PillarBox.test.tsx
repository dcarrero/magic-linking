import { act } from 'react';
import { createRoot } from 'react-dom/client';
import type { PillarState } from '../../types';
import { PillarBox } from '../PillarBox';

const mockPillar = jest.fn();
const mockSetPillar = jest.fn();

jest.mock( '../../api', () => ( {
	api: {
		pillar: ( ...args: unknown[] ) => mockPillar( ...args ),
		setPillar: ( ...args: unknown[] ) => mockSetPillar( ...args ),
	},
	errorMessage: ( _e: unknown, fallback: string ) => fallback,
} ) );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '@wordpress/components', () => ( {
	CheckboxControl: ( {
		label,
		help,
		checked,
		disabled,
		onChange,
	}: {
		label: string;
		help: string;
		checked: boolean;
		disabled: boolean;
		onChange: ( value: boolean ) => void;
	} ) => (
		<div>
			<label htmlFor="pillar">
				{ label }
				<input
					id="pillar"
					type="checkbox"
					checked={ checked }
					disabled={ disabled }
					onChange={ ( e ) => onChange( e.target.checked ) }
				/>
			</label>
			<p>{ help }</p>
		</div>
	),
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const state = ( extra: Partial< PillarState > ): PillarState => ( {
	post_id: 5,
	pillar: false,
	own: false,
	external: null,
	...extra,
} );

async function mount(): Promise< HTMLElement > {
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	await act( async () => {
		createRoot( host ).render( <PillarBox postId={ 5 } /> );
	} );
	return host;
}

describe( 'PillarBox', () => {
	beforeEach( () => {
		mockPillar.mockReset();
		mockSetPillar.mockReset();
	} );

	it( 'si Yoast la marca aparece marcada, con la nota, y no se cambia aquí', async () => {
		mockPillar.mockResolvedValue(
			state( { pillar: true, external: 'yoast' } )
		);
		const host = await mount();
		const box = host.querySelector( 'input' ) as HTMLInputElement;
		expect( box.checked ).toBe( true );
		expect( box.disabled ).toBe( true );
		expect( host.textContent ).toContain( 'cornerstone content in Yoast' );
	} );

	it( 'con Rank Math lo mismo', async () => {
		mockPillar.mockResolvedValue(
			state( { pillar: true, external: 'rank_math' } )
		);
		const host = await mount();
		expect( host.textContent ).toContain( 'pillar content in Rank Math' );
	} );

	it( 'marca la casilla propia y guarda al momento', async () => {
		mockPillar.mockResolvedValue( state( {} ) );
		mockSetPillar.mockResolvedValue( state( { pillar: true, own: true } ) );
		const host = await mount();
		const box = host.querySelector( 'input' ) as HTMLInputElement;
		expect( box.checked ).toBe( false );
		expect( box.disabled ).toBe( false );

		await act( async () => {
			box.click();
		} );
		expect( mockSetPillar ).toHaveBeenCalledWith( 5, true );
		expect(
			( host.querySelector( 'input' ) as HTMLInputElement ).checked
		).toBe( true );
	} );

	it( 'si falla guardar lo dice con un aviso', async () => {
		mockPillar.mockResolvedValue( state( {} ) );
		mockSetPillar.mockRejectedValue( new Error( 'x' ) );
		const host = await mount();
		await act( async () => {
			( host.querySelector( 'input' ) as HTMLInputElement ).click();
		} );
		expect( host.querySelector( '[role="alert"]' )?.textContent ).toContain(
			'could not be saved'
		);
	} );
} );
