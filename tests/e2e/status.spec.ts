import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { login, wp } from './wp';

/**
 * Pestaña Estado (F1-27, D-30): entradas del índice y tiempo del motor de las últimas peticiones de sugerencias,
 * estado vacío, tabla accesible, teclado y axe.
 */

const SCREEN_STATUS = '/wp-admin/admin.php?page=magic-linking-status';
const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

const axe = ( page: Page ) =>
	new AxeBuilder( { page } )
		.include( '.magiclinking-wrap' )
		.withTags( AXE_TAGS )
		.analyze();

/**
 * Tres entradas analizadas y sin medidas guardadas del motor.
 */
function seedStatus(): void {
	wp(
		'eval',
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'st-%' OR post_name LIKE 'ex-%' OR post_name LIKE 'e2e-%' OR post_name LIKE 'entrada-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		delete_option( 'magiclinking_engine_timing' );
		update_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_wizard_dismissed', '1' );
		$block = static fn( $t ) => "<!-- wp:paragraph -->\\n<p>$t</p>\\n<!-- /wp:paragraph -->";
		$make = static fn( $slug, $title, $content ) => wp_insert_post( array( 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish' ) );
		$make( 'st-bomba', 'Bomba de calor: cuánto se ahorra', $block( 'La bomba de calor aerotérmica extrae energía del aire exterior. Una bomba de calor bien dimensionada ahorra mucho.' ) );
		$make( 'st-origen', 'Guía de calefacción para casas', $block( 'La instalación de una bomba de calor reduce el consumo de energía en las casas con buen aislamiento.' ) );
		$make( 'st-otra', 'Aislamiento de casas', $block( 'Con una bomba de calor y un buen aislamiento, el consumo de energía baja mucho en cualquier casa moderna.' ) );
		`
	);
	wp( 'magic-linking', 'index' );
}

test.describe( 'Status tab', () => {
	test.beforeEach( () => {
		seedStatus();
	} );

	test.afterAll( () => {
		wp(
			'eval',
			`
			global $wpdb;
			foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'st-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
			foreach ( array( 'docs', 'links', 'jobs' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
			delete_option( 'magiclinking_engine_timing' );
			`
		);
	} );

	test( 'empty first, then the engine times of real suggestions requests, with keyboard and axe', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( SCREEN_STATUS );

		await expect(
			page.getByRole( 'heading', {
				level: 1,
				name: 'Magic Linking · Status',
			} )
		).toBeVisible();
		// El menú tiene su entrada.
		await expect(
			page
				.locator( '#adminmenu' )
				.getByRole( 'link', { name: 'Status', exact: true } )
		).toBeVisible();
		// Índice: entradas analizadas.
		const stats = page.locator(
			'.magiclinking-status-tab .magiclinking-stats'
		);
		await expect(
			stats.locator( 'div', { hasText: 'Entries analyzed' } )
		).toContainText( /[1-9]/ );
		await expect(
			page.getByText( 'No suggestions have been calculated yet.' )
		).toBeVisible();
		await expect( page.getByRole( 'table' ) ).toHaveCount( 0 );
		expect( ( await axe( page ) ).violations ).toEqual( [] );

		// Peticiones de sugerencias de verdad (salientes y entrantes); `wp eval` las apunta al terminar.
		wp(
			'eval',
			`
			$s = \\MagicLinking\\Core\\Plugin::container()->get( \\MagicLinking\\Index\\Suggestions::class );
			$s->outgoing( get_page_by_path( 'st-origen', OBJECT, 'post' )->ID );
			$s->outgoing( get_page_by_path( 'st-otra', OBJECT, 'post' )->ID );
			$s->incoming( get_page_by_path( 'st-bomba', OBJECT, 'post' )->ID );
			`
		);

		const refresh = page.getByRole( 'button', { name: 'Refresh' } );
		await refresh.focus();
		await page.keyboard.press( 'Enter' );

		const table = page.getByRole( 'table', {
			name: 'Time of the suggestions engine, in milliseconds',
		} );
		await expect( table ).toBeVisible();
		for ( const name of [
			'Direction',
			'Requests',
			'Median',
			'95th percentile',
			'Budget',
			'Candidates',
			'Retrieval',
			'Phrases',
			'Scoring',
		] ) {
			await expect(
				table.getByRole( 'columnheader', { name } )
			).toBeVisible();
		}
		const outgoing = table.getByRole( 'row', { name: /^Outgoing/ } );
		const cells = outgoing.getByRole( 'cell' );
		await expect( cells.nth( 0 ) ).toHaveText( '2' );
		await expect( cells.nth( 1 ) ).toHaveText( /^\d+\.\d ms$/ );
		await expect( outgoing ).toContainText( 'Within budget' );
		const incoming = table
			.getByRole( 'row', { name: /^Incoming/ } )
			.getByRole( 'cell' );
		await expect( incoming.nth( 0 ) ).toHaveText( '1' );
		await expect( incoming.nth( 1 ) ).toHaveText( /^\d+\.\d ms$/ );
		// El botón sigue siendo alcanzable con el teclado y el foco no se pierde.
		await expect( refresh ).toBeFocused();
		expect( ( await axe( page ) ).violations ).toEqual( [] );
	} );
} );
