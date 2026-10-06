<?php
/**
 * Contenedor mínimo de servicios.
 *
 * Sin librería de inyección de dependencias: cada servicio se declara con una
 * fábrica y se crea una sola vez, la primera vez que alguien lo pide.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Core;

use LogicException;

/**
 * Registro perezoso de servicios compartidos.
 */
final class Container {

	/**
	 * Fábricas por identificador.
	 *
	 * @var array<string, callable(Container): object>
	 */
	private array $factories = array();

	/**
	 * Servicios ya creados.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Declara un servicio. Se puede redefinir mientras nadie lo haya pedido.
	 *
	 * @param string                      $id      Identificador, normalmente el nombre de la clase.
	 * @param callable(Container): object $factory Crea el servicio; recibe el contenedor.
	 *
	 * @throws LogicException Si el servicio ya se creó.
	 */
	public function set( string $id, callable $factory ): void {
		if ( isset( $this->instances[ $id ] ) ) {
			throw new LogicException( sprintf( 'Service "%s" is already in use and cannot be redefined.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje interno, no se imprime.
		}

		$this->factories[ $id ] = $factory;
	}

	/**
	 * Indica si hay un servicio declarado con ese identificador.
	 *
	 * @param string $id Identificador.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Devuelve el servicio, creándolo la primera vez.
	 *
	 * @param string $id Identificador.
	 *
	 * @throws LogicException Si no está declarado.
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new LogicException( sprintf( 'Service "%s" is not defined.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje interno, no se imprime.
		}

		$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->instances[ $id ];
	}
}
