<?php
/**
 * Enlace «Qué viene después» en el pie de las pantallas del plugin (F2-04).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Admin\Screen;
use MagicLinking\Core\Plugin;
use MagicLinking\I18n\TextDomain;
use WP_UnitTestCase;

final class FooterLinkTest extends WP_UnitTestCase {

	private const BASE = 'Thank you for creating with WordPress.';

	/**
	 * Globales del menú de administración, para no filtrar el menú del plugin a otras pruebas.
	 *
	 * @var array<string, mixed>
	 */
	private array $backup = array();

	/**
	 * Hook suffix de la pantalla Ajustes, el que devuelve add_submenu_page().
	 */
	private string $settings_hook = '';

	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'menu', 'submenu', '_registered_pages', 'admin_page_hooks', '_parent_pages' ) as $name ) {
			$this->backup[ $name ] = $GLOBALS[ $name ] ?? null;
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Plugin::container()->get( Screen::class )->add_menu();
		$this->settings_hook = (string) get_plugin_page_hookname( Screen::SLUG_SETTINGS, Screen::SLUG );
	}

	public function tear_down(): void {
		foreach ( $this->backup as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	private function footer(): string {
		return (string) apply_filters( 'admin_footer_text', self::BASE );
	}

	public function test_plugin_screen_gets_the_english_link(): void {
		set_current_screen( 'toplevel_page_' . Screen::SLUG );

		$footer = $this->footer();

		$this->assertStringStartsWith( self::BASE, $footer );
		$this->assertSame( 1, substr_count( $footer, '<a ' ) );
		$this->assertStringContainsString( 'href="https://magiclinking.com/#roadmap"', $footer );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $footer );
		$this->assertStringContainsString( '(opens in a new tab)', $footer );
	}

	public function test_spanish_user_gets_the_spanish_roadmap(): void {
		set_current_screen( 'toplevel_page_' . Screen::SLUG );
		switch_to_locale( 'es_ES' );
		// El dominio se carga en `init` con determine_locale(); aquí se repite tras cambiar de idioma.
		unload_textdomain( 'magic-linking' );
		( new TextDomain() )->load();

		$footer = $this->footer();
		restore_previous_locale();
		unload_textdomain( 'magic-linking' );

		$this->assertStringContainsString( 'href="https://magiclinking.com/es/#hoja-de-ruta"', $footer );
		$this->assertStringContainsString( 'Qué viene después en Magic Linking', $footer );
	}

	public function test_subscreen_gets_the_link(): void {
		$this->assertNotSame( '', $this->settings_hook );
		set_current_screen( $this->settings_hook );

		$this->assertStringContainsString( 'href="https://magiclinking.com/#roadmap"', $this->footer() );
	}

	public function test_empty_footer_text_gets_no_leading_separator(): void {
		set_current_screen( 'toplevel_page_' . Screen::SLUG );

		$footer = (string) apply_filters( 'admin_footer_text', '  ' );

		$this->assertStringStartsWith( '<a ', $footer );
		$this->assertStringNotContainsString( '|', $footer );
	}

	public function test_other_screens_are_untouched(): void {
		set_current_screen( 'dashboard' );

		$this->assertSame( self::BASE, $this->footer() );
	}
}
