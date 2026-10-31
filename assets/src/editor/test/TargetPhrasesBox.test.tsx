import { act } from 'react';
import { createRoot } from 'react-dom/client';
import type { TargetPhrasesState } from '../../types';
import { TargetPhrasesBox } from '../TargetPhrasesBox';

const mockGet = jest.fn();
const mockSet = jest.fn();

jest.mock( '../../api', () => ( {
	api: {
		targetPhrases: ( ...args: unknown[] ) => mockGet( ...args ),
		setTargetPhrases: ( ...args: unknown[] ) => mockSet( ...args ),
	},
	errorMessage: ( e: unknown, fallback: string ) =>
		e instanceof Error && e.message ? e.message : fallback,
} ) );
const mockSpeak = jest.fn();
jest.mock( '@wordpress/a11y', () => ( {
	speak: ( ...args: unknown[] ) => mockSpeak( ...args ),
} ) );
jest.mock( '@wordpress/components', () => {
	// eslint-disable-next-line @typescript-eslint/no-var-requires
	const { forwardRef } = require( 'react' );
	return {
		Button: forwardRef(
			(
				{
					children,
					onClick,
					disabled,
					...rest
				}: {
					children: unknown;
					onClick: () => void;
					disabled?: boolean;
				},
				ref: unknown
			) => (
				<button
					ref={ ref as never }
					type="button"
					disabled={ disabled }
					onClick={ onClick }
					aria-expanded={
						( rest as Record< string, boolean > )[ 'aria-expanded' ]
					}
				>
					{ children as never }
				</button>
			)
		),
		TextControl: ( {
			label,
			value,
			disabled,
			onChange,
		}: {
			label: string;
			disabled?: boolean;
			value: string;
			onChange: ( v: string ) => void;
		} ) => (
			<input
				aria-label={ label }
				disabled={ disabled }
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
		),
		TextareaControl: ( {
			label,
			value,
			disabled,
			onChange,
		}: {
			label: string;
			disabled?: boolean;
			value: string;
			onChange: ( v: string ) => void;
		} ) => (
			<textarea
				aria-label={ label }
				disabled={ disabled }
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
		),
		CheckboxControl: ( {
			label,
			checked,
			disabled,
			onChange,
		}: {
			label: string;
			disabled?: boolean;
			checked: boolean;
			onChange: ( v: boolean ) => void;
		} ) => (
			<input
				aria-label={ label }
				disabled={ disabled }
				type="checkbox"
				checked={ checked }
				onChange={ ( e ) => onChange( e.target.checked ) }
			/>
		),
	};
} );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const LIMITS = { phrases: 2, variants: 5, words: 8, chars: 120 };
const state = (
	phrases: TargetPhrasesState[ 'phrases' ] = []
): TargetPhrasesState => ( {
	post_id: 5,
	phrases,
	removed: [],
	limits: LIMITS,
} );

async function mount(): Promise< HTMLElement > {
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	await act( async () => {
		createRoot( host ).render( <TargetPhrasesBox postId={ 5 } /> );
	} );
	return host;
}

const button = ( host: HTMLElement, name: string ) =>
	Array.from( host.querySelectorAll( 'button' ) ).find(
		( b ) => b.textContent?.includes( name )
	) as HTMLButtonElement;

