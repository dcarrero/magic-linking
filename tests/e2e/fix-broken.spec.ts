import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import {
	ensureAnalyzed,
	login,
	openHistoryByBatch,
	SCREEN_BROKEN,
	wp,
} from './wp';

/**
 * Arreglar un enlace roto desde la lista: cambiar el destino, quitar el enlace, deshacer, permisos y contenido ya cambiado.
 */

const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

/**
 * Entradas con enlaces rotos de prueba, sin historial y con el grafo al día.
 */
function seedFix(): void {
	wp(
		'eval',
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'e2e-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'changes', 'docs', 'links', 'jobs' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		update_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_wizard_dismissed', '1' );
		$block = "<!-- wp:paragraph -->\\n<p>%s</p>\\n<!-- /wp:paragraph -->";
		$make = static fn( $slug, $title, $text ) => wp_insert_post( array( 'post_name' => $slug, 'post_title' => $title, 'post_content' => sprintf( $block, $text ), 'post_status' => 'publish' ) );
		$make( 'e2e-fix-destino', 'E2E Calefaccion reparada', 'Destino bueno.' );
		$make( 'e2e-fix-a', 'E2E Origen A', 'Uno <a href="' . home_url( '/e2e-roto/' ) . '" class="x">ancla uno</a> y dos <a href="' . home_url( '/e2e-roto/' ) . '">ancla dos</a>.' );
		$make( 'e2e-fix-b', 'E2E Origen B', 'Texto <a href="' . home_url( '/e2e-roto-b/' ) . '"><strong>negrita</strong> final</a>.' );
		foreach ( array( 'author' ) as $role ) {
			if ( ! get_user_by( 'login', 'e2e_author' ) ) { wp_insert_user( array( 'user_login' => 'e2e_author', 'user_pass' => 'password', 'role' => $role, 'user_email' => 'e2e_author@example.org' ) ); }
		}
		`
	);
	ensureAnalyzed();
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
 * Pasa axe (WCAG A y AA) sobre una parte de la pantalla.
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
 * Fila de la lista de rotos con ese texto de ancla.
 * @param page   Página.
 * @param anchor Texto del ancla.
 */
function rowOf( page: Page, anchor: string ) {
	return page
		.getByRole( 'table', { name: 'Broken internal links' } )
		.getByRole( 'row' )
		.filter( { hasText: anchor } );
}

test.describe( 'Fix broken links', () => {
	test.beforeEach( () => {
		seedFix();
	} );

	test( 'changes the destination of one link, keeps the rest and undoes it from the list', async ( {
		page,
	} ) => {
		const original = content( 'e2e-fix-a' );
		await login( page );
		await page.goto( SCREEN_BROKEN );
		await expect( rowOf( page, 'ancla dos' ) ).toBeVisible();
		await expectNoViolations( page );

		const row = rowOf( page, 'ancla dos' );
		await row.hover();
		await row.getByRole( 'button', { name: /^Change destination/ } ).click();

		const dialog = page.getByRole( 'dialog', { name: 'Change destination' } );
		await expect( dialog ).toBeVisible();
		const confirm = dialog.getByRole( 'button', {
			name: 'Change destination',
			exact: true,
		} );
		await expect( confirm ).toBeDisabled();

		await dialog.getByRole( 'searchbox' ).fill( 'Calefaccion' );
		const choice = dialog.getByRole( 'radio', {
			name: /E2E Calefaccion reparada/,
		} );
		await expect( choice ).toBeVisible();
		await choice.check();
		await expect( confirm ).toBeEnabled();
		await expectNoViolations( page, '.components-modal__frame' );

		await confirm.click();
		await expect( dialog ).toHaveCount( 0 );
		const notice = page.locator( '.magiclinking-fix-notice' );
		await expect( notice ).toContainText(
			'Destination changed for “ancla dos” in “E2E Origen A”.'
		);
		await expect( notice ).toBeFocused();

		// Solo cambia esa aparición; la otra sigue rota y su fila en la lista.
		await expect( rowOf( page, 'ancla dos' ) ).toHaveCount( 0 );
		await expect( rowOf( page, 'ancla uno' ) ).toBeVisible();
		const fixed = content( 'e2e-fix-a' );
		const destination = wp(
			'eval',
			`echo get_permalink( get_page_by_path( 'e2e-fix-destino', OBJECT, 'post' ) );`
		).trim();
		expect( fixed.split( destination ) ).toHaveLength( 2 );
		expect( fixed.match( /e2e-roto\//g ) ).toHaveLength( 1 );
		expect( fixed ).toContain( 'class="x">ancla uno</a>' );
		await expectNoViolations( page );

		await notice.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( notice ).toContainText(
			'Done: the link is back as it was.'
		);
		await expect( rowOf( page, 'ancla dos' ) ).toBeVisible();
		expect( content( 'e2e-fix-a' ) ).toBe( original );
	} );

	test( 'removes a link leaving its formatted text, and the history can undo it', async ( {
		page,
	} ) => {
		const original = content( 'e2e-fix-b' );
		await login( page );
		await page.goto( SCREEN_BROKEN );

		const row = rowOf( page, 'negrita final' );
		await row.hover();
		await row.getByRole( 'button', { name: /^Remove link/ } ).click();
		const dialog = page.getByRole( 'dialog', { name: 'Remove link' } );
		await expectNoViolations( page, '.components-modal__frame' );
		await dialog
			.getByRole( 'button', { name: 'Remove link', exact: true } )
			.click();

		await expect( page.locator( '.magiclinking-fix-notice' ) ).toContainText(
			'its text stays'
		);
		await expect( rowOf( page, 'negrita final' ) ).toHaveCount( 0 );
		expect( content( 'e2e-fix-b' ) ).toContain(
			'Texto <strong>negrita</strong> final.'
		);

		await openHistoryByBatch( page );
		const batch = page.locator( '.magiclinking-batch' ).first();
		await expect(
			batch.getByRole( 'heading', { level: 2 } )
		).toContainText( '1 link removed' );
		await batch
			.locator( 'button[aria-controls^="magiclinking-details-"]' )
			.click();
		await expect( batch.getByRole( 'table' ) ).toContainText( 'negrita final' );
		await expectNoViolations( page );

		await batch.getByRole( 'button', { name: 'Undo batch' } ).click();
		await page
			.getByRole( 'dialog' )
			.getByRole( 'button', { name: 'Undo batch' } )
			.click();
		await expect( batch ).toContainText( '1 link undone.' );
		expect( content( 'e2e-fix-b' ) ).toBe( original );

		await batch.getByRole( 'button', { name: 'Redo batch' } ).click();
		await page
			.getByRole( 'dialog' )
			.getByRole( 'button', { name: 'Redo batch' } )
			.click();
		await expect( batch ).toContainText( '1 link added again.' );
		expect( content( 'e2e-fix-b' ) ).toContain(
			'Texto <strong>negrita</strong> final.'
		);
	} );

	test( 'a user who cannot edit the entry gets no fix actions', async ( {
		page,
	} ) => {
		await login( page, 'e2e_author' );
		await page.goto( SCREEN_BROKEN );

		await expect( rowOf( page, 'ancla uno' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: /^Change destination/ } )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'button', { name: /^Remove link/ } )
		).toHaveCount( 0 );
	} );

	test( 'refuses with a clear message when the entry changed since the list, and writes nothing', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( SCREEN_BROKEN );

		const row = rowOf( page, 'negrita final' );
		await row.hover();
		await row.getByRole( 'button', { name: /^Remove link/ } ).click();

		// Alguien edita la entrada mientras el cuadro está abierto.
		wp(
			'eval',
			`global $wpdb; $wpdb->query( "UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, 'final</a>', 'final</a> editado' ) WHERE post_name = 'e2e-fix-b'" ); $wpdb->query( "UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, 'negrita', 'cambiado' ) WHERE post_name = 'e2e-fix-b'" ); clean_post_cache( get_page_by_path( 'e2e-fix-b', OBJECT, 'post' )->ID );`
		);
		const edited = content( 'e2e-fix-b' );

		const dialog = page.getByRole( 'dialog', { name: 'Remove link' } );
		await dialog
			.getByRole( 'button', { name: 'Remove link', exact: true } )
			.click();

		await expect( dialog.getByRole( 'alert' ) ).toContainText(
			'no longer matches the list'
		);
		expect( content( 'e2e-fix-b' ) ).toBe( edited );
		await expectNoViolations( page, '.components-modal__frame' );
	} );
} );
