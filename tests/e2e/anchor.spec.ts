import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { login, wp } from './wp';

/**
 * Más frases y ancla ajustable (F1-22, D-30): la tarjeta del panel ofrece otras frases del mismo origen y deja ajustar
 * el tramo enlazado dentro de la frase, solo con teclado; el servidor lo vuelve a comprobar. Gutenberg y editor clásico,
 * salientes (en el cliente) y entrantes (en el servidor), con axe.
 */

const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

const PARAGRAPHS = [
	'Instalar una bomba de calor eficiente reduce el consumo de energía en casa.',
	'Una bomba de calor moderna se amortiza en pocos años de uso diario.',
	'Con una bomba de calor y paneles solares la factura de la luz baja mucho.',
];

interface Ids {
	bomba: number;
	origen: number;
}

/** Destino y un origen con la misma mención en tres frases; tres frases alternativas activadas. */
function seedAnchor(): Ids {
	const out = wp(
		'eval',
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'pe-%' OR post_name LIKE 'e2e-%' OR post_name LIKE 'entrada-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		update_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_wizard_dismissed', '1' );
		update_option( 'magiclinking_settings', array( 'alternatives' => 2 ) );
		$block = static fn( $t ) => "<!-- wp:paragraph -->\\n<p>$t</p>\\n<!-- /wp:paragraph -->";
		$make = static fn( $slug, $title, $content ) => wp_insert_post( array( 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish' ) );
		$bomba = $make( 'pe-bomba', 'Bomba de calor: cuánto se ahorra', $block( 'La bomba de calor aerotérmica extrae energía del aire exterior. Una bomba de calor bien dimensionada ahorra mucho en calefacción.' ) );
		$origen = $make( 'pe-origen', 'Guía de calefacción para casas', implode( "\\n\\n", array_map( $block, json_decode( '${ JSON.stringify( PARAGRAPHS ) }', true ) ) ) );
		echo wp_json_encode( array( 'bomba' => $bomba, 'origen' => $origen ) );
		`
	);
	const ids = JSON.parse( out.trim().split( '\n' ).pop() ?? '{}' ) as Ids;
	wp( 'magic-linking', 'index' );
	return ids;
}

/**
 * Contenido guardado de una entrada.
 *
 * @param id Entrada.
 */
function content( id: number ): string {
	return wp( 'eval', `echo get_post( ${ id } )->post_content;` ).trim();
}

/**
 * El mismo contenido sin el primer enlace.
 *
 * @param html Contenido.
 */
function withoutLink( html: string ): string {
	return html.replace( /<a href="[^"]*"[^>]*>([^<]*)<\/a>/, '$1' );
}

/** Pasa axe sobre el panel. */
async function expectAccessible( page: Page, scope: string ): Promise< void > {
	await page.waitForFunction( () =>
		document
			.getAnimations()
			.every( ( animation ) => animation.playState !== 'running' )
	);
	const results = await new AxeBuilder( { page } )
		.include( scope )
		.withTags( AXE_TAGS )
		.analyze();
	expect( results.violations ).toEqual( [] );
}

/**
 * Abre una entrada en Gutenberg con la guía de bienvenida desactivada.
 *
 * @param page Página.
 * @param id   Entrada.
 */
async function openBlockEditor( page: Page, id: number ): Promise< void > {
	await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
	await page.waitForFunction(
		() =>
			// @ts-expect-error wp lo pone WordPress.
			window.wp?.data?.select( 'core/block-editor' )?.getBlocks().length > 0
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

/**
 * Abre la barra lateral desde el botón de la barra superior.
 *
 * @param page Página.
 */
async function openSidebar( page: Page ): Promise< Locator > {
	await page
		.getByRole( 'button', { name: /^Internal links/ } )
		.first()
		.click();
	const panel = page.locator( '.magiclinking-panel' );
	await expect( panel ).toBeVisible();
	return panel;
}

const canvas = ( page: Page ) =>
	page.frameLocator( 'iframe[name="editor-canvas"]' );

/**
 * Ajusta el ancla de una tarjeta solo con el teclado: foco en el botón, Intro, flechas, Intro.
 *
 * @param page Página.
 * @param card Tarjeta.
 * @param keys Teclas a pulsar dentro del control antes de confirmar.
 */
async function adjustWithKeyboard(
	page: Page,
	card: Locator,
	keys: string[]
): Promise< void > {
	const adjust = card.getByRole( 'button', { name: /^Adjust anchor/ } );
	await adjust.focus();
	await page.keyboard.press( 'Enter' );
	const group = card.getByRole( 'group', { name: 'Adjust anchor' } );
	await expect( group ).toBeFocused();
	for ( const key of keys ) {
		await page.keyboard.press( key );
	}
}

test.describe( 'More sentences and adjustable anchor', () => {
	let ids: Ids;

	test.beforeEach( () => {
		ids = seedAnchor();
	} );

	test.afterAll( () => {
		wp(
			'eval',
			`
			@unlink( WPMU_PLUGIN_DIR . '/magiclinking-e2e-classic.php' );
			global $wpdb;
			foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'pe-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
			foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
			delete_option( 'magiclinking_settings' );
			`
		);
	} );

	test( 'outbound: the anchor is adjusted with the keyboard only, in the editor, and the rest stays intact', async ( {
		page,
	} ) => {
		const before = content( ids.origen );
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card.locator( '.magiclinking-card__sentence mark' ) ).toHaveText( 'bomba de calor' );

		// Extiende por la derecha con la flecha y confirma con Intro; el foco vuelve al botón.
		await adjustWithKeyboard( page, card, [ 'ArrowRight' ] );
		await expect( card.getByRole( 'status' ) ).toContainText( /, 4 words/ );
		await expectAccessible( page, '.magiclinking-panel' );
		await page.keyboard.press( 'Enter' );
		const adjust = card.getByRole( 'button', { name: /^Adjust anchor/ } );
		await expect( adjust ).toBeFocused();
		const anchor = ( await card.locator( '.magiclinking-card__sentence mark' ).textContent() ) ?? '';
		expect( anchor.split( ' ' ) ).toHaveLength( 4 );
		expect( anchor.startsWith( 'bomba de calor ' ) ).toBe( true );

		// Enlazar con el teclado.
		const link = card.getByRole( 'button', { name: /^Link to/ } );
		await link.focus();
		await page.keyboard.press( 'Enter' );
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveText( anchor );
		await expect( card.getByText( 'Link added.' ) ).toBeAttached();

		// Se guarda como siempre y fuera del enlace el contenido es idéntico byte a byte.
		await page.evaluate( () =>
			// @ts-expect-error wp lo pone WordPress.
			window.wp.data.dispatch( 'core/editor' ).savePost()
		);
		await page.waitForFunction(
			() =>
				// @ts-expect-error wp lo pone WordPress.
				! window.wp.data.select( 'core/editor' ).isSavingPost() &&
				// @ts-expect-error wp lo pone WordPress.
				! window.wp.data.select( 'core/editor' ).isEditedPostDirty()
		);
		const after = content( ids.origen );
		expect( after ).toContain( `>${ anchor }</a>` );
		expect( withoutLink( after ) ).toBe( before );
	} );

	test( 'outbound: an anchor the server rejects is explained in words, cannot be applied and Escape cancels', async ( {
		page,
	} ) => {
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		const original = ( await card.locator( '.magiclinking-card__sentence mark' ).textContent() ) ?? '';

		// Una palabra a la izquierda ("una", "Con una"...): empieza por una palabra vacía.
		await adjustWithKeyboard( page, card, [ 'Shift+ArrowLeft' ] );
		const status = card.getByRole( 'status' );
		await expect( status ).toContainText( 'cannot start with a filler word' );
		await expect(
			card.getByRole( 'button', { name: 'Apply anchor' } )
		).toBeDisabled();
		await expect( card.getByRole( 'button', { name: /^Link to/ } ) ).toBeDisabled();
		await expectAccessible( page, '.magiclinking-panel' );

		// Intro no lo aplica.
		await page.keyboard.press( 'Enter' );
		await expect( status ).toContainText( 'cannot start with a filler word' );
		await expect( card.getByRole( 'group', { name: 'Adjust anchor' } ) ).toBeVisible();

		// Escape cancela, no cierra la barra lateral y devuelve el foco al botón.
		await page.keyboard.press( 'Escape' );
		await expect(
			card.getByRole( 'button', { name: /^Adjust anchor/ } )
		).toBeFocused();
		await expect( card.locator( '.magiclinking-card__sentence mark' ) ).toHaveText( original );
		await expect( panel ).toBeVisible();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 0 );
	} );

	test( 'outbound: Tab leaves the adjusting control and nothing is trapped', async ( {
		page,
	} ) => {
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();

		await adjustWithKeyboard( page, card, [] );
		const group = card.getByRole( 'group', { name: 'Adjust anchor' } );
		await page.keyboard.press( 'Tab' );
		await expect( group ).not.toBeFocused();
		await expect(
			card.getByRole( 'button', { name: 'Apply anchor' } )
		).toBeFocused();
		await page.keyboard.press( 'Enter' );
		// Sin cambios: se cierra y vuelve el foco.
		await expect(
			card.getByRole( 'button', { name: /^Adjust anchor/ } )
		).toBeFocused();
	} );

	test( 'outbound: More sentences chooses another sentence of the source and links from it', async ( {
		page,
	} ) => {
		const before = content( ids.origen );
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		const first = ( await card.locator( '.magiclinking-card__sentence' ).textContent() ) ?? '';

		const more = card.getByRole( 'button', { name: /^More sentences \(\d\)/ } );
		await expect( more ).toHaveAttribute( 'aria-expanded', 'false' );
		await more.focus();
		await page.keyboard.press( 'Enter' );
		await expect( more ).toHaveAttribute( 'aria-expanded', 'true' );
		const options = card.getByRole( 'radio' );
		expect( await options.count() ).toBeGreaterThanOrEqual( 2 );
		await expect( options.first() ).toBeChecked();
		await expectAccessible( page, '.magiclinking-panel' );

		// Elegir la segunda con el teclado cambia la frase de la tarjeta.
		await options.first().focus();
		await page.keyboard.press( 'ArrowDown' );
		await expect( options.nth( 1 ) ).toBeChecked();
		const chosen = ( await card.locator( '.magiclinking-card__sentence' ).textContent() ) ?? '';
		expect( chosen ).not.toBe( first );

		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		const link = canvas( page ).locator( 'a[href]' );
		await expect( link ).toHaveCount( 1 );
		await expect( canvas( page ).locator( 'p[data-block]' ).filter( { has: link } ) ).toContainText( chosen.replace( /\s+/g, ' ' ).slice( 0, 20 ) );

		await page.evaluate( () =>
			// @ts-expect-error wp lo pone WordPress.
			window.wp.data.dispatch( 'core/editor' ).savePost()
		);
		await page.waitForFunction(
			() =>
				// @ts-expect-error wp lo pone WordPress.
				! window.wp.data.select( 'core/editor' ).isSavingPost() &&
				// @ts-expect-error wp lo pone WordPress.
				! window.wp.data.select( 'core/editor' ).isEditedPostDirty()
		);
		const after = content( ids.origen );
		expect( withoutLink( after ) ).toBe( before );
		// El enlace está en el párrafo de la frase elegida, no en el primero.
		const linked = after
			.split( '\n\n' )
			.find( ( paragraph ) => paragraph.includes( '<a href=' ) );
		expect( linked?.replace( /<[^>]+>/g, '' ).trim() ).toBe( chosen.trim() );
		expect( after.split( '\n\n' )[ 0 ] ).not.toContain( '<a href=' );
	} );

	test( 'inbound: the anchor is adjusted and inserted on the server, with its history and undo', async ( {
		page,
	} ) => {
		const before = content( ids.origen );
		await login( page );
		await openBlockEditor( page, ids.bomba );
		const panel = await openSidebar( page );
		await panel.getByRole( 'tab', { name: /^Inbound/ } ).click();
		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toContainText( 'Guía de calefacción para casas' );

		await adjustWithKeyboard( page, card, [ 'ArrowRight' ] );
		await expect( card.getByRole( 'status' ) ).toContainText( /, 4 words/ );
		await page.keyboard.press( 'Enter' );
		const anchor = ( await card.locator( '.magiclinking-card__sentence mark' ).textContent() ) ?? '';
		expect( anchor.split( ' ' ) ).toHaveLength( 4 );

		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( card.getByText( /^Link added in/ ) ).toBeVisible();
		const after = content( ids.origen );
		expect( after ).toContain( `>${ anchor }</a>` );
		expect( withoutLink( after ) ).toBe( before );

		await expectAccessible( page, '.magiclinking-panel' );
		await card.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( card.getByRole( 'button', { name: /^Link to/ } ) ).toBeVisible();
		expect( content( ids.origen ) ).toBe( before );
	} );

	test( 'classic editor: the anchor is adjusted with the keyboard and linked in TinyMCE', async ( {
		page,
	} ) => {
		const clasico = Number(
			wp(
				'eval',
				`echo wp_insert_post( array( 'post_name' => 'pe-clasico', 'post_title' => 'Guía clásica de calefacción', 'post_status' => 'publish', 'post_content' => '<p>Instalar una bomba de calor eficiente reduce el consumo de energía en casa.</p>' ) );`
			).trim()
		);
		wp( 'magic-linking', 'index' );
		wp(
			'eval',
			`
			if ( ! is_dir( WPMU_PLUGIN_DIR ) ) { mkdir( WPMU_PLUGIN_DIR ); }
			file_put_contents( WPMU_PLUGIN_DIR . '/magiclinking-e2e-classic.php', "<?php\\nadd_filter( 'use_block_editor_for_post', '__return_false' );\\n" );
			`
		);
		await login( page );
		await page.goto( `/wp-admin/post.php?post=${ clasico }&action=edit` );
		const box = page.locator( '#magiclinking-editor-panel' );
		const card = box.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();

		await adjustWithKeyboard( page, card, [ 'ArrowRight' ] );
		await expect( card.getByRole( 'status' ) ).toContainText( /, 4 words/ );
		await page.keyboard.press( 'Enter' );
		const anchor = ( await card.locator( '.magiclinking-card__sentence mark' ).textContent() ) ?? '';
		expect( anchor.split( ' ' ) ).toHaveLength( 4 );

		await expectAccessible( page, '#magiclinking-editor-panel' );
		await page.locator( '#content-tmce' ).click();
		const frame = page.frameLocator( '#content_ifr' );
		await expect( frame.locator( 'body p' ) ).toBeVisible();
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( frame.locator( 'body a' ) ).toHaveText( anchor );
	} );
} );
