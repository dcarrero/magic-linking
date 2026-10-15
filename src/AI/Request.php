<?php
/**
 * Petición de texto al cliente de IA.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\AI;

/**
 * Lo que `Client` necesita para hacer una petición. Sin WordPress.
 */
final class Request {

	/**
	 * Crea la petición.
	 *
	 * @param string                    $prompt     Texto de la petición.
	 * @param string                    $system     Instrucción de sistema.
	 * @param array<string, mixed>|null $schema     Esquema JSON de la respuesta; null = texto libre.
	 * @param int                       $max_tokens Tope de tokens de salida (no baja de unos 2.000 con modelos de razonamiento, 05 §10).
	 * @param int                       $timeout    Segundos de espera como máximo.
	 * @param array                     $models     Modelos preferidos, en orden; vacío = el que elija el cliente.
	 * @param int                       $estimated  Tokens estimados (entrada más salida), para el tope diario y el apunte.
	 * @param string                    $type       Tipo de apunte en `magiclinking_jobs` (`ai_rerank`).
	 * @param array<string, mixed>      $params     Datos que se guardan con el apunte; nunca texto del usuario.
	 * @param bool                      $json       Pide salida JSON aunque no haya esquema.
	 * @param string                    $provider   Proveedor previsto (el de `Capabilities`); vacío = el que elija el cliente.
	 *
	 * @phpstan-param list<string> $models
	 */
	public function __construct(
		public readonly string $prompt,
		public readonly string $system = '',
		public readonly ?array $schema = null,
		public readonly int $max_tokens = 2500,
		public readonly int $timeout = 20,
		public readonly array $models = array(),
		public readonly int $estimated = 0,
		public readonly string $type = 'ai_rerank',
		public readonly array $params = array(),
		public readonly bool $json = false,
		public readonly string $provider = ''
	) {
	}

	/**
	 * Copia con otros modelos preferidos.
	 *
	 * @param array $models Modelos.
	 *
	 * @phpstan-param list<string> $models
	 */
	public function with_models( array $models ): self {
		return $this->with( array( 'models' => $models ) );
	}

	/**
	 * Copia con otro tiempo de espera.
	 *
	 * @param int $timeout Segundos.
	 */
	public function with_timeout( int $timeout ): self {
		return $this->with( array( 'timeout' => $timeout ) );
	}

	/**
	 * Copia que pide JSON sin esquema (por si el proveedor rechaza el esquema).
	 */
	public function without_schema(): self {
		return $this->with(
			array(
				'schema' => null,
				'json'   => true,
			)
		);
	}

	/**
	 * Copia con otros datos que guardar en el apunte.
	 *
	 * @param array<string, mixed> $params Datos.
	 */
	public function with_params( array $params ): self {
		return $this->with( array( 'params' => $params ) );
	}

	/**
	 * Copia con algunos campos cambiados.
	 *
	 * @param array<string, mixed> $changes Campos nuevos, por nombre.
	 */
	private function with( array $changes ): self {
		return new self( ...array_merge( get_object_vars( $this ), $changes ) );
	}
}
