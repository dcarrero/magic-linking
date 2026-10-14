import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { login, SCREEN_HISTORY, wp } from './wp';

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
		foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
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

/** Filas del Historial registradas desde el editor para una entrada (F1-34). */
function editorRows( id: number ): number {
	return Number(
		wp(
			'eval',
			`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}magiclinking_changes WHERE post_id = %d AND origin = 'editor'", ${ id } ) );`
		).trim()
	);
}

/** Filas del editor registradas y todavía puestas para una entrada. */
function activeEditorRows( id: number ): number {
	return Number(
		wp(
			'eval',
			`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}magiclinking_changes WHERE post_id = %d AND origin = 'editor' AND action = 'insert' AND undone_at IS NULL", ${ id } ) );`
		).trim()
	);
}

/**
 * Quien acaba de salir del editor deja el bloqueo `_edit_lock` vigente 150 s y Deshacer no escribe mientras dure
 * (docs/06 §1.5). Aquí se simula que ya venció.
 * @param id Entrada.
 */
function releaseEditLock( id: number ): void {
	wp( 'eval', `delete_post_meta( ${ id }, '_edit_lock' );` );
}

/** Pasa axe sobre un trozo de la pantalla del Historial. */
async function expectHistoryAccessible( page: Page ): Promise< void > {
	await page.waitForFunction( () =>
		document
			.getAnimations()
			.every( ( animation ) => animation.playState !== 'running' )
	);
	const results = await new AxeBuilder( { page } )
		.include( '.magiclinking-wrap' )
		.withTags( AXE_TAGS )
		.analyze();
	expect( results.violations ).toEqual( [] );
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

/** Escribe texto al final del primer párrafo del lienzo, como lo haría el usuario. */
async function typeAtEnd( page: Page, text: string ): Promise< void > {
	await canvas( page )
		.locator( 'p[data-block]' )
		.first()
		.evaluate( ( element ) => {
			element.focus();
			const range = element.ownerDocument.createRange();
			range.selectNodeContents( element );
			range.collapse( false );
			const selection = element.ownerDocument.getSelection();
			selection?.removeAllRanges();
			selection?.addRange( range );
		} );
	await page.keyboard.type( text );
}

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
			foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
			`
		);
	} );

	test( 'settings: table text is only offered when allowed, and the link gets target and rel', async ( {
		page,
	} ) => {
		const table = wp(
			'eval',
			`
			$cell = 'Instalar una bomba de calor reduce el consumo de energía en las casas con buen aislamiento.';
			$content = "<!-- wp:table -->\\n<figure class=\\"wp-block-table\\"><table class=\\"has-fixed-layout\\"><tbody><tr><td>$cell</td></tr></tbody></table></figure>\\n<!-- /wp:table -->\\n\\n<!-- wp:paragraph -->\\n<p>Un párrafo sin relación alguna con el resto.</p>\\n<!-- /wp:paragraph -->";
			echo wp_insert_post( array( 'post_name' => 'pe-tabla', 'post_title' => 'Guía en una tabla', 'post_content' => $content, 'post_status' => 'publish' ) );
			`
		).trim();
		wp( 'magic-linking', 'index' );
		await login( page );

		// Por defecto, el texto de una tabla no se ofrece.
		await openBlockEditor( page, Number( table ) );
		let panel = await openSidebar( page );
		await expect( panel.locator( '.magiclinking-card' ) ).toHaveCount( 0 );

		// Con las tablas permitidas y los ajustes de enlace, sí, y el enlace sale con ellos.
		wp(
			'option',
			'update',
			'magiclinking_settings',
			'{"link_in_tables":true,"open_in_new_tab":true,"extra_rel":"nofollow"}',
			'--format=json'
		);
		await openBlockEditor( page, Number( table ) );
		panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();
		await card.hover();
		await card.getByRole( 'button', { name: /^Link to/ } ).click();

		const link = canvas( page ).locator( 'td a[href]' );
		await expect( link ).toHaveText( 'bomba de calor' );
		await expect( link ).toHaveAttribute( 'target', '_blank' );
		await expect( link ).toHaveAttribute( 'rel', /noopener/ );
		await expect( link ).toHaveAttribute( 'rel', /nofollow/ );

		// Deshacer desde la tarjeta quita solo el enlace de la celda.
		await card.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 0 );
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( link ).toHaveText( 'bomba de calor' );

		const before = content( Number( table ) );
		await save( page );
		const after = content( Number( table ) );
		expect( after ).toContain( 'target="_blank"' );
		expect( withoutLink( after ) ).toBe( before );
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
		await expect( card.locator( 'mark' ) ).toHaveText( 'bomba de calor' );
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
		await expect( card.locator( '.magiclinking-fold__text' ) ).toBeFocused();
		await expect( card.locator( '.magiclinking-fold__text' ) ).toContainText( 'Link added.' );
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveText(
			'bomba de calor'
		);
		expect( content( ids.origen ) ).toBe( before );

		// La tarjeta enlazada es una fila corta (sin contexto ni motivo), con el destino y Deshacer, y sin fallos de axe.
		await expect( card ).toHaveClass( /is-folded/ );
		await expect( card ).toContainText( 'bomba de calor' );
		await expect( card ).toContainText( 'Aislamiento de casas' );
		await expect( card.locator( '.magiclinking-card__sentence, .magiclinking-card__reasons' ) ).toHaveCount( 0 );
		expect( ( await card.boundingBox() )?.height ?? 999 ).toBeLessThan( 60 );
		const folded = await new AxeBuilder( { page } )
			.include( '.magiclinking-panel' )
			.withTags( AXE_TAGS )
			.analyze();
		expect( folded.violations ).toEqual( [] );

		// Deshacer desde la tarjeta quita el enlace del editor; se vuelve a enlazar.
		await card.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( card ).not.toHaveClass( /is-folded/ );
		await expect( card ).toBeFocused();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 0 );
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveText(
			'bomba de calor'
		);

		// Guardar con el editor: solo cambia ese enlace.
		await save( page );
		const after = content( ids.origen );
		expect( after ).toContain( '<a href=' );
		expect( withoutLink( after ) ).toBe( before );
	} );

	test( 'outbound: the card Undo removes only its link and keeps what was typed after it', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();

		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 1 );
		await typeAtEnd( page, ' ESCRITO' );
		await card.getByRole( 'button', { name: 'Undo' } ).click();

		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 0 );
		await expect( canvas( page ).locator( 'p[data-block]' ).first() ).toContainText(
			'ESCRITO'
		);
	} );

	test( 'outbound: the card Undo removes the link and keeps a title changed after it', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();

		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 1 );
		await page.evaluate( () =>
			// @ts-expect-error wp lo pone WordPress.
			window.wp.data.dispatch( 'core/editor' ).editPost( { title: 'Título nuevo' } )
		);
		await card.getByRole( 'button', { name: 'Undo' } ).click();

		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 0 );
		expect(
			await page.evaluate( () =>
				// @ts-expect-error wp lo pone WordPress.
				window.wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' )
			)
		).toBe( 'Título nuevo' );
	} );

	test( 'outbound: the editor Undo after linking keeps what was typed before the link', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();

		await typeAtEnd( page, ' ANTES' );
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 1 );
		await page.evaluate( () =>
			// @ts-expect-error wp lo pone WordPress.
			window.wp.data.dispatch( 'core/editor' ).undo()
		);

		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 0 );
		await expect( canvas( page ).locator( 'p[data-block]' ).first() ).toContainText(
			'ANTES'
		);
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

	test( 'inbound: a page that no longer exists goes back to the last one instead of showing an empty list', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		// La primera petición dice que hay dos páginas; la segunda, pedida de verdad, llega vacía (la situación
		// tras enlazar la última tarjeta de la última página) y el panel debe volver a la primera.
		let first = true;
		await page.route( /suggestions(\/|%2F)inbound/i, async ( route ) => {
			const response = await route.fetch();
			const body = await response.json();
			if ( new URL( route.request().url() ).searchParams.get( 'page' ) === '1' && first ) {
				first = false;
				body.total_pages = 2;
			}
			await route.fulfill( { status: response.status(), json: body } );
		} );
		await login( page );
		await openBlockEditor( page, ids.aislamiento );
		const panel = await openSidebar( page );
		await panel.getByRole( 'tab', { name: /^Inbound/ } ).click();
		await expect( panel.getByText( 'Page 1 of 2' ) ).toBeVisible();
		const second = page.waitForResponse(
			( response ) =>
				/suggestions(\/|%2F)inbound/i.test( response.url() ) &&
				new URL( response.url() ).searchParams.get( 'page' ) === '2'
		);
		await panel.getByRole( 'button', { name: 'Next' } ).click();
		await second;

		await expect( panel.locator( '.magiclinking-card' ).first() ).toBeVisible();
		await expect( panel ).not.toContainText( 'No other entry has a sentence' );
		await expect( panel.getByRole( 'tab', { name: /^Inbound \(\d+\)/ } ) ).toBeVisible();
	} );

	test( 'inbound: dismissing every card is saved, the count follows and the cards do not come back after reloading', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		await login( page );
		await openBlockEditor( page, ids.aislamiento );
		const panel = await openSidebar( page );
		await panel.getByRole( 'tab', { name: /^Inbound/ } ).click();
		const cards = panel.locator( '.magiclinking-card:not(.is-dismissed)' );
		await expect( cards.first() ).toBeVisible();
		const total = await cards.count();
		for ( let i = 0; i < total; i++ ) {
			const saved = page.waitForResponse(
				( response ) =>
					/suggestions(\/|%2F)dismissals/i.test( response.url() ) &&
					response.request().method() === 'POST'
			);
			await cards.first().getByRole( 'button', { name: /^Dismiss/ } ).click();
			expect( ( await saved ).status() ).toBe( 200 );
		}
		await expect( panel.locator( '.magiclinking-card.is-dismissed' ) ).toHaveCount( total );
		await expect( panel.getByRole( 'tab', { name: 'Inbound (0)' } ) ).toBeVisible();

		// Recargar el editor: lo descartado no vuelve.
		await openBlockEditor( page, ids.aislamiento );
		const again = await openSidebar( page );
		await again.getByRole( 'tab', { name: /^Inbound/ } ).click();
		await expect( again ).toContainText( 'No other entry has a sentence that fits' );
		await expect( again.locator( '.magiclinking-card' ) ).toHaveCount( 0 );
		await expect( again.getByRole( 'tab', { name: 'Inbound (0)' } ) ).toBeVisible();
	} );

	test( 'outbound: a dismissed suggestion does not come back after reloading and can be restored from the panel', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toContainText( 'Aislamiento de casas' );
		const tab = panel.getByRole( 'tab', { name: /^Outbound \(\d+\)/ } );
		const count = Number( ( await tab.textContent() )?.match( /\((\d+)\)/ )?.[ 1 ] );
		expect( count ).toBeGreaterThan( 0 );

		// Descartar: la tarjeta pasa a una fila con Deshacer (con el foco) y el contador baja.
		await card.getByRole( 'button', { name: /^Dismiss/ } ).click();
		const row = panel.locator( '.magiclinking-card.is-dismissed' ).first();
		await expect( row.locator( '.magiclinking-fold__text' ) ).toBeFocused();
		await expect( row.locator( '.magiclinking-fold__text' ) ).toContainText( 'Suggestion dismissed' );
		await expect( tab ).toHaveText( `Outbound (${ count - 1 })` );
		const accessibility = await new AxeBuilder( { page } )
			.include( '.magiclinking-panel' )
			.withTags( AXE_TAGS )
			.analyze();
		expect( accessibility.violations ).toEqual( [] );

		// Deshacer el descarte: vuelve la tarjeta y el contador.
		await row.getByRole( 'button', { name: /^Undo dismissing/ } ).click();
		await expect( panel.locator( '.magiclinking-card.is-dismissed' ) ).toHaveCount( 0 );
		await expect( panel.locator( '.magiclinking-card' ).first() ).toBeFocused();
		await expect( panel.locator( '.magiclinking-card' ).first() ).toContainText( 'Aislamiento de casas' );
		await expect( tab ).toHaveText( `Outbound (${ count })` );

		// Descartar otra vez y recargar: no vuelve, ni con Actualizar.
		await panel
			.locator( '.magiclinking-card' )
			.first()
			.getByRole( 'button', { name: /^Dismiss/ } )
			.click();
		await expect( panel.locator( '.magiclinking-card.is-dismissed' ) ).toHaveCount( 1 );
		await openBlockEditor( page, ids.origen );
		const again = await openSidebar( page );
		await expect( again.getByRole( 'tab', { name: /^Outbound/ } ) ).toBeVisible();
		await expect( again ).not.toContainText( 'Aislamiento de casas' );
		await again.getByRole( 'button', { name: 'Refresh' } ).first().click();
		await expect( again ).not.toContainText( 'Aislamiento de casas' );
		// Con ese destino fuera, la misma frase puede ofrecer otro (una sola sugerencia por frase): el contador no baja a la fuerza.
		await expect( again.getByRole( 'tab', { name: /^Outbound \(\d+\)/ } ) ).toBeVisible();
	} );

	test( 'inbound: dismissing on a page re-requests it so the next page does not skip any suggestion', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		// Una por página: con dos entrantes hay dos páginas y descartar la primera desplaza la lista del servidor.
		await page.route( /suggestions(\/|%2F)inbound/i, async ( route ) => {
			const url = new URL( route.request().url() );
			url.searchParams.set( 'per_page', '1' );
			await route.continue( { url: url.toString() } );
		} );
		await login( page );
		await openBlockEditor( page, ids.aislamiento );
		const panel = await openSidebar( page );
		await panel.getByRole( 'tab', { name: /^Inbound/ } ).click();
		const live = panel.locator( '.magiclinking-card:not(.is-dismissed)' );
		await expect( live ).toHaveCount( 1 );
		await expect( panel.getByText( 'Page 1 of 2' ) ).toBeVisible();
		const first = await live.first().innerText();

		await live.first().getByRole( 'button', { name: /^Dismiss/ } ).click();
		await expect( panel.locator( '.magiclinking-card.is-dismissed' ) ).toHaveCount( 1 );
		// La página se vuelve a pedir: sube la segunda sugerencia y no queda página 2 que saltarse.
		await expect( live ).toHaveCount( 1 );
		expect( await live.first().innerText() ).not.toBe( first );
		await expect( panel.getByRole( 'button', { name: 'Next' } ) ).toHaveCount( 0 );

		// Deshacer: vuelve la descartada y la lista de dos páginas.
		await panel.getByRole( 'button', { name: /^Undo dismissing/ } ).click();
		await expect( panel.locator( '.magiclinking-card.is-dismissed' ) ).toHaveCount( 0 );
		await expect( panel.getByText( 'Page 1 of 2' ) ).toBeVisible();
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
		// Descartar para siempre exige poder editar el origen (docs/04 §11): sin permiso, no hay botón.
		await expect( card.getByRole( 'button', { name: /^Dismiss/ } ) ).toHaveCount( 0 );
	} );

	test( 'folded rows: Refresh and changing tab do not steal the focus, and the row says what happened in visible text', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		const text = card.locator( '.magiclinking-fold__text' );
		await expect( text ).toBeFocused();
		// Texto visible (no solo el glifo) y el destino completo en el DOM.
		await expect( card.locator( '.magiclinking-fold__state' ) ).toHaveText( /Linked/ );
		await expect( card.locator( '.magiclinking-fold__state' ) ).toBeVisible();

		// Cambiar de pestaña y volver monta la fila ya plegada: no roba el foco.
		await panel.getByRole( 'tab', { name: /^Inbound/ } ).click();
		await panel.getByRole( 'tab', { name: /^Outbound/ } ).click();
		await expect( panel.locator( '.magiclinking-fold__text' ).first() ).not.toBeFocused();
		await expect( panel.getByRole( 'tab', { name: /^Outbound/ } ) ).toBeFocused();

		// Actualizar vuelve a pedir la lista: el foco se queda en el botón.
		const refresh = panel.getByRole( 'button', { name: 'Refresh' } ).first();
		await refresh.focus();
		await refresh.click();
		await expect( refresh ).toBeFocused();

	} );

	test( 'inbound: the folded row shows the source entry and a double click on Undo sends a single request', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		await login( page );
		await openBlockEditor( page, ids.aislamiento );
		const panel = await openSidebar( page );
		await panel.getByRole( 'tab', { name: /^Inbound/ } ).click();
		const card = panel.locator( '.magiclinking-card' ).first();
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( card ).toHaveClass( /is-folded/ );
		await expect( card ).toContainText( 'In: Guía de calefacción para casas' );
		const undo = card.getByRole( 'button', { name: /^Undo the link in “Guía de calefacción/ } );
		await expect( undo ).toBeVisible();
		let calls = 0;
		page.on( 'request', ( request ) => {
			if ( request.method() === 'POST' && /undo/i.test( request.url() ) ) {
				calls++;
			}
		} );
		await undo.dblclick();
		await expect( card.getByRole( 'button', { name: /^Link to/ } ) ).toBeVisible();
		await expect( card ).toBeFocused();
		expect( calls ).toBe( 1 );
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

	test( 'history: a link added in the editor is recorded on save, shown as added in the editor and undone from History', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		const before = content( ids.origen );
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();

		// Un enlace que se deshace antes de guardar no cuenta.
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 1 );
		await card.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 0 );
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 1 );

		// Sin guardar, el servidor no sabe nada.
		expect( editorRows( ids.origen ) ).toBe( 0 );

		await save( page );
		const saved = content( ids.origen );
		expect( withoutLink( saved ) ).toBe( before );
		await expect.poll( () => editorRows( ids.origen ) ).toBe( 1 );

		// Guardar otra vez no lo registra de nuevo, y registrar no escribe en la entrada.
		await page.evaluate( () =>
			// @ts-expect-error wp lo pone WordPress.
			window.wp.data.dispatch( 'core/editor' ).editPost( { title: 'Otro título' } )
		);
		await save( page );
		await page.waitForTimeout( 1500 );
		expect( editorRows( ids.origen ) ).toBe( 1 );
		expect( withoutLink( content( ids.origen ) ) ).toBe( before );

		await page.goto( SCREEN_HISTORY );
		const batch = page.locator( '.magiclinking-batch' );
		await expect( batch ).toHaveCount( 1 );
		await expect( batch.getByRole( 'heading', { level: 2 } ) ).toContainText( '1 link added' );
		await expect( batch ).toContainText( 'Added in the editor' );
		await expect( page.locator( '.magiclinking-wrap' ) ).toContainText(
			'Links you add from the editor panel are listed here too once you save the entry.'
		);
		await expectHistoryAccessible( page );

		// Deshacer desde el Historial quita el enlace de la entrada, byte a byte.
		const toggle = batch.locator( 'button[aria-controls^="magiclinking-details-"]' );
		await toggle.click();
		const table = batch.getByRole( 'table', { name: 'Links in this batch' } );
		await expect( table ).toContainText( 'bomba de calor' );
		releaseEditLock( ids.origen );
		await table.getByRole( 'button', { name: /^Undo change in/ } ).click();
		const dialog = page.getByRole( 'dialog', { name: 'Undo this change?' } );
		await dialog.getByRole( 'button', { name: 'Undo change', exact: true } ).click();
		await expect( batch.getByRole( 'status' ) ).toContainText( '1 link undone.' );
		expect( content( ids.origen ) ).toBe( before );
		await expectHistoryAccessible( page );
	} );

	test( 'history: removing a recorded link by hand and saving marks it as undone in History', async ( {
		page,
	} ) => {
		wp( 'magic-linking', 'index' );
		const before = content( ids.origen );
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		await expect( card ).toBeVisible();
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 1 );
		await save( page );
		await expect.poll( () => activeEditorRows( ids.origen ) ).toBe( 1 );

		// El usuario quita el enlace a mano en el bloque y guarda: el servidor lo nota al guardar.
		await page.evaluate( ( html ) => {
			// @ts-expect-error wp lo pone WordPress.
			const { select, dispatch } = window.wp.data;
			const first = select( 'core/block-editor' ).getBlocks()[ 0 ];
			dispatch( 'core/block-editor' ).updateBlockAttributes(
				first.clientId,
				{ content: html }
			);
		}, ( await canvas( page ).locator( 'p[data-block]' ).first().innerText() ) );
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount( 0 );
		await save( page );
		await expect.poll( () => activeEditorRows( ids.origen ) ).toBe( 0 );
		expect( content( ids.origen ) ).not.toContain( '<a href=' );
		expect( content( ids.origen ).replace( /\s+/g, ' ' ) ).toContain( 'bomba de calor' );
		expect( before ).toContain( 'bomba de calor' );

		await page.goto( SCREEN_HISTORY );
		const batch = page.locator( '.magiclinking-batch' );
		await expect( batch ).toContainText( 'Added in the editor' );
		await expect( batch ).toContainText( 'Undone' );
		await expectHistoryAccessible( page );
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

		// Deshacer de la tarjeta quita solo ese enlace y respeta lo escrito después.
		await page.evaluate( () =>
			// @ts-expect-error tinymce lo pone WordPress.
			window.tinymce.get( 'content' ).execCommand( 'mceInsertContent', false, ' ESCRITO' )
		);
		await card.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( frame.locator( 'body a' ) ).toHaveCount( 0 );
		await expect( frame.locator( 'body' ) ).toContainText( 'ESCRITO' );
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( frame.locator( 'body a' ) ).toHaveText( anchor );

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
		expect(
			plain( withoutLink( after ) ).replace( /(\s|&nbsp;)ESCRITO/, '' )
		).toBe( plain( before ) );
	} );
	test( 'history: a link added in the classic editor is recorded after saving and undone from History', async ( {
		page,
	} ) => {
		const clasico = Number(
			wp(
				'eval',
				`echo wp_insert_post( array( 'post_name' => 'pe-clasico-historial', 'post_title' => 'Guía clásica con historial', 'post_status' => 'publish', 'post_content' => '<p>La instalación de una bomba de calor reduce el consumo de energía en las casas con buen aislamiento.</p>' ) );`
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

		const card = page.locator( '#magiclinking-editor-panel .magiclinking-card' ).first();
		await expect( card ).toBeVisible();
		await page.locator( '#content-tmce' ).click();
		const frame = page.frameLocator( '#content_ifr' );
		await expect( frame.locator( 'body p' ) ).toBeVisible();
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( frame.locator( 'body a' ) ).toHaveCount( 1 );
		expect( editorRows( clasico ) ).toBe( 0 );

		// Al guardar la página se recarga; el panel avisa al volver a cargar.
		await page.locator( '#publish' ).click();
		await page.waitForURL( /message=1/ );
		await expect.poll( () => editorRows( clasico ) ).toBe( 1 );

		await page.goto( SCREEN_HISTORY );
		const batch = page.locator( '.magiclinking-batch' );
		await expect( batch ).toHaveCount( 1 );
		await expect( batch ).toContainText( 'Added in the editor' );
		await expectHistoryAccessible( page );

		await batch.locator( 'button[aria-controls^="magiclinking-details-"]' ).click();
		const table = batch.getByRole( 'table', { name: 'Links in this batch' } );
		releaseEditLock( clasico );
		await table.getByRole( 'button', { name: /^Undo change in/ } ).click();
		await page
			.getByRole( 'dialog', { name: 'Undo this change?' } )
			.getByRole( 'button', { name: 'Undo change', exact: true } )
			.click();
		await expect( batch.getByRole( 'status' ) ).toContainText( '1 link undone.' );
		const plain = ( html: string ) => html.replace( /<\/?p>/g, '' ).trim();
		expect( plain( content( clasico ) ) ).toBe( plain( before ) );
	} );
	test( 'history: removing a recorded link in the classic editor and saving marks it as undone', async ( {
		page,
	} ) => {
		const clasico = Number(
			wp(
				'eval',
				`echo wp_insert_post( array( 'post_name' => 'pe-clasico-quitar', 'post_title' => 'Guía clásica para quitar', 'post_status' => 'publish', 'post_content' => '<p>La instalación de una bomba de calor reduce el consumo de energía en las casas con buen aislamiento.</p>' ) );`
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
		const card = page.locator( '#magiclinking-editor-panel .magiclinking-card' ).first();
		await expect( card ).toBeVisible();
		await page.locator( '#content-tmce' ).click();
		const frame = page.frameLocator( '#content_ifr' );
		await expect( frame.locator( 'body p' ) ).toBeVisible();
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( frame.locator( 'body a' ) ).toHaveCount( 1 );
		await page.locator( '#publish' ).click();
		await page.waitForURL( /message=1/ );
		await expect.poll( () => activeEditorRows( clasico ) ).toBe( 1 );

		// Se quita el enlace en TinyMCE y se guarda de nuevo.
		await page.locator( '#content-tmce' ).click();
		await expect( frame.locator( 'body a' ) ).toHaveCount( 1 );
		await page.evaluate( () => {
			// @ts-expect-error tinymce lo pone WordPress.
			const editor = window.tinymce.get( 'content' );
			editor.setContent( editor.getContent().replace( /<a [^>]*>(.*?)<\/a>/, '$1' ) );
		} );
		await page.locator( '#publish' ).click();
		await page.waitForURL( /message=1/ );
		await expect.poll( () => activeEditorRows( clasico ) ).toBe( 0 );
	} );
} );
