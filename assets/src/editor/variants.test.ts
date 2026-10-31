import type { Suggestion, SuggestionAlternative } from '../types';
import { suggestionKey } from './keys';
import {
	applyChoice,
	signature,
	byteLength,
	displacements,
	resolve,
	resolveAll,
	spanForWords,
	wordRange,
	wordsOf,
	type Choices,
} from './variants';

const post = ( id: number ) => ( { id, title: `Post ${ id }` } );

function alt(
	sentence: string,
	anchor: string,
	paragraph = 0,
	postId = 1
): SuggestionAlternative {
	const start = sentence.indexOf( anchor );
	const before = sentence.slice( 0, start );
	return {
		anchor,
		sentence,
		offset: byteLength( before ),
		before,
		after: sentence.slice( start + anchor.length ),
		anchor_start: start,
		anchor_end: start + anchor.length,
		paragraph,
		score: 0.5,
		insert: {
			post_id: postId,
			target_id: 9,
			sentence,
			offset: byteLength( before ),
			anchor,
			block_path: null,
		},
	};
}

function suggestion(
	target: number,
	main: SuggestionAlternative,
	alternatives: SuggestionAlternative[] = []
): Suggestion {
	return {
		...main,
		source: post( 1 ),
		target: post( target ),
		can_insert: true,
		reasons: [],
		alternatives,
	};
}

const S1 = 'La bomba de calor aerotérmica ahorra mucho en casa.';
const S2 = 'Una bomba de calor bien dimensionada ahorra energía.';
const S3 = 'Con la bomba de calor el consumo baja bastante.';

describe( 'words', () => {
	it( 'finds the words of a sentence with accents, apostrophes and hyphens', () => {
		const words = wordsOf( "Ñoño's e-mail llegó, ¡ya! 3,5 años" );
		expect( words.map( ( w ) => w.text ) ).toEqual( [
			'Ñoño',
			's',
			'e-mail',
			'llegó',
			'ya',
			'3,5',
			'años',
		] );
	} );

	it( 'maps an anchor span to the words it covers and back', () => {
		const words = wordsOf( S1 );
		const start = S1.indexOf( 'bomba' );
		const end = S1.indexOf( 'aerotérmica' ) + 'aerotérmica'.length;
		const range = spanForWords( words, start, end );
		expect( range ).toEqual( { first: 1, last: 4 } );
		expect( wordRange( words, range.first, range.last ) ).toEqual( {
			start,
			end,
		} );
	} );

	it( 'counts the bytes of UTF-8 like the server does', () => {
		expect( byteLength( 'niña 🍕' ) ).toBe( 10 );
		expect( byteLength( '' ) ).toBe( 0 );
	} );
} );

// Los mismos casos que `AnchorCheckTest::unicode_words()`: lo que el ajustador entiende por palabra es lo que
// el servidor entiende por límite de palabra.
const UNICODE_CASES: Array< [ string, string, string, boolean ] > = [
	[
		'NFD entero',
		'Un cafe\u0301 caliente sirve.',
		'cafe\u0301 caliente',
		true,
	],
	[
		'NFD corta la marca',
		'Un cafe\u0301 caliente sirve.',
		'e\u0301 caliente',
		false,
	],
	[
		'NFD acaba en la marca',
		'Un cafe\u0301 caliente sirve.',
		'Un cafe',
		false,
	],
	[ 'hindi entero', 'यह नमस्ते दुनिया है', 'नमस्ते दुनिया', true ],
	[ 'hindi corta la sílaba', 'यह नमस्ते दुनिया है', 'ते दुनिया', false ],
	[
		'francés tras apóstrofo',
		"Il aime l'école publique.",
		'école publique',
		true,
	],
	[
		'francés con apóstrofo',
		"Il aime l'école publique.",
		"l'école publique",
		true,
	],
	[
		'francés corta la palabra',
		"Il aime l'école publique.",
		'cole publique',
		false,
	],
];

describe( 'words like the server', () => {
	it.each( UNICODE_CASES )( '%s', ( _name, sentence, anchor, boundaries ) => {
		const words = wordsOf( sentence );
		const start = sentence.indexOf( anchor );
		const end = start + anchor.length;
		const range = wordRange(
			words,
			spanForWords( words, start, end ).first,
			spanForWords( words, start, end ).last
		);
		expect( range.start === start && range.end === end ).toBe( boundaries );
	} );

	it( 'splits at apostrophes and keeps combining marks inside the word', () => {
		expect( wordsOf( "l'école" ).map( ( w ) => w.text ) ).toEqual( [
			'l',
			'école',
		] );
		expect( wordsOf( 'cafe\u0301 noir' ).map( ( w ) => w.text ) ).toEqual( [
			'cafe\u0301',
			'noir',
		] );
		expect( wordsOf( 'नमस्ते दुनिया' ) ).toHaveLength( 2 );
	} );
} );

