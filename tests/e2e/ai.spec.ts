import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import {
	login,
	openHistoryByBatch,
	openSettingsTab,
	SCREEN,
	SCREEN_HISTORY,
	SCREEN_SETTINGS,
	wp,
} from './wp';

/**
 * IA vía conector (F1-14): botón «Improve with AI» del panel, pestaña IA de Ajustes y aviso «sin conector».
 * El conector es un proveedor simulado (`support/ai-provider.php`) que se instala como mu-plugin: no sale nada a la red.
 */

const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

const MU = 'magiclinking-e2e-ai.php';

type Mode = 'on' | 'fail' | 'off';

/** Pone el modo del conector simulado y borra lo que el plugin tiene guardado de él. */
function aiMode( mode: Mode ): void {
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
		delete_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_ai_notice_dismissed' );
		`
	);
}

/** Un origen con tres frases y un destino para cada una (más recetas de relleno para que el motor distinga temas). */
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
		$make( 'pe-ai-aerotermia', 'Aerotermia: guía completa', $block( 'La aerotermia aprovecha la energía del aire exterior para calentar la vivienda con una bomba de calor eficiente.' ) . $block( 'El consumo de una instalación de aerotermia depende del aislamiento y de la zona climática.' ) );
		$make( 'pe-ai-suelo', 'Suelo radiante: ventajas', $block( 'El suelo radiante reparte el calor de forma uniforme por toda la vivienda con agua a baja temperatura.' ) . $block( 'Instalar suelo radiante exige obra, pero el confort y el ahorro compensan en una casa nueva.' ) );
		$make( 'pe-ai-placas', 'Placas solares fotovoltaicas', $block( 'Las placas solares fotovoltaicas producen electricidad para el autoconsumo y reducen la factura de la luz.' ) . $block( 'Una instalación de placas solares con baterías permite almacenar la energía sobrante del día.' ) );
		foreach ( array( 'aceite de oliva', 'gazpacho andaluz', 'tortilla de patatas', 'pan de masa madre', 'queso manchego', 'cocina mediterránea', 'arroz con verduras', 'lentejas estofadas' ) as $d ) { $make( 'pe-ai-receta-' . sanitize_title( $d ), 'Receta de ' . $d, $block( 'Una receta sencilla de ' . $d . ' con ingredientes de temporada, paso a paso y con trucos para que salga jugosa en la mesa.' ) ); }
		echo $make( 'pe-ai-origen', 'Reformar una casa vieja', $block( 'Para calentar la casa hemos valorado la aerotermia, que aprovecha el aire exterior y gasta poco al año.' ) . $block( 'En las habitaciones nos gusta la idea del suelo radiante, porque reparte el calor y no ocupa espacio en las paredes.' ) . $block( 'Y para pagar menos en la factura de la luz, instalaremos placas solares fotovoltaicas en el tejado.' ) );
		`
	);
	wp( 'magic-linking', 'index' );
	return Number( out.trim().split( '\n' ).pop() );
}

