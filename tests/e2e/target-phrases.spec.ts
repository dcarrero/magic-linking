import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { login, wp } from './wp';

/**
 * Frases objetivo del usuario (F1-24, D-30): la sección plegable del panel, en el editor de bloques y en el clásico.
 * Una frase con hueco encaja en «reforma de la cocina» y no en «reforma de toda la cocina». Todo con axe.
 */

const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];
const MU = 'magiclinking-e2e-classic.php';

interface Ids {
	destino: number;
	una: number;
	dos: number;
}

function seedPhrases(): Ids {
	const out = wp(
		'eval',
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'tp-%' OR post_name LIKE 'e2e-%' OR post_name LIKE 'entrada-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		@unlink( WPMU_PLUGIN_DIR . '/${ MU }' );
		$block = static fn( $t ) => "<!-- wp:paragraph -->\\n<p>$t</p>\\n<!-- /wp:paragraph -->";
		$make = static fn( $slug, $title, $text ) => wp_insert_post( array( 'post_name' => $slug, 'post_title' => $title, 'post_content' => $block( $text ), 'post_status' => 'publish' ) );
		$destino = $make( 'tp-destino', 'Cocinas a medida', 'Diseñamos cocinas a medida con muebles y encimeras de calidad para cada piso de la ciudad.' );
		$una = $make( 'tp-una', 'Presupuesto de obra', 'Presupuesto y materiales para la reforma de la cocina de un piso antiguo con muebles nuevos y encimera de cuarzo.' );
		$dos = $make( 'tp-dos', 'Presupuesto de piso', 'Presupuesto y materiales para la reforma de toda la cocina de un piso antiguo con muebles nuevos y encimera de cuarzo.' );
		foreach ( array( 'aceite de oliva', 'gazpacho andaluz', 'tortilla de patatas', 'pan de masa madre', 'queso manchego' ) as $i => $dish ) {
			$make( 'tp-receta-' . $i, 'Receta de ' . $dish, 'Una receta sencilla de ' . $dish . ' con ingredientes de temporada, paso a paso y con trucos para que salga jugosa en la mesa.' );
		}
		echo wp_json_encode( array( 'destino' => $destino, 'una' => $una, 'dos' => $dos ) );
		`
	);
	return JSON.parse( out.trim().split( '\n' ).pop() ?? '{}' ) as Ids;
}

async function openBlockEditor( page: Page, id: number ): Promise< void > {
	await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
	await page.waitForFunction(
		() =>
			// @ts-expect-error wp lo pone WordPress.
			window.wp?.data?.select( 'core/block-editor' )?.getBlocks().length >
			0
	);
	await page.evaluate( () => {
		// @ts-expect-error wp lo pone WordPress.
		const prefs = window.wp.data.dispatch( 'core/preferences' );
		prefs.set( 'core/edit-post', 'welcomeGuide', false );
		prefs.set( 'core/edit-post', 'fullscreenMode', false );
	} );
	const close = page.getByRole( 'button', { name: 'Close', exact: true } );
	if ( await close.isVisible().catch( () => false ) ) {
		await close.click();
	}
}

async function openSidebar( page: Page ) {
	await page
		.getByRole( 'button', { name: /^Internal links/ } )
		.first()
		.click();
	const panel = page.locator( '.magiclinking-panel' );
	await expect( panel ).toBeVisible();
	return panel;
}

async function expectAccessible( page: Page, selector: string ) {
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

test.describe( 'Target phrases', () => {
	let ids: Ids;

	test.beforeEach( () => {
		ids = seedPhrases();
		wp( 'magic-linking', 'index' );
	} );

	test.afterAll( () => {
		wp(
			'eval',
			`
			global $wpdb;
			foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'tp-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
			foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
			delete_option( 'magiclinking_settings' );
			@unlink( WPMU_PLUGIN_DIR . '/${ MU }' );
			`
		);
	} );

	test( 'a phrase with a gap links "reforma de la cocina" and not "reforma de toda la cocina", saved from the keyboard-friendly section', async ( {
		page,
	} ) => {
		await login( page );
		await openBlockEditor( page, ids.destino );
		let panel = await openSidebar( page );

		// Cerrada: el contenido no está en el árbol de accesibilidad y el botón dice que está plegado.
		const toggle = panel.getByRole( 'button', { name: 'Target phrases' } );
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		await toggle.click();
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect(
			panel.getByRole( 'button', { name: 'Save phrases' } )
		).toBeDisabled();

		await panel.getByRole( 'button', { name: 'Add phrase' } ).click();
		const group = panel.getByRole( 'group', { name: 'Phrase 1' } );
		await group
			.getByLabel( 'Phrase', { exact: true } )
			.fill( 'reforma de cocina' );
		await group
			.getByLabel( 'Variants (one per line)' )
			.fill( 'obra de cocina' );
		await group
			.getByRole( 'checkbox', { name: 'Allow one word in between' } )
			.check();
		await expectAccessible( page, '.magiclinking-panel' );

		await panel.getByRole( 'button', { name: 'Save phrases' } ).click();
		await expect( panel.getByRole( 'status' ).first() ).toContainText(
			'Target phrases saved: 1 phrase.'
		);
		await expect
			.poll( () =>
				wp(
					'post',
					'meta',
					'get',
					String( ids.destino ),
					'_magiclinking_target_phrases'
				).trim()
			)
			.toContain( 'reforma de cocina' );

		// Sigue ahí al recargar, y las entrantes la usan: una palabra en medio sí, dos no.
		await openBlockEditor( page, ids.destino );
		panel = await openSidebar( page );
		await panel.getByRole( 'button', { name: 'Target phrases' } ).click();
		await expect(
			panel
				.getByRole( 'group', { name: 'Phrase 1' } )
				.getByLabel( 'Phrase', { exact: true } )
		).toHaveValue( 'reforma de cocina' );

		await panel.getByRole( 'tab', { name: /^Inbound/ } ).click();
		const una = panel
			.locator( '.magiclinking-card' )
			.filter( { hasText: 'Presupuesto de obra' } );
		await expect(
			una.locator( '.magiclinking-card__sentence mark' )
		).toHaveText( 'reforma de la cocina' );
		const dos = panel
			.locator( '.magiclinking-card' )
			.filter( { hasText: 'Presupuesto de piso' } );
		await expect( dos.locator( 'mark', { hasText: 'toda' } ) ).toHaveCount(
			0
		);
	} );

	test( 'removing a phrase moves the focus to Add phrase, and the server limit message is shown', async ( {
		page,
	} ) => {
		wp(
			'post',
			'meta',
			'update',
			String( ids.destino ),
			'_magiclinking_target_phrases',
			JSON.stringify( [
				{ phrase: 'cocina a medida', variants: [], gap: false },
			] )
		);
		await login( page );
		await openBlockEditor( page, ids.destino );
		const panel = await openSidebar( page );
		await panel.getByRole( 'button', { name: 'Target phrases' } ).click();

		await panel.getByRole( 'button', { name: 'Add phrase' } ).click();
		await panel
			.getByRole( 'group', { name: 'Phrase 2' } )
			.getByLabel( 'Phrase', { exact: true } )
			.fill( 'una dos tres cuatro cinco seis siete ocho nueve' );
		await panel.getByRole( 'button', { name: 'Save phrases' } ).click();
		await expect( panel.getByRole( 'alert' ) ).toContainText(
			'up to 8 words'
		);

		await panel.getByRole( 'button', { name: 'Remove phrase 2' } ).click();
		await expect(
			panel.getByRole( 'button', { name: 'Add phrase' } )
		).toBeFocused();
		await expect(
			panel.getByRole( 'group', { name: 'Phrase 2' } )
		).toHaveCount( 0 );
	} );

	test( 'classic editor: the same section in the box under the editor', async ( {
		page,
	} ) => {
		wp(
			'eval',
			`
			if ( ! is_dir( WPMU_PLUGIN_DIR ) ) { mkdir( WPMU_PLUGIN_DIR ); }
			file_put_contents( WPMU_PLUGIN_DIR . '/${ MU }', "<?php\\nadd_filter( 'use_block_editor_for_post', '__return_false' );\\n" );
			`
		);
		await login( page );
		await page.goto(
			`/wp-admin/post.php?post=${ ids.destino }&action=edit`
		);
		const box = page.locator( '#magiclinking-editor-panel' );
		await expect(
			box.getByRole( 'button', { name: 'Target phrases' } )
		).toBeVisible();
		await box.getByRole( 'button', { name: 'Target phrases' } ).click();
		await box.getByRole( 'button', { name: 'Add phrase' } ).click();
		await box
			.getByRole( 'group', { name: 'Phrase 1' } )
			.getByLabel( 'Phrase', { exact: true } )
			.fill( 'reforma de cocina' );
		await expectAccessible( page, '#magiclinking-editor-panel' );
		await box.getByRole( 'button', { name: 'Save phrases' } ).click();
		await expect( box.getByRole( 'status' ).first() ).toContainText(
			'saved'
		);
		await expect
			.poll( () =>
				wp(
					'post',
					'meta',
					'get',
					String( ids.destino ),
					'_magiclinking_target_phrases'
				).trim()
			)
			.toContain( 'reforma de cocina' );
	} );
} );
