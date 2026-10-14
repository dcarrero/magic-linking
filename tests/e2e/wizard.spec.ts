import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { ensureAnalyzed, login, SCREEN, SCREEN_SETTINGS, seed, wp } from './wp';

/**
 * Asistente de arranque (docs/07 §2) y ajustes de F1-16.
 */

const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

/** Sitio recién instalado: sin análisis y con el asistente sin cerrar para el administrador. */
function freshSite(): void {
	seed();
	wp( 'eval', `delete_user_meta( 1, 'magiclinking_wizard_dismissed' );` );
}

const wizard = ( page: import('@playwright/test').Page ) =>
	page.locator( '.magiclinking-wizard' );

test.describe( 'Setup assistant', () => {
	test.beforeEach( () => {
		freshSite();
	} );

	test.afterAll( () => {
		wp(
			'eval',
			`update_user_meta( 1, 'magiclinking_wizard_dismissed', '1' );`
		);
	} );

	test( 'opens on a new site, asks for no email and can be skipped for good', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( SCREEN );

		await expect(
			wizard( page ).getByRole( 'heading', { name: 'What do we link?' } )
		).toBeVisible();
		await expect( wizard( page ) ).toContainText( 'Setup · step 1 of 3' );
		await expect( wizard( page ).getByText( /e-?mail/i ) ).toHaveCount( 0 );
		await expect( wizard( page ).getByLabel( 'Posts' ) ).toBeChecked();
		await expect( wizard( page ).getByLabel( 'Pages' ) ).toBeChecked();
		await expect( wizard( page ) ).toContainText( 'Language detected:' );
		// La presentación del primer análisis no se duplica mientras el asistente está abierto.
		await expect(
			page.getByRole( 'button', { name: 'Analyze my site' } )
		).toHaveCount( 0 );

		await wizard( page )
			.getByRole( 'button', { name: 'Skip setup' } )
			.click();
		await expect( wizard( page ) ).toHaveCount( 0 );
		await expect(
			page.getByRole( 'button', { name: 'Analyze my site' } )
		).toBeVisible();

		// Cerrado para siempre: ni al recargar ni en otra pantalla.
		await page.reload();
		await expect(
			page.getByRole( 'button', { name: 'Analyze my site' } )
		).toBeVisible();
		await expect( wizard( page ) ).toHaveCount( 0 );
	} );

	test( 'goes through the three steps, analyzes and shows the summary', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( SCREEN );

		const box = wizard( page );
		await box.getByRole( 'button', { name: 'Next' } ).click();
		await expect(
			box.getByRole( 'heading', { name: 'Artificial intelligence' } )
		).toBeFocused();
		await expect( box ).toContainText(
			/does not use it yet|No AI connected/
		);
		await box.getByRole( 'button', { name: 'Back' } ).click();
		await expect(
			box.getByRole( 'heading', { name: 'What do we link?' } )
		).toBeFocused();
		await box.getByRole( 'button', { name: 'Next' } ).click();
		await box.getByRole( 'button', { name: 'Next' } ).click();

		await expect(
			box.getByRole( 'heading', { name: 'Analyze your site' } )
		).toBeFocused();
		await box.getByRole( 'button', { name: 'Start' } ).click();
		await expect( page.locator( 'progress' ) ).toBeVisible();
		try {
			wp( 'action-scheduler', 'run' );
		} catch ( e ) {
			// Si otro proceso ya lo ejecutó no hay nada que correr.
		}

		await expect(
			box.getByRole( 'heading', { name: 'All set' } )
		).toBeVisible( { timeout: 30_000 } );
		await expect( box ).toContainText(
			/\d+ entries analyzed · \d+ orphans · \d+ internal links/
		);
		await box.getByRole( 'button', { name: 'See orphans' } ).click();

		await expect( wizard( page ) ).toHaveCount( 0 );
		await expect(
			page.getByRole( 'link', { name: /^Orphans/ } )
		).toHaveAttribute( 'aria-current', 'true' );
		await page.reload();
		await expect( wizard( page ) ).toHaveCount( 0 );
	} );

	test( 'choosing content types saves them and a type is required', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( SCREEN );
		const box = wizard( page );

		await box.getByLabel( 'Posts' ).uncheck();
		await box.getByLabel( 'Pages' ).uncheck();
		await expect(
			box.getByRole( 'button', { name: 'Next' } )
		).toBeDisabled();
		await expect( box ).toContainText(
			'Choose at least one content type.'
		);

		await box.getByLabel( 'Pages' ).check();
		await box.getByRole( 'button', { name: 'Next' } ).click();
		await expect(
			box.getByRole( 'heading', { name: 'Artificial intelligence' } )
		).toBeVisible();
		const types = wp(
			'eval',
			`echo implode( ',', get_option( 'magiclinking_settings' )['post_types'] );`
		).trim();
		expect( types ).toBe( 'page' );
	} );

	test( 'can be opened again from Settings', async ( { page } ) => {
		await login( page );
		await page.goto( SCREEN );
		await wizard( page )
			.getByRole( 'button', { name: 'Skip setup' } )
			.click();
		await expect( wizard( page ) ).toHaveCount( 0 );

		await page.goto( SCREEN_SETTINGS );
		await page
			.getByRole( 'button', { name: 'Open the setup assistant' } )
			.click();
		await expect(
			wizard( page ).getByRole( 'heading', { name: 'What do we link?' } )
		).toBeVisible();
	} );

	test( 'does not appear on a site that already has an analysis, nor for editors', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN );
		await expect( page.locator( '.magiclinking-panel' ) ).toBeVisible();
		await expect( wizard( page ) ).toHaveCount( 0 );

		freshSite();
		await page.context().clearCookies();
		await login( page, 'e2e_editor' );
		await page.goto( SCREEN );
		await expect( wizard( page ) ).toHaveCount( 0 );
		await expect(
			page.getByText( 'An administrator has to run the first analysis.' )
		).toBeVisible();
	} );

	test( 'can be used with the keyboard only', async ( { page } ) => {
		await login( page );
		await page.goto( SCREEN );
		const box = wizard( page );
		await expect( box ).toBeVisible();

		await box.getByLabel( 'Posts' ).focus();
		await page.keyboard.press( 'Space' );
		await expect( box.getByLabel( 'Posts' ) ).not.toBeChecked();
		await page.keyboard.press( 'Space' );
		await expect( box.getByLabel( 'Posts' ) ).toBeChecked();
		await box.getByRole( 'button', { name: 'Next' } ).focus();
		await page.keyboard.press( 'Enter' );
		await expect(
			box.getByRole( 'heading', { name: 'Artificial intelligence' } )
		).toBeFocused();
		await page.keyboard.press( 'Tab' );
		await expect(
			box.getByRole( 'button', { name: 'Back' } )
		).toBeFocused();
	} );

	for ( const step of [ 1, 2, 3 ] ) {
		test( `axe finds no WCAG A/AA violations in step ${ step }`, async ( {
			page,
		} ) => {
			await login( page );
			await page.goto( SCREEN );
			const box = wizard( page );
			for ( let i = 1; i < step; i++ ) {
				await box.getByRole( 'button', { name: 'Next' } ).click();
			}
			await expect( box ).toContainText( `step ${ step } of 3` );
			await page.waitForLoadState( 'networkidle' );

			const results = await new AxeBuilder( { page } )
				.include( '.magiclinking-wrap' )
				.withTags( AXE_TAGS )
				.analyze();
			expect( results.violations ).toEqual( [] );
		} );
	}
} );
