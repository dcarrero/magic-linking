import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { login, SCREEN_HISTORY, SCREEN_SETTINGS, wp } from './wp';

/**
 * Pestaña «History»: deshacer un cambio y un lote, rehacer, estado vacío, conservación y accesibilidad.
 */

const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

/**
 * Deja el historial en un estado conocido: un lote de dos enlaces (origen A y B) y otro de uno (origen C).
 */
function seedHistory(): void {
	wp(
		'eval',
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'e2e-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		$wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_changes" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_jobs WHERE type IN ( 'undo', 'purge' )" );
		as_unschedule_all_actions( 'magiclinking_run_undo' );
		delete_option( 'magiclinking_settings' );
		$block = "<!-- wp:paragraph -->\\n<p>%s</p>\\n<!-- /wp:paragraph -->";
		$make = static fn( $slug, $title, $text ) => wp_insert_post( array( 'post_name' => $slug, 'post_title' => $title, 'post_content' => sprintf( $block, $text ), 'post_status' => 'publish' ) );
		$target = $make( 'e2e-destino', 'E2E destino', 'Destino.' );
		$a = $make( 'e2e-origen-a', 'E2E origen A', 'Compra aire acondicionado ya.' );
		$b = $make( 'e2e-origen-b', 'E2E origen B', 'Compra aire acondicionado ya.' );
		$c = $make( 'e2e-origen-c', 'E2E origen C', 'Compra aire acondicionado ya.' );
		if ( ! get_user_by( 'login', 'e2e_editor' ) ) { wp_insert_user( array( 'user_login' => 'e2e_editor', 'user_pass' => 'password', 'role' => 'editor', 'user_email' => 'e2e_editor@example.org' ) ); }
		wp_set_current_user( 1 );
		$inserter = MagicLinking\\Core\\Plugin::container()->get( MagicLinking\\Content\\Inserter::class );
		$request = static fn( $post ) => new MagicLinking\\Content\\InsertRequest( $post, get_permalink( $target ), 'aire acondicionado', 'Compra ', ' ya.' );
		$batch = MagicLinking\\History\\BatchId::generate();
		$inserter->insert( $request( $a ), $batch );
		$inserter->insert( $request( $b ), $batch );
		usleep( 20000 );
		$inserter->insert( $request( $c ) );
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
 * Pasa axe (WCAG A y AA) sobre la pantalla.
 * @param page Página.
 */
async function expectNoViolations(
	page: Page,
	selector = '.magiclinking-wrap'
): Promise< void > {
	// Los cuadros de diálogo entran con una animación: axe mide el contraste con el color ya definitivo.
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

test.describe( 'History', () => {
	test.beforeEach( () => {
		seedHistory();
	} );

	test( 'lists the batches, undoes one change and redoes it, with the content checked', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( SCREEN_HISTORY );

		await expect( page.getByRole( 'heading', { level: 1 } ) ).toContainText(
			'Magic Linking · History'
		);
		const batches = page.locator( '.magiclinking-batch' );
		await expect( batches ).toHaveCount( 2 );
		await expect(
			batches.first().getByRole( 'heading', { level: 2 } )
		).toContainText( '1 link added' );
		await expect(
			batches.nth( 1 ).getByRole( 'heading', { level: 2 } )
		).toContainText( '2 links added' );
		await expect( page.getByText( 'Changes are kept for 90 days.' ) ).toBeVisible();
		await expect( page.locator( '.nav-tab-wrapper' ) ).toHaveCount( 0 );
		await expectNoViolations( page );

		// Detalle del lote de dos enlaces.
		const batch = batches.nth( 1 );
		// El texto del botón cambia a «Hide links»: se localiza por lo que controla.
		const toggle = batch.locator( 'button[aria-controls^="magiclinking-details-"]' );
		await expect( toggle ).toHaveText( 'Show links' );
		await toggle.click();
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect( toggle ).toHaveText( 'Hide links' );
		const table = batch.getByRole( 'table', { name: 'Links in this batch' } );
		await expect( table.getByRole( 'row' ) ).toHaveCount( 3 );
		await expect( table ).toContainText( 'aire acondicionado' );
		await expectNoViolations( page );

		// Deshacer el cambio de A: pide confirmación y no escribe hasta confirmar.
		await table
			.getByRole( 'button', { name: 'Undo change in E2E origen A' } )
			.click();
		const dialog = page.getByRole( 'dialog', { name: 'Undo this change?' } );
		await expect( dialog ).toBeVisible();
		await expectNoViolations( page, '.components-modal__frame' );
		expect( content( 'e2e-origen-a' ) ).toContain( '<a href=' );
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).toBeHidden();
		expect( content( 'e2e-origen-a' ) ).toContain( '<a href=' );

		await table
			.getByRole( 'button', { name: 'Undo change in E2E origen A' } )
			.click();
		await dialog
			.getByRole( 'button', { name: 'Undo change', exact: true } )
			.click();

		const status = batch.getByRole( 'status' );
		await expect( status ).toContainText( '1 link undone.' );
		await expect( status ).toContainText( 'E2E origen A' );
		await expect( batch.getByText( 'Partly undone' ) ).toBeVisible();
		expect( content( 'e2e-origen-a' ) ).toBe(
			'<!-- wp:paragraph -->\n<p>Compra aire acondicionado ya.</p>\n<!-- /wp:paragraph -->'
		);
		expect( content( 'e2e-origen-b' ) ).toContain( '<a href=' );
		await expect(
			table.getByRole( 'button', { name: 'Redo change in E2E origen A' } )
		).toBeVisible();
		await expectNoViolations( page );

		// Rehacer el mismo cambio.
		await table
			.getByRole( 'button', { name: 'Redo change in E2E origen A' } )
			.click();
		await page
			.getByRole( 'dialog', { name: 'Redo this change?' } )
			.getByRole( 'button', { name: 'Redo change', exact: true } )
			.click();
		await expect( status ).toContainText( '1 link added again.' );
		expect( content( 'e2e-origen-a' ) ).toContain( '<a href=' );
		await expect( batch.getByText( 'In place' ).first() ).toBeVisible();
	} );

	test( 'undoes a whole batch, lists what needs attention and keeps the focus under control', async ( {
		page,
	} ) => {
		// B se editó después copiando el enlace: no se puede deshacer solo.
		wp(
			'eval',
			`
			$post = get_page_by_path( 'e2e-origen-b', OBJECT, 'post' );
			preg_match( '#<a href="[^"]+">aire acondicionado</a>#', $post->post_content, $link );
			wp_update_post( array( 'ID' => $post->ID, 'post_content' => wp_slash( $post->post_content . "\\n\\n<!-- wp:paragraph -->\\n<p>" . $link[0] . "</p>\\n<!-- /wp:paragraph -->" ) ) );
			`
		);

		await login( page );
		await page.goto( SCREEN_HISTORY );
		const batch = page.locator( '.magiclinking-batch' ).nth( 1 );
		await batch.getByRole( 'button', { name: 'Undo batch' } ).click();

		const dialog = page.getByRole( 'dialog', { name: 'Undo this batch?' } );
		await expect( dialog ).toContainText( '2 links will be taken out.' );
		await dialog.getByRole( 'button', { name: 'Undo batch' } ).click();
		await expect( dialog ).toBeHidden();

		const status = batch.getByRole( 'status' );
		await expect( status ).toContainText(
			'Undone: 1. Need your attention: 1.'
		);
		await expect( status ).toContainText( 'Needs your attention' );
		await expect(
			status.getByRole( 'link', { name: 'Edit entry' } )
		).toHaveAttribute( 'href', /post=\d+&action=edit/ );
		await expect( batch.getByText( 'Partly undone' ) ).toBeVisible();

		// A volvió a como estaba; B no se tocó.
		expect( content( 'e2e-origen-a' ) ).not.toContain( '<a href=' );
		expect( content( 'e2e-origen-b' ).match( /<a href=/g ) ).toHaveLength( 2 );

		// El foco vuelve a un control del lote, no se pierde en el cuerpo de la página.
		await expect( batch.getByRole( 'button', { name: 'Undo batch' } ) ).toBeFocused();
		await expectNoViolations( page );

		// Rehacer el lote vuelve a poner el enlace de A y deja B como está.
		await batch.getByRole( 'button', { name: 'Redo batch' } ).click();
		await page
			.getByRole( 'dialog', { name: 'Redo this batch?' } )
			.getByRole( 'button', { name: 'Redo batch' } )
			.click();
		await expect( status ).toContainText( '1 link added again.' );
		expect( content( 'e2e-origen-a' ) ).toContain( '<a href=' );
	} );

	test( 'shows the empty state', async ( { page } ) => {
		wp(
			'eval',
			'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_changes" );'
		);
		await login( page );
		await page.goto( SCREEN_HISTORY );

		await expect(
			page.getByText( 'No changes yet. When you add a link with Magic Linking' )
		).toBeVisible();
		await expect( page.locator( '.magiclinking-batch' ) ).toHaveCount( 0 );
		await expectNoViolations( page );
	} );

	test( 'the retention is a setting and the history screen follows it', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( SCREEN_SETTINGS );

		const select = page.getByLabel( 'Keep the history of changes for' );
		await expect( select ).toHaveValue( '90' );
		await select.selectOption( '30' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect(
			page.locator( '.components-notice' ).getByText( 'Settings saved.' )
		).toBeVisible();
		expect(
			wp( 'eval', 'echo get_option( "magiclinking_settings" )["history_retention_days"];' )
		).toBe( '30' );
		await expectNoViolations( page );

		await page.goto( SCREEN_HISTORY );
		await expect( page.getByText( 'Changes are kept for 30 days.' ) ).toBeVisible();
	} );

	test( 'a large batch is undone in the background with progress on screen', async ( {
		page,
	} ) => {
		// Un complemento de prueba baja el límite a 1 cambio y deja que el test ejecute Action Scheduler a mano.
		wp(
			'eval',
			`
			wp_mkdir_p( WPMU_PLUGIN_DIR );
			file_put_contents( WPMU_PLUGIN_DIR . '/magiclinking-e2e.php', '<?php if ( ! defined( "DISABLE_WP_CRON" ) ) { define( "DISABLE_WP_CRON", true ); } add_filter( "magiclinking_undo_sync_limit", static fn() => 1 ); add_filter( "action_scheduler_allow_async_request_runner", "__return_false" );' );
			`
		);
		try {
			await login( page );
			await page.goto( SCREEN_HISTORY );
			const batch = page.locator( '.magiclinking-batch' ).nth( 1 );
			await batch.getByRole( 'button', { name: 'Undo batch' } ).click();
			await page
				.getByRole( 'dialog', { name: 'Undo this batch?' } )
				.getByRole( 'button', { name: 'Undo batch' } )
				.click();

			// En cola: barra de progreso y botones bloqueados; todavía no se ha tocado nada.
			const progress = batch.getByRole( 'progressbar' );
			await expect( progress ).toBeVisible();
			await expect( progress ).toHaveAttribute( 'max', '2' );
			await expect(
				batch.getByText( 'Working in the background: 0 of 2.' )
			).toBeVisible();
			await expect(
				batch.getByRole( 'button', { name: 'Undo batch' } )
			).toBeDisabled();
			expect( content( 'e2e-origen-a' ) ).toContain( '<a href=' );
			await expectNoViolations( page );

			// Action Scheduler lo ejecuta; la pantalla lo sigue cada 3 s y muestra el resultado.
			wp( 'action-scheduler', 'run' );
			await expect( batch.getByRole( 'status' ) ).toContainText(
				'2 links undone.',
				{ timeout: 15_000 }
			);
			await expect( progress ).toBeHidden();
			await expect( batch.getByText( 'Undone', { exact: true } ).first() ).toBeVisible();
			expect( content( 'e2e-origen-a' ) ).not.toContain( '<a href=' );
			expect( content( 'e2e-origen-b' ) ).not.toContain( '<a href=' );
			await expectNoViolations( page );
		} finally {
			wp(
				'eval',
				'@unlink( WPMU_PLUGIN_DIR . "/magiclinking-e2e.php" );'
			);
		}
	} );

	test( 'an editor can use the screen', async ( { page } ) => {
		await login( page, 'e2e_editor' );
		await page.goto( SCREEN_HISTORY );
		await expect( page.locator( '.magiclinking-batch' ) ).toHaveCount( 2 );
	} );
} );
