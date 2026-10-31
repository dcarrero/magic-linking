import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { login, wp } from './wp';

/**
 * Avisos (F1-17, docs/07 §5): solo en las pantallas del plugin, como mucho uno, con cierre para siempre por usuario
 * (un solo metadato), aviso de reseña a los 20 enlaces y 30 días, accesible y sin avisos en el escritorio.
 */

const SCREENS = [
	'/wp-admin/admin.php?page=magic-linking-history',
	'/wp-admin/admin.php?page=magic-linking-broken',
	'/wp-admin/admin.php?page=magic-linking-rules',
	'/wp-admin/admin.php?page=magic-linking-status',
	'/wp-admin/admin.php?page=magic-linking-settings',
];
const SCREEN_REPORT = '/wp-admin/admin.php?page=magic-linking';
const REVIEW = '.magiclinking-notice';
const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

/**
 * El usuario 1 ha insertado `links` enlaces y el plugin se activó hace `days` días; ningún aviso cerrado.
 *
 * @param links Enlaces insertados en el Historial.
 * @param days  Días desde la activación.
 */
function seedReview( links: number, days: number ): void {
	wp(
		'eval',
		`
		global $wpdb;
		$t = $wpdb->prefix . 'magiclinking_changes';
		$wpdb->query( "DELETE FROM $t" );
		for ( $i = 0; $i < ${ links }; $i++ ) {
			$wpdb->insert( $t, array( 'batch_id' => 'N', 'post_id' => 1, 'action' => 'insert', 'before_html' => '', 'after_html' => '', 'user_id' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		}
		update_option( 'magiclinking_activated_at', time() - ${ days } * DAY_IN_SECONDS, false );
		delete_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_dismissed_notices' );
		update_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_wizard_dismissed', '1' );
		// El aviso de IA tiene el suyo: aquí está cerrado para que no ocupe el sitio.
		update_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_ai_notice_dismissed', '1' );
		`
	);
}

const axe = ( page: Page ) =>
	new AxeBuilder( { page } )
		.include( '.magiclinking-wrap' )
		.withTags( AXE_TAGS )
		.analyze();

