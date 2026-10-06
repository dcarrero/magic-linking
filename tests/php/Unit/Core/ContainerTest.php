<?php
/**
 * Contenedor mínimo de servicios.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Core;

use LogicException;
use MagicLinking\Core\Container;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ContainerTest extends TestCase {

	public function test_creates_a_service_once_and_reuses_it(): void {
		$container = new Container();
		$calls     = 0;
		$container->set(
			'svc',
			static function () use ( &$calls ): stdClass {
				++$calls;
				return new stdClass();
			}
		);

		$this->assertSame( 0, $calls, 'La fábrica no se llama al declarar.' );
		$first = $container->get( 'svc' );
		$this->assertSame( $first, $container->get( 'svc' ) );
		$this->assertSame( 1, $calls );
	}

	public function test_factory_receives_the_container_to_resolve_dependencies(): void {
		$container = new Container();
		$container->set( 'dep', static fn(): stdClass => new stdClass() );
		$container->set(
			'svc',
			static function ( Container $c ): stdClass {
				$svc      = new stdClass();
				$svc->dep = $c->get( 'dep' );
				return $svc;
			}
		);

		$svc = $container->get( 'svc' );
		$this->assertInstanceOf( stdClass::class, $svc );
		$this->assertSame( $container->get( 'dep' ), $svc->dep ?? null );
	}

	public function test_has(): void {
		$container = new Container();
		$this->assertFalse( $container->has( 'svc' ) );
		$container->set( 'svc', static fn(): stdClass => new stdClass() );
		$this->assertTrue( $container->has( 'svc' ) );
	}

	public function test_unknown_service_throws(): void {
		$this->expectException( LogicException::class );
		( new Container() )->get( 'nope' );
	}

	public function test_can_redefine_before_use_but_not_after(): void {
		$container = new Container();
		$a         = new stdClass();
		$b         = new stdClass();
		$container->set( 'svc', static fn(): stdClass => $a );
		$container->set( 'svc', static fn(): stdClass => $b );
		$this->assertSame( $b, $container->get( 'svc' ) );

		$this->expectException( LogicException::class );
		$container->set( 'svc', static fn(): stdClass => $a );
	}
}