describe( 'applyChoice', () => {
	const main = alt( S1, 'bomba de calor' );
	const first = alt( S2, 'bomba de calor' );
	const item = suggestion( 9, main, [ first, alt( S3, 'bomba de calor' ) ] );

	it( 'leaves the suggestion as it is without a choice', () => {
		expect( applyChoice( item, undefined ) ).toBe( item );
		expect(
			applyChoice( item, { option: 0, seq: 1, sig: signature( item ) } )
		).toBe( item );
	} );

	it( 'shows an alternative sentence and inserts with it', () => {
		const chosen = applyChoice( item, {
			option: 1,
			seq: 1,
			sig: signature( item ),
		} );
		expect( chosen.sentence ).toBe( S2 );
		expect( chosen.before ).toBe( 'Una ' );
		expect( chosen.insert ).toEqual( first.insert );
		expect( chosen.target ).toBe( item.target );
		expect( chosen.alternatives ).toBe( item.alternatives );
	} );

	it( 'adjusts the anchor inside the chosen sentence and tells the server', () => {
		const start = S2.indexOf( 'bomba' );
		const end = S2.indexOf( 'dimensionada' ) + 'dimensionada'.length;
		const chosen = applyChoice( item, {
			option: 1,
			seq: 1,
			sig: signature( item ),
			span: { start, end },
		} );
		expect( chosen.anchor ).toBe( 'bomba de calor bien dimensionada' );
		expect( chosen.before ).toBe( 'Una ' );
		expect( chosen.after ).toBe( ' ahorra energía.' );
		expect( chosen.anchor_start ).toBe( start );
		expect( chosen.anchor_end ).toBe( end );
		expect( chosen.offset ).toBe( 4 );
		expect( chosen.insert ).toEqual( {
			...first.insert,
			anchor: 'bomba de calor bien dimensionada',
			offset: 4,
			adjusted: true,
		} );
	} );

	it( 'counts the offset in bytes, not in characters', () => {
		const sentence = 'La niña 🍕 compró una bomba de calor muy buena.';
		const withEmoji = suggestion( 9, alt( sentence, 'bomba de calor' ) );
		const start = sentence.indexOf( 'bomba' );
		const chosen = applyChoice( withEmoji, {
			option: 0,
			seq: 1,
			sig: '',
			span: { start, end: sentence.indexOf( 'muy' ) + 3 },
		} );
		expect( chosen.insert?.offset ).toBe(
			byteLength( 'La niña 🍕 compró una ' )
		);
		expect( chosen.anchor_start ).toBe( start );
	} );

	it( 'has no insert when the user cannot edit the source', () => {
		const readOnly = { ...item, can_insert: false, insert: undefined };
		const chosen = applyChoice( readOnly, {
			option: 0,
			seq: 1,
			sig: '',
			span: { start: 0, end: 2 },
		} );
		expect( chosen.insert ).toBeUndefined();
	} );
} );

describe( 'card keys', () => {
	it( 'tells apart two suggestions to the same target by their engine sentence', () => {
		const one = suggestion( 9, alt( S1, 'bomba de calor', 0 ) );
		const two = suggestion( 9, alt( S2, 'bomba de calor', 1 ) );
		expect( suggestionKey( one ) ).not.toBe( suggestionKey( two ) );
	} );

	it( 'keeps the key of the engine suggestion whatever variant is shown', () => {
		const item = suggestion( 9, alt( S1, 'bomba de calor' ), [
			alt( S2, 'bomba de calor', 1 ),
		] );
		const key = suggestionKey( item );
		const shown = applyChoice(
			item,
			{
				option: 1,
				seq: 1,
				sig: signature( item ),
				span: { start: 5, end: 10 },
			},
			key
		);
		expect( shown.offset ).not.toBe( item.offset );
		expect( suggestionKey( shown ) ).toBe( key );
	} );
} );

