<?php
/**
 * Cliente de IA: la única puerta al cliente de IA de WordPress (docs/05 §2).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\AI;

use Throwable;
use WP_Error;

/**
 * Todo lo que el plugin pide a un modelo pasa por aquí, y solo aquí se llama a `wp_ai_client_prompt()`.
 *
 * Nombres del núcleo de WordPress 7.x que se usan (leídos en `wp-includes` de WordPress 7.1.3, F1-14):
 *
 * - `wp_ai_client_prompt()` y `wp_supports_ai()`: `wp-includes/ai-client.php`.
 * - `WP_AI_Client_Prompt_Builder` (métodos en snake_case: `using_system_instruction()`, `using_max_tokens()`,
 *   `using_model_preference()`, `using_model_config()`, `as_json_response()`, `is_supported_for_text_generation()`,
 *   `generate_text_result()`): `wp-includes/ai-client/class-wp-ai-client-prompt-builder.php`. Los métodos que
 *   generan devuelven `WP_Error` en vez de lanzar excepciones: `prompt_prevented` (503), `prompt_network_error`
 *   (503), `prompt_client_error` (código HTTP del proveedor), `prompt_upstream_server_error`,
 *   `prompt_token_limit_reached` y `prompt_invalid_argument`. La propia clase aplica el filtro
 *   `wp_ai_client_prevent_prompt` a `is_supported_*()` y a `generate_*()`, así que respetarlo no cuesta código aquí.
 * - Filtro `wp_ai_client_default_request_timeout` (segundos): mismo fichero, constructor del constructor de peticiones.
 * - El resultado es un `WordPress\AiClient\Results\DTO\GenerativeAiResult` (`wp-includes/php-ai-client/src/Results/DTO/`):
 *   `toText()` (lanza `RuntimeException` sin texto), `getTokenUsage()` (`getPromptTokens()`, `getCompletionTokens()`,
 *   `getTotalTokens()`), `getProviderMetadata()` (`getId()`, `getName()`) y `getModelMetadata()` (`getId()`). El
 *   envoltorio del núcleo devuelve ese objeto tal cual, en camelCase.
 * - Lista de modelos de texto sin llamar al modelo: `AiClient::defaultRegistry()->findModelsMetadataForSupport()` con
 *   `ModelRequirements` y `CapabilityEnum::textGeneration()` (`php-ai-client/src/Providers/ProviderRegistry.php`).
 *
 * Reglas (docs/05 §2 y §8): se comprueba el tope diario, se respeta el veto del sitio y el filtro propio
 * `magiclinking_before_ai_request`, el modelo usado se lee del resultado (la preferencia no garantiza nada, §10),
 * se apuntan los tokens reales y se hace un reintento con espera ante error de red, 429 o 5xx. Devuelve una
 * respuesta o un error tipado; el detalle técnico nunca sale de aquí hacia la pantalla.
 */
class Client {

	/**
	 * Proveedores que se consideran locales (el texto no sale del servidor), por identificador. Ampliable con el
	 * filtro `magiclinking_local_ai_providers`.
	 */
	public const LOCAL_PROVIDERS = array( 'ollama' );

	/**
	 * Espera antes del reintento, en milisegundos.
	 *
	 * @var int
	 */
	protected int $retry_wait_ms = 800;

	/**
	 * Constructor.
	 *
	 * @param Budget $budget Tope diario y registro del gasto.
	 */
	public function __construct( protected Budget $budget ) {
	}

