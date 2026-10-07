import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { login, wp } from './wp';

/**
 * Panel del editor (F1-11): barra lateral de Gutenberg y caja del editor clásico.
 * Salientes (el enlace se pone en el cliente, sin escribir en el servidor), entrantes y accesibilidad.
 */

const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

const block = ( text: string ) =>
	`<!-- wp:paragraph -->\\n<p>${ text }</p>\\n<!-- /wp:paragraph -->`;

interface Ids {
	bomba: number;
	origen: number;
	aislamiento: number;
}

/** Crea el sitio de prueba: un origen con un párrafo enlazable, el destino, una entrada de otro autor y una clásica. */
function seedEditor( ): Ids {
	const out = wp(
		'eval',
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'pe-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'docs', 'links', 'jobs', 'changes' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
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
	return JSON.parse( out.trim().split( '\n' ).pop() ?? '{}' ) as Ids;
}

/** Contenido guardado de una entrada. */
function content( id: number ): string {
	return wp(
		'eval',
		`echo get_post( ${ id } )->post_content;`
	).trim();
}

/** El mismo contenido sin el primer enlace: tiene que ser idéntico al original. */
function withoutLink( html: string ): string {
	return html.replace( /<a href="[^"]*"[^>]*>([^<]*)<\/a>/, '$1' );
}

/** Abre una entrada en Gutenberg con la guía de bienvenida desactivada. */
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

/** Abre la barra lateral desde el botón de la barra superior. */
async function openSidebar( page: Page ): Promise< Locator > {
	await page
		.getByRole( 'button', { name: /^Internal links/ } )
		.first()
		.click();
	const panel = page.locator( '.magiclinking-panel' );
	await expect( panel ).toBeVisible();
	return panel;
}

async function save( page: Page ): Promise< void > {
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
}

const canvas = ( page: Page ) =>
	page.frameLocator( 'iframe[name="editor-canvas"]' );

test.describe( 'Editor panel', () => {
	let ids: Ids;

	test.beforeEach( () => {
		ids = seedEditor();
	} );

	test.afterAll( () => {
		wp(
			'eval',
			`
			@unlink( WPMU_PLUGIN_DIR . '/magiclinking-e2e-classic.php' );
			global $wpdb;
			foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'pe-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
			foreach ( array( 'docs', 'links', 'jobs', 'changes' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
			`
		);
	} );

	test( 'says the site has not been analyzed when there is no index', async ( {
		page,
	} ) => {
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		await expect( panel ).toContainText(
			'The site has not been analyzed yet'
		);
		await expect(
			panel.getByRole( 'link', { name: 'Open the report' } )
		).toBeVisible();
	} );

	test( 'outbound: shows the card, highlights, links in the client and leaves the rest intact', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		const before = content( ids.origen );
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );

		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();
		await expect( card ).toContainText( 'Aislamiento de casas' );
		await expect( card.locator( 'mark' ) ).toHaveText( 'una bomba de' );
		await expect(
			panel.getByRole( 'tab', { name: /^Outbound \(\d+\)/ } )
		).toBeVisible();

		// Resaltado en el lienzo al pasar el ratón; no se toca el contenido.
		await card.hover();
		await expect
			.poll( () =>
				page.evaluate( () => {
					const frame = document.querySelector< HTMLIFrameElement >(
						'iframe[name="editor-canvas"]'
					);
					const win = ( frame?.contentWindow ?? window ) as Window & {
						CSS: { highlights?: Map< string, unknown > };
					};
					return win.CSS.highlights?.has( 'magiclinking' ) ?? false;
				} )
			)
			.toBe( true );
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 0 );

		// Enlazar: el enlace aparece en el editor, sin guardar; el servidor no se toca.
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( card.getByText( 'Link added.' ) ).toBeFocused();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveText(
			'una bomba de'
		);
		expect( content( ids.origen ) ).toBe( before );

		// Deshacer desde la tarjeta quita el enlace del editor; se vuelve a enlazar.
		await card.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 0 );
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveText(
			'una bomba de'
		);

		// Guardar con el editor: solo cambia ese enlace.
		await save( page );
		const after = content( ids.origen );
		expect( after ).toContain( '<a href=' );
		expect( withoutLink( after ) ).toBe( before );
	} );

	test( 'outbound: warns when the text changed and refresh analyzes the editor content', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();

		await page.evaluate( () => {
			// @ts-expect-error wp lo pone WordPress.
			const { select, dispatch } = window.wp.data;
			const first = select( 'core/block-editor' ).getBlocks()[ 0 ];
			dispatch( 'core/block-editor' ).updateBlockAttributes(
				first.clientId,
				{ content: 'Un texto totalmente distinto.' }
			);
		} );
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( card.locator( '.magiclinking-card__status' ) ).toContainText(
			'The text has changed since the suggestion was made'
		);
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 0 );

		const request = page.waitForResponse(
			( response ) =>
				response.url().includes( 'suggestions%2Foutbound' ) ||
				response.url().includes( 'suggestions/outbound' )
		);
		await panel.getByRole( 'button', { name: 'Refresh' } ).first().click();
		const response = await request;
		expect( response.request().method() ).toBe( 'POST' );
		expect( response.request().postData() ).toContain(
			'Un texto totalmente distinto.'
		);
		// La frase que ya no existe en el editor desaparece de la lista.
		await expect( panel ).not.toContainText(
			'La instalación de una bomba de calor'
		);
	} );

	test( 'inbound: shows who can link here, links on the server and undoes', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		const before = content( ids.origen );
		await login( page );
		await openBlockEditor( page, ids.aislamiento );
		const panel = await openSidebar( page );
		await panel.getByRole( 'tab', { name: /^Inbound/ } ).click();

		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();
		await expect( card ).toContainText( 'Guía de calefacción para casas' );
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( card.getByText( /^Link added in/ ) ).toBeVisible();
		await expect( card.getByRole( 'link', { name: 'See in History' } ) ).toBeVisible();
		expect( content( ids.origen ) ).toContain( '<a href=' );

		await card.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( card.getByRole( 'button', { name: /^Link to/ } ) ).toBeVisible();
		expect( content( ids.origen ) ).toBe( before );
	} );

	test( 'inbound: an entry the user cannot edit is shown without the Link button', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		await login( page, 'e2e_author' );
		await openBlockEditor( page, ids.aislamiento );
		const panel = await openSidebar( page );
		await panel.getByRole( 'tab', { name: /^Inbound/ } ).click();

		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();
		await expect( card ).toContainText(
			'You cannot edit this entry, so the link cannot be added from here.'
		);
		await expect( card.getByRole( 'button', { name: /^Link to/ } ) ).toHaveCount( 0 );
		await expect( card.getByRole( 'button', { name: /^Dismiss/ } ) ).toBeVisible();
	} );

	test( 'has no accessibility violations', async ( { page } ) => {
		wp( 'magic-linking', 'index' );
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		await expect( panel.locator( '.magiclinking-card' ).first() ).toBeVisible();

		const run = async () => {
			const results = await new AxeBuilder( { page } )
				.include( '.magiclinking-panel' )
				.withTags( AXE_TAGS )
				.analyze();
			expect( results.violations ).toEqual( [] );
		};
		await run();
		await panel.getByRole( 'tab', { name: /^Inbound/ } ).click();
		await expect( panel ).toBeVisible();
		await run();
	} );

	test( 'classic editor: the box links in TinyMCE and does nothing in the Text tab', async ( {
		page,
	} ) => {
		const clasico = Number(
			wp(
				'eval',
				`echo wp_insert_post( array( 'post_name' => 'pe-clasico', 'post_title' => 'Guía clásica de calefacción', 'post_status' => 'publish', 'post_content' => '<p>La instalación de una bomba de calor reduce el consumo de energía en las casas con buen aislamiento.</p>' ) );`
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
		const before = content( clasico );
		await login( page );
		await page.goto( `/wp-admin/post.php?post=${ clasico }&action=edit` );

		const box = page.locator( '#magiclinking-editor-panel' );
		await expect( box ).toBeVisible();
		const card = box.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();
		const anchor = ( await card.locator( 'mark' ).textContent() ) ?? '';
		expect( anchor ).not.toBe( '' );

		// En la pestaña Texto no se escribe: se explica.
		await page.locator( '#content-html' ).click();
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( card.locator( '.magiclinking-card__status' ) ).toContainText(
			'Switch to the Visual tab'
		);

		await page.locator( '#content-tmce' ).click();
		const frame = page.frameLocator( '#content_ifr' );
		await expect( frame.locator( 'body p' ) ).toBeVisible();
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( frame.locator( 'body a' ) ).toHaveText( anchor );
		expect( content( clasico ) ).toBe( before );

		const axe = await new AxeBuilder( { page } )
			.include( '#magiclinking-editor-panel' )
			.withTags( AXE_TAGS )
			.analyze();
		expect( axe.violations ).toEqual( [] );

		await page.locator( '#publish' ).click();
		await page.waitForURL( /message=1/ );
		const after = content( clasico );
		expect( after ).toContain( '<a href=' );
		// WordPress guarda un solo párrafo sin <p>: se compara sin etiquetas de párrafo.
		const plain = ( html: string ) => html.replace( /<\/?p>/g, '' );
		expect( plain( withoutLink( after ) ) ).toBe( plain( before ) );
	} );
} );
