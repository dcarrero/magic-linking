<?php
/**
 * Proveedor de IA simulado, registrado en el registro del cliente de IA del núcleo (WordPress 7.0+).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Support;

use Throwable;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Un proveedor sin red: contesta lo que diga `$reply` y apunta lo que recibió. Una vez registrado se queda en el
 * registro mientras dure el proceso de PHPUnit, así que `enable( false )` lo deja sin configurar.
 */
final class FakeAiProvider extends AbstractProvider {

	public const ID = 'fake-ai';

	/**
	 * Modelos que ofrece.
	 *
	 * @var list<string>
	 */
	public static array $models = array( 'gpt-4o-mini', 'unpriced-model' );

	/**
	 * Está configurado (hay «conector»).
	 *
	 * @var bool
	 */
	public static bool $enabled = true;

	/**
	 * Qué contesta: un texto, un `Throwable` que lanzar o una función que recibe el número de llamada (desde 1) y
	 * devuelve cualquiera de los dos.
	 *
	 * @var string|Throwable|callable
	 */
	public static $reply = '';

	/**
	 * Tokens que informa: entrada y salida.
	 *
	 * @var array{0: int, 1: int}
	 */
	public static array $usage = array( 1000, 200 );

	/**
	 * Llamadas recibidas.
	 *
	 * @var int
	 */
	public static int $calls = 0;

	/**
	 * Texto de todas las peticiones recibidas, una cadena por llamada (instrucción de sistema y mensajes).
	 *
	 * @var list<string>
	 */
	public static array $sent = array();

	/**
	 * Modelo con el que se atendió cada llamada.
	 *
	 * @var list<string>
	 */
	public static array $used = array();

	/**
	 * Registra el proveedor una vez y lo deja en su estado inicial.
	 */
	public static function install(): void {
		$registry = AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( self::ID ) ) {
			$registry->registerProvider( self::class );
		}
		self::reset();
	}

	/**
	 * Estado inicial entre pruebas.
	 */
	public static function reset(): void {
		self::$models  = array( 'gpt-4o-mini', 'unpriced-model' );
		self::$enabled = true;
		self::$reply   = '';
		self::$usage   = array( 1000, 200 );
		self::$calls   = 0;
		self::$sent    = array();
		self::$used    = array();
	}

	/**
	 * Lo que contesta a la llamada número `$call`.
	 *
	 * @param int $call Número de llamada, desde 1.
	 *
	 * @return string|Throwable
	 */
	public static function answer( int $call ) {
		$reply = self::$reply;

		return is_callable( $reply ) ? $reply( $call ) : $reply;
	}

	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( self::ID, 'Fake AI', ProviderTypeEnum::cloud() );
	}

	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new FakeAiAvailability();
	}

	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new FakeAiDirectory();
	}

	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		return new FakeAiModel( $model_metadata, $provider_metadata );
	}
}
