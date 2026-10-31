import * as wpI18n from '@wordpress/i18n';
import { classicAdapter } from '../adapters/classic';

const options = {
	linkIn: { headings: false, quotes: false, tables: false },
	linkAttrs: {},
	terms: {
		category: {
			taxonomy: 'category',
			field: 'categories',
			hierarchical: true,
		},
		post_tag: { taxonomy: 'post_tag', field: 'tags', hierarchical: false },
	},
} as const;

describe( 'classicAdapter.getTerms', () => {
	it( 'lee las categorías marcadas y las etiquetas escritas en la caja, sin guardar', () => {
		document.body.innerHTML = `
			<ul id="categorychecklist">
			<li><input type="checkbox" name="post_category[]" value="4" checked></li>
			<li><input type="checkbox" name="post_category[]" value="7"></li>
			<li><input type="checkbox" name="post_category[]" value="9" checked></li>
			</ul>
			<textarea name="tax_input[post_tag]">sol, aire ,</textarea>`;
		expect( classicAdapter( 5, options ).getTerms() ).toEqual( {
			ids: { category: [ 4, 9 ] },
			names: { post_tag: [ 'sol', 'aire' ] },
		} );
	} );

	it( 'las etiquetas numéricas siguen siendo nombres, nunca IDs', () => {
		document.body.innerHTML =
			'<textarea name="tax_input[post_tag]">2026, 5</textarea>';
		expect( classicAdapter( 5, options ).getTerms()?.names ).toEqual( {
			post_tag: [ '2026', '5' ],
		} );
		expect( classicAdapter( 5, options ).getTerms()?.ids ).toEqual( {} );
	} );

	it( 'sin la caja de categorías no envía categorías: mandan las guardadas', () => {
		document.body.innerHTML =
			'<textarea name="tax_input[post_tag]">sol</textarea>';
		expect( classicAdapter( 5, options ).getTerms()?.ids ).toEqual( {} );
		document.body.innerHTML = '';
		expect( classicAdapter( 5, options ).getTerms() ).toBeUndefined();
	} );

	it( 'una caja de categorías sin nada marcado sí envía una lista vacía', () => {
		document.body.innerHTML = '<ul id="categorychecklist"></ul>';
		expect( classicAdapter( 5, options ).getTerms()?.ids ).toEqual( {
			category: [],
		} );
	} );

	it( 'usa el delimitador de etiquetas del idioma, como el núcleo', () => {
		document.body.innerHTML =
			'<textarea name="tax_input[post_tag]">sol、aire,mar</textarea>';
		( wpI18n as { setLocaleData: ( d: unknown ) => void } ).setLocaleData( {
			'': {},
			'tag delimiter\u0004,': [ '、' ],
		} );
		expect( classicAdapter( 5, options ).getTerms()?.names ).toEqual( {
			post_tag: [ 'sol', 'aire,mar' ],
		} );
	} );

	it( 'sin taxonomías en el arranque no envía nada', () => {
		expect(
			classicAdapter( 5, {
				linkIn: options.linkIn,
				linkAttrs: {},
			} ).getTerms()
		).toBeUndefined();
	} );
} );
