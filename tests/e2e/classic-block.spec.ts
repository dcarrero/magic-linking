import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { login, openHistoryByBatch, SCREEN_HISTORY, wp } from './wp';

/**
 * Enlazar dentro del bloque Clásico (`core/freeform`) de Gutenberg (F1-41).
 *
 * Una entrada antigua guarda todo su contenido en un único bloque Clásico: HTML en bruto, con párrafos sin `<p>`,
 * `<strong>`, entidades, dobles espacios y `[caption]`. El enlace se inserta sobre la cadena y el resto del HTML tiene
 * que quedar idéntico byte a byte.
 */

const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];
const MU = 'magiclinking-e2e-ai.php';

/** El HTML del bloque Clásico tal como lo guardó el editor clásico (el PHP lo recibe con `\n` reales). */
const CLASSIC = [
	'Para calentar la casa hemos valorado la <strong>aerotermia</strong>, que aprovecha el aire exterior y gasta poco al a&ntilde;o.',
	'En las habitaciones nos gusta la idea del suelo radiante,  porque reparte el calor &amp; no ocupa espacio en las paredes&#8230;',
	'[caption id="attachment_7" align="aligncenter" width="300"]<img src="https://example.org/a.jpg" alt="" width="300" height="200" /> Pie de la foto[/caption]',
	'Y para pagar menos en la factura de la luz, instalaremos placas solares fotovoltaicas en el tejado.',
].join( '\n\n' );

/** Instala el proveedor de IA simulado (el mismo de `ai.spec.ts`). */
function installProvider(): void {
	wp(
		'eval',
		`
		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) { mkdir( WPMU_PLUGIN_DIR, 0777, true ); }
		copy( WP_PLUGIN_DIR . '/magiclinking-wordpress-plugin/tests/e2e/support/ai-provider.php', WPMU_PLUGIN_DIR . '/${ MU }' );
		`
	);
}

function removeProvider(): void {
	wp(
		'eval',
		`
		@unlink( WPMU_PLUGIN_DIR . '/${ MU }' );
		delete_option( 'magiclinking_e2e_ai' );
		delete_transient( 'magiclinking_ai_capabilities' );
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'pe-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		`
	);
}

function aiMode( mode: 'on' | 'off' ): void {
	wp(
		'eval',
		`
		update_option( 'magiclinking_e2e_ai', '${ mode }', false );
		delete_transient( 'magiclinking_ai_capabilities' );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_jobs WHERE type LIKE 'ai\\\\_%'" );
		`
	);
}

/** Origen en el bloque Clásico (sin comentarios de bloque) y tres destinos con sus temas. */
function seed(): number {
	const out = wp(
		'eval',
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'pe-%' OR post_name LIKE 'e2e-%' OR post_name LIKE 'entrada-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed', 'terms', 'postings' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		update_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_wizard_dismissed', '1' );
		$block = static fn( $t ) => "<!-- wp:paragraph -->\\n<p>$t</p>\\n<!-- /wp:paragraph -->";
		$make = static fn( $slug, $title, $content ) => wp_insert_post( array( 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish' ) );
		$make( 'pe-cb-aerotermia', 'Aerotermia: guía completa', $block( 'La aerotermia aprovecha la energía del aire exterior para calentar la vivienda con una bomba de calor eficiente.' ) . $block( 'El consumo de una instalación de aerotermia depende del aislamiento y de la zona climática.' ) );
		$make( 'pe-cb-suelo', 'Suelo radiante: ventajas', $block( 'El suelo radiante reparte el calor de forma uniforme por toda la vivienda con agua a baja temperatura.' ) . $block( 'Instalar suelo radiante exige obra, pero el confort y el ahorro compensan en una casa nueva.' ) );
		$make( 'pe-cb-placas', 'Ver Brujas en un día... desde las alturas', $block( 'Las placas solares fotovoltaicas producen electricidad para el autoconsumo y reducen la factura de la luz.' ) . $block( 'Una instalación de placas solares con baterías permite almacenar la energía sobrante del día.' ) );
		foreach ( array( 'aceite de oliva', 'gazpacho andaluz', 'tortilla de patatas', 'pan de masa madre', 'queso manchego', 'cocina mediterránea', 'arroz con verduras', 'lentejas estofadas' ) as $d ) { $make( 'pe-cb-receta-' . sanitize_title( $d ), 'Receta de ' . $d, $block( 'Una receta sencilla de ' . $d . ' con ingredientes de temporada, paso a paso y con trucos para que salga jugosa en la mesa.' ) ); }
		$classic = ${ JSON.stringify( CLASSIC ) };
		echo $make( 'pe-cb-origen', 'Reformar una casa vieja', $classic );
		`
	);
	wp( 'magic-linking', 'index' );
	return Number( out.trim().split( '\n' ).pop() );
}

function content( id: number ): string {
	return wp( 'eval', `echo get_post( ${ id } )->post_content;` ).replace(
		/\n$/,
		''
	);
}

/** El mismo HTML sin los `<a …>` y `</a>`: tiene que ser idéntico al original. */
function withoutLinks( html: string ): string {
	return html.replace( /<a href="[^"]*"[^>]*>/g, '' ).replace( /<\/a>/g, '' );
}

function editorRows( id: number ): { total: number; active: number } {
	return JSON.parse(
		wp(
			'eval',
			`global $wpdb; echo wp_json_encode( array( 'total' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}magiclinking_changes WHERE post_id = %d AND origin = 'editor'", ${ id } ) ), 'active' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}magiclinking_changes WHERE post_id = %d AND origin = 'editor' AND action = 'insert' AND undone_at IS NULL", ${ id } ) ) ) );`
		)
			.trim()
			.split( '\n' )
			.pop() ?? '{}'
	);
}

function aiRecommended( id: number ): number {
	return Number(
		wp(
			'eval',
			`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}magiclinking_changes WHERE post_id = %d AND origin = 'editor' AND ai_recommended = 1", ${ id } ) );`
		).trim()
	);
}

