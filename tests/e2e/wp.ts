import { execFileSync } from 'node:child_process';
import type { Page } from '@playwright/test';

/**
 * Ejecuta WP-CLI dentro de wp-env.
 *
 * @param args Argumentos de `wp`.
 */
export function wp( ...args: string[] ): string {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', ...args ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'ignore' ],
		// Carpeta desde la que se levantó wp-env (por defecto, esta).
		cwd: process.env.WP_ENV_DIR ?? process.cwd(),
	} );
}

/**
 * Deja el sitio en un estado conocido: entradas de prueba con enlaces, sin índice y con un editor.
 */
export function seed(): void {
	// Enlaces permanentes bonitos con .htaccess (Apache de wp-env), para que /wp-json/ responda.
	wp( 'rewrite', 'structure', '/%postname%/', '--hard' );
	wp(
		'eval',
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'e2e-%' OR post_name LIKE 'entrada-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'docs', 'links', 'jobs' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		$link = static fn( $slug ) => '<a href="' . home_url( "/$slug/" ) . '">' . $slug . '</a>';
		$make = static fn( $slug, $content ) => wp_insert_post( array( 'post_name' => $slug, 'post_title' => 'E2E ' . $slug, 'post_content' => $content, 'post_status' => 'publish' ) );
		$make( 'e2e-huerfana', 'Nadie me enlaza.' );
		$make( 'e2e-popular', 'Muchos me enlazan.' );
		$make( 'e2e-a', $link( 'e2e-popular' ) . ' ' . $link( 'e2e-no-existe' ) );
		$make( 'e2e-b', $link( 'e2e-popular' ) );
		$make( 'e2e-c', $link( 'e2e-popular' ) . $link( 'e2e-a' ) );
		foreach ( array( 'editor' ) as $role ) {
			if ( ! get_user_by( 'login', 'e2e_editor' ) ) { wp_insert_user( array( 'user_login' => 'e2e_editor', 'user_pass' => 'password', 'role' => $role, 'user_email' => 'e2e_editor@example.org' ) ); }
		}
		$wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_docs" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_links" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_jobs" );
		`
	);
}

/**
 * Inicia sesión en el administrador.
 *
 * @param page Página.
 * @param user Usuario.
 */
export async function login( page: Page, user = 'admin' ): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	// Basta con que el servidor responda con la redirección: no hay que esperar al evento `load` del
	// escritorio, que carga recursos externos (Gravatar, widgets) y en CI llegaba a agotar los 60 s.
	await page.waitForURL( /wp-admin/, { waitUntil: 'commit' } );
}

export const SCREEN = '/wp-admin/admin.php?page=magic-linking';
export const SCREEN_BROKEN = '/wp-admin/admin.php?page=magic-linking-broken';
export const SCREEN_SETTINGS =
	'/wp-admin/admin.php?page=magic-linking-settings';

/**
 * Deja el sitio analizado (desde WP-CLI), para las pruebas que no prueban el primer análisis.
 * Playwright vuelve a ejecutar `beforeAll` cuando se reinicia el proceso tras un fallo; por eso
 * cada prueba se asegura del estado que necesita.
 */
export function ensureAnalyzed(): void {
	wp( 'magic-linking', 'index' );
}