describe( 'frozen cards and shared anchors', () => {
	const T1 = 'La bomba de calor ahorra mucho en casa.';
	const T2 = 'El consumo baja con una buena bomba de calor.';
	const T3 = 'Revisar la caldera cada año evita averías costosas.';
	const mk = (
		target: number,
		main: SuggestionAlternative,
		alts: SuggestionAlternative[] = []
	) => suggestion( target, main, alts );
	const keyOf = suggestionKey;

	it( 'never takes the sentence from a card that was linked or dismissed', () => {
		const a = mk( 9, alt( T1, 'bomba de calor', 0 ) );
		const b = mk( 8, alt( T3, 'caldera', 2 ), [
			alt( T1, 'bomba de calor', 0 ),
		] );
		const choices: Choices = {
			[ keyOf( b ) ]: { option: 1, seq: 5, sig: signature( b ) },
		};
		const free = resolveAll( [ a, b ], choices, keyOf );
		expect( free.options.get( keyOf( b ) ) ).toBe( 1 );
		expect( free.options.get( keyOf( a ) ) ).toBeNull();

		const frozen = resolveAll(
			[ a, b ],
			choices,
			keyOf,
			new Set( [ keyOf( a ) ] )
		);
		expect( frozen.options.get( keyOf( a ) ) ).toBe( 0 );
		expect( frozen.options.get( keyOf( b ) ) ).toBe( 0 );
	} );

	it( 'gives the shared anchor to the most recent choice and moves the other card', () => {
		const a = mk( 9, alt( T1, 'bomba de calor', 0 ), [
			alt( T3, 'caldera', 2 ),
		] );
		const b = mk( 8, alt( T2, 'consumo', 1 ), [
			alt( T2.replace( 'consumo', 'Consumo' ), 'bomba de calor', 1 ),
		] );
		const choices: Choices = {
			[ keyOf( b ) ]: { option: 1, seq: 3, sig: signature( b ) },
		};
		const out = resolveAll( [ a, b ], choices, keyOf );
		expect( out.options.get( keyOf( b ) ) ).toBe( 1 );
		// a vuelve a una frase con otra ancla.
		expect( out.options.get( keyOf( a ) ) ).toBe( 1 );
	} );

	it( 'drops an adjusted span that repeats another anchor and hides a card with nowhere to go', () => {
		const a = mk( 9, alt( T1, 'Bomba de CALOR', 0 ) );
		const b = mk( 8, alt( T2, 'consumo', 1 ) );
		const start = T2.indexOf( 'bomba' );
		const choices: Choices = {
			[ keyOf( b ) ]: {
				option: 0,
				seq: 4,
				sig: signature( b ),
				span: { start, end: start + 'bomba de calor'.length },
			},
		};
		const out = resolveAll( [ a, b ], choices, keyOf );
		// b es la elección más reciente y se queda con el ancla; a no tiene otra frase.
		expect( out.spans.get( keyOf( b ) ) ).toEqual( {
			start,
			end: start + 14,
		} );
		expect( out.options.get( keyOf( a ) ) ).toBeNull();
	} );

	it( 'rejects the span when the card that has the anchor is frozen', () => {
		const a = mk( 9, alt( T1, 'bomba de calor', 0 ) );
		const b = mk( 8, alt( T2, 'consumo', 1 ) );
		const start = T2.indexOf( 'bomba' );
		const choices: Choices = {
			[ keyOf( b ) ]: {
				option: 0,
				seq: 4,
				sig: signature( b ),
				span: { start, end: start + 14 },
			},
		};
		const out = resolveAll(
			[ a, b ],
			choices,
			keyOf,
			new Set( [ keyOf( a ) ] )
		);
		expect( out.options.get( keyOf( a ) ) ).toBe( 0 );
		expect( out.spans.has( keyOf( b ) ) ).toBe( false );
	} );

	it( 'does not mind the same anchor towards the same target', () => {
		const a = mk( 9, alt( T1, 'bomba de calor', 0 ) );
		const b = mk( 9, alt( T2, 'bomba de calor', 1 ) );
		const out = resolveAll( [ a, b ], {}, keyOf );
		expect( out.options.get( keyOf( a ) ) ).toBe( 0 );
		expect( out.options.get( keyOf( b ) ) ).toBe( 0 );
	} );

	it( 'compares anchors without case, accents or extra spaces', () => {
		const a = mk(
			9,
			alt( 'La  calefacción central.', 'calefacción  central', 0 )
		);
		const b = mk(
			8,
			alt( 'Una calefaccion Central barata.', 'calefaccion Central', 1 )
		);
		const out = resolveAll( [ a, b ], {}, keyOf );
		// Sin elecciones del usuario ninguna es más reciente: gana la que va primero.
		expect( out.options.get( keyOf( a ) ) ).toBe( 0 );
		expect( out.options.get( keyOf( b ) ) ).toBeNull();
	} );
} );

