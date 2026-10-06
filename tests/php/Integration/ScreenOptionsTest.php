<?php
/**
 * «Opciones de pantalla»: elementos por página, por usuario.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Admin\Screen;
use WP_UnitTestCase;

final class ScreenOptionsTest extends WP_UnitTestCase {

	public function test_default_is_twenty(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 20, Screen::per_page() );
	}

	public function test_saving_goes_through_the_core_filter_and_is_clamped(): void {
		$this->assertSame( 50, apply_filters( 'set_screen_option_' . Screen::PER_PAGE_OPTION, false, Screen::PER_PAGE_OPTION, '50' ) );
		$this->assertSame( Screen::PER_PAGE_MAX, apply_filters( 'set_screen_option_' . Screen::PER_PAGE_OPTION, false, Screen::PER_PAGE_OPTION, '99999' ) );
		$this->assertSame( 1, apply_filters( 'set_screen_option_' . Screen::PER_PAGE_OPTION, false, Screen::PER_PAGE_OPTION, '-4' ) );
	}

	public function test_value_is_per_user(): void {
		$one = self::factory()->user->create( array( 'role' => 'editor' ) );
		$two = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_user_meta( $one, Screen::PER_PAGE_OPTION, 35 );

		wp_set_current_user( $one );
		$this->assertSame( 35, Screen::per_page() );
		wp_set_current_user( $two );
		$this->assertSame( 20, Screen::per_page() );
	}
}