async function type(
	input: HTMLInputElement | HTMLTextAreaElement,
	v: string
) {
	const proto =
		input instanceof HTMLTextAreaElement
			? HTMLTextAreaElement.prototype
			: HTMLInputElement.prototype;
	await act( async () => {
		Object.getOwnPropertyDescriptor( proto, 'value' )?.set?.call(
			input,
			v
		);
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

describe( 'TargetPhrasesBox', () => {
	beforeEach( () => {
		mockGet.mockReset();
		mockSet.mockReset();
		mockSpeak.mockReset();
	} );

	it( 'no pide nada al servidor hasta abrir la sección y la abre con el botón', async () => {
		mockGet.mockResolvedValue( state() );
		const host = await mount();
		expect( mockGet ).not.toHaveBeenCalled();
		const toggle = button( host, 'Target phrases' );
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'false' );

		await act( async () => {
			toggle.click();
		} );
		expect( mockGet ).toHaveBeenCalledTimes( 1 );
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
	} );

	it( 'añade una frase con variantes y hueco, y la guarda tal cual', async () => {
		mockGet.mockResolvedValue( state() );
		mockSet.mockResolvedValue(
			state( [
				{
					phrase: 'reforma de cocina',
					variants: [ 'obra de cocina' ],
					gap: true,
				},
			] )
		);
		const host = await mount();
		await act( async () => {
			button( host, 'Target phrases' ).click();
		} );
		expect( button( host, 'Save phrases' ).disabled ).toBe( true );

		await act( async () => {
			button( host, 'Add phrase' ).click();
		} );
		expect( button( host, 'Save phrases' ).disabled ).toBe( false );
		await type(
			host.querySelector( 'input:not([type])' ) as HTMLInputElement,
			'reforma de cocina'
		);
		await type(
			host.querySelector( 'textarea' ) as HTMLTextAreaElement,
			'obra de cocina'
		);
		await act( async () => {
			(
				host.querySelector( 'input[type=checkbox]' ) as HTMLElement
			 ).click();
		} );
		await act( async () => {
			button( host, 'Save phrases' ).click();
		} );

		expect( mockSet ).toHaveBeenCalledWith( 5, [
			{
				phrase: 'reforma de cocina',
				variants: [ 'obra de cocina' ],
				gap: true,
			},
		] );
		expect( host.querySelector( '[role="status"]' )?.textContent ).toBe(
			'Target phrases saved: 1 phrase.'
		);
		expect( button( host, 'Save phrases' ).disabled ).toBe( true );
	} );

	it( 'respeta el máximo de frases que fija el servidor y quita una con el foco en Añadir', async () => {
		mockGet.mockResolvedValue(
			state( [
				{ phrase: 'uno', variants: [], gap: false },
				{ phrase: 'dos', variants: [], gap: false },
			] )
		);
		const host = await mount();
		await act( async () => {
			button( host, 'Target phrases' ).click();
		} );
		expect( button( host, 'Add phrase' ).disabled ).toBe( true );

		await act( async () => {
			button( host, 'Remove phrase 1' ).click();
		} );
		expect( host.querySelectorAll( 'fieldset' ) ).toHaveLength( 1 );
		expect( button( host, 'Add phrase' ).disabled ).toBe( false );
		expect( button( host, 'Save phrases' ).disabled ).toBe( false );
	} );

	it( 'si el servidor rechaza la lista enseña su mensaje en un aviso y conserva lo escrito', async () => {
		mockGet.mockResolvedValue(
			state( [ { phrase: 'a', variants: [], gap: false } ] )
		);
		mockSet.mockRejectedValue( new Error( 'Too long.' ) );
		const host = await mount();
		await act( async () => {
			button( host, 'Target phrases' ).click();
		} );
		await type(
			host.querySelector( 'input:not([type])' ) as HTMLInputElement,
			'ab'
		);
		await act( async () => {
			button( host, 'Save phrases' ).click();
		} );
		expect( host.querySelector( '[role="alert"]' )?.textContent ).toBe(
			'Too long.'
		);
		expect(
			( host.querySelector( 'input:not([type])' ) as HTMLInputElement )
				.value
		).toBe( 'ab' );
	} );

	it( 'quita las líneas vacías antes de enviar y deja lo repetido al servidor', async () => {
		mockGet.mockResolvedValue( state() );
		mockSet.mockResolvedValue( state() );
		const host = await mount();
		await act( async () => {
			button( host, 'Target phrases' ).click();
		} );
		await act( async () => {
			button( host, 'Add phrase' ).click();
		} );
		await type(
			host.querySelector( 'input:not([type])' ) as HTMLInputElement,
			'principal'
		);
		await type(
			host.querySelector( 'textarea' ) as HTMLTextAreaElement,
			'uno\n\n  dos  \nUNO\n   \ntres'
		);
		await act( async () => {
			button( host, 'Save phrases' ).click();
		} );
		expect( mockSet ).toHaveBeenCalledWith( 5, [
			{
				phrase: 'principal',
				variants: [ 'uno', 'dos', 'UNO', 'tres' ],
				gap: false,
			},
		] );
	} );

	it( 'mientras se guarda no se puede editar, y después avisa de lo que el servidor quitó', async () => {
		mockGet.mockResolvedValue(
			state( [ { phrase: 'a', variants: [], gap: false } ] )
		);
		let finish: ( value: TargetPhrasesState ) => void = () => undefined;
		mockSet.mockReturnValue(
			new Promise< TargetPhrasesState >( ( resolve ) => {
				finish = resolve;
			} )
		);
		const host = await mount();
		await act( async () => {
			button( host, 'Target phrases' ).click();
		} );
		await type(
			host.querySelector( 'input:not([type])' ) as HTMLInputElement,
			'ab'
		);
		await act( async () => {
			button( host, 'Save phrases' ).click();
		} );

		for ( const field of Array.from(
			host.querySelectorAll( 'input, textarea' )
		) ) {
			expect( ( field as HTMLInputElement ).disabled ).toBe( true );
		}
		expect( button( host, 'Remove phrase 1' ).disabled ).toBe( true );

		await act( async () => {
			finish( {
				...state( [ { phrase: 'ab', variants: [], gap: false } ] ),
				removed: [ 'Obra' ],
			} );
		} );
		expect(
			host.querySelector( '[role="status"]' )?.textContent
		).toContain( 'Not kept because they were repeated: “Obra”.' );
		expect(
			( host.querySelector( 'input:not([type])' ) as HTMLInputElement )
				.disabled
		).toBe( false );
	} );

	it( 'anuncia el resultado una sola vez: por la región de estado, sin speak()', async () => {
		mockGet.mockResolvedValue(
			state( [ { phrase: 'a', variants: [], gap: false } ] )
		);
		mockSet.mockResolvedValue(
			state( [ { phrase: 'ab', variants: [], gap: false } ] )
		);
		const host = await mount();
		await act( async () => {
			button( host, 'Target phrases' ).click();
		} );
		await type(
			host.querySelector( 'input:not([type])' ) as HTMLInputElement,
			'ab'
		);
		await act( async () => {
			button( host, 'Save phrases' ).click();
		} );
		expect(
			host.querySelector( '[role="status"]' )?.textContent
		).toContain( 'saved' );
		expect( mockSpeak ).not.toHaveBeenCalled();
	} );

	it( 'avisa junto al campo de demasiadas variantes o de un texto largo y no envía', async () => {
		mockGet.mockResolvedValue( state() );
		const host = await mount();
		await act( async () => {
			button( host, 'Target phrases' ).click();
		} );
		await act( async () => {
			button( host, 'Add phrase' ).click();
		} );
		await type(
			host.querySelector( 'input:not([type])' ) as HTMLInputElement,
			'principal'
		);
		await type(
			host.querySelector( 'textarea' ) as HTMLTextAreaElement,
			'a1\nb2\nc3\nd4\ne5\nf6\n\n'
		);
		expect( host.querySelector( 'fieldset' )?.textContent ).toContain(
			'Each phrase can have up to 5 variants.'
		);
		expect( button( host, 'Save phrases' ).disabled ).toBe( true );

		await type(
			host.querySelector( 'textarea' ) as HTMLTextAreaElement,
			'x'.repeat( 121 )
		);
		expect( host.querySelector( 'fieldset' )?.textContent ).toContain(
			'is too long: a phrase can have up to 120 characters.'
		);
		expect( button( host, 'Save phrases' ).disabled ).toBe( true );
		expect( mockSet ).not.toHaveBeenCalled();

		await type(
			host.querySelector( 'textarea' ) as HTMLTextAreaElement,
			'ok'
		);
		expect( button( host, 'Save phrases' ).disabled ).toBe( false );
	} );

	it( 'al cargar bien después de un fallo, quita el aviso de error', async () => {
		mockGet.mockRejectedValueOnce( new Error( 'Boom' ) );
		mockGet.mockResolvedValue( state() );
		const host = await mount();
		await act( async () => {
			button( host, 'Target phrases' ).click();
		} );
		expect( host.querySelector( '[role="alert"]' )?.textContent ).toBe(
			'Boom'
		);
		await act( async () => {
			button( host, 'Target phrases' ).click();
		} );
		await act( async () => {
			button( host, 'Target phrases' ).click();
		} );
		expect( host.querySelector( '[role="alert"]' ) ).toBeNull();
	} );
} );
