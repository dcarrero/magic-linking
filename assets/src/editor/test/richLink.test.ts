import { findOwnLink } from '../richLink';

const link = ( url: string ) => [ { type: 'core/link', attributes: { url } } ];

describe( 'findOwnLink', () => {
	const text = 'Una bomba de calor.';

	it( 'encuentra el tramo exacto del ancla enlazada', () => {
		const formats = Array.from( { length: text.length }, ( _, i ) =>
			i >= 4 && i < 12 ? link( 'https://x/y' ) : undefined
		);
		expect(
			findOwnLink( text, formats, 'https://x/y', 'bomba de' )
		).toEqual( {
			start: 4,
			end: 12,
		} );
	} );

	it( 'no toca nada si el enlace cambió de alcance, de dirección o ya no está', () => {
		const wider = Array.from( { length: text.length }, ( _, i ) =>
			i >= 4 && i < 17 ? link( 'https://x/y' ) : undefined
		);
		expect(
			findOwnLink( text, wider, 'https://x/y', 'bomba de' )
		).toBeNull();
		const other = Array.from( { length: text.length }, ( _, i ) =>
			i >= 4 && i < 12 ? link( 'https://otro' ) : undefined
		);
		expect(
			findOwnLink( text, other, 'https://x/y', 'bomba de' )
		).toBeNull();
		expect(
			findOwnLink(
				text,
				new Array( text.length ).fill( undefined ),
				'https://x/y',
				'bomba de'
			)
		).toBeNull();
	} );
} );
