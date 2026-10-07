import { locate, normalize } from '../locate';

const target = {
	before: 'La instalación de una ',
	anchor: 'bomba de calor',
	after: ' reduce el consumo.',
};

describe( 'locate', () => {
	it( 'encuentra el ancla en su bloque', () => {
		const result = locate(
			[
				{ key: 'a', text: 'Otro párrafo.' },
				{
					key: 'b',
					text: 'Hola. La instalación de una bomba de calor reduce el consumo. Adiós.',
				},
			],
			target
		);
		expect( result ).toEqual( {
			status: 'found',
			key: 'b',
			start: 'Hola. La instalación de una '.length,
			end: 'Hola. La instalación de una bomba de calor'.length,
		} );
	} );

	it( 'trata los espacios de no ruptura y las secuencias como un solo espacio', () => {
		const text = 'La instalación  de una bomba de calor reduce el consumo.';
		const result = locate( [ { key: 'x', text } ], target );
		expect( result.status ).toBe( 'found' );
		if ( result.status === 'found' ) {
			expect( text.slice( result.start, result.end ) ).toBe(
				'bomba de calor'
			);
		}
	} );

	it( 'no encuentra el texto cambiado', () => {
		expect(
			locate(
				[ { key: 'x', text: 'La instalación de una caldera reduce.' } ],
				target
			)
		).toEqual( { status: 'not_found' } );
	} );

	it( 'rechaza lo ambiguo: el mismo contexto dos veces', () => {
		const text =
			'La instalación de una bomba de calor reduce el consumo. La instalación de una bomba de calor reduce el consumo.';
		expect( locate( [ { key: 'x', text } ], target ) ).toEqual( {
			status: 'ambiguous',
		} );
	} );

	it( 'cuenta bien con emojis y tildes (UTF-16)', () => {
		const text =
			'😀 La instalación de una bomba de calor reduce el consumo.';
		const result = locate( [ { key: 'x', text } ], target );
		expect( result.status ).toBe( 'found' );
		if ( result.status === 'found' ) {
			expect( text.slice( result.start, result.end ) ).toBe(
				'bomba de calor'
			);
		}
	} );

	it( 'normaliza recordando las posiciones originales', () => {
		const { norm, map } = normalize( 'a  b' );
		expect( norm ).toBe( 'a b' );
		expect( map ).toEqual( [ 0, 1, 3 ] );
	} );
} );