describe( 'resolve', () => {
	const a = suggestion( 9, alt( S1, 'bomba de calor', 0 ), [
		alt( S2, 'bomba de calor', 1 ),
		alt( S3, 'bomba de calor', 2 ),
	] );
	// La principal de b es la segunda frase de a.
	const b = suggestion( 8, alt( S2, 'calefacción', 1 ), [
		alt( S3, 'consumo', 2 ),
	] );
	const c = suggestion( 7, alt( S3, 'consumo', 2 ) );
	const keyOf = suggestionKey;

	it( 'keeps everything as the engine said without choices', () => {
		const out = resolve( [ a, b ], {}, keyOf );
		expect( out.get( keyOf( a ) ) ).toBe( 0 );
		expect( out.get( keyOf( b ) ) ).toBe( 0 );
	} );

	it( 'moves the other suggestion to its next free sentence when one takes its sentence', () => {
		const choices: Choices = {
			[ keyOf( a ) ]: { option: 1, seq: 1, sig: signature( a ) },
		};
		const out = resolve( [ a, b ], choices, keyOf );
		expect( out.get( keyOf( a ) ) ).toBe( 1 );
		expect( out.get( keyOf( b ) ) ).toBe( 1 );
	} );

	it( 'hides the other suggestion when it has no free sentence left', () => {
		const choices: Choices = {
			[ keyOf( a ) ]: { option: 1, seq: 1, sig: signature( a ) },
		};
		const out = resolve( [ a, c, b ], choices, keyOf );
		// c usa S3, b pasaría a S3 pero c la tiene: b desaparece.
		expect( out.get( keyOf( c ) ) ).toBe( 0 );
		expect( out.get( keyOf( b ) ) ).toBeNull();
	} );

	it( 'lets the latest choice win when two suggestions want the same sentence', () => {
		const choices: Choices = {
			[ keyOf( a ) ]: { option: 1, seq: 1, sig: signature( a ) },
			[ keyOf( b ) ]: { option: 0, seq: 2, sig: signature( b ) },
		};
		const out = resolve( [ a, b ], choices, keyOf );
		expect( out.get( keyOf( b ) ) ).toBe( 0 );
		expect( out.get( keyOf( a ) ) ).toBe( 0 );
	} );

	it( 'ignores a choice made for a list that has changed', () => {
		const stale: Choices = {
			[ keyOf( a ) ]: { option: 1, seq: 1, sig: 'otra frase' },
		};
		expect( resolve( [ a, b ], stale, keyOf ).get( keyOf( a ) ) ).toBe( 0 );
		// La misma sugerencia con las frases en otro orden tampoco hereda la elección.
		const reordered = suggestion( 9, alt( S2, 'bomba de calor', 1 ), [
			alt( S1, 'bomba de calor', 0 ),
		] );
		const choices: Choices = {
			[ keyOf( a ) ]: { option: 1, seq: 1, sig: signature( a ) },
		};
		expect( signature( reordered ) ).not.toBe( signature( a ) );
		expect(
			resolve( [ reordered ], choices, keyOf ).get( keyOf( reordered ) )
		).toBe( 0 );
	} );

	it( 'reports who moved or disappeared because of a choice', () => {
		const before = resolve( [ a, c, b ], {}, keyOf );
		const after = resolve(
			[ a, c, b ],
			{ [ keyOf( a ) ]: { option: 1, seq: 1, sig: signature( a ) } },
			keyOf
		);
		expect( displacements( before, after, keyOf( a ) ) ).toEqual( [
			{ key: keyOf( b ), kind: 'removed' },
		] );
		const withoutC = resolve(
			[ a, b ],
			{ [ keyOf( a ) ]: { option: 1, seq: 1, sig: signature( a ) } },
			keyOf
		);
		expect(
			displacements(
				resolve( [ a, b ], {}, keyOf ),
				withoutC,
				keyOf( a )
			)
		).toEqual( [ { key: keyOf( b ), kind: 'moved' } ] );
	} );
} );
