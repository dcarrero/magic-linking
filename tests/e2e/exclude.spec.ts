import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { login, SCREEN, wp } from './wp';

/**
 * Excluir del análisis (F1-12): acción de fila del informe, filtro «Excluded», volver a incluir, teclado,
 * accesibilidad y permisos.
 */

const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

/**
 * Tres entradas analizadas; todas del administrador.
 */
function seedExclude(): void {
	wp(
		'eval',
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'ex-%' OR post_name LIKE 'pe-%' OR post_name LIKE 'e2e-%' OR post_name LIKE 'entrada-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		update_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_wizard_dismissed', '1' );
		$block = static fn( $t ) => "<!-- wp:paragraph -->\\n<p>$t</p>\\n<!-- /wp:paragraph -->";
		$make = static fn( $slug, $title, $content ) => wp_insert_post( array( 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish' ) );
		$make( 'ex-bomba', 'Bomba de calor: cuánto se ahorra', $block( 'La bomba de calor aerotérmica extrae energía del aire exterior. Una bomba de calor bien dimensionada ahorra mucho.' ) );
		$make( 'ex-origen', 'Guía de calefacción para casas', $block( 'La instalación de una bomba de calor reduce el consumo de energía en las casas con buen aislamiento.' ) );
		$make( 'ex-otra', 'Aislamiento de casas', $block( 'Con una bomba de calor y un buen aislamiento, el consumo de energía baja mucho en cualquier casa moderna.' ) );
		`
	);
	wp( 'magic-linking', 'index' );
}

const axe = ( page: Page ) =>
	new AxeBuilder( { page } )
		.include( '.magiclinking-wrap' )
		.withTags( AXE_TAGS )
		.analyze();

test.describe( 'Exclude from the analysis', () => {
	test.beforeEach( () => {
		seedExclude();
	} );

	// Las entradas de esta pasada hablan de bombas de calor, como las de otras pruebas: no se dejan atrás.
	test.afterAll( () => {
		wp(
			'eval',
			`
			global $wpdb;
			foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'ex-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
			foreach ( array( 'docs', 'links', 'jobs' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
			`
		);
	} );

	test( 'exclude from a row with the keyboard, see it in the Excluded filter and include it again', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( SCREEN );
		const table = page.getByRole( 'table', {
			name: 'Internal links by entry',
		} );
		await expect(
			table.locator( 'tbody strong', { hasText: 'Guía de calefacción' } )
		).toBeVisible();
		await expect(
			page
				.locator( '.subsubsub' )
				.getByRole( 'link', { name: /Excluded/ } )
		).toHaveCount( 0 );

		const exclude = table.getByRole( 'button', {
			name: 'Exclude from the analysis Guía de calefacción para casas',
		} );
		await exclude.focus();
		await page.keyboard.press( 'Enter' );

		await expect(
			table.locator( 'tbody strong', { hasText: 'Guía de calefacción' } )
		).toHaveCount( 0 );
		const tab = page
			.locator( '.subsubsub' )
			.getByRole( 'link', { name: /^Excluded/ } );
		await expect( tab ).toContainText( '(1)' );
		// El foco no se pierde con el botón: vuelve a la pestaña activa.
		await expect(
			page.locator( '.subsubsub' ).getByRole( 'link', { name: /^All/ } )
		).toBeFocused();
		expect(
			wp(
				'eval',
				"echo get_post_meta( get_page_by_path( 'ex-origen', OBJECT, 'post' )->ID, '_magiclinking_excluded', true );"
			).trim()
		).toBe( '1' );

		await tab.click();
		await expect( table.locator( 'tbody tr' ) ).toHaveCount( 1 );
		await expect( table.locator( 'tbody' ) ).toContainText( 'Excluded' );
		await expect(
			table.getByRole( 'button', { name: /Inbound suggestions/ } )
		).toHaveCount( 0 );
		expect( ( await axe( page ) ).violations ).toEqual( [] );

		const include = table.getByRole( 'button', {
			name: 'Include in the analysis Guía de calefacción para casas',
		} );
		await include.focus();
		await page.keyboard.press( 'Enter' );
		await expect( table.locator( 'tbody' ) ).toContainText(
			'No entries are excluded from the analysis.'
		);
		await page
			.locator( '.subsubsub' )
			.getByRole( 'link', { name: /^All/ } )
			.click();
		await expect(
			table.locator( 'tbody strong', { hasText: 'Guía de calefacción' } )
		).toBeVisible();
		expect(
			wp(
				'eval',
				"echo get_post_meta( get_page_by_path( 'ex-origen', OBJECT, 'post' )->ID, '_magiclinking_excluded', true ) === '' ? 'clean' : 'set';"
			).trim()
		).toBe( 'clean' );
	} );

	test( 'an excluded entry is not suggested as origin or destination', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( SCREEN );
		const table = page.getByRole( 'table', {
			name: 'Internal links by entry',
		} );
		const bomba = table.locator( 'tbody tr', {
			hasText: 'Bomba de calor: cuánto',
		} );
		await bomba.hover();
		await bomba
			.getByRole( 'button', { name: /Exclude from the analysis/ } )
			.click();
		await expect(
			table.locator( 'tbody strong', {
				hasText: 'Bomba de calor: cuánto',
			} )
		).toHaveCount( 0 );

		// Ya no es destino de las salientes de otra entrada.
		const origen = table.locator( 'tbody tr', {
			hasText: 'Guía de calefacción',
		} );
		await origen.hover();
		await origen
			.getByRole( 'button', { name: /Outbound suggestions/ } )
			.click();
		const drawer = page.getByRole( 'dialog' );
		await expect( drawer ).toBeVisible();
		await expect(
			drawer.getByText( 'Bomba de calor: cuánto' )
		).toHaveCount( 0 );
		await expect(
			drawer.getByText( 'Aislamiento de casas' )
		).toBeVisible();
	} );

	test( 'the row action is not offered for an entry the user cannot edit, and the route refuses it', async ( {
		page,
	} ) => {
		wp(
			'eval',
			`
			if ( ! get_user_by( 'login', 'e2e_author' ) ) { wp_insert_user( array( 'user_login' => 'e2e_author', 'user_pass' => 'password', 'role' => 'author', 'user_email' => 'e2e_author@example.org' ) ); }
			`
		);
		await login( page, 'e2e_author' );
		await page.goto( SCREEN );
		const table = page.getByRole( 'table', {
			name: 'Internal links by entry',
		} );
		await expect(
			table.locator( 'tbody strong', { hasText: 'Guía de calefacción' } )
		).toBeVisible();
		await expect(
			table.getByRole( 'button', { name: /Exclude from the analysis/ } )
		).toHaveCount( 0 );

		const id = Number(
			wp(
				'eval',
				"echo get_page_by_path( 'ex-origen', OBJECT, 'post' )->ID;"
			).trim()
		);
		const status = await page.evaluate( ( post ) => {
			const { apiFetch } = (
				window as unknown as {
					wp: { apiFetch: ( options: object ) => Promise< unknown > };
				}
			 ).wp;
			return apiFetch( {
				path: `/magic-linking/v1/exclusions/${ post }`,
				method: 'POST',
				data: { excluded: true },
			} ).then(
				() => 200,
				( error: { data?: { status?: number } } ) =>
					error.data?.status ?? 0
			);
		}, id );
		expect( status ).toBe( 403 );
	} );

	test( 'axe finds no violations in the report with an excluded entry', async ( {
		page,
	} ) => {
		wp(
			'eval',
			"update_post_meta( get_page_by_path( 'ex-otra', OBJECT, 'post' )->ID, '_magiclinking_excluded', '1' );"
		);
		await login( page );
		await page.goto( SCREEN );
		await expect( page.locator( '.subsubsub' ) ).toContainText(
			'Excluded (1)'
		);
		await page.waitForLoadState( 'networkidle' );
		expect( ( await axe( page ) ).violations ).toEqual( [] );
	} );
} );
