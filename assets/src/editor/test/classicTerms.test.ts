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
			<input type="checkbox" name="post_category[]" value="4" checked>
			<input type="checkbox" name="post_category[]" value="7">
			<input type="checkbox" name="post_category[]" value="9" checked>
			<textarea name="tax_input[post_tag]">sol, aire ,</textarea>`;
		expect( classicAdapter( 5, options ).getTerms() ).toEqual( {
			category: [ 4, 9 ],
			post_tag: [ 'sol', 'aire' ],
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
