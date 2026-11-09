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
use WP_UnitTestCase;

final class FooterLinkTest extends WP_UnitTestCase {

	private const BASE = 'Thank you for creating with WordPress.';

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Plugin::container()->get( Screen::class )->add_menu();
	}

	public function tear_down(): void {
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
		update_user_meta( get_current_user_id(), 'locale', 'es_ES' );
		wp_get_current_user()->locale = 'es_ES';

		$footer = $this->footer();

		$this->assertStringContainsString( 'href="https://magiclinking.com/es/#hoja-de-ruta"', $footer );
	}

	public function test_other_screens_are_untouched(): void {
		set_current_screen( 'dashboard' );

		$this->assertSame( self::BASE, $this->footer() );
	}
}
