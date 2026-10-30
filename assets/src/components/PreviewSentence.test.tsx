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
	it( 'no deja un espacio antes de la coma que sigue a la frase', () => {
		expect( text( 'Esta guía está', 'completa', ', ten' ) ).toBe(
			'Esta guía está completa, ten'
		);
	} );

	it( 'separa con un espacio en el caso normal', () => {
		expect( text( 'Lee la', 'guía de enlaces', 'antes de empezar' ) ).toBe(
			'Lee la guía de enlaces antes de empezar'
		);
	} );

	it( 'no separa de un paréntesis de apertura ni de un cierre', () => {
		expect( text( 'Mira (', 'este tema', ').' ) ).toBe(
			'Mira (este tema).'
		);
	} );

	it( 'admite que no haya texto alrededor', () => {
		expect( text( '', 'ancla', '' ) ).toBe( 'ancla' );
	} );
} );
