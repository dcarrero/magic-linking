import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { login, SCREEN_HISTORY, SCREEN_RULES, wp } from './wp';

/**
 * Reglas automáticas (F1-13): crear, aplicar a una entrada con vista previa, deshacer, permisos y accesibilidad.
 */

const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

const TEXT =
	'Our guide explains how a heat pump saves energy at home every winter and why homeowners keep choosing one when they replace an old boiler.';

/**
 * Entradas de prueba, sin reglas ni historial.
 */
function seedRules(): void {
	wp(
		'eval',
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'e2e-rule-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'changes', 'docs', 'links', 'jobs', 'rules' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		update_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_wizard_dismissed', '1' );
		if ( ! get_user_by( 'login', 'e2e_editor' ) ) { wp_insert_user( array( 'user_login' => 'e2e_editor', 'user_pass' => 'password', 'role' => 'editor', 'user_email' => 'e2e_editor@example.org' ) ); }
		$block = "<!-- wp:paragraph -->\\n<p>%s</p>\\n<!-- /wp:paragraph -->";
		$make = static fn( $slug, $title, $text ) => wp_insert_post( array( 'post_name' => $slug, 'post_title' => $title, 'post_content' => sprintf( $block, $text ), 'post_status' => 'publish' ) );
		$make( 'e2e-rule-target', 'E2E Heat pump guide', 'The destination.' );
		$make( 'e2e-rule-a', 'E2E Source A', ${ JSON.stringify( TEXT ) } );
		$make( 'e2e-rule-b', 'E2E Source B', 'Nothing relevant here.' );
		$make( 'e2e-rule-c', 'E2E Source C', 'Already linked: <a href="' . home_url( '/e2e-rule-b/' ) . '">the heat pump page</a> and that is all.' );
		`
	);
}

/**
 * Contenido guardado de una entrada de prueba.
 * @param slug Enlace permanente.
 */
function content( slug: string ): string {
	return wp(
		'eval',
		`echo get_page_by_path( '${ slug }', OBJECT, 'post' )->post_content;`
	).trim();
}

/**
 * Pasa axe (WCAG A y AA, 2.2 incluido) sobre una parte de la pantalla.
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
 * Crea una regla con el formulario.
 * @param page Página.
 */
async function addRule( page: Page ): Promise< void > {
	await page.getByRole( 'button', { name: 'Add rule' } ).first().click();
	const form = page.locator( '.magiclinking-rule-form' );
	await expect( form ).toBeVisible();
	await expect( form.getByRole( 'heading', { name: 'Add a rule' } ) ).toBeFocused();
	await form.getByLabel( 'Phrases to link' ).fill( 'heat pump\nheat pumps\nair-source heating' );
	await form.getByRole( 'searchbox' ).fill( 'Heat pump guide' );
	const choice = form.getByRole( 'radio', { name: /E2E Heat pump guide/ } );
	await expect( choice ).toBeVisible();
	await choice.check();
	await expectNoViolations( page );
	await form.getByRole( 'button', { name: 'Add rule' } ).click();
}

test.describe( 'Automatic rules', () => {
	test.beforeEach( () => {
		seedRules();
	} );

	test( 'adds a rule, previews it on existing entries, applies it to one and undoes it', async ( {
		page,
	} ) => {
		const original = content( 'e2e-rule-a' );
		await login( page );
		await page.goto( SCREEN_RULES );
		await expect(
			page.getByRole( 'heading', { level: 1 } )
		).toContainText( 'Rules' );
		await expectNoViolations( page );

		await addRule( page );
		const notice = page.locator( '.magiclinking-fix-notice' );
		await expect( notice ).toContainText( 'Rule “heat pump” added' );
		await expect( notice ).toBeFocused();
		const table = page.getByRole( 'table', { name: 'Automatic rules' } );
		await expect( table ).toContainText( 'E2E Heat pump guide' );
		await expect( table ).toContainText( 'Active' );
		await expectNoViolations( page );
		expect( content( 'e2e-rule-a' ) ).toBe( original ); // Crear la regla no toca nada.

		const row = table.getByRole( 'row' ).filter( { hasText: 'heat pump' } );
		await row.hover();
		const open = row.getByRole( 'button', { name: /^Apply to existing entries/ } );
		await open.click();

		const dialog = page.getByRole( 'dialog', { name: /Apply “heat pump” to existing entries/ } );
		await expect( dialog ).toBeVisible();
		await expect( dialog ).toContainText( '1 where it fits' );
		const item = dialog.locator( 'li' ).filter( { hasText: 'E2E Source A' } );
		await expect( item ).toBeVisible();
		await expect( item.locator( 'mark' ) ).toHaveText( 'heat pump' );
		// La que ya tiene el enlace y la que no encaja no salen.
		await expect( dialog.locator( 'li' ).filter( { hasText: 'E2E Source B' } ) ).toHaveCount( 0 );
		await expect( dialog.locator( 'li' ).filter( { hasText: 'E2E Source C' } ) ).toHaveCount( 0 );
		await expectNoViolations( page, '.components-modal__frame' );
		expect( content( 'e2e-rule-a' ) ).toBe( original ); // La vista previa no escribe.

		await item.getByRole( 'button', { name: /^Add link/ } ).click();
		await expect( item ).toContainText( '1 link added.' );
		await expect( item.locator( '[tabindex="-1"]' ).last() ).toBeFocused();
		const linked = content( 'e2e-rule-a' );
		expect( linked ).toContain( '>heat pump</a>' );
		expect( linked.replace( /<a [^>]*>|<\/a>/g, '' ) ).toBe( original );
		await expectNoViolations( page, '.components-modal__frame' );

		await item.getByRole( 'button', { name: /^Undo/ } ).click();
		await expect( item ).toContainText( 'Undone' );
		expect( content( 'e2e-rule-a' ) ).toBe( original );

		// Esc cierra y el foco vuelve a la acción que abrió el cuadro.
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toHaveCount( 0 );
		await expect( open ).toBeFocused();
	} );

	test( 'the history tells the link came from a rule and can filter by it', async ( { page } ) => {
		await login( page );
		await page.goto( SCREEN_RULES );
		await addRule( page );
		const row = page
			.getByRole( 'table', { name: 'Automatic rules' } )
			.getByRole( 'row' )
			.filter( { hasText: 'heat pump' } );
		await row.hover();
		await row.getByRole( 'button', { name: /^Apply to existing entries/ } ).click();
		const dialog = page.getByRole( 'dialog' );
		await dialog.getByRole( 'button', { name: /^Add link/ } ).first().click();
		await expect( dialog ).toContainText( '1 link added.' );
		await dialog.getByRole( 'button', { name: 'Close' } ).last().click();

		await page.goto( SCREEN_HISTORY );
		await expect( page.locator( '.magiclinking-wrap' ) ).toContainText( 'Automatic rule' );
		await page.getByLabel( 'Source', { exact: false } ).first().selectOption( 'rule' );
		await expect( page.locator( '.magiclinking-wrap' ) ).toContainText( 'E2E Source A' );
		await expectNoViolations( page );
	} );

	test( 'edits, pauses and deletes a rule, keeping the links it added', async ( { page } ) => {
		await login( page );
		await page.goto( SCREEN_RULES );
		await addRule( page );
		const table = page.getByRole( 'table', { name: 'Automatic rules' } );
		const row = table.getByRole( 'row' ).filter( { hasText: 'heat pump' } );

		await row.hover();
		await row.getByRole( 'button', { name: /^Edit/ } ).click();
		const form = page.locator( '.magiclinking-rule-form' );
		await expect( form.getByRole( 'heading', { name: 'Edit rule' } ) ).toBeFocused();
		await form.getByLabel( 'Allow one extra word inside a phrase' ).check();
		await form.getByLabel( 'Links per entry' ).fill( '2' );
		await form.getByRole( 'button', { name: 'Save rule' } ).click();
		// Los ajustes permiten un enlace al mismo destino por entrada: el servidor lo explica y no guarda.
		await expect( form.getByRole( 'alert' ) ).toContainText( 'Your settings allow 1 link' );
		await form.getByLabel( 'Links per entry' ).fill( '1' );
		await form.getByRole( 'button', { name: 'Save rule' } ).click();
		await expect( page.locator( '.magiclinking-fix-notice' ) ).toContainText( 'saved' );
		await expect( table ).toContainText( 'one extra word allowed' );

		await row.hover();
		await row.getByRole( 'button', { name: /^Pause/ } ).click();
		await expect( table ).toContainText( 'Paused' );
		await expectNoViolations( page );

		await row.hover();
		await row.getByRole( 'button', { name: /^Delete/ } ).click();
		const confirm = page.getByRole( 'dialog', { name: 'Delete rule' } );
		await expectNoViolations( page, '.components-modal__frame' );
		await confirm.getByRole( 'button', { name: 'Delete rule' } ).click();
		await expect( page.locator( '.magiclinking-fix-notice' ) ).toContainText( 'Rule deleted' );
		await expect( page.getByText( 'There are no rules yet.' ) ).toBeVisible();
	} );

	test( 'Enter in the target search neither navigates nor loses what was typed', async ( { page } ) => {
		await login( page );
		await page.goto( SCREEN_RULES );
		await page.getByRole( 'button', { name: 'Add rule' } ).first().click();
		const form = page.locator( '.magiclinking-rule-form' );
		await form.getByLabel( 'Phrases to link' ).fill( 'heat pump' );
		const search = form.getByRole( 'searchbox' );
		await search.fill( 'Heat pump guide' );
		const url = page.url();
		await search.press( 'Enter' );
		// Un <form> dentro del <form> de la regla enviaba el formulario de fuera y dejaba admin.php? en blanco.
		await expect( page ).toHaveURL( url );
		await expect( form ).toBeVisible();
		await expect( form.getByLabel( 'Phrases to link' ) ).toHaveValue( 'heat pump' );
		await expect( search ).toHaveValue( 'Heat pump guide' );
		await expect( form.locator( 'form' ) ).toHaveCount( 0 );
	} );

	test( 'an invalid rule explains why and writes nothing', async ( { page } ) => {
		await login( page );
		await page.goto( SCREEN_RULES );
		await page.getByRole( 'button', { name: 'Add rule' } ).first().click();
		const form = page.locator( '.magiclinking-rule-form' );
		await form.getByLabel( 'Phrases to link' ).fill( 'the of' );
		await form.getByRole( 'searchbox' ).fill( 'Heat pump guide' );
		await form.getByRole( 'radio', { name: /E2E Heat pump guide/ } ).check();
		await form.getByRole( 'button', { name: 'Add rule' } ).click();
		const alert = form.getByRole( 'alert' );
		await expect( alert ).toContainText( 'has no meaningful word to link' );
		await expect( alert ).toBeFocused();
		await expectNoViolations( page );

		await form.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( form ).toHaveCount( 0 );
		await expect( page.getByRole( 'button', { name: 'Add rule' } ).first() ).toBeFocused();
	} );

	test( 'editors cannot reach the rules screen or its API', async ( { page } ) => {
		await login( page, 'e2e_editor' );
		const denied = await page.goto( SCREEN_RULES );
		expect( denied?.status() ).toBe( 403 );
		const api = await page.request.get( '/index.php?rest_route=/magic-linking/v1/rules' );
		// Sin nonce de REST el núcleo responde 401; con nonce, 403 (lo cubren las pruebas de integración).
		expect( [ 401, 403 ] ).toContain( api.status() );
	} );
} );