	/**
	 * Hay cliente de IA en este WordPress (7.0 o posterior).
	 */
	public function available(): bool {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * El sitio impide las peticiones de IA: el cliente de IA está desactivado (`wp_supports_ai()`) o hay un filtro
	 * `wp_ai_client_prevent_prompt` puesto. Sirve para decirle al usuario la causa; que haya filtro no significa que
	 * vete esta petición.
	 */
	public function vetoed(): bool {
		return $this->available() && ( ! wp_supports_ai() || false !== has_filter( 'wp_ai_client_prevent_prompt' ) );
	}

	/**
	 * El cliente puede generar texto con algún conector, sin llamar al modelo. Con `$json`, además, con salida JSON.
	 *
	 * Respeta `wp_supports_ai()` y `wp_ai_client_prevent_prompt` (el constructor los aplica a `is_supported_*()`).
	 *
	 * @param bool $json Pide salida JSON.
	 */
	public function supports( bool $json = false ): bool {
		if ( ! $this->available() ) {
			return false;
		}

		try {
			$builder = wp_ai_client_prompt( 'ping' );
			if ( $json ) {
				$builder = $builder->as_json_response( array( 'type' => 'object' ) );
			}

			return true === $builder->is_supported_for_text_generation();
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Modelos de texto de los conectores configurados, sin llamar a ningún modelo.
	 *
	 * Con algunos proveedores (Ollama) la primera lectura de cada petición PHP pide la lista a la red (05 §10): por
	 * eso `Capabilities` guarda el resultado y esto nunca se llama en el front-end.
	 *
	 * @return list<array{provider: string, provider_name: string, model: string, name: string}>
	 */
	public function text_models(): array {
		if ( ! $this->available() || ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			return array();
		}

		$registry_class     = '\\WordPress\\AiClient\\AiClient';
		$requirements_class = '\\WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements';
		$capability_class   = '\\WordPress\\AiClient\\Providers\\Models\\Enums\\CapabilityEnum';
		if ( ! class_exists( $registry_class ) || ! class_exists( $requirements_class ) || ! class_exists( $capability_class ) ) {
			return array();
		}

		$out = array();
		try {
			$groups = $registry_class::defaultRegistry()->findModelsMetadataForSupport(
				new $requirements_class( array( $capability_class::textGeneration() ), array() )
			);
			foreach ( $groups as $group ) {
				$provider = $group->getProvider();
				foreach ( $group->getModels() as $model ) {
					$out[] = array(
						'provider'      => (string) $provider->getId(),
						'provider_name' => (string) $provider->getName(),
						'model'         => (string) $model->getId(),
						'name'          => (string) $model->getName(),
					);
				}
			}
		} catch ( Throwable $e ) {
			return array();
		}

		return $out;
	}

	/**
	 * Si un proveedor es local: el texto no sale del servidor.
	 *
	 * @param string $provider Identificador del proveedor.
	 */
	public function is_local( string $provider ): bool {
		/**
		 * Filtra los identificadores de proveedor de IA que cuentan como locales (el texto no sale del servidor).
		 *
		 * Solo cambia lo que el plugin dice al usuario sobre privacidad y el coste mostrado (cero); no cambia adónde
		 * va la petición. Quien apunte Ollama a otra máquina debería quitarlo de la lista.
		 *
		 * @param list<string> $providers Identificadores (por defecto, `ollama`).
		 */
		$providers = apply_filters( 'magiclinking_local_ai_providers', self::LOCAL_PROVIDERS );

		return in_array( strtolower( $provider ), array_map( 'strtolower', array_map( 'strval', (array) $providers ) ), true );
	}

	/**
	 * Hace una petición de texto.
	 *
	 * @param Request $request Petición.
	 *
	 * @return Response|Failure
	 */
	public function generate( Request $request ): Response|Failure {
		if ( ! $this->available() ) {
			return new Failure( Failure::UNAVAILABLE, 'wp_ai_client_prompt() does not exist.' );
		}

		/**
		 * Permite al sitio vetar o modificar una petición de IA antes de enviarla (además de
		 * `wp_ai_client_prevent_prompt`, que también se respeta).
		 *
		 * Devuelve la petición (la misma o una modificada) para seguir, o `false` para vetarla: el plugin seguirá
		 * con el motor léxico sin mostrar ningún error.
		 *
		 * @param Request|false $request La petición.
		 * @param string        $type    Tipo de apunte (`ai_rerank`).
		 */
		$request = apply_filters( 'magiclinking_before_ai_request', $request, $request->type );
		if ( ! $request instanceof Request ) {
			return new Failure( Failure::VETOED, 'magiclinking_before_ai_request' );
		}

		if ( ! $this->budget->allows( $request->estimated ) ) {
			return new Failure( Failure::BUDGET, 'daily token limit' );
		}

		$started  = microtime( true );
		$attempts = 1;
		$outcome  = $this->attempt( $request );

		// Un reintento con espera ante red, 429 o 5xx, si cabe en el tiempo que se dio a la petición.
		if ( $outcome instanceof Failure && $outcome->retryable() ) {
			$left = (int) floor( $request->timeout - ( microtime( true ) - $started ) - $this->retry_wait_ms / 1000 );
			if ( $left >= 3 ) {
				$this->wait( $this->retry_wait_ms );
				++$attempts;
				$outcome = $this->attempt( $request->with_timeout( $left ) );
			}
		}

		$this->log( $request, $outcome, $attempts, (int) round( ( microtime( true ) - $started ) * 1000 ) );

		return $outcome;
	}

	/**
	 * Una vuelta al proveedor. Si el proveedor rechaza el esquema JSON (400 o 422), se repite sin esquema: el
	 * reordenado valida la respuesta por su cuenta.
	 *
	 * @param Request $request Petición.
	 *
	 * @return Response|Failure
	 */
	private function attempt( Request $request ): Response|Failure {
		$outcome = $this->read( $this->run( $request ) );

		if ( $outcome instanceof Failure && null !== $request->schema && in_array( $outcome->http, array( 400, 422 ), true ) ) {
			$outcome = $this->read( $this->run( $request->without_schema() ) );
		}

		return $outcome;
	}

	/**
	 * Hace la llamada al cliente de IA de WordPress. Es la única llamada a `wp_ai_client_prompt()` que genera.
	 *
	 * Se puede sustituir en las pruebas para no necesitar un proveedor.
	 *
	 * @param Request $request Petición.
	 *
	 * @return object|WP_Error El resultado (`GenerativeAiResult`) o el error del envoltorio del núcleo.
	 */
	protected function run( Request $request ): object {
		$seconds = max( 1, $request->timeout );
		$limit   = static fn(): float => (float) $seconds;

		try {
			// El tiempo de espera se lee al crear el constructor de peticiones: el filtro vive lo que dura esa línea.
			add_filter( 'wp_ai_client_default_request_timeout', $limit, 99 );
			try {
				$builder = wp_ai_client_prompt( $request->prompt );
			} finally {
				remove_filter( 'wp_ai_client_default_request_timeout', $limit, 99 );
			}

			if ( '' !== $request->system ) {
				$builder = $builder->using_system_instruction( $request->system );
			}
			$builder = $builder->using_max_tokens( $request->max_tokens );
			if ( '' !== $request->provider ) {
				$builder = $builder->using_provider( $request->provider );
			}
			if ( array() !== $request->models ) {
				$builder = $builder->using_model_preference( ...$request->models );
			}
			if ( null !== $request->schema ) {
				$builder = $builder->as_json_response( $request->schema );
			} elseif ( $request->json ) {
				$builder = $builder->as_json_response();
			}

			// El proveedor de Ollama ignora el filtro de arriba y corta a los 60 s (05 §10): su opción propia. Solo con
			// ese proveedor: una opción a medida es un requisito más y dejaría fuera a los modelos que no la declaran.
			$config_class = '\\WordPress\\AiClient\\Providers\\Models\\DTO\\ModelConfig';
			if ( 'ollama' === $request->provider && class_exists( $config_class ) ) {
				$builder = $builder->using_model_config( $config_class::fromArray( array( 'customOptions' => array( 'ollama.request_timeout' => $seconds ) ) ) );
			}

			return $builder->generate_text_result();
		} catch ( Throwable $e ) {
			return new WP_Error( 'magiclinking_ai_exception', $e->getMessage(), array( 'status' => 500 ) );
		}//end try
	}

	/**
	 * Convierte lo que devolvió `run()` en una respuesta o un error tipado.
	 *
	 * @param object $result Resultado de `run()`.
	 *
	 * @return Response|Failure
	 */
	private function read( object $result ): Response|Failure {
		if ( $result instanceof WP_Error ) {
			return self::classify( $result );
		}

		try {
			$usage    = $result->getTokenUsage();
			$in       = (int) $usage->getPromptTokens();
			$total    = max( (int) $usage->getTotalTokens(), $in + (int) $usage->getCompletionTokens() );
			$provider = $result->getProviderMetadata();
			$id       = (string) $provider->getId();
			$name     = (string) $provider->getName();
			$model    = (string) $result->getModelMetadata()->getId();
		} catch ( Throwable $e ) {
			return new Failure( Failure::FAILED, 'Unreadable result: ' . $e->getMessage() );
		}

		try {
			$text = $result->toText();
		} catch ( Throwable $e ) {
			$text = '';
		}

		// Un modelo de razonamiento con poco margen de salida puede gastarlo todo pensando y devolver texto vacío
		// sin error (05 §10): es una respuesta inválida, y los tokens se cobran igualmente.
		if ( '' === trim( $text ) ) {
			return new Failure( Failure::INVALID, 'Empty text from ' . $model, 0, $total );
		}

		$out   = max( 0, $total - $in );
		$local = $this->is_local( $id );

		return new Response( $text, $in, $out, $id, $name, $model, $local, CostEstimator::cost( $in, $out, $model, $local ) );
	}

	/**
	 * Clasifica el error del envoltorio del núcleo.
	 *
	 * @param WP_Error $error Error de `generate_*()`.
	 */
	public static function classify( WP_Error $error ): Failure {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$detail = $error->get_error_code() . ': ' . $error->get_error_message();

		switch ( $error->get_error_code() ) {
			case 'prompt_prevented':
				return new Failure( Failure::VETOED, $detail, $status );
			case 'prompt_network_error':
			case 'prompt_upstream_server_error':
				return new Failure( Failure::NETWORK, $detail, $status );
			case 'prompt_client_error':
				return new Failure( 429 === $status ? Failure::RATE_LIMIT : Failure::FAILED, $detail, $status );
			case 'prompt_token_limit_reached':
				return new Failure( Failure::INVALID, $detail, $status );
			default:
				return new Failure( Failure::FAILED, $detail, $status );
		}
	}

	/**
	 * Espera antes del reintento.
	 *
	 * @param int $milliseconds Milisegundos.
	 */
	protected function wait( int $milliseconds ): void {
		usleep( $milliseconds * 1000 );
	}

	/**
	 * Apunta la petición en `magiclinking_jobs` si llegó a hacerse (las vetadas y las que no caben no cuestan nada).
	 *
	 * @param Request          $request  Petición.
	 * @param Response|Failure $outcome  Resultado.
	 * @param int              $attempts Intentos hechos.
	 * @param int              $elapsed  Milisegundos empleados.
	 */
	private function log( Request $request, Response|Failure $outcome, int $attempts, int $elapsed ): void {
		$params = $request->params + array(
			'attempts'   => $attempts,
			'elapsed_ms' => $elapsed,
		);

		if ( $outcome instanceof Response ) {
			$this->budget->record(
				array(
					'type'        => $request->type,
					'status'      => 'done',
					'tokens_est'  => $request->estimated,
					'tokens_real' => $outcome->tokens(),
					'cost_est'    => (float) CostEstimator::cost( $request->estimated, 0, $outcome->model, $outcome->local ),
					'cost_real'   => (float) $outcome->cost,
					'provider'    => $outcome->provider,
					'model'       => $outcome->model,
					'user_id'     => get_current_user_id(),
					'params'      => $params,
					'error'       => '',
				)
			);

			return;
		}

		if ( in_array( $outcome->code, array( Failure::UNAVAILABLE, Failure::VETOED, Failure::BUDGET ), true ) ) {
			return;
		}

		$this->budget->record(
			array(
				'type'        => $request->type,
				'status'      => 'failed',
				'tokens_est'  => $request->estimated,
				'tokens_real' => $outcome->tokens,
				'cost_est'    => 0.0,
				'cost_real'   => 0.0,
				'provider'    => '',
				'model'       => '',
				'user_id'     => get_current_user_id(),
				'params'      => $params,
				'error'       => substr( $outcome->code . ( '' !== $outcome->detail ? ': ' . $outcome->detail : '' ), 0, 500 ),
			)
		);
	}
}