function content( id: number ): string {
	return wp( 'eval', `echo get_post( ${ id } )->post_content;` ).trim();
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

/**
 * Lo que el Historial guardó de la IA para una entrada: filas del editor, recomendadas, con su ancla y modelos.
 * @param id
 */
/**
 * Celdas de origen de una entrada del Historial cuyo texto cumple el patrón.
 * @param entry
 * @param pattern
 */
const sources = ( entry: Locator, pattern: RegExp ) =>
	entry.locator( 'td[data-label="Source"]' ).filter( { hasText: pattern } );

function aiRows( id: number ): {
	total: number;
	recommended: number;
	anchor: number;
	models: string[];
} {
	return JSON.parse(
		wp(
			'eval',
			`
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ai_recommended, ai_anchor, ai_model FROM {$wpdb->prefix}magiclinking_changes WHERE post_id = %d AND action = 'insert' AND origin = 'editor'", ${ id } ), ARRAY_A );
			echo wp_json_encode( array(
				'total' => count( $rows ),
				'recommended' => count( array_filter( $rows, static fn( $r ) => '1' === (string) $r['ai_recommended'] ) ),
				'anchor' => count( array_filter( $rows, static fn( $r ) => '1' === (string) $r['ai_anchor'] ) ),
				'models' => array_values( array_unique( array_filter( array_column( $rows, 'ai_model' ) ) ) ),
			) );
			`
		)
			.trim()
			.split( '\n' )
			.pop() ?? '{}'
	);
}

const canvas = ( page: Page ) =>
	page.frameLocator( 'iframe[name="editor-canvas"]' );

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

test.describe( 'AI through the connector', () => {
	let origen: number;
	// WordPress 6.9 no trae el cliente de IA: ahí solo se prueba que todo queda como sin IA y se explica.
	let hasAi = true;

	test.beforeAll( () => {
		installProvider();
		hasAi = wp( 'eval', 'echo function_exists( "wp_ai_client_prompt" ) ? "1" : "0";' ).trim() === '1';
	} );

	test.afterAll( () => {
		removeProvider();
	} );

	test.beforeEach( () => {
		aiMode( 'on' );
		origen = seed();
	} );

	test( 'editor: Improve with AI orders the suggestions, marks the unfit ones, offers a better anchor and shows the cost', async ( {
		page,
	} ) => {
		test.skip( ! hasAi, 'Necesita el cliente de IA de WordPress 7.0 o posterior.' );
		const before = content( origen );
		await login( page );
		await openBlockEditor( page, origen );
		const panel = await openSidebar( page );

		const cards = panel.locator( '.magiclinking-card' );
		await expect( cards.first() ).toBeVisible();
		expect( await cards.count() ).toBeGreaterThanOrEqual( 2 );
		const titles = async () =>
			cards.locator( '.magiclinking-card__target' ).allTextContents();
		const original = await titles();

		// El botón dice a quién se envía y cuánto costaría; todavía no se ha llamado al modelo.
		const button = panel.getByRole( 'button', { name: 'Improve with AI' } );
		await expect( button ).toBeVisible();
		await expect( button ).toHaveAccessibleDescription( /Fake AI.*gpt-4o-mini.*tokens/ );
		expect(
			wp( 'eval', `global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}magiclinking_jobs WHERE type LIKE 'ai\\\\_%'" );` ).trim()
		).toBe( '0' );

		await button.click();

		await expect( panel ).toContainText( 'Ordered by AI.' );
		await expect( panel ).toContainText( /Used [\d.,]+ tokens/ );
		await expect( panel ).toContainText( 'is marked as not recommended' );
		const reordered = await titles();
		expect( reordered ).not.toEqual( original );
		expect( reordered.sort() ).toEqual( original.sort() );
		await expect( cards.last() ).toContainText(
			'The AI does not see this as a good link.'
		);
		await expect( cards.first() ).toContainText( 'Recommended by the AI' );

		// El ancla mejorada se propone y solo cambia cuando el usuario la acepta.
		const at = await cards.evaluateAll( ( items ) =>
			items.findIndex( ( item ) =>
				( item.textContent ?? '' ).includes( 'The AI suggests this anchor' )
			)
		);
		expect( at ).toBeGreaterThanOrEqual( 0 );
		const card = cards.nth( at );
		const proposal = card.locator( '.magiclinking-card__ai-anchor' );
		await expect( proposal ).toContainText( 'The AI suggests this anchor' );
		const anchor = ( await card.locator( 'mark' ).textContent() ) ?? '';
		expect( anchor ).not.toBe( '' );
		await expectAccessible( page, '.magiclinking-panel' );

		await proposal.getByRole( 'button', { name: /^Use the anchor/ } ).click();
		await expect( card.locator( 'mark' ) ).toHaveText( new RegExp( `^\\p{L}+ ${ anchor }$`, 'u' ) );
		const improved = ( await card.locator( 'mark' ).textContent() ) ?? '';
		await expect( card.locator( '.magiclinking-card__ai-anchor' ) ).toBeFocused();

		// Enlazar usa el ancla aceptada y no toca nada más del contenido.
		await card.getByRole( 'button', { name: /^Link to/ } ).click();
		const link = canvas( page ).locator( 'a[href]' );
		await expect( link ).toHaveText( improved );
		expect( content( origen ) ).toBe( before );

		// Volver al orden del motor.
		await panel.getByRole( 'button', { name: 'Back to the original order' } ).click();
		await expect( panel ).not.toContainText( 'Ordered by AI.' );
	} );

	test( 'history: links added from cards the AI recommended are marked in the History, filtered and accessible', async ( {
		page,
	} ) => {
		test.skip(
			! hasAi,
			'Necesita el cliente de IA de WordPress 7.0 o posterior.'
		);
		await login( page );
		await openBlockEditor( page, origen );
		const panel = await openSidebar( page );
		const cards = panel.locator( '.magiclinking-card' );
		await expect( cards.first() ).toBeVisible();
		await panel.getByRole( 'button', { name: 'Improve with AI' } ).click();
		await expect( panel ).toContainText( 'Ordered by AI.' );

		// Se enlazan todas las tarjetas: las que la IA recomienda (una con su ancla aceptada) y la que no.
		const total = await cards.count();
		expect( total ).toBeGreaterThanOrEqual( 2 );
		let recommended = 0;
		let withAnchor = 0;
		for ( let i = 0; i < total; i++ ) {
			const card = cards.nth( i );
			const text = ( await card.textContent() ) ?? '';
			if ( text.includes( 'Recommended by the AI' ) ) {
				recommended++;
				const use = card.getByRole( 'button', {
					name: /^Use the anchor/,
				} );
				if ( ( await use.count() ) > 0 ) {
					await use.click();
					withAnchor++;
				}
			}
			await card.getByRole( 'button', { name: /^Link to/ } ).click();
		}
		expect( recommended ).toBeGreaterThanOrEqual( 1 );
		expect( recommended ).toBeLessThan( total );
		await expect( canvas( page ).locator( 'a[href]' ) ).toHaveCount(
			total
		);

		await save( page );
		await expect.poll( () => aiRows( origen ).total ).toBe( total );
		expect( aiRows( origen ) ).toEqual( {
			total,
			recommended,
			anchor: withAnchor,
			models: [ 'gpt-4o-mini' ],
		} );

		// Por entrada: «Editor panel · AI» con el modelo, y «Editor panel» a secas en el que no recomendó la IA.
		await page.goto( SCREEN_HISTORY );
		const entry = page.locator( '.magiclinking-entry' );
		await expect( entry ).toHaveCount( 1 );
		const rows = entry.locator( 'tbody tr' );
		await expect( rows ).toHaveCount( total );
		await expect( sources( entry, /^Editor panel · AI/ ) ).toHaveCount(
			recommended
		);
		await expect( sources( entry, /^Editor panel(?! · AI)/ ) ).toHaveCount(
			total - recommended
		);
		await expect( entry.getByText( 'AI model: gpt-4o-mini' ) ).toHaveCount(
			recommended
		);
		await expect(
			entry.getByText( 'Anchor suggested by the AI' )
		).toHaveCount( withAnchor );
		await expectAccessible( page, '.magiclinking-wrap' );

		// El filtro deja solo los recomendados y lo dice con texto.
		await page.getByLabel( 'Recommended by the AI' ).check();
		await expect( rows ).toHaveCount( recommended );
		await expect( sources( entry, /^Editor panel · AI/ ) ).toHaveCount(
			recommended
		);
		await expect( sources( entry, /^Editor panel(?! · AI)/ ) ).toHaveCount(
			0
		);
		await expectAccessible( page, '.magiclinking-wrap' );

		// Por lote, la tarjeta cuenta cuántos recomendó la IA.
		await openHistoryByBatch( page );
		await expect( page.locator( '.magiclinking-batch' ) ).toContainText(
			recommended === 1
				? '1 link recommended by the AI'
				: `${ recommended } links recommended by the AI`
		);
		await expectAccessible( page, '.magiclinking-wrap' );

		// Las filas desplegadas del lote llevan la misma marca, con el modelo.
		await page
			.locator( '.magiclinking-batch' )
			.locator( 'button[aria-controls^="magiclinking-details-"]' )
			.click();
		const batchRows = page.locator( '.magiclinking-batch tbody tr' );
		await expect( batchRows ).toHaveCount( total );
		await expect(
			batchRows.locator( 'td', { hasText: /^Editor panel · AI/ } )
		).toHaveCount( recommended );
		await expect( page.locator( '.magiclinking-batch' ) ).toContainText(
			'AI model: gpt-4o-mini'
		);
		await expectAccessible( page, '.magiclinking-wrap' );
	} );

	test( 'history: a link the AI did not recommend stays a plain editor link', async ( {
		page,
	} ) => {
		test.skip(
			! hasAi,
			'Necesita el cliente de IA de WordPress 7.0 o posterior.'
		);
		await login( page );
		await openBlockEditor( page, origen );
		const panel = await openSidebar( page );
		const cards = panel.locator( '.magiclinking-card' );
		await expect( cards.first() ).toBeVisible();

		// Sin pulsar «Improve with AI» no hay nada que anotar.
		await cards
			.first()
			.getByRole( 'button', { name: /^Link to/ } )
			.click();
		await save( page );
		await expect.poll( () => aiRows( origen ).total ).toBe( 1 );
		expect( aiRows( origen ) ).toEqual( {
			total: 1,
			recommended: 0,
			anchor: 0,
			models: [],
		} );

		await page.goto( SCREEN_HISTORY );
		const entry = page.locator( '.magiclinking-entry' );
		await expect( sources( entry, /^Editor panel(?! · AI)/ ) ).toHaveCount(
			1
		);
		await expect( entry ).not.toContainText( 'AI model' );
	} );

	test( 'editor: when the AI provider fails the panel keeps the lexical order and says so in plain words', async ( {
		page,
	} ) => {
		test.skip( ! hasAi, 'Necesita el cliente de IA de WordPress 7.0 o posterior.' );
		aiMode( 'fail' );
		await login( page );
		await openBlockEditor( page, origen );
		const panel = await openSidebar( page );
		const cards = panel.locator( '.magiclinking-card' );
		await expect( cards.first() ).toBeVisible();
		const original = await cards.locator( '.magiclinking-card__target' ).allTextContents();

		await panel.getByRole( 'button', { name: 'Improve with AI' } ).click();

		await expect( panel ).toContainText(
			'The AI provider had a problem on its side; showing suggestions without AI.'
		);
		expect(
			await cards.locator( '.magiclinking-card__target' ).allTextContents()
		).toEqual( original );
		await expect( panel ).not.toContainText( /HTTP|529|Overloaded|prompt_/ );
		await expect( panel.getByRole( 'button', { name: 'Improve with AI' } ) ).toBeEnabled();
		await expectAccessible( page, '.magiclinking-panel' );
	} );

	test( 'editor: without a connector the panel has no AI button and looks exactly as before', async ( {
		page,
	} ) => {
		test.skip( ! hasAi, 'Necesita el cliente de IA de WordPress 7.0 o posterior.' );
		aiMode( 'off' );
		await login( page );
		await openBlockEditor( page, origen );
		const panel = await openSidebar( page );
		await expect( panel.locator( '.magiclinking-card' ).first() ).toBeVisible();

		await expect( panel.getByRole( 'button', { name: /AI/ } ) ).toHaveCount( 0 );
		await expect( panel.locator( '.magiclinking-ai' ) ).toHaveCount( 0 );
		await expect( panel ).not.toContainText( 'AI' );
	} );

	test( 'the daily limit set to zero hides the button; reaching it disables it with a reason', async ( {
		page,
	} ) => {
		test.skip( ! hasAi, 'Necesita el cliente de IA de WordPress 7.0 o posterior.' );
		wp( 'option', 'update', 'magiclinking_settings', '{"ai_daily_token_limit":0}', '--format=json' );
		await login( page );
		await openBlockEditor( page, origen );
		let panel = await openSidebar( page );
		await expect( panel.locator( '.magiclinking-card' ).first() ).toBeVisible();
		await expect( panel.getByRole( 'button', { name: 'Improve with AI' } ) ).toHaveCount( 0 );

		// Un tope menor que una sola petición: no se dice «mañana», se dice que no alcanza.
		wp( 'option', 'update', 'magiclinking_settings', '{"ai_daily_token_limit":10}', '--format=json' );
		await openBlockEditor( page, origen );
		panel = await openSidebar( page );
		await expect( panel.getByRole( 'button', { name: 'Improve with AI' } ) ).toBeDisabled();
		await expect( panel ).toContainText( 'is lower than what one request needs' );
		await expect( panel ).not.toContainText( 'available again tomorrow' );

		// Un tope que alcanza, pero ya gastado hoy: disponible mañana.
		wp( 'option', 'update', 'magiclinking_settings', '{"ai_daily_token_limit":100000}', '--format=json' );
		wp(
			'eval',
			`global $wpdb; $now = current_time( 'mysql', true ); $wpdb->insert( $wpdb->prefix . 'magiclinking_jobs', array( 'type' => 'ai_rerank', 'status' => 'done', 'ai_tokens_real' => 99990, 'created_at' => $now, 'updated_at' => $now ) );`
		);
		await openBlockEditor( page, origen );
		panel = await openSidebar( page );
		await expect( panel.getByRole( 'button', { name: 'Improve with AI' } ) ).toBeDisabled();
		await expect( panel ).toContainText( 'The daily AI limit you set is reached' );
	} );

	test( 'settings: the AI tab shows the connector, the privacy line, saves the limit and is accessible', async ( {
		page,
	} ) => {
		test.skip( ! hasAi, 'Necesita el cliente de IA de WordPress 7.0 o posterior.' );
		await login( page );
		await page.goto( SCREEN_SETTINGS );
		await openSettingsTab( page, 'AI' );

		const tab = page.locator( '.magiclinking-settings-tabcontent' );
		await expect( tab ).toContainText( 'Fake AI' );
		await expect( tab ).toContainText( 'gpt-4o-mini' );
		await expect( tab ).toContainText(
			'the text of the entries involved is sent to Fake AI through your WordPress connector'
		);
		await expect( tab ).toContainText( 'No AI requests in the last 30 days.' );
		await expect( tab.getByRole( 'link', { name: 'Go to Connectors' } ) ).toHaveAttribute(
			'href',
			/options-connectors\.php/
		);

		const limit = tab.getByLabel( 'Daily AI limit (tokens)' );
		await expect( limit ).toHaveValue( '2000000' );
		await limit.fill( '500000' );
		await tab.getByLabel( 'Preferred model' ).selectOption( 'unpriced-model' );
		await expectAccessible( page, '.magiclinking-settings-page' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.components-notice' ).getByText( /^Settings saved\./ ) ).toBeVisible();

		await page.reload();
		await openSettingsTab( page, 'AI' );
		await expect( page.getByLabel( 'Daily AI limit (tokens)' ) ).toHaveValue( '500000' );
		await expect( page.getByLabel( 'Preferred model' ) ).toHaveValue( 'unpriced-model' );
		await expect( page.locator( '.magiclinking-settings-tabcontent' ) ).toContainText( 'unpriced-model' );
	} );

	test( 'settings: without a connector the AI tab says so and points to Connectors', async ( {
		page,
	} ) => {
		test.skip( ! hasAi, 'Necesita el cliente de IA de WordPress 7.0 o posterior.' );
		aiMode( 'off' );
		await login( page );
		await page.goto( SCREEN_SETTINGS );
		await openSettingsTab( page, 'AI' );

		const tab = page.locator( '.magiclinking-settings-tabcontent' );
		await expect( tab ).toContainText( 'This site has no AI connected.' );
		await expect( tab.getByRole( 'link', { name: 'Go to Connectors' } ) ).toBeVisible();
		await expectAccessible( page, '.magiclinking-settings-page' );
	} );

	test( 'the “no AI connected” notice appears only in the plugin screens and is closed for good', async ( {
		page,
	} ) => {
		test.skip( ! hasAi, 'Necesita el cliente de IA de WordPress 7.0 o posterior.' );
		aiMode( 'off' );
		await login( page );
		await page.goto( SCREEN );

		const notice = page.locator( '.magiclinking-ai-notice' );
		await expect( notice ).toContainText( 'This site has no AI connected.' );
		await expect( notice ).toContainText( 'lexical engine' );
		await expect( notice.getByRole( 'link', { name: 'Go to Connectors' } ) ).toHaveAttribute(
			'href',
			/options-connectors\.php/
		);

		// Fuera de las pantallas del plugin no hay nada.
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '.magiclinking-ai-notice' ) ).toHaveCount( 0 );
		await expect( page.locator( '#wpbody-content' ) ).not.toContainText( 'no AI connected' );

		await page.goto( SCREEN );
		await page.locator( '.magiclinking-ai-notice' ).getByRole( 'button', { name: 'Close' } ).first().click();
		await expect( page.locator( '.magiclinking-ai-notice' ) ).toHaveCount( 0 );
		await page.waitForTimeout( 300 );

		await page.reload();
		await expect( page.locator( '.magiclinking-stats, .magiclinking-wrap' ).first() ).toBeVisible();
		await expect( page.locator( '.magiclinking-ai-notice' ) ).toHaveCount( 0 );
	} );

	test( 'with a connector there is no notice', async ( { page } ) => {
		test.skip( ! hasAi, 'Necesita el cliente de IA de WordPress 7.0 o posterior.' );
		await login( page );
		await page.goto( SCREEN );
		await expect( page.locator( '.magiclinking-wrap' ).first() ).toBeVisible();
		await expect( page.locator( '.magiclinking-ai-notice' ) ).toHaveCount( 0 );
	} );

	test( 'without the AI client of WordPress 7.0 everything works as before and the AI tab explains why', async ( {
		page,
	} ) => {
		test.skip( hasAi, 'Solo en WordPress anterior a 7.0.' );
		await login( page );

		await page.goto( SCREEN );
		await expect( page.locator( '.magiclinking-wrap' ).first() ).toBeVisible();
		await expect( page.locator( '.magiclinking-ai-notice' ) ).toHaveCount( 0 );

		await page.goto( SCREEN_SETTINGS );
		await openSettingsTab( page, 'AI' );
		const tab = page.locator( '.magiclinking-settings-tabcontent' );
		await expect( tab ).toContainText( 'AI needs WordPress 7.0 or later.' );
		await expect( tab.getByLabel( 'Daily AI limit (tokens)' ) ).toBeDisabled();
		await expect( tab.getByLabel( 'Preferred model' ) ).toBeDisabled();
		await expectAccessible( page, '.magiclinking-settings-page' );

		await openBlockEditor( page, origen );
		const panel = await openSidebar( page );
		await expect( panel.locator( '.magiclinking-card' ).first() ).toBeVisible();
		await expect( panel.locator( '.magiclinking-ai' ) ).toHaveCount( 0 );
	} );
} );
