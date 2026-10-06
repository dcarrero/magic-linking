<?php
/**
 * Arranque dentro de WordPress: plugins_loaded y Action Scheduler.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Container;
use MagicLinking\Core\Plugin;
use WP_UnitTestCase;

final class BootTest extends WP_UnitTestCase {

	public function test_boot_is_hooked_on_plugins_loaded(): void {
		$this->assertSame( 10, has_action( 'plugins_loaded', array( Plugin::class, 'boot' ) ) );
	}

	public function test_plugin_booted_and_exposes_its_container(): void {
		$this->assertTrue( Plugin::is_booted() );
		$this->assertInstanceOf( Container::class, Plugin::container() );
	}

	public function test_boot_is_idempotent(): void {
		$container = Plugin::container();
		Plugin::boot();
		$this->assertSame( $container, Plugin::container() );
	}

	public function test_action_scheduler_is_available(): void {
		$this->assertTrue( class_exists( 'ActionScheduler', false ), 'Action Scheduler se inicializa en plugins_loaded.' );
		$this->assertTrue( function_exists( 'as_enqueue_async_action' ) );
	}
}