function releaseEditLock( id: number ): void {
	wp( 'eval', `delete_post_meta( ${ id }, '_edit_lock' );` );
}

async function expectAccessible( page: Page, selector: string ): Promise< void > {
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

/** HTML del único bloque del editor, tal como lo tiene el almacén (no lo que pinta TinyMCE). */
function blockContent( page: Page ): Promise< { name: string; content: string }[] > {
	return page.evaluate( () =>
		// @ts-expect-error wp lo pone WordPress.
		window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()
			.map( ( b: { name: string; attributes: { content: string } } ) => ( {
				name: b.name,
				content: b.attributes.content,
			} ) )
	);
}

test.describe( 'Classic block in Gutenberg', () => {
	let origen: number;
	let hasAi = true;

	test.beforeAll( () => {
		installProvider();
		hasAi =
			wp( 'eval', 'echo function_exists( "wp_ai_client_prompt" ) ? "1" : "0";' ).trim() ===
			'1';
	} );

	test.afterAll( () => {
		removeProvider();
	} );

	test.beforeEach( () => {
		aiMode( 'off' );
		origen = seed();
	} );

	test( 'links inside the Classic block, keeps the rest of the HTML byte for byte, records it in History and undoes it', async ( {
		page,
	} ) => {
		expect( content( origen ) ).toBe( CLASSIC );
		await login( page );
		await openBlockEditor( page, origen );
		const opened = await blockContent( page );
		expect( opened.map( ( b ) => b.name ) ).toEqual( [ 'core/freeform' ] );
		// Gutenberg aplica `wpautop` al abrir un bloque Clásico (pone los `<p>`): ese es el HTML del que se parte, y es
		// el que el editor guardaría aunque no se enlazara nada. «Idéntico» se mide contra él.
		const before = opened[ 0 ]?.content ?? '';
		expect( before ).toContain( '<p>' );
		const panel = await openSidebar( page );
		const cards = panel.locator( '.magiclinking-card' );
		await expect( cards.first() ).toBeVisible();
		const total = await cards.count();
		expect( total ).toBeGreaterThanOrEqual( 2 );

		// Se enlaza cada tarjeta: ninguna acaba en «el texto ha cambiado».
		for ( let i = 0; i < total; i++ ) {
			const card = cards.nth( i );
			await card.getByRole( 'button', { name: /^Link to/ } ).click();
			await expect( card.locator( '.magiclinking-fold__text' ) ).toContainText( 'Link added.' );
		}

		// En el almacén del editor: solo se añadieron `<a>` y `</a>`.
		const [ block ] = await blockContent( page );
		expect( block?.content.match( /<a href=/g )?.length ).toBe( total );
		expect( withoutLinks( block?.content ?? '' ) ).toBe( before );
		// Todavía no se ha guardado nada en el servidor.
		expect( content( origen ) ).toBe( CLASSIC );
		// El bloque lo enseña con los enlaces puestos.
		await expect
			.poll( async () => canvas( page ).locator( 'a[href]' ).count() )
			.toBe( total );

		// Guardar: el editor quita los `<p>` que puso al abrir; la entrada es el HTML original con los enlaces, nada más.
		await save( page );
		const saved = content( origen );
		expect( saved.match( /<a href=/g )?.length ).toBe( total );
		expect( withoutLinks( saved ) ).toBe( CLASSIC );
		await expect.poll( () => editorRows( origen ).active ).toBe( total );

		// Historial: aparece como añadido desde el panel del editor y se deshace desde ahí, byte a byte.
		await openHistoryByBatch( page );
		const batch = page.locator( '.magiclinking-batch' );
		await expect( batch ).toHaveCount( 1 );
		await expect( batch ).toContainText( 'Added from the editor panel' );
		await expectAccessible( page, '.magiclinking-wrap' );
		releaseEditLock( origen );
		await batch.getByRole( 'button', { name: /^Undo batch/ } ).click();
		const dialog = page.getByRole( 'dialog' );
		await dialog.getByRole( 'button', { name: /^Undo/ } ).last().click();
		await expect( batch.getByRole( 'status' ) ).toContainText( /links? undone/ );
		expect( content( origen ) ).toBe( CLASSIC );
	} );

	test( 'the editor Undo (Ctrl+Z) after linking removes the link', async ( {
		page,
	} ) => {
		await login( page );
		await openBlockEditor( page, origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();

		const before = ( await blockContent( page ) )[ 0 ]?.content;
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( card.locator( '.magiclinking-fold__text' ) ).toContainText( 'Link added.' );
		expect( ( await blockContent( page ) )[ 0 ]?.content ).toContain( '<a href=' );

		// Con el foco en el título del editor, como lo haría el usuario.
		await canvas( page ).getByRole( 'textbox', { name: 'Add title' } ).click();
		await page.keyboard.press( 'ControlOrMeta+z' );
		await expect
			.poll( async () => ( await blockContent( page ) )[ 0 ]?.content )
			.toBe( before );
	} );

	test( 'highlights the anchor in the Classic block when the pointer is over the card, without touching the content', async ( {
		page,
	} ) => {
		await login( page );
		await openBlockEditor( page, origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();
		const before = ( await blockContent( page ) )[ 0 ]?.content;

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
		expect( ( await blockContent( page ) )[ 0 ]?.content ).toBe( before );
	} );

	test( 'the card Undo removes only its link from the Classic block', async ( {
		page,
	} ) => {
		await login( page );
		await openBlockEditor( page, origen );
		const panel = await openSidebar( page );
		const cards = panel.locator( '.magiclinking-card' );
		await expect( cards.first() ).toBeVisible();

		const before = ( await blockContent( page ) )[ 0 ]?.content ?? '';
		await cards.first().getByRole( 'button', { name: /^Link to/ } ).click();
		await cards.nth( 1 ).getByRole( 'button', { name: /^Link to/ } ).click();
		expect(
			( await blockContent( page ) )[ 0 ]?.content.match( /<a href=/g )?.length
		).toBe( 2 );

		await cards.first().getByRole( 'button', { name: 'Undo' } ).click();
		const [ block ] = await blockContent( page );
		expect( block?.content.match( /<a href=/g )?.length ).toBe( 1 );
		expect( withoutLinks( block?.content ?? '' ) ).toBe(
			withoutLinks( before )
		);
	} );

	test( 'says honestly why it cannot link: moved into a heading is not "the text has changed"', async ( {
		page,
	} ) => {
		await login( page );
		await openBlockEditor( page, origen );
		const panel = await openSidebar( page );
		const cards = panel.locator( '.magiclinking-card' );
		await expect( cards.first() ).toBeVisible();

		// El usuario convierte el primer párrafo en un encabezado dentro del bloque Clásico: la frase sigue ahí.
		await page.evaluate( () => {
			// @ts-expect-error wp lo pone WordPress.
			const { select, dispatch } = window.wp.data;
			const [ first ] = select( 'core/block-editor' ).getBlocks();
			const [ head, ...rest ] = first.attributes.content.split( '\n\n' );
			dispatch( 'core/block-editor' ).updateBlockAttributes( first.clientId, {
				content: [ `<h2>${ head }</h2>`, ...rest ].join( '\n\n' ),
			} );
		} );
		const status = cards.first().locator( '.magiclinking-card__status' );
		await cards.first().getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( status ).toContainText( 'cannot add a link' );
		await expect( status ).not.toContainText( 'text has changed' );
		await expect( status ).toContainText( 'Add the link by hand' );

		// Si la frase ya no está, sí es «ha cambiado».
		await page.evaluate( () => {
			// @ts-expect-error wp lo pone WordPress.
			const { select, dispatch } = window.wp.data;
			const [ first ] = select( 'core/block-editor' ).getBlocks();
			dispatch( 'core/block-editor' ).updateBlockAttributes( first.clientId, {
				content: 'Un texto totalmente distinto.',
			} );
		} );
		await cards.first().getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( status ).toContainText( 'The text has changed since the suggestion was made' );
		await expectAccessible( page, '.magiclinking-panel' );
	} );

	test( 'History shows the destination titles as text, without entities', async ( {
		page,
	} ) => {
		await login( page );
		await openBlockEditor( page, origen );
		const panel = await openSidebar( page );
		const cards = panel.locator( '.magiclinking-card' );
		await expect( cards.first() ).toBeVisible();
		const total = await cards.count();
		for ( let i = 0; i < total; i++ ) {
			await cards.nth( i ).getByRole( 'button', { name: /^Link to/ } ).click();
		}
		await save( page );
		await expect.poll( () => editorRows( origen ).active ).toBe( total );

		await page.goto( SCREEN_HISTORY );
		const wrap = page.locator( '.magiclinking-wrap' );
		await expect( wrap ).toContainText( 'Reformar una casa vieja' );
		await expect( wrap ).toContainText( 'Ver Brujas en un día… desde las alturas' );
		await expect( wrap ).not.toContainText( '&#8230;' );
		await expect( wrap ).not.toContainText( '&amp;' );
	} );

	test( 'with AI: Improve with AI orders the cards and the links still go into the Classic block and are marked in History', async ( {
		page,
	} ) => {
		test.skip( ! hasAi, 'Necesita el cliente de IA de WordPress 7.0 o posterior.' );
		aiMode( 'on' );
		await login( page );
		await openBlockEditor( page, origen );
		const panel = await openSidebar( page );
		const cards = panel.locator( '.magiclinking-card' );
		await expect( cards.first() ).toBeVisible();
		await panel.getByRole( 'button', { name: 'Improve with AI' } ).click();
		await expect( panel ).toContainText( 'Ordered by AI.' );

		const total = await cards.count();
		expect( total ).toBeGreaterThanOrEqual( 2 );
		let recommended = 0;
		for ( let i = 0; i < total; i++ ) {
			const card = cards.nth( i );
			if ( ( ( await card.textContent() ) ?? '' ).includes( 'Recommended by the AI' ) ) {
				recommended++;
				const use = card.getByRole( 'button', { name: /^Use the anchor/ } );
				if ( ( await use.count() ) > 0 ) {
					await use.click();
				}
			}
			await card.getByRole( 'button', { name: /^Link to/ } ).click();
			await expect( card.locator( '.magiclinking-fold__text' ) ).toContainText( 'Link added.' );
		}
		expect( recommended ).toBeGreaterThanOrEqual( 1 );

		await save( page );
		const saved = content( origen );
		expect( saved.match( /<a href=/g )?.length ).toBe( total );
		expect( withoutLinks( saved ) ).toBe( CLASSIC );
		await expect.poll( () => editorRows( origen ).active ).toBe( total );
		await expect.poll( () => aiRecommended( origen ) ).toBe( recommended );

		await page.goto( SCREEN_HISTORY );
		await expect(
			page.locator( 'td[data-label="Source"]' ).filter( { hasText: /^Editor panel · AI/ } )
		).toHaveCount( recommended );
		await expectAccessible( page, '.magiclinking-wrap' );
	} );

	test( 'has no accessibility violations in the panel with the Classic block', async ( {
		page,
	} ) => {
		await login( page );
		await openBlockEditor( page, origen );
		const panel = await openSidebar( page );
		await expect( panel.locator( '.magiclinking-card' ).first() ).toBeVisible();
		await expectAccessible( page, '.magiclinking-panel' );
	} );
} );
