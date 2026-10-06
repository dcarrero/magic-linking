<?php
/**
 * Registro de módulos desde el contenedor.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Core;

use LogicException;
use MagicLinking\Core\Container;
use MagicLinking\Core\Module;
use MagicLinking\Core\Plugin;
use PHPUnit\Framework\TestCase;
use stdClass;

final class PluginTest extends TestCase {

	public function test_registers_modules_in_order(): void {
		$log       = new \ArrayObject();
		$container = new Container();
		foreach ( array( 'a', 'b' ) as $name ) {
			$container->set(
				$name,
				static fn(): Module => new class( $log, $name ) implements Module {
					/**
					 * @param \ArrayObject<int, string> $log
					 */
					public function __construct( private \ArrayObject $log, private string $name ) {}

					public function register(): void {
						$this->log->append( $this->name );
					}
				}
			);
		}

		( new Plugin( $container ) )->register_modules( array( 'b', 'a' ) );

		$this->assertSame( array( 'b', 'a' ), $log->getArrayCopy() );
	}

	public function test_rejects_a_service_that_is_not_a_module(): void {
		$container = new Container();
		$container->set( 'x', static fn(): stdClass => new stdClass() );

		$this->expectException( LogicException::class );
		( new Plugin( $container ) )->register_modules( array( 'x' ) );
	}
}
