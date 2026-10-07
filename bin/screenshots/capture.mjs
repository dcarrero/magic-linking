/**
 * Capturas de la ficha de wordpress.org sobre el sitio de demostración en castellano.
 *
 * Uso (con wp-env arrancado; WP_BASE_URL por defecto http://localhost:8888):
 *   node bin/screenshots/capture.mjs <directorio de salida>
 *
 * Siembra el sitio (bin/screenshots/seed-demo.php), construye el índice y genera, en este orden:
 * 1 salientes con el ancla resaltada, 2 enlace añadido, 3 entrantes, 4 historial, 5 informe, 6 ajustes.
 */
import { execFileSync } from 'node:child_process';
import { chromium } from '@playwright/test';

const out = process.argv[2];
const base = process.env.WP_BASE_URL ?? 'http://localhost:8888';
if ( ! out ) {
	throw new Error( 'Falta el directorio de salida.' );
}

const wp = ( ...args ) =>
	execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } );

wp( 'language', 'core', 'install', 'es_ES' );
wp( 'site', 'switch-language', 'es_ES' );
wp( 'user', 'update', '1', '--locale=es_ES' );
wp( 'rewrite', 'structure', '/%postname%/', '--hard' );
wp( 'eval-file', 'wp-content/plugins/magiclinking-wordpress-plugin/bin/screenshots/seed-demo.php' );
wp( 'magic-linking', 'index', '--force' );
const postId = wp( 'post', 'list', '--name=bomba-de-calor-aerotermica', '--post_type=post', '--field=ID' ).trim();

const browser = await chromium.launch();
const context = await browser.newContext( { viewport: { width: 1440, height: 1000 }, locale: 'es-ES' } );
const page = await context.newPage();
page.on( 'dialog', ( d ) => d.accept() );

// Sin avisos de actualización ni contadores que no son del plugin.
const CSS = `#wp-admin-bar-updates, #wp-admin-bar-comments, .update-plugins, #wpfooter, .notice:not(.magiclinking-notice) { display: none !important; }`;
const shot = async ( n ) => {
	await page.addStyleTag( { content: CSS } );
	// La dirección del sitio de pruebas no sale en la ficha.
	await page.evaluate( ( host ) => {
		const walker = document.createTreeWalker( document.body, NodeFilter.SHOW_TEXT );
		for ( let node = walker.nextNode(); node; node = walker.nextNode() ) {
			node.nodeValue = node.nodeValue.split( host ).join( 'https://casaconfortable.example' );
		}
		document.activeElement?.blur?.();
	}, base );
	await page.screenshot( { path: `${ out }/screenshot-${ n }.png` } );
};

await page.goto( `${ base }/wp-login.php` );
await page.fill( '#user_login', 'admin' );
await page.fill( '#user_pass', 'password' );
await page.click( '#wp-submit' );
await page.waitForURL( /wp-admin/, { waitUntil: 'commit' } );

// Editor de bloques.
await page.goto( `${ base }/wp-admin/post.php?post=${ postId }&action=edit` );
await page.waitForFunction( () => window.wp?.data?.select( 'core/block-editor' )?.getBlocks().length > 0 );
await page.evaluate( () => {
	const prefs = window.wp.data.dispatch( 'core/preferences' );
	prefs.set( 'core/edit-post', 'welcomeGuide', false );
	prefs.set( 'core/edit-post', 'fullscreenMode', false );
} );
await page.getByRole( 'button', { name: /^Enlaces internos/ } ).first().click();
const panel = page.locator( '.magiclinking-panel' );
await panel.getByRole( 'button', { name: 'Enlazar' } ).first().waitFor();
await page.waitForTimeout( 800 );

// 1. Salientes con el ancla resaltada en el lienzo (el resaltado sigue al ratón sobre la tarjeta).
const firstCard = panel.getByRole( 'button', { name: 'Enlazar' } ).first();
await firstCard.hover();
await page.waitForTimeout( 500 );
await shot( 1 );

// 2. Enlace añadido, con su botón Deshacer.
await firstCard.click();
await panel.getByRole( 'button', { name: 'Deshacer' } ).first().waitFor();
await page.mouse.move( 700, 500 );
await page.waitForTimeout( 500 );
await shot( 2 );

// 3. Entrantes.
await panel.getByRole( 'tab', { name: /^Entrantes/ } ).click();
await panel.getByRole( 'button', { name: 'Enlazar' } ).first().waitFor();
await page.waitForTimeout( 500 );
await panel.getByRole( 'button', { name: 'Enlazar' } ).first().hover();
await page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( 'body' ).evaluate( () => window.scrollTo( 0, 0 ) );
await page.waitForTimeout( 300 );
await shot( 3 );

// Cuatro enlaces desde entrantes (se escriben en otras entradas, con su historial).
for ( let i = 0; i < 4; i++ ) {
	await panel.getByRole( 'button', { name: 'Enlazar' } ).first().click();
	await panel.getByRole( 'button', { name: 'Deshacer' } ).first().waitFor();
	await page.waitForTimeout( 1000 );
}

// 4. Historial: un lote deshecho y los demás puestos, con los enlaces del primero a la vista.
await page.goto( `${ base }/wp-admin/admin.php?page=magic-linking-history` );
await page.getByRole( 'button', { name: 'Deshacer lote' } ).last().waitFor();
await page.getByRole( 'button', { name: 'Deshacer lote' } ).last().click();
await page.getByRole( 'dialog' ).getByRole( 'button', { name: /^Deshacer/ } ).last().click();
await page.getByText( 'Deshecho', { exact: true } ).first().waitFor();
await page.getByRole( 'button', { name: 'Ver enlaces' } ).first().click();
await page.waitForTimeout( 800 );
await shot( 4 );

// 5. Informe.
await page.goto( `${ base }/wp-admin/admin.php?page=magic-linking` );
await page.waitForSelector( 'table' );
await page.waitForTimeout( 800 );
await shot( 5 );

// 6. Ajustes.
await page.goto( `${ base }/wp-admin/admin.php?page=magic-linking-settings` );
await page.waitForSelector( 'form, .magiclinking-settings, input' );
await page.waitForTimeout( 800 );
await shot( 6 );

await browser.close();
