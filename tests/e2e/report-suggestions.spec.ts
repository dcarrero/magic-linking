import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { login, openHistoryByBatch, SCREEN, wp } from './wp';

/**
 * Añadir enlaces desde el informe (F1-26): sugerencias entrantes y salientes de una fila, una a una en el servidor,
 * con su Historial, su Deshacer, las cifras de la fila al día, permisos y accesibilidad.
 */

const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

interface Ids {
	bomba: number;
	origen: number;
	aislamiento: number;
}

/**
 * Tres entradas con sugerencias entre ellas, sin historial ni descartes y con el índice al día.
 */
function seedReport(): Ids {
	const out = wp(
		'eval',
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'pe-%' OR post_name LIKE 'e2e-%' OR post_name LIKE 'entrada-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		update_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_wizard_dismissed', '1' );
		if ( ! get_user_by( 'login', 'e2e_author' ) ) { wp_insert_user( array( 'user_login' => 'e2e_author', 'user_pass' => 'password', 'role' => 'author', 'user_email' => 'e2e_author@example.org' ) ); }
		$author = get_user_by( 'login', 'e2e_author' )->ID;
		$block = static fn( $t ) => "<!-- wp:paragraph -->\\n<p>$t</p>\\n<!-- /wp:paragraph -->";
		$make = static fn( $slug, $title, $content, $user = 1 ) => wp_insert_post( array( 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish', 'post_author' => $user ) );
		$bomba = $make( 'pe-bomba', 'Bomba de calor: cuánto se ahorra', $block( 'La bomba de calor aerotérmica extrae energía del aire exterior. Una bomba de calor bien dimensionada ahorra mucho en calefacción.' ) );
		$origen = $make( 'pe-origen', 'Guía de calefacción para casas', $block( 'La instalación de una bomba de calor reduce el consumo de energía en las casas con buen aislamiento.' ) . "\\n\\n" . $block( 'Otro párrafo con <strong>texto en negrita</strong> y calefacción de suelo radiante para toda la casa.' ) );
		$aislamiento = $make( 'pe-aislamiento', 'Aislamiento de casas', $block( 'Con una bomba de calor y un buen aislamiento, el consumo de energía baja mucho en cualquier casa moderna.' ), $author );
		echo wp_json_encode( array( 'bomba' => $bomba, 'origen' => $origen, 'aislamiento' => $aislamiento ) );
		`
	);
	const ids = JSON.parse( out.trim().split( '\n' ).pop() ?? '{}' ) as Ids;
	wp( 'magic-linking', 'index' );
	return ids;
}

/**
 * Contenido guardado de una entrada.
 * @param id Entrada.
 */
function content( id: number ): string {
	return wp( 'eval', `echo get_post( ${ id } )->post_content;` ).trim();
}

/**
 * Quien acaba de salir del editor deja el bloqueo `_edit_lock` vigente; aquí se simula que ya venció.
 * @param id Entrada.
 */
function releaseEditLock( id: number ): void {
	wp( 'eval', `delete_post_meta( ${ id }, '_edit_lock' );` );
}

/**
 * El mismo contenido sin el primer enlace: tiene que ser idéntico al original.
 * @param html Contenido.
 */
function withoutLink( html: string ): string {
	return html.replace( /<a href="[^"]*"[^>]*>([^<]*)<\/a>/, '$1' );
}

/**
 * Pasa axe (WCAG A y AA, 2.2) sobre una parte de la pantalla.
 * @param page     Página.
 * @param selector Parte.
 */
async function expectNoViolations(
	page: Page,
	selector = '.magiclinking-wrap'
): Promise< void > {
	await page.waitForFunction( () =>
		document
			.getAnimations()
			.every( ( animation ) => animation.playState !== 'running' )
	);
	const results = await new AxeBuilder( { page } )
		.include( selector )
		.withTags( AXE_TAGS )
		.analyze();
	expect( results.violations ).toEqual( [] );
}

/**
 * Fila del informe de una entrada.
 * @param page  Página.
 * @param title Título.
 */
function rowOf( page: Page, title: string ): Locator {
	// Con el panel abierto el resto de la página queda `aria-hidden`: los roles no lo ven, el CSS sí.
	return page
		.locator( '.magiclinking-wrap table tbody tr' )
		.filter( { hasText: title } );
}

/**
 * Abre el panel de sugerencias de una fila.
 * @param page      Página.
 * @param title     Título de la fila.
 * @param direction Entrantes o salientes.
 */
async function openDrawer(
	page: Page,
	title: string,
	direction: 'Inbound' | 'Outbound'
): Promise< Locator > {
	const row = rowOf( page, title );
	await row.hover();
	await row
		.getByRole( 'button', {
			name: new RegExp( `^${ direction } suggestions` ),
		} )
		.click();
	const dialog = page.getByRole( 'dialog', {
		name: `${ direction } suggestions: ${ title }`,
	} );
	await expect( dialog ).toBeVisible();
	await expect(
		dialog.locator( '.magiclinking-card' ).first()
	).toBeVisible();
	return dialog;
}

/**
 * Cifra de una columna de la fila (entrantes = 2, salientes internas = 3).
 * @param row   Fila.
 * @param index Columna.
 */
async function figure( row: Locator, index: number ): Promise< number > {
	return Number(
		( await row.locator( 'td' ).nth( index ).innerText() ).trim()
	);
}

test.describe( 'Add links from the report', () => {
	let ids: Ids;

	test.beforeEach( () => {
		ids = seedReport();
	} );

	test( 'outbound: adds a link on the server without opening the editor, updates the row and undoes it', async ( {
		page,
	} ) => {
		const before = content( ids.origen );
		await login( page );
		await page.goto( SCREEN );
		const row = rowOf( page, 'Guía de calefacción para casas' );
		await expect( row ).toBeVisible();
		const outbound = await figure( row, 3 );

		const dialog = await openDrawer(
			page,
			'Guía de calefacción para casas',
			'Outbound'
		);
		await expectNoViolations( page, '.components-modal__frame' );

		const card = dialog.locator( '.magiclinking-card' ).first();
		await expect( card.locator( 'mark' ) ).toBeVisible();
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( card ).toContainText( 'Linked' );
		await expect(
			card.getByRole( 'button', { name: /^Undo/ } )
		).toBeVisible();
		await expect( card.locator( 'p' ).first() ).toBeFocused();
		await expectNoViolations( page, '.components-modal__frame' );

		// Escrito en el servidor, solo el enlace, y la fila de detrás ya lo cuenta.
		const after = content( ids.origen );
		expect( after ).toContain( '<a href=' );
		expect( withoutLink( after ) ).toBe( before );
		await expect.poll( () => figure( row, 3 ) ).toBe( outbound + 1 );

		// Deshacer desde la misma fila deja el contenido como estaba y la fila con su cifra.
		await card.getByRole( 'button', { name: /^Undo the link to/ } ).click();
		await expect(
			card.getByRole( 'button', { name: /^Link to/ } )
		).toBeVisible();
		expect( content( ids.origen ) ).toBe( before );
		await expect.poll( () => figure( row, 3 ) ).toBe( outbound );
		releaseEditLock( ids.origen );

		// Se vuelve a añadir; cerrar con Esc devuelve el foco a la acción de la fila y el Historial lo tiene.
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( card ).toContainText( 'Linked' );
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toHaveCount( 0 );
		await expect(
			rowOf( page, 'Guía de calefacción para casas' ).getByRole(
				'button',
				{
					name: /^Outbound suggestions/,
				}
			)
		).toBeFocused();
		await openHistoryByBatch( page );
		await expect(
			page.locator( '.magiclinking-batch' ).first()
		).toContainText( '1 link added' );
	} );

	test( 'inbound: links from another entry, refreshes the row, undoes and the content is back byte for byte', async ( {
		page,
	} ) => {
		const before = content( ids.origen );
		await login( page );
		await page.goto( SCREEN );
		const row = rowOf( page, 'Aislamiento de casas' );
		const inbound = await figure( row, 2 );

		const dialog = await openDrawer(
			page,
			'Aislamiento de casas',
			'Inbound'
		);
		await expectNoViolations( page, '.components-modal__frame' );
		const card = dialog.locator( '.magiclinking-card' ).first();
		await expect( card ).toContainText( 'Guía de calefacción para casas' );
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect(
			card.getByRole( 'button', { name: /^Undo the link in/ } )
		).toBeVisible();
		await expect(
			card.getByRole( 'link', { name: 'See in History' } )
		).toBeVisible();

		expect( withoutLink( content( ids.origen ) ) ).toBe( before );
		await expect.poll( () => figure( row, 2 ) ).toBe( inbound + 1 );
		await expectNoViolations( page, '.components-modal__frame' );

		await card.getByRole( 'button', { name: /^Undo the link in/ } ).click();
		await expect(
			card.getByRole( 'button', { name: /^Link to/ } )
		).toBeVisible();
		await expect( card ).toBeFocused();
		expect( content( ids.origen ) ).toBe( before );
		await expect.poll( () => figure( row, 2 ) ).toBe( inbound );
	} );

	test( 'dismissing in the panel is saved and can be undone', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( SCREEN );
		const dialog = await openDrawer(
			page,
			'Aislamiento de casas',
			'Inbound'
		);
		const card = dialog.locator( '.magiclinking-card' ).first();
		await card.getByRole( 'button', { name: /^Dismiss/ } ).click();
		await expect( card ).toContainText( 'Dismissed' );
		expect( content( ids.origen ) ).not.toContain( '<a href=' );

		await card.getByRole( 'button', { name: /^Undo dismissing/ } ).click();
		await expect(
			card.getByRole( 'button', { name: /^Link to/ } )
		).toBeVisible();
		await expect( card ).toBeFocused();
	} );

	test( 'has no checkboxes and no add-all button', async ( { page } ) => {
		await login( page );
		await page.goto( SCREEN );
		await expect( rowOf( page, 'Aislamiento de casas' ) ).toBeVisible();
		const dialog = await openDrawer(
			page,
			'Aislamiento de casas',
			'Inbound'
		);
		await expect( page.getByRole( 'checkbox' ) ).toHaveCount( 0 );
		await expect(
			dialog.getByRole( 'button', { name: /all/i } )
		).toHaveCount( 0 );
	} );

	test( 'refuses with a clear message when the entry changed while the panel was open, and writes nothing', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( SCREEN );
		const dialog = await openDrawer(
			page,
			'Aislamiento de casas',
			'Inbound'
		);

		wp(
			'eval',
			`global $wpdb; $wpdb->query( "UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, 'bomba de calor', 'caldera' ) WHERE ID = ${ ids.origen }" ); clean_post_cache( ${ ids.origen } );`
		);
		const edited = content( ids.origen );

		const card = dialog.locator( '.magiclinking-card' ).first();
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect(
			card.locator( '.magiclinking-card__status.is-error' )
		).toBeVisible();
		await expect(
			card.locator( '.magiclinking-card__status' )
		).not.toHaveText( '' );
		expect( content( ids.origen ) ).toBe( edited );
		await expectNoViolations( page, '.components-modal__frame' );
	} );

	test( 'a user who cannot edit an entry gets no suggestion actions for it, and cannot link into other people’s entries', async ( {
		page,
	} ) => {
		const before = content( ids.origen );
		await login( page, 'e2e_author' );
		await page.goto( SCREEN );

		const others = rowOf( page, 'Guía de calefacción para casas' );
		await expect( others ).toBeVisible();
		await others.hover();
		await expect(
			others.getByRole( 'button', { name: /suggestions/ } )
		).toHaveCount( 0 );

		const dialog = await openDrawer(
			page,
			'Aislamiento de casas',
			'Inbound'
		);
		const card = dialog.locator( '.magiclinking-card' ).first();
		await expect( card ).toContainText( 'You cannot edit this entry' );
		await expect(
			card.getByRole( 'button', { name: /^Link to/ } )
		).toHaveCount( 0 );
		await expect(
			card.getByRole( 'button', { name: /^Dismiss/ } )
		).toHaveCount( 0 );
		expect( content( ids.origen ) ).toBe( before );
	} );
} );
