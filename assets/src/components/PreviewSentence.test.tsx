import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { PreviewSentence } from './PreviewSentence';

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

function text( before: string, anchor: string, after: string ): string {
	const host = document.createElement( 'p' );
	act( () => {
		createRoot( host ).render(
			<PreviewSentence
				before={ before }
				anchor={ anchor }
				after={ after }
			/>
		);
	} );
	return host.textContent ?? '';
}

describe( 'PreviewSentence', () => {
	it( 'pinta el contexto tal cual: sin espacio antes de la coma', () => {
		expect( text( 'Esta guía está ', 'completa', ', ten' ) ).toBe(
			'Esta guía está completa, ten'
		);
	} );

	it( 'conserva el espacio cuando el servidor lo manda', () => {
		expect( text( 'Lee la ', 'guía', ' antes de empezar' ) ).toBe(
			'Lee la guía antes de empezar'
		);
	} );

	it( 'no separa elisiones, comillas ni la tipografía francesa', () => {
		expect( text( "Sobre l'", 'energia', ' solar' ) ).toBe(
			"Sobre l'energia solar"
		);
		expect( text( 'Dit « ', 'énergie', ' » !' ) ).toBe(
			'Dit « énergie » !'
		);
		expect( text( 'Mira (', 'este tema', ').' ) ).toBe(
			'Mira (este tema).'
		);
	} );

	it( 'admite que no haya texto alrededor', () => {
		expect( text( '', 'ancla', '' ) ).toBe( 'ancla' );
	} );
} );
