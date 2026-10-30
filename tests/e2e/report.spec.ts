import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import {
	ensureAnalyzed,
	login,
	openSettingsTab,
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

		// El umbral de poco enlazada está en la pestaña «Suggestions».
		await openSettingsTab( page, 'Suggestions' );
		const threshold = page.getByLabel(
			'Under-linked below this many inbound links'
		);
		await threshold.fill( '3' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect(
			page.locator( '#magiclinking-root' ).getByText( 'Settings saved.' )
		).toBeVisible();

		// Recargar conserva la pestaña (va en la dirección).
		await page.reload();
		await expect( threshold ).toHaveValue( '3' );
		await threshold.fill( '2' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect(
			page.locator( '#magiclinking-root' ).getByText( 'Settings saved.' )
		).toBeVisible();
	} );

	test( 'settings are in tabs with grouped cards and one sticky bar that tracks unsaved changes', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN_SETTINGS );
		const root = page.locator( '#magiclinking-root' );

		// Cinco pestañas reales, y no hay índice de anclas.
		await expect( root.getByRole( 'tablist' ) ).toBeVisible();
		await expect( root.getByRole( 'tab' ) ).toHaveText( [
			'General',
			'Suggestions',
			'Links',
			'AI',
			'History and data',
		] );
		await expect( root.getByRole( 'tab', { name: 'General' } ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		await expect( root.getByRole( 'navigation' ) ).toHaveCount( 0 );

		// Cada pestaña, con sus tarjetas (h2).
		const groups: [ 'General' | 'Suggestions' | 'Links' | 'AI' | 'History and data', string[] ][] = [
			[ 'General', [ 'Content analyzed', 'Maintenance' ] ],
			[
				'Suggestions',
				[
					'Suggestions shown',
					'To avoid overloading your texts',
					'Under-linked entries',
				],
			],
			[
				'Links',
				[
					'Where links can be inserted',
					'How links are inserted',
					'Exclusions',
				],
			],
			[
				'AI',
				[ 'AI connector', 'Daily limit and model', 'AI use in the last 30 days' ],
			],
			[
				'History and data',
				[ 'History of changes', 'When the plugin is deleted' ],
			],
		];
		for ( const [ tab, headings ] of groups ) {
			await openSettingsTab( page, tab );
			await expect( root.getByRole( 'heading', { level: 2 } ) ).toHaveText(
				headings
			);
			// Solo la pestaña General lleva Mantenimiento.
			await expect( root.getByRole( 'tabpanel' ) ).toBeVisible();
		}
		await openSettingsTab( page, 'General' );

		// Sin cambios: guardar está deshabilitado y no hay aviso.
		const save = root.getByRole( 'button', { name: 'Save settings' } );
		await expect( save ).toBeDisabled();
		await expect( root.getByText( 'Unsaved changes', { exact: true } ) ).toHaveCount( 0 );

		// Con cambios (en otra pestaña): aviso y botón activo; la barra queda visible aunque se baje.
		await openSettingsTab( page, 'Links' );
		await root.getByLabel( 'Additional rel values' ).fill( 'nofollow' );
		await expect( root.getByText( 'Unsaved changes', { exact: true } ) ).toBeVisible();
		await expect( save ).toBeEnabled();
		await root
			.getByRole( 'heading', { level: 2, name: 'Where links can be inserted' } )
			.scrollIntoViewIfNeeded();
		// La barra está al final del contenido: solo se ve aquí si es fija.
		await expect( save ).toBeInViewport();

		// Volver al valor guardado quita el aviso.
		await root.getByLabel( 'Additional rel values' ).fill( '' );
		await expect( root.getByText( 'Unsaved changes', { exact: true } ) ).toHaveCount( 0 );
		await expect( save ).toBeDisabled();

		// Guardar deja el estado limpio.
		await root.getByLabel( 'Additional rel values' ).fill( 'nofollow' );
		await save.click();
		await expect( root.getByText( 'Settings saved.' ) ).toBeVisible();
		await expect( root.getByText( 'Unsaved changes', { exact: true } ) ).toHaveCount( 0 );
		await expect( save ).toBeDisabled();

		wp( 'option', 'delete', 'magiclinking_settings' );
	} );

	test( 'what is typed in one tab is kept when changing tabs, and the tab shows it', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN_SETTINGS );
		const root = page.locator( '#magiclinking-root' );
		const marked = ( name: string ) =>
			root.getByRole( 'tab', { name: `${ name } (unsaved changes)` } );

		await openSettingsTab( page, 'Suggestions' );
		await root.getByLabel( 'Outbound suggestions in the editor' ).fill( '9' );
		// La marca lleva texto para lectores de pantalla, y solo en la pestaña con cambios.
		await expect( marked( 'Suggestions' ) ).toBeVisible();
		await expect( root.getByText( '(unsaved changes)' ) ).toHaveCount( 1 );

		await openSettingsTab( page, 'Links' );
		await expect( marked( 'Suggestions' ) ).toBeVisible();
		await expect( root.getByRole( 'tab', { name: 'Links' } ) ).toHaveAccessibleName(
			'Links'
		);
		await openSettingsTab( page, 'Suggestions' );
		await expect(
			root.getByLabel( 'Outbound suggestions in the editor' )
		).toHaveValue( '9' );

		// Guardar desde otra pestaña guarda lo de todas.
		await openSettingsTab( page, 'History and data' );
		await root.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( root.getByText( 'Settings saved.' ) ).toBeVisible();
		await expect( root.getByText( '(unsaved changes)' ) ).toHaveCount( 0 );
		expect(
			JSON.parse(
				wp( 'option', 'get', 'magiclinking_settings', '--format=json' ).trim()
			).outbound_shown
		).toBe( 9 );

		// Desmarcar y volver a marcar un tipo no es un cambio (aunque cambie el orden interno).
		await openSettingsTab( page, 'General' );
		await root.getByLabel( 'Posts' ).uncheck();
		await root.getByLabel( 'Posts' ).check();
		await expect( root.getByText( '(unsaved changes)' ) ).toHaveCount( 0 );
		await expect(
			root.getByRole( 'button', { name: 'Save settings' } )
		).toBeDisabled();

		// Sin ningún tipo de contenido, General pide atención (y guardar queda deshabilitado).
		await openSettingsTab( page, 'General' );
		await root.getByLabel( 'Posts' ).uncheck();
		await root.getByLabel( 'Pages' ).uncheck();
		await openSettingsTab( page, 'Links' );
		await expect(
			root.getByRole( 'tab', { name: 'General (needs attention)' } )
		).toBeVisible();
		await expect(
			root.getByRole( 'button', { name: 'Save settings' } )
		).toBeDisabled();
		wp( 'option', 'delete', 'magiclinking_settings' );
	} );

	test( 'the address opens a tab, follows the selection, and old anchors still work', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		const root = page.locator( '#magiclinking-root' );
		const selected = () =>
			root.locator( '[role="tab"][aria-selected="true"]' );

		await page.goto( `${ SCREEN_SETTINGS }#tab=links` );
		await expect( selected() ).toHaveText( 'Links' );
		await expect( root.getByLabel( 'Additional rel values' ) ).toBeVisible();

		// Elegir otra pestaña actualiza la dirección sin añadir historial; recargar la abre.
		await openSettingsTab( page, 'History and data' );
		expect( page.url() ).toContain( '#tab=data' );
		await page.reload();
		await expect( selected() ).toHaveText( 'History and data' );

		// Un enlace con otra pestaña la abre sin recargar.
		await page.evaluate( () => {
			window.location.hash = 'tab=suggestions';
		} );
		await expect( selected() ).toHaveText( 'Suggestions' );

		// Una dirección que no existe cae en General.
		await page.goto( SCREEN );
		await page.goto( `${ SCREEN_SETTINGS }#tab=nope` );
		await expect( selected() ).toHaveText( 'General' );

		// Otros fragmentos (ids de la pantalla) no sacan de la pestaña.
		await openSettingsTab( page, 'Links' );
		for ( const other of [
			'magiclinking-root',
			'magiclinking-settings-form',
			'magiclinking-s-exclusions-title',
		] ) {
			await page.evaluate( ( hash ) => {
				window.location.hash = hash;
			}, other );
			await page.waitForTimeout( 300 );
			await expect( selected() ).toHaveText( 'Links' );
		}

		// Las anclas de la versión anterior llevan a su pestaña (y a su tarjeta).
		const legacy: [ string, string, string ][] = [
			[ 'magiclinking-s-suggestions', 'Suggestions', 'Suggestions shown' ],
			[ 'magiclinking-s-insertion', 'Links', 'Where links can be inserted' ],
			[ 'magiclinking-s-exclusions', 'Links', 'Exclusions' ],
			[ 'magiclinking-s-data', 'History and data', 'History of changes' ],
			[ 'magiclinking-maintenance', 'General', 'Maintenance' ],
		];
		for ( const [ anchor, tab, heading ] of legacy ) {
			// Carga nueva (no solo un cambio de fragmento): primero otra pantalla, luego la dirección antigua.
			await page.goto( SCREEN );
			await page.goto( `${ SCREEN_SETTINGS }#${ anchor }` );
			await expect( root.getByRole( 'tablist' ) ).toBeVisible();
			await expect( selected() ).toHaveText( tab );
			await expect(
				root.getByRole( 'heading', { level: 2, name: heading } )
			).toBeVisible();
		}
	} );

	test( 'tabs work with the arrow keys and fit a narrow screen without scrolling the page', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN_SETTINGS );
		const root = page.locator( '#magiclinking-root' );
		const general = root.getByRole( 'tab', { name: 'General' } );
		await general.focus();
		await page.keyboard.press( 'ArrowRight' );
		await expect(
			root.getByRole( 'tab', { name: 'Suggestions' } )
		).toBeFocused();
		await expect(
			root.getByRole( 'tab', { name: 'Suggestions' } )
		).toHaveAttribute( 'aria-selected', 'true' );
		await page.keyboard.press( 'End' );
		await expect(
			root.getByRole( 'tab', { name: 'History and data' } )
		).toHaveAttribute( 'aria-selected', 'true' );
		await page.keyboard.press( 'Home' );
		await expect( general ).toHaveAttribute( 'aria-selected', 'true' );

		await page.setViewportSize( { width: 360, height: 740 } );
		for ( const tab of [ 'General', 'Suggestions', 'Links', 'History and data' ] as const ) {
			await openSettingsTab( page, tab );
			const overflow = await page.evaluate(
				() =>
					document.documentElement.scrollWidth -
					document.documentElement.clientWidth
			);
			expect( overflow ).toBeLessThanOrEqual( 0 );
		}
	} );

	test( 'number fields keep what is typed until they lose focus', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN_SETTINGS );
		const root = page.locator( '#magiclinking-root' );
		await openSettingsTab( page, 'Suggestions' );

		const words = root.getByLabel( 'Words per internal link' );
		await words.fill( '' );
		await expect( words ).toHaveValue( '' );
		await words.pressSequentially( '50' );
		await expect( words ).toHaveValue( '50' );
		const inbound = root.getByLabel(
			'Inbound suggestions per page in the editor'
		);
		await inbound.fill( '' );
		await inbound.pressSequentially( '15' );
		await expect( inbound ).toHaveValue( '15' );

		// Fuera de rango o vacío: al salir del campo se corrige.
		await words.fill( '3' );
		await words.blur();
		await expect( words ).toHaveValue( '20' );
		await words.fill( '' );
		await words.blur();
		await expect( words ).toHaveValue( '100' );

		await words.fill( '50' );
		await root.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( root.getByText( 'Settings saved.' ) ).toBeVisible();
		const saved = JSON.parse(
			wp( 'option', 'get', 'magiclinking_settings', '--format=json' ).trim()
		);
		expect( saved.words_per_link ).toBe( 50 );
		expect( saved.inbound_per_page ).toBe( 15 );
		wp( 'option', 'delete', 'magiclinking_settings' );
	} );

	test( 'edits made while saving are not lost', async ( { page } ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN_SETTINGS );
		const root = page.locator( '#magiclinking-root' );
		await page.route( /settings/, async ( route ) => {
			if ( route.request().method() === 'POST' ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 1500 ) );
			}
			await route.continue();
		} );

		await openSettingsTab( page, 'Links' );
		await root.getByLabel( 'Additional rel values' ).fill( 'nofollow' );
		await root.getByRole( 'button', { name: 'Save settings' } ).click();
		await root.getByLabel( 'Ignored words' ).fill( 'durante' );
		await expect( root.getByText( 'Settings saved.' ) ).toBeVisible();
		await expect( root.getByLabel( 'Ignored words' ) ).toHaveValue(
			'durante'
		);
		await expect( root.getByText( 'Unsaved changes', { exact: true } ) ).toBeVisible();
		wp( 'option', 'delete', 'magiclinking_settings' );
	} );

	test( 'cancelling the leave warning does not reopen the assistant for good', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN_SETTINGS );
		const root = page.locator( '#magiclinking-root' );
		const dismissed = () =>
			wp(
				'eval',
				'echo get_user_meta( 1, $GLOBALS["wpdb"]->get_blog_prefix() . "magiclinking_wizard_dismissed", true );'
			);
		expect( dismissed() ).toBe( '1' );

		page.on( 'dialog', ( dialog ) => dialog.dismiss() );
		await openSettingsTab( page, 'Links' );
		await root.getByLabel( 'Additional rel values' ).fill( 'nofollow' );
		await openSettingsTab( page, 'General' );
		await root
			.getByRole( 'button', { name: 'Open the setup assistant' } )
			.click();
		await page.waitForTimeout( 1000 );
		expect( page.url() ).toContain( 'page=magic-linking-settings' );
		expect( dismissed() ).toBe( '1' );
	} );

	test( 'settings look the same while loading, and keyboard order goes fields, save, maintenance', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.route( /rest_route=.*settings|wp-json.*settings/, async ( route ) => {
			if ( route.request().method() === 'GET' ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 1500 ) );
			}
			await route.continue();
		} );
		await page.goto( SCREEN_SETTINGS );
		const root = page.locator( '#magiclinking-root' );
		await expect( root.getByText( 'Loading…' ) ).toBeVisible();
		// Sin esperas: mientras carga ya no hay caja blanca.
		expect(
			await root
				.locator( '.magiclinking-panel' )
				.evaluate( ( el ) => getComputedStyle( el ).backgroundColor )
		).toBe( 'rgba(0, 0, 0, 0)' );
		await expect(
			root.getByRole( 'heading', { level: 2, name: 'Maintenance' } )
		).toBeVisible();

		// Un cambio en otra pestaña activa Guardar; en General el orden es campos, Guardar, Mantenimiento.
		await openSettingsTab( page, 'Links' );
		await root.getByLabel( 'Additional rel values' ).fill( 'nofollow' );
		await openSettingsTab( page, 'General' );
		await root.locator( 'fieldset input[type="checkbox"]' ).last().focus();
		await page.keyboard.press( 'Tab' );
		await expect(
			root.getByRole( 'button', { name: 'Save settings' } )
		).toBeFocused();
		await page.keyboard.press( 'Tab' );
		await expect(
			root.getByRole( 'button', {
				name: 'Analyze everything again from scratch',
			} )
		).toBeFocused();
		wp( 'option', 'delete', 'magiclinking_settings' );
	} );

	test( 'the new settings are saved and kept', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN_SETTINGS );
		await page.waitForLoadState( 'networkidle' );

		await openSettingsTab( page, 'Links' );
		await page.getByLabel( 'Open links in a new tab' ).check();
		await page.getByLabel( 'Additional rel values' ).fill( 'nofollow' );
		await page.getByLabel( 'Quotes' ).check();
		await openSettingsTab( page, 'Suggestions' );
		await page
			.getByLabel( 'Inbound suggestions per page in the editor' )
			.fill( '7' );
		await page
			.getByLabel( 'Outbound suggestions in the editor' )
			.fill( '4' );
		await page
			.getByLabel(
				'Entries that can use the same anchor text for the same target'
			)
			.fill( '3' );
		await openSettingsTab( page, 'Links' );
		await page.getByLabel( 'Ignored words' ).fill( 'gratis\nmuy barato' );
		await page
			.getByLabel( 'Ignored addresses' )
			.fill( 'https://example.org/a/' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect(
			page
				.locator( '#magiclinking-root' )
				.getByText( /^Settings saved\./ )
		).toBeVisible();

		await page.reload();
		await expect(
			page.getByLabel( 'Open links in a new tab' )
		).toBeChecked();
		await expect( page.getByLabel( 'Additional rel values' ) ).toHaveValue(
			'nofollow'
		);
		await expect( page.getByLabel( 'Quotes' ) ).toBeChecked();
		await expect( page.getByLabel( 'Tables' ) ).not.toBeChecked();
		await expect( page.getByLabel( 'Headings' ) ).not.toBeChecked();
		await expect( page.getByLabel( 'Ignored words' ) ).toHaveValue(
			'gratis\nmuy barato'
		);
		await openSettingsTab( page, 'Suggestions' );
		await expect(
			page.getByLabel( 'Inbound suggestions per page in the editor' )
		).toHaveValue( '7' );

		// Solo hay una opción en autoload y el resto de valores son los por defecto.
		const saved = JSON.parse(
			wp(
				'option',
				'get',
				'magiclinking_settings',
				'--format=json'
			).trim()
		);
		expect( saved.open_in_new_tab ).toBe( true );
		expect( saved.link_in_quotes ).toBe( true );
		expect( saved.link_in_tables ).toBe( false );
		expect( saved.ignored_words ).toEqual( [ 'gratis', 'muy barato' ] );

		// Cambiar dónde se enlaza vuelve a leer las entradas.
		await openSettingsTab( page, 'Links' );
		await page.getByLabel( 'Tables' ).check();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect(
			page
				.locator( '#magiclinking-root' )
				.getByText( /read.*again|analyzed again/ )
				.first()
		).toBeVisible();
		try {
			wp( 'action-scheduler', 'run' );
		} catch ( e ) {
			// Si otro proceso ya lo ejecutó no hay nada que correr.
		}

		// Los demás ajustes de prueba no deben afectar a las siguientes.
		wp( 'option', 'delete', 'magiclinking_settings' );
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
			'Rules',
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
	};
	const axe = async ( page: Page ) =>
		new AxeBuilder( { page } )
			.include( '.magiclinking-wrap' )
			.withTags( [
				'wcag2a',
				'wcag2aa',
				'wcag21a',
				'wcag21aa',
				'wcag22aa',
			] )
			.analyze();
	for ( const [ tab, url ] of Object.entries( screens ) ) {
		test( `axe finds no WCAG A/AA violations in the ${ tab } tab`, async ( {
			page,
		} ) => {
			ensureAnalyzed();
			await login( page );
			await page.goto( url );
			await expect( page.locator( '.magiclinking-panel' ) ).toBeVisible();
			await page.waitForLoadState( 'networkidle' );

			expect( ( await axe( page ) ).violations ).toEqual( [] );
		} );
	}
	// Cada pestaña de Ajustes, abierta por su dirección, también con un cambio sin guardar y un campo inválido.
	for ( const tab of [ 'general', 'suggestions', 'links', 'ai', 'data' ] ) {
		test( `axe finds no WCAG A/AA violations in the settings ${ tab } tab`, async ( {
			page,
		} ) => {
			ensureAnalyzed();
			await login( page );
			await page.goto( `${ SCREEN_SETTINGS }#tab=${ tab }` );
			await expect( page.getByRole( 'tabpanel' ) ).toBeVisible();
			await page.waitForLoadState( 'networkidle' );
			expect( ( await axe( page ) ).violations ).toEqual( [] );

			// Con un cambio sin guardar y un estado no válido real (ningún tipo de contenido).
			await openSettingsTab( page, 'Suggestions' );
			await page.getByLabel( 'Outbound suggestions in the editor' ).fill( '8' );
			await openSettingsTab( page, 'General' );
			await page.getByLabel( 'Posts' ).uncheck();
			await page.getByLabel( 'Pages' ).uncheck();
			await expect(
				page.getByRole( 'tab', { name: 'General (needs attention)' } )
			).toBeVisible();
			await openSettingsTab(
				page,
				( {
					general: 'General',
					suggestions: 'Suggestions',
					links: 'Links',
					ai: 'AI',
					data: 'History and data',
				} as const )[ tab as 'general' ]
			);
			expect( ( await axe( page ) ).violations ).toEqual( [] );
		} );
	}

	test( 'axe finds no violations with an invalid number still being edited', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( `${ SCREEN_SETTINGS }#tab=suggestions` );
		const words = page.getByLabel( 'Words per internal link' );
		await words.fill( '3' );
		await expect( words ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( words ).toBeFocused();
		await expect(
			page.getByText( /Enter a whole number from 20 to 1000/ )
		).toBeVisible();
		expect( ( await axe( page ) ).violations ).toEqual( [] );
	} );

	test( 'the unsaved and needs-attention marks differ by shape, not only by colour', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( `${ SCREEN_SETTINGS }#tab=links` );
		await page.getByLabel( 'Additional rel values' ).fill( 'nofollow' );
		await openSettingsTab( page, 'General' );
		await page.getByLabel( 'Posts' ).uncheck();
		await page.getByLabel( 'Pages' ).uncheck();
		await openSettingsTab( page, 'Links' );
		const shape = ( name: string ) =>
			page
				.getByRole( 'tab', { name: new RegExp( `^${ name }` ) } )
				.locator( '.magiclinking-tab-mark' )
				.evaluate( ( el ) => {
					const style = getComputedStyle( el );
					return style.clipPath + '|' + style.borderRadius;
				} );
		const unsaved = await shape( 'Links' );
		const attention = await shape( 'General' );
		expect( unsaved ).not.toBe( attention );
	} );

	test( 'a failure in Maintenance survives changing tabs', async ( {
		page,
	} ) => {
		ensureAnalyzed();
		await login( page );
		await page.goto( SCREEN_SETTINGS );
		const root = page.locator( '#magiclinking-root' );
		await page.route( /rest_route=.*index|wp-json.*\/index/, async ( route ) => {
			if ( route.request().method() === 'POST' ) {
				await route.fulfill( {
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify( { code: 'boom', message: 'Boom.' } ),
				} );
				return;
			}
			await route.continue();
		} );
		await root
			.getByRole( 'button', {
				name: 'Analyze everything again from scratch',
			} )
			.click();
		await page.getByRole( 'button', { name: 'Start analysis' } ).click();
		await expect( root.getByText( 'Boom.' ) ).toBeVisible();
		await openSettingsTab( page, 'Links' );
		await openSettingsTab( page, 'General' );
		await expect( root.getByText( 'Boom.' ) ).toBeVisible();
	} );

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
