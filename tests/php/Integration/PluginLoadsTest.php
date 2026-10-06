<?php
/**
 * El plugin se carga dentro de WordPress sin errores.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use WP_UnitTestCase;

final class PluginLoadsTest extends WP_UnitTestCase {

	public function test_constants_are_defined(): void {
		$this->assertTrue( defined( 'MAGICLINKING_VERSION' ) );
		$this->assertSame( 'magiclinking_', MAGICLINKING_PREFIX );
	}

	public function test_no_own_autoloaded_options(): void {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> 'magiclinking_settings' AND autoload IN ('yes','on','auto-on','auto')",
				$wpdb->esc_like( MAGICLINKING_PREFIX ) . '%'
			)
		);

		$this->assertSame( 0, $count );
	}
}
