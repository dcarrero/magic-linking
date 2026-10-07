import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import {
	ensureAnalyzed,
	login,
	SCREEN,
	SCREEN_BROKEN,
	SCREEN_HISTORY,
	SCREEN_SETTINGS,
	seed,
	wp,
} from './wp';

/**
 * Pantalla «Internal links»: primera ejecución, informe, rotos, ajustes, permisos y accesibilidad.
 */
test.describe( 'Internal links', () => {
	test.beforeAll( () => {
		seed();
	} );

	test( 'first analysis, report, filters, sorting and CSV link', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( SCREEN );

		await expect(
			page.getByRole( 'heading', { name: 'Analyze your site' } )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Analyze my site' } ).click();

		// El proceso queda en cola de Action Scheduler.
		await expect( page.locator( 'progress' ) ).toBeVisible();

		// Action Scheduler corre con el cron del sitio; en wp-env se ejecuta a mano si no lo ha hecho ya.
		try {
			wp( 'action-scheduler', 'run' );
		} catch ( e ) {
			// Si otro proceso ya lo ejecutó no hay nada que correr.
		}

		await expect( page.getByText( 'Entries analyzed' ) ).toBeVisible( {
			timeout: 30_000,
		} );
		const table = page.getByRole( 'table', {
			name: 'Internal links by entry',
		} );
		await expect( table.getByRole( 'row' ).nth( 1 ) ).toBeVisible();

		// Filtro «Orphans».
		await page.getByRole( 'link', { name: /^Orphans/ } ).click();
		await expect(
			table.getByText( 'E2E e2e-huerfana' ).first()
		).toBeVisible();
		await expect( table.getByText( 'E2E e2e-popular' ) ).toHaveCount( 0 );
		await expect(
			page.getByRole( 'link', { name: /^Orphans/ } )
		).toHaveAttribute( 'aria-current', 'true' );

		// Vuelve a «All» y ordena por Inbound descendente.
		await page
			.locator( '.subsubsub' )
			.getByRole( 'link', { name: /^All/ } ).click();
		const inbound = table
			.locator( 'thead' )
			.getByRole( 'link', { name: 'Inbound' } );
		await inbound.click();
		await expect(
			table.locator( 'thead' ).getByRole( 'columnheader', { name: /Inbound/ } )
		).toHaveAttribute( 'aria-sort', 'descending' );
		await expect( table.getByRole( 'row' ).nth( 1 ) ).toContainText(
			'E2E e2e-popular'
		);

		// Búsqueda.
		await page
			.getByRole( 'searchbox', { name: 'Search entries by title' } )
			.fill( 'huerfana' );
		await expect( table.locator( 'tbody tr' ) ).toHaveCount( 1 );

		// El CSV lleva la vista actual.
		const href = await page
			.getByRole( 'link', { name: 'Export CSV' } )
			.getAttribute( 'href' );
		expect( href ).toContain( 'admin-post.php?action=magiclinking_export' );
		expect( href ).toContain( 'search=huerfana' );
		expect( href ).toContain( 'dataset=report' );

		const response = await page.request.get( href ?? '' );
		expect( response.headers()[ 'content-type' ] ).toContain( 'text/csv' );
		expect( await response.text() ).toContain( 'e2e-huerfana' );
	} );

	test( 'broken links tab, reached from the report row', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN );

		await page
			.getByRole( 'link', { name: /^With broken links/ } )
			.click();
		await page
			.getByRole( 'button', { name: /broken links in E2E e2e-a/ } )
			.click();

		await expect( page ).toHaveURL( /page=magic-linking-broken/ );
		await expect( page.getByRole( 'heading', { level: 1 } ) ).toContainText(
			'Magic Linking · Broken links'
		);
		await expect( page.locator( '.nav-tab-wrapper' ) ).toHaveCount( 0 );
		await expect(
			page.getByText( /Showing only the broken links in/ )
		).toBeVisible();
		const table = page.getByRole( 'table', {
			name: 'Broken internal links',
		} );
		await expect(
			table.getByText( 'No entry exists with this URL.' )
		).toBeVisible();
		await expect(
			table.getByRole( 'link', { name: /^Edit entry/ } ).first()
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Show all' } ).click();
		await expect( page.getByText( /Showing only/ ) ).toHaveCount( 0 );
	} );

	test( 'items per page comes from Screen Options', async ( { page } ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN );
		await page.getByRole( 'button', { name: 'Screen Options' } ).click();
		const field = page.getByLabel( 'Items per page' );
		await field.fill( '3' );
		await page.getByRole( 'button', { name: 'Apply' } ).click();
		await expect( page.locator( '.tablenav.top .displaying-num' ) ).toContainText( /\d+ entries/ );
		await expect( page.locator( 'tbody tr' ) ).toHaveCount( 3 );
		await expect(
			page.locator( '.tablenav.top .next-page' )
		).toBeVisible();

		// Se restablece para no afectar a otras pruebas.
		await page.getByRole( 'button', { name: 'Screen Options' } ).click();
		await page.getByLabel( 'Items per page' ).fill( '20' );
		await page.getByRole( 'button', { name: 'Apply' } ).click();
		await expect( page.locator( 'tbody tr' ) ).toHaveCount( 7 );
	} );

	test( 'settings can be saved by an administrator', async ( { page } ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN_SETTINGS );

		const threshold = page.getByLabel(
			'Under-linked below this many inbound links'
		);
		await threshold.fill( '3' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect(
			page.locator( '#magiclinking-root' ).getByText( 'Settings saved.' )
		).toBeVisible();

		await page.reload();
		await expect( threshold ).toHaveValue( '3' );
		await threshold.fill( '2' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect(
			page.locator( '#magiclinking-root' ).getByText( 'Settings saved.' )
		).toBeVisible();
	} );

	test( 'the report has no language column or filter on a single-language site', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN );
		const table = page.getByRole( 'table', {
			name: 'Internal links by entry',
		} );
		await expect(
			table
				.locator( 'thead' )
				.getByRole( 'columnheader', { name: 'Type', exact: true } )
		).toBeVisible();
		await expect( page.getByText( 'Type · Language' ) ).toHaveCount( 0 );
		await expect( page.getByLabel( 'Language' ) ).toHaveCount( 0 );
	} );

	test( 'Analyze changes skips unchanged entries and reports the modified ones', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN );

		const run = async () => {
			await page
				.getByRole( 'button', { name: 'Analyze changes' } )
				.click();
			try {
				wp( 'action-scheduler', 'run' );
			} catch ( e ) {
				// Si otro proceso ya lo ejecutó no hay nada que correr.
			}
		};

		// El resultado aparece dos veces: en el aviso visible y en la región aria-live de @wordpress/a11y
		// (`#a11y-speak-polite`, fuera de la raíz, que se conserva para los lectores de pantalla).
		// Se comprueba el aviso visible.
		const notice = page.locator( '#magiclinking-root .components-notice' );

		// Nada ha cambiado: se recorre todo y no se procesa ninguna entrada.
		await run();
		await expect(
			notice.getByText( /Analysis finished: 0 new or modified/ )
		).toBeVisible( { timeout: 30_000 } );

		// Una entrada modificada: solo esa cuenta como nueva o modificada.
		wp(
			'eval',
			`$p = get_page_by_path( 'e2e-huerfana', OBJECT, 'post' ); global $wpdb; $wpdb->update( $wpdb->posts, array( 'post_content' => 'Texto cambiado.' ), array( 'ID' => $p->ID ) ); clean_post_cache( $p->ID );`
		);
		await page.reload();
		await run();
		await expect(
			notice.getByText( /Analysis finished: 1 new or modified/ )
		).toBeVisible( { timeout: 30_000 } );
	} );

	test( 'settings offer to analyze everything again from scratch, with confirmation', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN_SETTINGS );

		const root = page.locator( '#magiclinking-root' );
		await expect(
			root.getByRole( 'heading', { name: 'Maintenance' } )
		).toBeVisible();
		await expect( root.getByText( 'Last full analysis' ) ).toBeVisible();

		await root
			.getByRole( 'button', {
				name: 'Analyze everything again from scratch',
			} )
			.click();
		const dialog = page.getByRole( 'dialog', {
			name: 'Analyze everything again from scratch?',
		} );
		await expect( dialog ).toContainText(
			'it never changes it'
		);

		// Cancelar no lanza nada.
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).toHaveCount( 0 );
		await expect( root.locator( 'progress' ) ).toHaveCount( 0 );

		// Confirmar lanza el proceso y desactiva el botón mientras corre.
		await root
			.getByRole( 'button', {
				name: 'Analyze everything again from scratch',
			} )
			.click();
		await page.getByRole( 'button', { name: 'Start analysis' } ).click();
		await expect( root.locator( 'progress' ) ).toBeVisible();
		await expect(
			root.getByRole( 'button', {
				name: 'Analyze everything again from scratch',
			} )
		).toBeDisabled();

		// Se deja el sitio sin proceso activo.
		await root.getByRole( 'button', { name: 'Cancel' } ).first().click();
		await expect( root.locator( 'progress' ) ).toHaveCount( 0 );
	} );

	test( 'an editor sees the report but no settings and cannot start an analysis', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page, 'e2e_editor' );
		await page.goto( SCREEN );

		await expect( page.getByText( 'Entries analyzed' ) ).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Settings' } )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'button', { name: 'Analyze my site' } )
		).toHaveCount( 0 );

		// Con el nonce de la propia pantalla, la API rechaza a un editor.
		const code = await page.evaluate( async () => {
			const api = (
				window as unknown as {
					wp: {
						apiFetch: ( o: object ) => Promise< unknown >;
					};
				}
			 ).wp.apiFetch;
			try {
				await api( {
					path: '/magic-linking/v1/index',
					method: 'POST',
				} );
				return 'allowed';
			} catch ( e ) {
				return ( e as { code?: string } ).code ?? 'error';
			}
		} );
		expect( code ).toBe( 'rest_forbidden' );
	} );

	test( 'the menu has no duplicated submenu', async ( { page } ) => {
		await login( page );
		await page.goto( SCREEN );
		const items = page.locator(
			'#toplevel_page_magic-linking .wp-submenu li:not(.wp-submenu-head)'
		);
		await expect( items ).toHaveText( [
			'Report',
			'Broken links',
			'History',
			'Settings',
		] );
	} );

	test( 'no notices outside the plugin screen', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/index.php' );
		// Otros plugins y wp-env pueden mostrar avisos; los de Magic Linking, ninguno.
		const ours =
			'.notice:has-text("Magic Linking"), .notice:has-text("Internal links")';
		await expect( page.locator( ours ) ).toHaveCount( 0 );
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( ours ) ).toHaveCount( 0 );
	} );

	const screens = {
		report: SCREEN,
		broken: SCREEN_BROKEN,
		history: SCREEN_HISTORY,
		settings: SCREEN_SETTINGS,
	};
	for ( const [ tab, url ] of Object.entries( screens ) ) {
		test( `axe finds no WCAG A/AA violations in the ${ tab } tab`, async ( {
			page,
		} ) => {
			ensureAnalyzed();
			await login( page );
			await page.goto( url );
			await expect( page.locator( '.magiclinking-panel' ) ).toBeVisible();
			await page.waitForLoadState( 'networkidle' );

			const results = await new AxeBuilder( { page } )
				.include( '.magiclinking-wrap' )
				.withTags( [
					'wcag2a',
					'wcag2aa',
					'wcag21a',
					'wcag21aa',
					'wcag22aa',
				] )
				.analyze();
			expect( results.violations ).toEqual( [] );
		} );
	}

	test( 'the report can be used with the keyboard only', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN );
		await expect( page.getByText( 'Entries analyzed' ) ).toBeVisible();

		const orphans = page.getByRole( 'link', { name: /^Orphans/ } );
		await orphans.focus();
		await page.keyboard.press( 'Enter' );
		await expect( orphans ).toHaveAttribute( 'aria-current', 'true' );

		const sort = page
			.locator( 'thead' )
			.getByRole( 'link', { name: 'Title' } );
		await sort.focus();
		await page.keyboard.press( 'Enter' );
		await expect(
			page
				.locator( 'thead' )
				.getByRole( 'columnheader', { name: /Title/ } )
		).toHaveAttribute( 'aria-sort', 'ascending' );
	} );
} );
