<?php
/**
 * Qué puede hacer el conector de IA del sitio (docs/05 §3).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\AI;

use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;

/**
 * Detecta, sin llamar a ningún modelo, si el sitio puede generar texto, si el modelo admite salida JSON y si es local.
 *
 * Las comprobaciones `is_supported_*()` del cliente no llaman al modelo, pero la lista de modelos de algunos
 * proveedores (Ollama: `/api/tags` y un `/api/show` por modelo) sí se pide a la red la primera vez de cada petición
 * PHP (05 §10). Por eso el resultado se guarda una hora en un transitorio propio, sin autoload, y se borra cuando
 * cambian los conectores. Nunca se calcula en el front-end.
 *
 * En WordPress anterior a 7.0 (sin `wp_ai_client_prompt()`) todo es falso y el plugin sigue con el motor léxico.
 *
 * @phpstan-type Detected array{text: bool, json: bool, models: list<array{provider: string, provider_name: string, model: string, name: string, strict?: bool}>, checked_at: int}
 */
final class Capabilities implements Module {

	/**
	 * Transitorio con el resultado (se crea sin autoload porque caduca).
	 */
	public const TRANSIENT = 'magiclinking_ai_capabilities';

	/**
	 * Cuánto dura el resultado guardado.
	 */
	public const TTL = HOUR_IN_SECONDS;

	/**
	 * Prefijo de las opciones de los conectores del núcleo (`connectors_ai_openai_api_key`…).
	 */
	public const CONNECTOR_OPTION_PREFIX = 'connectors_';

	/**
	 * Resultado ya calculado en esta petición.
	 *
	 * @var array|null
	 *
	 * @phpstan-var Detected|null
	 */
	private ?array $memo = null;

	/**
	 * Dice si la petición es del front-end, donde nunca se comprueba nada (null = la comprobación de WordPress).
	 *
	 * @var callable|null
	 */
	private $front_end;

	/**
	 * Constructor.
	 *
	 * @param Client        $client    Cliente de IA.
	 * @param Settings      $settings  Ajustes.
	 * @param callable|null $front_end Devuelve si la petición es del front-end; por defecto, `is_front_end()`.
	 */
	public function __construct( private Client $client, private Settings $settings, ?callable $front_end = null ) {
		$this->front_end = $front_end;
	}

	/**
	 * La petición es una página del front-end. La API REST también pasa por `index.php` (con `WP_USE_THEMES`), pero
	 * no es el front-end: el panel del editor la usa.
	 */
	public static function is_front_end(): bool {
		return wp_using_themes() && ! wp_is_serving_rest_request();
	}

	/**
	 * Engancha la invalidación: cambiar un conector o un plugin de proveedor borra el resultado guardado.
	 */
	public function register(): void {
		if ( ! $this->client->available() ) {
			return;
		}

		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, array( $this, 'on_option' ), 10, 1 );
		}
		foreach ( array( 'activated_plugin', 'deactivated_plugin', 'deleted_plugin' ) as $hook ) {
			add_action( $hook, array( $this, 'forget' ) );
		}
		add_action( 'upgrader_process_complete', array( $this, 'forget' ) );
	}

	/**
	 * Una opción cambió: si es de un conector, el resultado guardado ya no vale.
	 *
	 * @param string $option Nombre de la opción.
	 */
	public function on_option( string $option ): void {
		if ( str_starts_with( $option, self::CONNECTOR_OPTION_PREFIX ) ) {
			$this->forget();
		}
	}

	/**
	 * Borra el resultado guardado.
	 */
	public function forget(): void {
		$this->memo = null;
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Estado del conector: `unavailable` (WordPress sin cliente de IA), `connected` (puede generar texto) o `none`.
	 */
	public function state(): string {
		if ( ! $this->client->available() ) {
			return 'unavailable';
		}

		return $this->detect()['text'] ? 'connected' : 'none';
	}

	/**
	 * Capacidades del sitio.
	 *
	 * @param bool $refresh Ignora lo guardado y vuelve a comprobar.
	 *
	 * @return array{text: bool, json: bool, local: bool, provider: array{id: string, name: string}|null, model: string|null, strict: bool, models: list<array{provider: string, provider_name: string, model: string, name: string, strict?: bool}>, checked_at: int}
	 */
	public function detect( bool $refresh = false ): array {
		$found = $this->found( $refresh );

		// Solo se elige en automático entre los modelos que declaran todo lo que pide el reordenado (esquema JSON,
		// instrucción de sistema, tope de salida); si ninguno lo declara, entre todos. El fijado por el usuario manda.
		$all    = array_values( array_unique( array_column( $found['models'], 'model' ) ) );
		$strict = array();
		foreach ( $found['models'] as $model ) {
			if ( $model['strict'] ?? true ) {
				$strict[] = $model['model'];
			}
		}
		$pinned = $this->settings->ai_model();
		$chosen = '' !== $pinned && Models::pick( $all, $pinned ) === $pinned ? $pinned : Models::pick( array_values( array_unique( array() === $strict ? $all : $strict ) ) );
		$match  = null;
		foreach ( $found['models'] as $model ) {
			if ( $model['model'] === $chosen ) {
				$match = $model;
				break;
			}
		}

		return array(
			'text'       => $found['text'],
			'json'       => $found['json'],
			'local'      => null !== $match && $this->client->is_local( $match['provider'] ),
			'provider'   => null === $match ? null : array(
				'id'   => $match['provider'],
				'name' => $match['provider_name'],
			),
			'model'      => $chosen,
			'strict'     => null === $match || ( $match['strict'] ?? true ),
			'models'     => $found['models'],
			'checked_at' => $found['checked_at'],
		);
	}

	/**
	 * Lo comprobado en el cliente de IA, desde el transitorio si lo hay.
	 *
	 * @param bool $refresh Vuelve a comprobar.
	 *
	 * @phpstan-return Detected
	 */
	private function found( bool $refresh ): array {
		$none = array(
			'text'       => false,
			'json'       => false,
			'models'     => array(),
			'checked_at' => 0,
		);

		// Sin cliente de IA o en el front-end (donde nunca se comprueba): todo en falso y sin escribir nada.
		if ( ! $this->client->available() || ( null === $this->front_end ? self::is_front_end() : (bool) ( $this->front_end )() ) ) {
			return $none;
		}

		if ( ! $refresh && null !== $this->memo ) {
			return $this->memo;
		}

		// Un veto del sitio puede depender del usuario o de la petición: con uno puesto no se guarda nada.
		$cacheable = ! has_filter( 'wp_ai_client_prevent_prompt' ) && ( ! function_exists( 'wp_supports_ai' ) || wp_supports_ai() );

		if ( $cacheable && ! $refresh ) {
			$stored = get_transient( self::TRANSIENT );
			if ( is_array( $stored ) && isset( $stored['text'], $stored['json'], $stored['models'], $stored['checked_at'] ) ) {
				$this->memo = $stored;

				return $stored;
			}
		}

		$text  = $this->client->supports();
		$found = array(
			'text'       => $text,
			'json'       => $text && $this->client->supports( true ),
			'models'     => $text ? $this->client->text_models() : array(),
			'checked_at' => time(),
		);

		$this->memo = $found;
		if ( $cacheable ) {
			set_transient( self::TRANSIENT, $found, self::TTL );
		}

		return $found;
	}
}
