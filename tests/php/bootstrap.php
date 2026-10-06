<?php
/**
 * Arranque de PHPUnit. Las pruebas de integración cargan la suite de WordPress
 * de wp-env; las unitarias solo necesitan el autoloader.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$magiclinking_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $magiclinking_tests_dir || '' === $magiclinking_tests_dir ) {
	return;
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once __DIR__ . '/stubs/wp-cli.php';
require_once $magiclinking_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/magic-linking.php';
	}
);

require $magiclinking_tests_dir . '/includes/bootstrap.php';