test.describe( 'Notices', () => {
	test.afterAll( () => {
		wp(
			'eval',
			`
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_changes" );
			delete_option( 'magiclinking_activated_at' );
			delete_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_dismissed_notices' );
			delete_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_ai_notice_dismissed' );
			`
		);
	} );

	test( 'a due review notice never shows outside the plugin screens', async ( {
		page,
	} ) => {
		seedReview( 25, 40 );
		await login( page );
		for ( const url of [
			'/wp-admin/index.php',
			'/wp-admin/edit.php',
			'/wp-admin/plugins.php',
			'/wp-admin/post-new.php?post_type=page',
		] ) {
			await page.goto( url );
			await expect( page.locator( 'body.wp-admin' ) ).toBeVisible();
			await expect( page.locator( REVIEW ) ).toHaveCount( 0 );
			await expect(
				page.getByText( 'a review on wordpress.org' )
			).toHaveCount( 0 );
		}
		// Y el script de la pantalla no se carga fuera de ella.
		await page.goto( '/wp-admin/index.php' );
		expect( await page.content() ).not.toContain( 'window.magiclinking =' );
		// En el front-end tampoco.
		await page.goto( '/' );
		const front = await page.content();
		expect( front ).not.toContain( 'magiclinking-admin' );
		expect( front ).not.toContain( 'window.magiclinking' );
	} );

	test( 'no review notice before 20 links or before 30 days', async ( {
		page,
	} ) => {
		await login( page );
		seedReview( 19, 40 );
		await page.goto( SCREENS.at( 0 ) ?? SCREEN_REPORT );
		await expect( page.getByRole( 'heading', { level: 1 } ) ).toContainText(
			'History'
		);
		await expect( page.locator( REVIEW ) ).toHaveCount( 0 );

		seedReview( 25, 10 );
		await page.goto( SCREENS.at( 0 ) ?? SCREEN_REPORT );
		await expect( page.getByRole( 'heading', { level: 1 } ) ).toContainText(
			'History'
		);
		await expect( page.locator( REVIEW ) ).toHaveCount( 0 );
	} );

	test( 'review notice: once, on every plugin screen, a plain link and no stars', async ( {
		page,
	} ) => {
		seedReview( 25, 40 );
		await login( page );
		for ( const url of SCREENS ) {
			await page.goto( url );
			await expect( page.locator( REVIEW ) ).toHaveCount( 1 );
		}
		await page.goto( SCREENS.at( 0 ) ?? SCREEN_REPORT );
		const notice = page.locator( REVIEW );
		const link = notice.getByRole( 'link', { name: 'Write a review' } );
		await expect( link ).toHaveAttribute(
			'href',
			/^https:\/\/wordpress\.org\/support\/plugin\/magic-linking\/reviews\//
		);
		await expect( notice.locator( 'input, [role="radio"]' ) ).toHaveCount(
			0
		);
		await expect(
			notice.getByRole( 'button', { name: 'No, thanks' } )
		).toBeVisible();
		await expect(
			notice.getByRole( 'button', { name: /close|dismiss/i } )
		).toBeVisible();
		expect( ( await axe( page ) ).violations ).toEqual( [] );
	} );

	test( 'No, thanks closes it for good, stores one meta, returns focus and survives a reload', async ( {
		page,
	} ) => {
		seedReview( 25, 40 );
		await login( page );
		await page.goto( SCREENS.at( 0 ) ?? SCREEN_REPORT );
		const notice = page.locator( REVIEW );
		const saved = page
			.waitForResponse( /notices(\/|%2F)dismiss/ )
			.catch( () => null );
		await notice.getByRole( 'button', { name: 'No, thanks' } ).click();
		await expect( notice ).toHaveCount( 0 );
		await expect( page.locator( '.magiclinking-wrap h1' ) ).toBeFocused();
		await saved;

		const stored = wp(
			'eval',
			`global $wpdb; echo wp_json_encode( get_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_dismissed_notices' ) );`
		).trim();
		expect( stored ).toBe( '[["review"]]' );

		for ( const url of [ SCREEN_REPORT, ...SCREENS ] ) {
			await page.goto( url );
			await expect(
				page.locator( '.magiclinking-wrap h1' )
			).toBeVisible();
			await expect( page.locator( REVIEW ) ).toHaveCount( 0 );
		}
	} );

	test( 'the X also closes it for good', async ( { page } ) => {
		seedReview( 25, 40 );
		await login( page );
		await page.goto( SCREENS.at( 3 ) ?? SCREEN_REPORT );
		const notice = page.locator( REVIEW );
		const saved = page
			.waitForResponse( /notices(\/|%2F)dismiss/ )
			.catch( () => null );
		await notice.getByRole( 'button', { name: /close|dismiss/i } ).click();
		await expect( notice ).toHaveCount( 0 );
		await saved;
		await page.reload();
		await expect( page.locator( '.magiclinking-wrap h1' ) ).toBeVisible();
		await expect( page.locator( REVIEW ) ).toHaveCount( 0 );
	} );

	test( 'at most one notice at a time on the report', async ( { page } ) => {
		seedReview( 25, 40 );
		// El aviso de «Sin conector de IA» (si WordPress no tiene conector) tiene prioridad: nunca salen los dos.
		wp(
			'eval',
			`global $wpdb; delete_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_ai_notice_dismissed' );`
		);
		await login( page );
		await page.goto( SCREEN_REPORT );
		await expect( page.locator( '.magiclinking-wrap h1' ) ).toBeVisible();
		await page.waitForTimeout( 1500 );
		await expect(
			page.locator( '.magiclinking-wrap .components-notice' )
		).toHaveCount( 1 );
	} );

	test( 'the existing rule: no Magic Linking notice on the dashboard', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( '/wp-admin/index.php' );
		await expect(
			page.locator(
				'.notice:has-text("Magic Linking"), .notice:has-text("Internal links")'
			)
		).toHaveCount( 0 );
	} );
} );
