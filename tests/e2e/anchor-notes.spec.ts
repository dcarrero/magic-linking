import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { login, wp } from './wp';

/**
 * «Ya enlazado» y aviso de ancla repetida (F1-23, D-30, docs/07 §3.3): la tarjeta avisa con texto de que el ancla ya
 * lleva a otro destino (canibalización) o se usa demasiadas veces hacia el mismo (sobreexplotada), el bloque plegable
 * «Enlaces actuales» lista los destinos ya enlazados con la marca «Ya enlazado», y todo se maneja con teclado y pasa
 * axe, en Gutenberg y en el editor clásico.
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
	guia: number;
}

/** Destino, origen, un destino rival y cuatro entradas que enlazan «bomba de calor» a uno y a otro. */
function seedNotes(): Ids {
	const out = wp(
		'eval',
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'pn-%' OR post_name LIKE 'pe-%' OR post_name LIKE 'e2e-%' OR post_name LIKE 'entrada-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		update_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_wizard_dismissed', '1' );
		update_option( 'magiclinking_settings', array( 'same_anchor_max' => 1 ) );
		$block = static fn( $t ) => "<!-- wp:paragraph -->\\n<p>$t</p>\\n<!-- /wp:paragraph -->";
		$make = static fn( $slug, $title, $content ) => wp_insert_post( array( 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish' ) );
		$bomba = $make( 'pn-bomba', 'Bomba de calor: cuánto se ahorra', $block( 'La bomba de calor aerotérmica extrae energía del aire exterior. Una bomba de calor bien dimensionada ahorra mucho en calefacción.' ) );
		$precios = $make( 'pn-precios', 'Bombas de calor: precios', $block( 'Los precios de las bombas de calor dependen de la potencia y de la marca elegida.' ) );
		$guia = $make( 'pn-guia', 'Guía de ahorro', $block( 'Consejos sencillos para gastar menos en la factura de la luz cada mes.' ) );
		$origen = $make( 'pn-origen', 'Guía de calefacción para casas', implode( "\\n\\n", array_map( $block, json_decode( '${ JSON.stringify( PARAGRAPHS ) }', true ) ) ) . "\\n\\n" . $block( 'Antes de decidir, lee <a href="' . get_permalink( $guia ) . '">nuestra guía de ahorro</a> con calma.' ) );
		foreach ( array( 'uno', 'dos' ) as $n ) {
			$make( 'pn-rival-' . $n, 'Instalador ' . $n, $block( 'Para ahorrar, mira <a href="' . get_permalink( $precios ) . '">bomba de calor</a> y compara con tiempo.' ) );
			$make( 'pn-mismo-' . $n, 'Instalador mismo ' . $n, $block( 'Para ahorrar, mira <a href="' . get_permalink( $bomba ) . '">bomba de calor</a> y compara con tiempo.' ) );
		}
		// Relleno de otros temas: con pocas entradas, cada mención de la bomba de calor le quita peso al término.
		for ( $i = 0; $i < 150; $i++ ) { $make( 'pn-viaje-' . $i, 'Viaje número ' . $i, $block( 'Una ruta por ' . wp_generate_password( 8, false ) . ' con paradas para comer, dormir y recorrer calles estrechas y plazas con fuentes antiguas.' ) ); }
		echo wp_json_encode( array( 'bomba' => $bomba, 'origen' => $origen, 'guia' => $guia ) );
		`
	);
	const ids = JSON.parse( out.trim().split( '\n' ).pop() ?? '{}' ) as Ids;
	wp( 'magic-linking', 'index' );
	return ids;
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

const CANNIBALIZATION =
	/This anchor already links to “Bombas de calor: precios” in 2 entries\./;
const OVERUSED =
	/This anchor is already used in 2 entries for this destination\./;

/**
 * Comprueba los dos avisos de una tarjeta, con texto, y el bloque de enlaces actuales con el teclado.
 *
 * @param page  Página.
 * @param panel Panel del editor.
 * @param scope Selector que pasa axe.
 */
async function expectNotesAndCurrentLinks(
	page: Page,
	panel: Locator,
	scope: string
): Promise< void > {
	const card = panel.locator( '.magiclinking-card' ).first();
	await expect( card.locator( '.magiclinking-card__sentence mark' ) ).toHaveText(
		'bomba de calor'
	);

	// Los dos avisos salen con texto (el icono va oculto a los lectores de pantalla) y en una lista con nombre.
	const notices = card.getByRole( 'list', { name: 'Anchor notices' } );
	await expect( notices.getByRole( 'listitem' ) ).toHaveCount( 2 );
	await expect( notices ).toContainText( CANNIBALIZATION );
	await expect( notices ).toContainText( OVERUSED );
	await expect( notices.locator( '[aria-hidden="true"]' ) ).toHaveCount( 2 );

	// Enlaces actuales: plegado, con el recuento en el nombre; se abre con el teclado.
	const toggle = panel.getByRole( 'button', { name: /^Current links \(1\)/ } );
	await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
	await expect( panel.getByText( 'Already linked' ) ).toBeHidden();
	await toggle.focus();
	await page.keyboard.press( 'Enter' );
	await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
	const row = panel.locator( '.magiclinking-current__item' );
	await expect( row ).toHaveCount( 1 );
	await expect( row ).toContainText( 'Guía de ahorro' );
	await expect( row ).toContainText( 'Already linked' );
	await expect( row ).toContainText( '“nuestra guía de ahorro”' );

	await expectAccessible( page, scope );
}

test.describe( 'Already linked and repeated anchor', () => {
	let ids: Ids;

	test.beforeEach( () => {
		ids = seedNotes();
	} );

	test.afterAll( () => {
		wp(
			'eval',
			`
			@unlink( WPMU_PLUGIN_DIR . '/magiclinking-e2e-classic.php' );
			global $wpdb;
			foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'pn-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
			foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
			delete_option( 'magiclinking_settings' );
			`
		);
	} );

	test( 'block editor: the card says both notices in words and the current links open with the keyboard', async ( {
		page,
	} ) => {
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );

		await expectNotesAndCurrentLinks( page, panel, '.magiclinking-panel' );
	} );

	test( 'block editor: the notices follow the anchor the user adjusts, and a new link joins the current ones', async ( {
		page,
	} ) => {
		await login( page );
		await openBlockEditor( page, ids.origen );
		const panel = await openSidebar( page );
		const card = panel.locator( '.magiclinking-card' ).first();
		const notices = card.getByRole( 'list', { name: 'Anchor notices' } );
		await expect( notices ).toBeVisible();

		// Con otra ancla («bomba de calor eficiente») el servidor ya no ve ese uso: los avisos se van.
		const adjust = card.getByRole( 'button', { name: /^Adjust anchor/ } );
		await adjust.focus();
		await page.keyboard.press( 'Enter' );
		await expect(
			card.getByRole( 'group', { name: 'Adjust anchor' } )
		).toBeFocused();
		await page.keyboard.press( 'ArrowRight' );
		await expect( card.getByRole( 'status' ) ).toContainText( /, 4 words/ );
		await page.keyboard.press( 'Enter' );
		await expect( adjust ).toBeFocused();
		await expect( card.locator( '.magiclinking-card__sentence mark' ) ).toHaveText(
			/^bomba de calor \S+$/
		);
		await expect( notices ).toHaveCount( 0 );

		// Enlazar añade el destino a «Enlaces actuales» sin pedir la lista otra vez.
		const toggle = panel.getByRole( 'button', { name: /^Current links/ } );
		await expect( toggle ).toContainText( '(1)' );
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		await expect( toggle ).toContainText( '(2)' );
		await toggle.focus();
		await page.keyboard.press( 'Enter' );
		await expect( panel.locator( '.magiclinking-current__item' ).filter( {
			hasText: 'Bomba de calor: cuánto se ahorra',
		} ) ).toContainText( 'Already linked' );
		await expectAccessible( page, '.magiclinking-panel' );
	} );

	test( 'inbound: the card of the other entry carries the notice, counting inflected forms of the anchor', async ( {
		page,
	} ) => {
		await login( page );
		await openBlockEditor( page, ids.bomba );
		const panel = await openSidebar( page );
		await panel.getByRole( 'tab', { name: /^Inbound/ } ).click();
		const card = panel
			.locator( '.magiclinking-card' )
			.filter( { hasText: 'Bombas de calor: precios' } );
		await expect( card.locator( '.magiclinking-card__sentence mark' ) ).toHaveText(
			'bombas de calor'
		);

		// «bombas de calor» y «bomba de calor» son la misma ancla para el motor: las dos entradas que ya la llevan a este
		// destino cuentan, y es más que el tope de 1 de los ajustes.
		const notices = card.getByRole( 'list', { name: 'Anchor notices' } );
		await expect( notices.getByRole( 'listitem' ) ).toHaveCount( 1 );
		await expect( notices ).toContainText( OVERUSED );
		await expectAccessible( page, '.magiclinking-panel' );
	} );

	test( 'classic editor: the same notices and current links in the box under the editor', async ( {
		page,
	} ) => {
		// Una sola entrada que menciona la bomba de calor (el motor no repite ancla en la lista): la del editor clásico.
		const clasico = Number(
			wp(
				'eval',
				`
				wp_delete_post( ${ ids.origen }, true );
				echo wp_insert_post( array( 'post_name' => 'pn-clasico', 'post_title' => 'Guía clásica de calefacción', 'post_status' => 'publish', 'post_content' => '<p>Instalar una bomba de calor eficiente reduce el consumo de energía en casa.</p><p>Lee <a href="' . get_permalink( ${ ids.guia } ) . '">nuestra guía de ahorro</a>.</p>' ) );
				if ( ! is_dir( WPMU_PLUGIN_DIR ) ) { mkdir( WPMU_PLUGIN_DIR ); }
				file_put_contents( WPMU_PLUGIN_DIR . '/magiclinking-e2e-classic.php', "<?php\\nadd_filter( 'use_block_editor_for_post', '__return_false' );\\n" );
				`
			).trim()
		);
		wp( 'magic-linking', 'index' );
		await login( page );
		await page.goto( `/wp-admin/post.php?post=${ clasico }&action=edit` );
		const box = page.locator( '#magiclinking-editor-panel' );
		await expect( box.locator( '.magiclinking-card' ).first() ).toBeVisible();

		await expectNotesAndCurrentLinks( page, box, '#magiclinking-editor-panel' );
	} );
} );
