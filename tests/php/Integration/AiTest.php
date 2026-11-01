<?php
/**
 * IA vía conector (F1-14): cliente, capacidades, tope diario, reordenado y rutas REST, con un proveedor de IA
 * simulado registrado en el registro del cliente de IA del núcleo. Sin él (WordPress 6.9) todo degrada limpio.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\AI\Budget;
use MagicLinking\AI\Capabilities;
use MagicLinking\AI\Client;
use MagicLinking\AI\Failure;
use MagicLinking\AI\RateLimit;
use MagicLinking\AI\Request;
use MagicLinking\AI\RerankProtocol;
use MagicLinking\AI\Reranker;
use MagicLinking\AI\Response;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\Core\Settings;
use MagicLinking\Jobs\JobRepository;
use MagicLinking\Rest\AiController;
use MagicLinking\Tests\Integration\Support\FakeAiProvider;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;

final class AiTest extends GraphTestCase {

	private const BASE = '/magic-linking/v1';

	private const SENTENCE = 'Instalar una bomba de calor reduce el consumo de la vivienda de forma notable.';

	private int $admin = 0;

	private int $author = 0;

	private int $subscriber = 0;

	/** La entrada que se edita (del autor). */
	private int $source = 0;

	/** @var list<int> */
	private array $targets = array();

	public function set_up(): void {
		parent::set_up();

		add_filter( 'locale', static fn(): string => 'es_ES' );
		$this->admin      = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'secreto@ejemplo.com',
			)
		);
		$this->author     = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			FakeAiProvider::install();
		}
		Plugin::container()->get( Capabilities::class )->forget();
		delete_transient( Capabilities::TRANSIENT );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		if ( class_exists( FakeAiProvider::class, false ) ) {
			FakeAiProvider::reset();
		}
		Plugin::container()->get( Capabilities::class )->forget();
		parent::tear_down();
	}

	// ------------------------------------------------------------------ Ayudas.

	private function needs_ai_client(): void {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'Esta prueba necesita el cliente de IA de WordPress 7.0 o posterior.' );
		}
	}

	private function container( string $service ): object {
		return Plugin::container()->get( $service );
	}

	private function p( string $html ): string {
		return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * La entrada que se edita y destinos de todo tipo: dos publicados, uno privado, un borrador y uno con contraseña.
	 */
	private function corpus(): void {
		$this->source = self::factory()->post->create(
			array(
				'post_title'   => 'Ahorro en casa',
				'post_author'  => $this->author,
				'post_content' => $this->p( self::SENTENCE ),
			)
		);

		$make = static fn( string $title, array $extra = array() ): int => self::factory()->post->create(
			array_merge(
				array(
					'post_title'   => $title,
					'post_content' => "<!-- wp:heading -->\n<h2>Qué es</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Contenido de {$title} con datos útiles. Escribe a contacto@privado.es para más información.</p>\n<!-- /wp:paragraph -->",
				),
				$extra
			)
		);

		$this->targets = array(
			$make( 'Bombas de calor: guía' ),
			$make( 'Consumo eléctrico del hogar' ),
			$make( 'SECRETO privado', array( 'post_status' => 'private' ) ),
			$make( 'BORRADOR reservado', array( 'post_status' => 'draft' ) ),
			$make( 'Protegida con clave', array( 'post_password' => 'abc' ) ),
		);
	}

	/**
	 * Las sugerencias que el panel enviaría: una por destino, con la misma frase y ancla.
	 *
	 * @return list<array{target_id: int, sentence: string, anchor: string, offset: int}>
	 */
	private function items(): array {
		return array_map(
			static fn( int $id ): array => array(
				'target_id' => $id,
				'sentence'  => self::SENTENCE,
				'anchor'    => 'bomba de calor',
				'offset'    => (int) strpos( self::SENTENCE, 'bomba de calor' ),
			),
			$this->targets
		);
	}

	private function reply( string $json ): void {
		FakeAiProvider::$reply = $json;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function request( string $method, string $route, array $params = array(), ?int $user = null ): WP_REST_Response {
		wp_set_current_user( $user ?? $this->admin );
		$request = new WP_REST_Request( $method, self::BASE . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Un cliente que no espera entre intentos.
	 */
	private function quick_client( ?Budget $budget = null ): Client {
		return new class( $budget ?? $this->container( Budget::class ) ) extends Client {
			protected function wait( int $milliseconds ): void {
			}
		};
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function last_ai_row(): ?array {
		global $wpdb;
		$name = Schema::table( $wpdb->prefix, 'jobs' );
		$row  = $wpdb->get_row( "SELECT * FROM {$name} WHERE type LIKE 'ai\\_%' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	private function ai_rows(): int {
		global $wpdb;
		$name = Schema::table( $wpdb->prefix, 'jobs' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$name} WHERE type LIKE 'ai\\_%'" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function simple_request( string $prompt = 'hola' ): Request {
		return new Request( $prompt, 'sistema', null, 2500, 20, array(), 100, 'ai_rerank', array( 'post_id' => 7 ) );
	}

	// ------------------------------------------------------------------ Sin cliente de IA (WordPress 6.9).

	public function test_without_the_ai_client_everything_degrades_without_errors(): void {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'Solo en WordPress anterior a 7.0, sin cliente de IA.' );
		}

		$caps = $this->container( Capabilities::class )->detect();
		$this->assertFalse( $caps['text'] );
		$this->assertFalse( $caps['json'] );
		$this->assertFalse( $caps['local'] );
		$this->assertSame( 'unavailable', $this->container( Capabilities::class )->state() );
		$this->assertInstanceOf( Failure::class, $this->container( Client::class )->generate( $this->simple_request() ) );

		$this->corpus();
		$response = $this->request(
			'POST',
			'/suggestions/rerank',
			array(
				'post_id' => $this->source,
				'items'   => $this->items(),
			),
			$this->author
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['ok'] );
		$this->assertSame( Failure::UNAVAILABLE, $response->get_data()['reason'] );

		$status = $this->request( 'GET', '/ai/status' )->get_data();
		$this->assertSame( 'unavailable', $status['state'] );
		$this->assertFalse( $status['available'] );
		$this->assertFalse( $this->request( 'GET', '/ai/capabilities' )->get_data()['ready'] );
	}

	public function test_cached_state_never_computes_anything(): void {
		$spy = new class( $this->container( Budget::class ) ) extends Client {
			public int $calls = 0;

			public function available(): bool {
				return true;
			}

			public function supports( bool $json = false ): bool {
				++$this->calls;
				return true;
			}

			public function text_models(): array {
				++$this->calls;
				return array();
			}
		};
		delete_transient( Capabilities::TRANSIENT );
		$caps = new Capabilities( $spy, $this->container( Settings::class ), static fn(): bool => false );

		$this->assertNull( $caps->cached_state(), 'Con el transitorio frío no se sabe.' );
		$this->assertSame( 0, $spy->calls, 'Y no se pregunta al cliente de IA.' );
		$this->assertNull( ( new Capabilities( $spy, $this->container( Settings::class ), static fn(): bool => true ) )->cached_state(), 'En el front-end tampoco.' );

		$caps->detect();
		$this->assertGreaterThan( 0, $spy->calls );
		$calls = $spy->calls;
		$this->assertSame( 'connected', $caps->cached_state() );
		$this->assertSame( $calls, $spy->calls, 'Ya calculado, solo se lee.' );
	}

	public function test_a_client_that_is_not_available_degrades_the_same_way_on_any_version(): void {
		$none = new class( $this->container( Budget::class ) ) extends Client {
			public function available(): bool {
				return false;
			}
		};
		$caps = new Capabilities( $none, $this->container( Settings::class ) );

		$this->assertSame( 'unavailable', $caps->state() );
		$this->assertSame( 'unavailable', $caps->cached_state() );
		$this->assertFalse( $caps->detect()['text'] );
		$this->assertNull( $caps->detect()['provider'] );
		$this->assertFalse( $none->supports() );
		$this->assertSame( array(), $none->text_models() );

		$outcome = $none->generate( $this->simple_request() );
		$this->assertInstanceOf( Failure::class, $outcome );
		$this->assertSame( Failure::UNAVAILABLE, $outcome->code );
		$this->assertSame( 0, $this->ai_rows(), 'Sin cliente no hay petición que apuntar.' );

		$this->corpus();
		$reranker = new Reranker( $none, $caps, $this->container( \MagicLinking\Index\PostSource::class ), $this->container( \MagicLinking\Content\Destination::class ), $this->container( \MagicLinking\I18n\Language::class ), $this->container( Budget::class ) );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => Failure::UNAVAILABLE,
			),
			$reranker->rerank( $this->source, $this->items() )
		);
	}

	// ------------------------------------------------------------------ Capacidades.

	public function test_capabilities_detect_text_json_models_and_the_model_that_will_be_used(): void {
		$this->needs_ai_client();

		$caps = $this->container( Capabilities::class )->detect();

		$this->assertTrue( $caps['text'] );
		$this->assertTrue( $caps['json'] );
		$this->assertFalse( $caps['local'] );
		$this->assertSame( 'gpt-4o-mini', $caps['model'], 'Entre los dos, el pequeño de la lista de preferencia.' );
		$this->assertSame( FakeAiProvider::ID, $caps['provider']['id'] );
		$this->assertSame( 'Fake AI', $caps['provider']['name'] );
		$this->assertContains( 'unpriced-model', array_column( $caps['models'], 'model' ) );
		$this->assertSame( 0, FakeAiProvider::$calls, 'Detectar no llama al modelo.' );
		$this->assertSame( 'connected', $this->container( Capabilities::class )->state() );
	}

	public function test_the_model_chosen_in_settings_wins_if_it_exists(): void {
		$this->needs_ai_client();

		$this->container( Settings::class )->update( array( 'ai_model' => 'unpriced-model' ) );
		$this->assertSame( 'unpriced-model', $this->container( Capabilities::class )->detect()['model'] );

		$this->container( Settings::class )->update( array( 'ai_model' => 'ya-no-existe' ) );
		$this->assertSame( 'gpt-4o-mini', $this->container( Capabilities::class )->detect()['model'], 'Si el elegido ya no está, vuelve a la preferencia.' );
	}

	public function test_a_local_provider_is_marked_local_only_if_the_site_says_so(): void {
		$this->needs_ai_client();
		$caps = $this->container( Capabilities::class );

		$this->assertFalse( $caps->detect()['local'] );

		add_filter( 'magiclinking_local_ai_providers', static fn( array $providers ): array => array_merge( $providers, array( FakeAiProvider::ID ) ) );
		$this->assertTrue( $caps->detect()['local'] );
	}

	public function test_the_result_is_cached_for_an_hour_without_autoload_and_dropped_when_a_connector_changes(): void {
		$this->needs_ai_client();
		global $wpdb;
		$caps = $this->container( Capabilities::class );

		$caps->detect();
		$stored = get_transient( Capabilities::TRANSIENT );
		$this->assertIsArray( $stored );
		$this->assertTrue( $stored['text'] );
		$timeout = (int) get_option( '_transient_timeout_' . Capabilities::TRANSIENT );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $timeout, 30 );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . Capabilities::TRANSIENT ) ); // phpcs:ignore WordPress.DB
		$this->assertContains( $autoload, array( 'off', 'no' ), 'Nada del plugin en autoload salvo sus ajustes.' );

		// Se lee de lo guardado: aunque el proveedor desaparezca, mientras no cambie un conector sigue igual.
		FakeAiProvider::$enabled = false;
		$fresh                   = new Capabilities( $this->container( Client::class ), $this->container( Settings::class ) );
		$this->assertTrue( $fresh->detect()['text'] );

		update_option( 'connectors_ai_fake_api_key', 'abc' );
		$this->assertFalse( get_transient( Capabilities::TRANSIENT ), 'Cambiar un conector borra lo guardado.' );
		$this->assertFalse( ( new Capabilities( $this->container( Client::class ), $this->container( Settings::class ) ) )->detect()['text'] );
	}

	public function test_other_options_do_not_drop_the_cache(): void {
		$this->needs_ai_client();
		$this->container( Capabilities::class )->detect();

		update_option( 'blogname', 'Otro nombre' );

		$this->assertIsArray( get_transient( Capabilities::TRANSIENT ) );
	}

	public function test_capabilities_are_never_computed_on_the_front_end(): void {
		$this->needs_ai_client();
		$caps = new Capabilities( $this->container( Client::class ), $this->container( Settings::class ), static fn(): bool => true );

		$this->assertFalse( $caps->detect()['text'] );
		$this->assertSame( 0, FakeAiProvider::$calls );
		$this->assertFalse( get_transient( Capabilities::TRANSIENT ), 'Y no escribe nada.' );
	}

	public function test_the_front_end_check_lets_rest_requests_through_even_though_they_use_index_php(): void {
		$this->assertFalse( Capabilities::is_front_end(), 'Fuera de una plantilla no es front-end.' );

		add_filter( 'wp_using_themes', '__return_true' );
		$this->assertTrue( Capabilities::is_front_end(), 'Una página con plantilla, sí.' );
	}

	public function test_a_site_veto_makes_detection_false_and_is_not_cached(): void {
		$this->needs_ai_client();
		add_filter( 'wp_ai_client_prevent_prompt', '__return_true' );

		$caps = new Capabilities( $this->container( Client::class ), $this->container( Settings::class ) );

		$this->assertFalse( $caps->detect()['text'] );
		$this->assertFalse( get_transient( Capabilities::TRANSIENT ), 'Un veto puede depender del usuario: no se guarda.' );
	}

	// ------------------------------------------------------------------ Cliente.

	public function test_a_request_reads_tokens_and_model_from_the_result_and_records_them(): void {
		$this->needs_ai_client();
		wp_set_current_user( $this->admin );
		$this->reply( 'hola' );
		FakeAiProvider::$usage = array( 1200, 300 );

		// Se pide un modelo que no existe: el cliente usa cualquier otro (05 §10), y lo que cuenta es el del resultado.
		$outcome = $this->quick_client()->generate( $this->simple_request()->with_models( array( 'no-existe' ) ) );

		$this->assertInstanceOf( Response::class, $outcome );
		$this->assertSame( 'hola', $outcome->text );
		$this->assertSame( 1200, $outcome->tokens_in );
		$this->assertSame( 300, $outcome->tokens_out );
		$this->assertSame( FakeAiProvider::ID, $outcome->provider );
		$this->assertSame( FakeAiProvider::$used[0], $outcome->model );

		$row = $this->last_ai_row();
		$this->assertNotNull( $row );
		$this->assertSame( 'ai_rerank', $row['type'] );
		$this->assertSame( 'done', $row['status'] );
		$this->assertSame( '1500', $row['ai_tokens_real'] );
		$this->assertSame( '100', $row['ai_tokens_est'] );
		$this->assertSame( FakeAiProvider::ID, $row['ai_provider'] );
		$this->assertSame( FakeAiProvider::$used[0], $row['ai_model'] );
		$this->assertSame( (string) $this->admin, $row['created_by'] );
		$this->assertStringNotContainsString( 'hola', (string) $row['params'], 'El apunte no guarda texto.' );
	}

	public function test_cost_comes_from_the_price_table_and_is_unknown_for_a_model_without_price(): void {
		$this->needs_ai_client();
		$this->reply( 'ok' );
		FakeAiProvider::$usage = array( 1000000, 1000000 );
		$this->container( Settings::class )->update( array( 'ai_daily_token_limit' => 100000000 ) );

		$priced = $this->quick_client()->generate( $this->simple_request()->with_models( array( 'gpt-4o-mini' ) ) );
		$this->assertInstanceOf( Response::class, $priced );
		$this->assertEqualsWithDelta( 0.75, (float) $priced->cost, 1e-9 );
		$this->assertEqualsWithDelta( 0.75, (float) $this->last_ai_row()['ai_cost_real'], 1e-6 );

		$free = $this->quick_client()->generate( $this->simple_request()->with_models( array( 'unpriced-model' ) ) );
		$this->assertInstanceOf( Response::class, $free );
		$this->assertNull( $free->cost, 'Sin precio en la tabla solo se enseñan los tokens.' );
	}

	public function test_a_local_provider_costs_zero(): void {
		$this->needs_ai_client();
		add_filter( 'magiclinking_local_ai_providers', static fn(): array => array( FakeAiProvider::ID ) );
		$this->reply( 'ok' );

		$outcome = $this->quick_client()->generate( $this->simple_request() );

		$this->assertInstanceOf( Response::class, $outcome );
		$this->assertTrue( $outcome->local );
		$this->assertSame( 0.0, $outcome->cost );
	}

	public function test_a_server_error_is_retried_once_and_the_second_try_can_succeed(): void {
		$this->needs_ai_client();
		FakeAiProvider::$reply = static fn( int $call ) => 1 === $call ? new ServerException( 'Overloaded', 529 ) : 'ya está';

		$outcome = $this->quick_client()->generate( $this->simple_request() );

		$this->assertInstanceOf( Response::class, $outcome );
		$this->assertSame( 2, FakeAiProvider::$calls );
		$this->assertSame( 2, json_decode( (string) $this->last_ai_row()['params'], true )['attempts'] );
	}

	public function test_a_server_error_that_persists_fails_after_one_retry_with_a_typed_error(): void {
		$this->needs_ai_client();
		FakeAiProvider::$reply = new ServerException( 'Bad gateway', 502 );

		$outcome = $this->quick_client()->generate( $this->simple_request() );

		$this->assertInstanceOf( Failure::class, $outcome );
		$this->assertSame( Failure::UPSTREAM, $outcome->code, 'Un 5xx del proveedor no es un fallo de red.' );
		$this->assertSame( 2, FakeAiProvider::$calls, 'Un intento más, no más.' );
		$row = $this->last_ai_row();
		$this->assertSame( 'failed', $row['status'] );
		$this->assertStringContainsString( Failure::UPSTREAM, (string) $row['error'] );
	}

	public function test_classify_separates_timeout_network_and_upstream(): void {
		$this->assertSame( Failure::TIMEOUT, Client::classify( new \WP_Error( 'prompt_network_error', 'cURL error 28: Operation timed out after 20001 milliseconds' ) )->code );
		$this->assertSame( Failure::NETWORK, Client::classify( new \WP_Error( 'prompt_network_error', 'cURL error 6: Could not resolve host' ) )->code );
		$this->assertSame( Failure::UPSTREAM, Client::classify( new \WP_Error( 'prompt_upstream_server_error', 'Bad gateway', array( 'status' => 502 ) ) )->code );
	}

	/**
	 * Cliente con reloj propio: cada intento del proveedor falso avanza `$clock->t` los segundos que indique la prueba.
	 *
	 * @param \stdClass $clock Reloj.
	 */
	private function clocked_client( \stdClass $clock ): Client {
		return new class( $this->container( Budget::class ), $clock ) extends Client {
			public function __construct( Budget $budget, private \stdClass $clock ) {
				parent::__construct( $budget );
			}

			protected function wait( int $milliseconds ): void {
			}

			protected function now(): float {
				return $this->clock->t;
			}
		};
	}

	public function test_no_retry_when_the_first_attempt_used_up_the_time(): void {
		$this->needs_ai_client();
		$clock                 = new \stdClass();
		$clock->t              = 0.0;
		FakeAiProvider::$reply = static function () use ( $clock ) {
			$clock->t += 19.0;

			return new ServerException( 'Bad gateway', 502 );
		};

		$outcome = $this->clocked_client( $clock )->generate( $this->simple_request() );

		$this->assertInstanceOf( Failure::class, $outcome );
		$this->assertSame( 1, FakeAiProvider::$calls, 'Quedaba 1 s de 20: no cabe un reintento.' );
	}

	public function test_a_quick_failure_is_retried_with_the_time_that_is_left(): void {
		$this->needs_ai_client();
		$clock                 = new \stdClass();
		$clock->t              = 0.0;
		FakeAiProvider::$reply = static function ( int $call ) use ( $clock ) {
			$clock->t += 1.0;

			return 1 === $call ? new ServerException( 'Bad gateway', 502 ) : 'ya está';
		};

		$outcome = $this->clocked_client( $clock )->generate( $this->simple_request()->with_timeout( 50 ) );

		$this->assertInstanceOf( Response::class, $outcome );
		$this->assertSame( 2, FakeAiProvider::$calls );
	}

	public function test_a_failed_request_keeps_the_requested_model_and_a_scrubbed_detail(): void {
		$this->needs_ai_client();
		FakeAiProvider::$reply = new ClientException( 'Incorrect API key sk-abc123def456ghi789 for gpt-4o-mini', 401 );

		$this->quick_client()->generate( $this->simple_request()->with_models( array( 'gpt-4o-mini' ) ) );

		$failure = $this->container( JobRepository::class )->last_ai_failure();
		$this->assertSame( 'gpt-4o-mini', $failure['model'], 'Una llamada fallida sin gasto sigue sabiendo a qué modelo se pidió.' );
		$this->assertStringNotContainsString( 'abc123def456', $failure['error'] );
		$this->assertStringStartsWith( Failure::FAILED, $failure['error'] );
	}

	public function test_rest_status_returns_the_last_failure_and_recommended_models_without_batch(): void {
		$this->needs_ai_client();
		wp_set_current_user( $this->admin );
		FakeAiProvider::$models = array( 'openai/gpt-4.1-mini:batch', 'openai/gpt-4.1-mini', 'zzz/unknown', 'openai/gpt-4o-mini-2024-07-18', 'anthropic/claude-haiku-4.5' );
		FakeAiProvider::$reply  = new ClientException( 'Unauthorized sk-abc123def456ghi789', 401 );
		$this->quick_client()->generate( $this->simple_request()->with_models( array( 'openai/gpt-4.1-mini' ) ) );

		$data = $this->request( 'GET', '/ai/status', array( 'refresh' => true ) )->get_data();

		$ids = array_column( $data['models'], 'model' );
		$this->assertNotContains( 'openai/gpt-4.1-mini:batch', $ids );
		$this->assertSame( array( 'openai/gpt-4o-mini-2024-07-18', 'openai/gpt-4.1-mini', 'anthropic/claude-haiku-4.5', 'zzz/unknown' ), $ids, 'Primero los recomendados en orden de preferencia y después el resto.' );
		$this->assertSame( array( true, true, true, false ), array_column( $data['models'], 'recommended' ) );
		$this->assertSame( array( 0.40, 1.60 ), $data['models'][1]['price'] );
		$this->assertNull( $data['models'][3]['price'] );
		$this->assertSame( 'openai/gpt-4.1-mini', $data['last_failure']['model'] );
		$this->assertSame( Failure::FAILED, $data['last_failure']['code'] );
		$this->assertStringNotContainsString( 'abc123def456', $data['last_failure']['message'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['last_failure']['at'] );
	}

	public function test_rest_capabilities_say_how_long_the_server_waits(): void {
		$this->needs_ai_client();
		wp_set_current_user( $this->admin );

		$data = $this->request( 'GET', '/ai/capabilities', array( 'count' => 3 ) )->get_data();

		$this->assertSame( Reranker::CLOUD_TIMEOUT, $data['timeout'] );
	}

	public function test_the_exact_model_is_used_even_if_it_does_not_declare_json_schema(): void {
		$this->needs_ai_client();
		FakeAiProvider::$models         = array( 'aaa-first', 'gpt-4o-mini' );
		FakeAiProvider::$without_schema = array( 'gpt-4o-mini' );
		$this->reply( '{"ok":true}' );
		$request = new Request( 'hola', 'sistema', array( 'type' => 'object' ), 2000, 20, array( 'gpt-4o-mini' ), 100, 'ai_rerank', array(), false, FakeAiProvider::ID );

		$outcome = $this->quick_client()->generate( $request );

		$this->assertInstanceOf( Response::class, $outcome );
		$this->assertSame( array( 'gpt-4o-mini' ), FakeAiProvider::$used, 'Una preferencia habría caído en el primero de la lista.' );
		$this->assertSame( 'gpt-4o-mini', $outcome->model );
	}

	public function test_a_different_model_answering_is_logged_as_a_mismatch(): void {
		$this->needs_ai_client();
		FakeAiProvider::$models         = array( 'aaa-first', 'gpt-4o-mini' );
		FakeAiProvider::$without_schema = array( 'gpt-4o-mini' );
		$this->reply( '{"ok":true}' );
		// Sin proveedor no hay modelo exacto: manda la preferencia, que el cliente descarta (esquema no declarado).
		$request = new Request( 'hola', 'sistema', array( 'type' => 'object' ), 2000, 20, array( 'gpt-4o-mini' ), 100, 'ai_rerank' );

		$outcome = $this->quick_client()->generate( $request );

		$this->assertInstanceOf( Response::class, $outcome );
		$this->assertSame( 'aaa-first', $outcome->model );
		$params = json_decode( (string) $this->last_ai_row()['params'], true );
		$this->assertTrue( $params['model_mismatch'] );
		$this->assertSame( 'gpt-4o-mini', $params['model_requested'] );
		$this->assertSame( 'aaa-first', $this->last_ai_row()['ai_model'], 'Se apunta el que respondió de verdad.' );
	}

	public function test_automatic_choice_skips_models_that_do_not_declare_what_the_request_needs(): void {
		$this->needs_ai_client();
		FakeAiProvider::$models         = array( 'gpt-4o-mini', 'gpt-4.1-mini' );
		FakeAiProvider::$without_schema = array( 'gpt-4o-mini' );

		$caps = $this->container( Capabilities::class )->detect( true );

		$this->assertSame( 'gpt-4.1-mini', $caps['model'], 'El barato no declara esquema: el siguiente que sí.' );
		$this->assertTrue( $caps['strict'] );

		$this->container( Settings::class )->update( array( 'ai_model' => 'gpt-4o-mini' ) );
		$pinned = $this->container( Capabilities::class )->detect( true );
		$this->assertSame( 'gpt-4o-mini', $pinned['model'], 'Lo fijado por el usuario manda.' );
		$this->assertFalse( $pinned['strict'], 'Y entonces la petición va sin esquema.' );
	}

	public function test_the_schemaless_repeat_only_happens_if_time_is_left(): void {
		$this->needs_ai_client();
		$clock                 = new \stdClass();
		$clock->t              = 0.0;
		FakeAiProvider::$reply = static function () use ( $clock ) {
			$clock->t += 49.0;

			return new ClientException( 'Bad Request (400)', 400 );
		};
		$request               = new Request( 'hola', 'sistema', array( 'type' => 'object' ), 2000, 50, array(), 100, 'ai_rerank' );

		$outcome = $this->clocked_client( $clock )->generate( $request );

		$this->assertInstanceOf( Failure::class, $outcome );
		$this->assertSame( 1, FakeAiProvider::$calls, 'Quedaba 1 s: no se repite sin esquema.' );
	}

	public function test_a_batch_model_cannot_be_saved_as_the_preferred_model(): void {
		wp_set_current_user( $this->admin );

		$response = $this->request( 'POST', '/settings', array( 'ai_model' => 'openai/gpt-4.1-mini:batch' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->container( Settings::class )->update( array( 'ai_model' => 'openai/gpt-4.1-mini:batch' ) );
		$this->assertSame( '', $this->container( Settings::class )->ai_model() );
	}

	public function test_a_429_is_retried_and_ends_as_a_rate_limit_error(): void {
		$this->needs_ai_client();
		FakeAiProvider::$reply = new ClientException( 'Too Many Requests (429)', 429 );

		$outcome = $this->quick_client()->generate( $this->simple_request() );

		$this->assertInstanceOf( Failure::class, $outcome );
		$this->assertSame( Failure::RATE_LIMIT, $outcome->code );
		$this->assertSame( 2, FakeAiProvider::$calls );
	}

	public function test_other_client_errors_are_not_retried(): void {
		$this->needs_ai_client();
		FakeAiProvider::$reply = new ClientException( 'Unauthorized (401)', 401 );

		$outcome = $this->quick_client()->generate( $this->simple_request() );

		$this->assertInstanceOf( Failure::class, $outcome );
		$this->assertSame( Failure::FAILED, $outcome->code );
		$this->assertSame( 1, FakeAiProvider::$calls );
	}

	public function test_a_rejected_json_schema_is_retried_once_without_it(): void {
		$this->needs_ai_client();
		FakeAiProvider::$reply = static fn( int $call ) => 1 === $call ? new ClientException( 'Bad Request (400)', 400 ) : '{"ok":true}';

		$request = new Request( 'x', 's', array( 'type' => 'object' ), 2500, 20, array(), 100 );
		$outcome = $this->quick_client()->generate( $request );

		$this->assertInstanceOf( Response::class, $outcome );
		$this->assertSame( 2, FakeAiProvider::$calls );
	}

	public function test_empty_text_is_an_invalid_answer_and_its_tokens_still_count(): void {
		$this->needs_ai_client();
		$this->reply( '   ' );
		FakeAiProvider::$usage = array( 900, 2500 );

		$outcome = $this->quick_client()->generate( $this->simple_request()->with_models( array( 'gpt-4o-mini' ) ) );

		$this->assertInstanceOf( Failure::class, $outcome );
		$this->assertSame( Failure::INVALID, $outcome->code );
		$row = $this->last_ai_row();
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( '3400', $row['ai_tokens_real'], 'El proveedor lo cobra aunque no sirva.' );
		$this->assertSame( FakeAiProvider::ID, $row['ai_provider'] );
		$this->assertSame( 'gpt-4o-mini', $row['ai_model'] );
		$this->assertGreaterThan( 0.0, (float) $row['ai_cost_real'], 'Y se apunta lo que cuesta.' );
		$this->assertSame( 3400, $this->container( JobRepository::class )->ai_usage( 30 )['models'][0]['tokens'], 'Entra en el desglose por modelo.' );
		$this->assertSame( 1, FakeAiProvider::$calls, 'Una respuesta vacía no se reintenta.' );
	}

	public function test_the_wordpress_filter_that_prevents_prompts_is_respected_without_calling_the_model(): void {
		$this->needs_ai_client();
		add_filter( 'wp_ai_client_prevent_prompt', '__return_true' );

		$outcome = $this->quick_client()->generate( $this->simple_request() );

		$this->assertInstanceOf( Failure::class, $outcome );
		$this->assertSame( Failure::VETOED, $outcome->code );
		$this->assertTrue( $outcome->is_silent() );
		$this->assertSame( 0, FakeAiProvider::$calls );
		$this->assertSame( 0, $this->ai_rows() );
	}

	public function test_our_own_filter_can_veto_or_modify_a_request(): void {
		$this->needs_ai_client();
		$this->reply( 'ok' );

		add_filter( 'magiclinking_before_ai_request', '__return_false' );
		$outcome = $this->quick_client()->generate( $this->simple_request() );
		$this->assertSame( Failure::VETOED, $outcome->code ?? '' );
		$this->assertSame( 0, FakeAiProvider::$calls );
		remove_all_filters( 'magiclinking_before_ai_request' );

		add_filter( 'magiclinking_before_ai_request', static fn( Request $r ): Request => new Request( 'texto cambiado', $r->system, null, $r->max_tokens, $r->timeout, array(), $r->estimated ) );
		$this->quick_client()->generate( $this->simple_request( 'texto original' ) );
		$this->assertStringContainsString( 'texto cambiado', FakeAiProvider::$sent[0] );
		$this->assertStringNotContainsString( 'texto original', FakeAiProvider::$sent[0] );
	}

	public function test_the_daily_limit_stops_ai_until_tomorrow_and_zero_means_no_ai(): void {
		$this->needs_ai_client();
		wp_set_current_user( $this->admin );
		$this->reply( 'ok' );
		FakeAiProvider::$usage = array( 600, 400 );
		$settings              = $this->container( Settings::class );

		$settings->update( array( 'ai_daily_token_limit' => 1500 ) );
		$client = $this->quick_client();
		$this->assertInstanceOf( Response::class, $client->generate( $this->simple_request() ) );
		$this->assertSame( 1000, $this->container( Budget::class )->used_today() );

		// Quedan 500 y la petición estima 100: cabe; gasta 1.000 y ya no hay más.
		$this->assertInstanceOf( Response::class, $client->generate( $this->simple_request() ) );
		$blocked = $client->generate( $this->simple_request() );
		$this->assertInstanceOf( Failure::class, $blocked );
		$this->assertSame( Failure::BUDGET, $blocked->code );
		$this->assertSame( 2, FakeAiProvider::$calls, 'Con el tope alcanzado no se llama al modelo.' );

		// Sube el tope y vuelve a funcionar.
		$settings->update( array( 'ai_daily_token_limit' => 10000 ) );
		$this->assertInstanceOf( Response::class, $client->generate( $this->simple_request() ) );

		$settings->update( array( 'ai_daily_token_limit' => 0 ) );
		$off = $client->generate( $this->simple_request() );
		$this->assertSame( Failure::BUDGET, $off->code ?? '' );
	}

	public function test_yesterdays_spend_does_not_count_today(): void {
		global $wpdb;
		$jobs = $this->container( JobRepository::class );
		$jobs->record_ai(
			array(
				'type'        => 'ai_rerank',
				'status'      => 'done',
				'tokens_est'  => 10,
				'tokens_real' => 5000,
				'cost_est'    => 0.0,
				'cost_real'   => 0.0,
				'provider'    => 'x',
				'model'       => 'y',
				'user_id'     => 1,
				'params'      => array(),
				'error'       => '',
			)
		);
		$this->assertSame( 5000, $jobs->ai_tokens_today() );

		$name = Schema::table( $wpdb->prefix, 'jobs' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$name} SET created_at = %s", gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 0, $jobs->ai_tokens_today() );
		$this->assertSame( 5000, $jobs->ai_usage( 30 )['tokens'], 'Pero sí entra en los 30 días.' );
	}

	public function test_other_job_types_do_not_count_as_ai_spend(): void {
		$jobs = $this->container( JobRepository::class );
		$jobs->create( 'index', 10, array(), 1 );

		$this->assertSame( 0, $jobs->ai_tokens_today() );
		$this->assertSame( 0, $jobs->ai_usage( 30 )['calls'] );
	}

	// ------------------------------------------------------------------ Reordenado.

	public function test_rerank_end_to_end_orders_marks_unfit_and_accepts_only_a_literal_improved_anchor(): void {
		$this->needs_ai_client();
		$this->corpus();
		wp_set_current_user( $this->author );
		$this->reply( '{"results":[{"candidato":1,"apto":false,"confianza":0.3},{"candidato":2,"apto":true,"confianza":0.9,"ancla_mejorada":"bomba de calor reduce"}]}' );

		$result = $this->container( Reranker::class )->rerank( $this->source, $this->items() );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, FakeAiProvider::$calls, 'Una llamada por petición.' );
		$this->assertSame( array( 1, 0 ), array_column( $result['items'], 'index' ), 'La apta primero, la no apta al final.' );
		$this->assertSame( array( 2, 3, 4 ), $result['skipped'], 'Privada, borrador y con contraseña no se envían.' );
		$this->assertTrue( $result['items'][0]['fit'] );
		$this->assertFalse( $result['items'][1]['fit'] );
		$this->assertSame( $this->targets[1], $result['items'][0]['target_id'] );

		$improved = $result['items'][0]['improved'];
		$this->assertSame( 'bomba de calor reduce', $improved['anchor'] );
		$this->assertSame( (int) strpos( self::SENTENCE, 'bomba de calor reduce' ), $improved['offset'] );
		$this->assertSame( 'Instalar una ', $improved['before'] );
		$this->assertSame( 13, $improved['anchor_start'] );
		$this->assertNull( $result['items'][1]['improved'] );

		$this->assertSame( 1200, $result['usage']['tokens'] );
		$this->assertSame( FakeAiProvider::ID, $result['usage']['provider'] );
		$this->assertSame( 'gpt-4o-mini', $result['usage']['model'] );
		$this->assertNotNull( $result['usage']['cost'] );

		$row = $this->last_ai_row();
		$this->assertSame( 'ai_rerank', $row['type'] );
		$this->assertSame( '1200', $row['ai_tokens_real'] );
		$this->assertGreaterThan( 0, (int) $row['ai_tokens_est'] );
	}

	public function test_a_non_literal_improved_anchor_is_discarded_but_the_order_is_kept(): void {
		$this->needs_ai_client();
		$this->corpus();
		$this->reply( '{"results":[{"candidato":1,"apto":true,"confianza":0.4},{"candidato":2,"apto":true,"confianza":0.8,"ancla_mejorada":"bombas de calor modernas"}]}' );

		$result = $this->container( Reranker::class )->rerank( $this->source, $this->items() );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 1, 0 ), array_column( $result['items'], 'index' ) );
		$this->assertNull( $result['items'][0]['improved'] );
	}

	public function test_only_clean_text_of_publishable_targets_reaches_the_model(): void {
		$this->needs_ai_client();
		$this->corpus();
		$this->reply( '{"results":[{"candidato":1,"apto":true,"confianza":0.5},{"candidato":2,"apto":true,"confianza":0.5}]}' );

		$this->container( Reranker::class )->rerank( $this->source, $this->items() );

		$sent = FakeAiProvider::$sent[0];
		$this->assertStringContainsString( 'Bombas de calor: guía', $sent );
		$this->assertStringContainsString( 'Consumo eléctrico del hogar', $sent );
		$this->assertStringContainsString( '[[bomba de calor]]', $sent );
		$this->assertStringContainsString( 'Contenido de Bombas de calor: guía con datos útiles', $sent, 'El cuerpo del destino, como resumen.' );
		foreach ( array( 'SECRETO', 'BORRADOR', 'Protegida', 'secreto@ejemplo.com', 'contacto@privado.es', 'user_login', '<h2>', '<p>', 'wp:paragraph' ) as $never ) {
			$this->assertStringNotContainsString( $never, $sent, "No debe salir: {$never}" );
		}
		$this->assertSame( 0, substr_count( $sent, '@' ), 'Ni un solo correo.' );
	}

	public function test_the_prompt_carries_the_language_of_the_entry_not_a_fixed_one(): void {
		$this->needs_ai_client();
		$this->corpus();
		$this->reply( '{"results":[{"candidato":1,"apto":true,"confianza":0.5},{"candidato":2,"apto":true,"confianza":0.5}]}' );
		// La misma detección de la regla 9 (WPML, Polylang o el sitio); aquí la fija el filtro de la entrada.
		$fix = static fn() => 'es';
		add_filter( 'magiclinking_post_language', $fix );

		$this->container( Reranker::class )->rerank( $this->source, $this->items() );
		remove_filter( 'magiclinking_post_language', $fix, 10 );

		$this->assertStringContainsString( 'Content language: es.', FakeAiProvider::$sent[0] );
		$this->assertSame( 'es', $this->last_ai_row()['params'] ? json_decode( $this->last_ai_row()['params'], true )['lang'] : null );
	}

	public function test_invalid_json_empty_text_and_a_failing_provider_fall_back_to_lexical_order(): void {
		$this->needs_ai_client();
		$this->corpus();
		$reranker = $this->container( Reranker::class );

		$this->reply( 'esto no es json' );
		$bad = $reranker->rerank( $this->source, $this->items() );
		$this->assertFalse( $bad['ok'] );
		$this->assertSame( Failure::INVALID, $bad['reason'] );
		$this->assertArrayNotHasKey( 'items', $bad );
		$this->assertSame( 1200, $bad['usage']['tokens'], 'Lo gastado se cuenta aunque no sirva.' );

		$this->reply( '' );
		$empty = $reranker->rerank( $this->source, $this->items() );
		$this->assertFalse( $empty['ok'] );
		$this->assertSame( Failure::INVALID, $empty['reason'] );

		FakeAiProvider::$reply = new ClientException( 'Unauthorized', 401 );
		$down                  = $reranker->rerank( $this->source, $this->items() );
		$this->assertFalse( $down['ok'] );
		$this->assertSame( Failure::FAILED, $down['reason'] );
	}

	public function test_nothing_is_sent_when_no_target_can_be_sent(): void {
		$this->needs_ai_client();
		$this->corpus();

		$only_private = array_slice( $this->items(), 2, 3 );
		$result       = $this->container( Reranker::class )->rerank( $this->source, $only_private );

		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => Reranker::NOTHING,
			),
			$result
		);
		$this->assertSame( 0, FakeAiProvider::$calls );
	}

	public function test_an_anchor_that_is_not_where_the_browser_says_is_skipped(): void {
		$this->needs_ai_client();
		$this->corpus();
		$this->reply( '{"results":[{"candidato":1,"apto":true,"confianza":0.5}]}' );

		$items              = array_slice( $this->items(), 0, 2 );
		$items[1]['offset'] = 3;

		$result = $this->container( Reranker::class )->rerank( $this->source, $items );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 1 ), $result['skipped'] );
	}

	public function test_at_most_twenty_candidates_are_sent(): void {
		$this->needs_ai_client();
		$this->corpus();
		$this->reply( '{"results":[{"candidato":1,"apto":true,"confianza":0.5}]}' );

		$items = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$items[] = $this->items()[ $i % 2 ];
		}
		$result = $this->container( Reranker::class )->rerank( $this->source, $items );

		$this->assertSame( 5, count( $result['skipped'] ) );
		$this->assertSame( 20, substr_count( FakeAiProvider::$sent[0], '<candidate n=' ) );
	}

	public function test_the_daily_limit_blocks_the_rerank_before_calling_the_model(): void {
		$this->needs_ai_client();
		$this->corpus();
		$this->container( Settings::class )->update( array( 'ai_daily_token_limit' => 10 ) );

		$result = $this->container( Reranker::class )->rerank( $this->source, $this->items() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( Failure::TOO_SMALL, $result['reason'], 'Un tope menor que una petición no es «mañana».' );
		$this->assertGreaterThan( RerankProtocol::MAX_OUTPUT_TOKENS, $result['needs'], 'La cota incluye el tope de salida que se envía.' );
		$this->assertLessThanOrEqual( Reranker::worst_case_tokens( 2 ), $result['needs'] );
		$this->assertSame( 0, FakeAiProvider::$calls );
	}

	public function test_the_daily_limit_counts_what_the_request_needs_on_top_of_todays_spend(): void {
		$this->needs_ai_client();
		$this->corpus();
		wp_set_current_user( $this->admin );
		$this->reply( '{"results":[{"candidato":1,"apto":true,"confianza":0.5},{"candidato":2,"apto":true,"confianza":0.5}]}' );
		$reranker = $this->container( Reranker::class );

		// El tope cubre una petición, pero lo gastado hoy deja menos de lo que esta necesita.
		$needs = Reranker::worst_case_tokens( 2 );
		$this->container( Settings::class )->update( array( 'ai_daily_token_limit' => $needs + 500 ) );
		$this->container( JobRepository::class )->record_ai(
			array(
				'type'        => 'ai_rerank',
				'status'      => 'done',
				'tokens_est'  => 0,
				'tokens_real' => $needs,
				'cost_est'    => 0.0,
				'cost_real'   => 0.0,
				'provider'    => 'x',
				'model'       => 'y',
				'user_id'     => 1,
				'params'      => array(),
				'error'       => '',
			)
		);

		$result = $reranker->rerank( $this->source, array_slice( $this->items(), 0, 2 ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( Failure::BUDGET, $result['reason'] );
		$this->assertSame( 0, FakeAiProvider::$calls );
	}

	public function test_a_limit_equal_to_needs_lets_the_request_through_and_one_less_is_too_small_in_both_places(): void {
		$this->needs_ai_client();
		$this->corpus();
		wp_set_current_user( $this->admin );
		$this->reply( '{"results":[{"candidato":1,"apto":true,"confianza":0.5},{"candidato":2,"apto":true,"confianza":0.5}]}' );
		$items    = array_slice( $this->items(), 0, 2 );
		$needs    = Reranker::worst_case_tokens( 2 );
		$settings = $this->container( Settings::class );

		$settings->update( array( 'ai_daily_token_limit' => $needs - 1 ) );
		$caps = $this->request( 'GET', '/ai/capabilities', array( 'count' => 2 ), $this->admin )->get_data();
		$this->assertSame( 'too_small', $caps['budget'] );

		$settings->update( array( 'ai_daily_token_limit' => $needs ) );
		$caps = $this->request( 'GET', '/ai/capabilities', array( 'count' => 2 ), $this->admin )->get_data();
		$this->assertSame( 'ok', $caps['budget'], 'Botón y petición coinciden: con el tope en `needs` hay sitio.' );

		$result = $this->container( Reranker::class )->rerank( $this->source, $items );
		$this->assertTrue( $result['ok'], 'Lo que dijo el botón no se contradice con la petición real.' );
		$this->assertLessThanOrEqual( $needs, (int) $this->last_ai_row()['ai_tokens_est'], 'Lo guardado es la cota usada para el tope y cabe en `needs`.' );
		$this->assertGreaterThan( RerankProtocol::MAX_OUTPUT_TOKENS, (int) $this->last_ai_row()['ai_tokens_est'] );
	}

	public function test_a_sentence_that_is_not_in_the_entry_is_never_sent(): void {
		$this->needs_ai_client();
		$this->corpus();
		$this->reply( '{"results":[{"candidato":1,"apto":true,"confianza":0.5}]}' );
		$invented = 'Texto inventado que nadie escribió pero que contiene una bomba de calor en medio.';
		$items    = $this->items();
		$items[1] = array(
			'target_id' => $this->targets[1],
			'sentence'  => $invented,
			'anchor'    => 'bomba de calor',
			'offset'    => (int) strpos( $invented, 'bomba de calor' ),
		);

		$result = $this->container( Reranker::class )->rerank( $this->source, array_slice( $items, 0, 2 ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 1 ), $result['skipped'] );
		$this->assertStringNotContainsString( 'inventado', FakeAiProvider::$sent[0] );

		FakeAiProvider::$calls = 0;
		$none                  = $this->container( Reranker::class )->rerank( $this->source, array( $items[1] ) );
		$this->assertSame( Reranker::NOTHING, $none['reason'] );
		$this->assertSame( 0, FakeAiProvider::$calls, 'Sin ninguna frase de la entrada no se llama al modelo.' );
	}

	public function test_sentences_are_checked_against_the_editor_content_when_it_is_sent(): void {
		$this->needs_ai_client();
		$this->corpus();
		$this->reply( '{"results":[{"candidato":1,"apto":true,"confianza":0.5}]}' );
		$draft_sentence = 'Una frase nueva sin guardar sobre la bomba de calor y el ahorro.';
		$item           = array(
			'target_id' => $this->targets[0],
			'sentence'  => $draft_sentence,
			'anchor'    => 'bomba de calor',
			'offset'    => (int) strpos( $draft_sentence, 'bomba de calor' ),
		);
		$reranker       = $this->container( Reranker::class );

		$saved = $reranker->rerank( $this->source, array( $item ) );
		$this->assertSame( Reranker::NOTHING, $saved['reason'], 'Sin guardar, la entrada del servidor no la tiene.' );

		$draft = $reranker->rerank( $this->source, array( $item ), $this->p( $draft_sentence ) );
		$this->assertTrue( $draft['ok'] );

		// Y al revés: con el contenido del editor, lo que solo estaba en lo guardado ya no cuenta.
		$old = $reranker->rerank( $this->source, array( $this->items()[0] ), $this->p( $draft_sentence ) );
		$this->assertSame( Reranker::NOTHING, $old['reason'] );
	}

	public function test_a_sentence_longer_than_the_limit_is_skipped(): void {
		$this->needs_ai_client();
		$this->corpus();
		$long = 'Instalar una bomba de calor ' . str_repeat( 'reduce el consumo de la vivienda y mucho más. ', 60 );
		wp_update_post(
			array(
				'ID'           => $this->source,
				'post_content' => $this->p( $long ),
			)
		);
		$item = array(
			'target_id' => $this->targets[0],
			'sentence'  => $long,
			'anchor'    => 'bomba de calor',
			'offset'    => (int) strpos( $long, 'bomba de calor' ),
		);

		$result = $this->container( Reranker::class )->rerank( $this->source, array( $item ) );

		$this->assertSame( Reranker::NOTHING, $result['reason'] );
		$this->assertSame( 0, FakeAiProvider::$calls );
	}

	public function test_rest_rerank_is_limited_per_user_per_minute(): void {
		$this->needs_ai_client();
		$this->corpus();
		$this->reply( '{"results":[{"candidato":1,"apto":true,"confianza":0.5},{"candidato":2,"apto":true,"confianza":0.5}]}' );
		$body = array(
			'post_id' => $this->source,
			'items'   => array_slice( $this->items(), 0, 2 ),
		);

		for ( $i = 0; $i < RateLimit::PER_MINUTE; $i++ ) {
			$this->assertTrue( $this->request( 'POST', '/suggestions/rerank', $body, $this->admin )->get_data()['ok'], "Llamada {$i}" );
		}
		$blocked = $this->request( 'POST', '/suggestions/rerank', $body, $this->admin )->get_data();

		$this->assertFalse( $blocked['ok'] );
		$this->assertSame( Failure::TOO_MANY, $blocked['reason'] );
		$this->assertSame( RateLimit::PER_MINUTE, FakeAiProvider::$calls );

		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertTrue( $this->request( 'POST', '/suggestions/rerank', $body, $other )->get_data()['ok'], 'El límite es por usuario.' );
	}

	// ------------------------------------------------------------------ REST.

	public function test_rest_rerank_returns_the_reordered_list_for_the_author_of_the_entry(): void {
		$this->needs_ai_client();
		$this->corpus();
		$this->reply( '{"results":[{"candidato":1,"apto":false,"confianza":0.1},{"candidato":2,"apto":true,"confianza":0.9}]}' );

		$response = $this->request(
			'POST',
			'/suggestions/rerank',
			array(
				'post_id' => $this->source,
				'items'   => $this->items(),
			),
			$this->author
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( array( 1, 0 ), array_column( $data['items'], 'index' ) );
		$this->assertArrayHasKey( 'usage', $data );
	}

	public function test_rest_rerank_brings_the_notices_of_the_improved_anchor_so_the_card_asks_nothing(): void {
		global $wpdb;
		$this->needs_ai_client();
		$this->corpus();
		// Otra entrada ya lleva esa ancla, con el mismo texto, a este destino: con el tope en 1, está agotada.
		update_option( 'magiclinking_settings', array( 'same_anchor_max' => 1 ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			\MagicLinking\Core\Schema::table( $wpdb->prefix, 'links' ),
			array(
				'source_id'   => 999999,
				'target_id'   => $this->targets[1],
				'target_url'  => get_permalink( $this->targets[1] ),
				'anchor'      => 'bomba de calor reduce',
				'is_internal' => 1,
				'is_broken'   => 0,
			)
		);
		$this->reply( '{"results":[{"candidato":1,"apto":false,"confianza":0.1},{"candidato":2,"apto":true,"confianza":0.9,"ancla_mejorada":"bomba de calor reduce"}]}' );

		$data = $this->request(
			'POST',
			'/suggestions/rerank',
			array(
				'post_id' => $this->source,
				'items'   => $this->items(),
			),
			$this->author
		)->get_data();

		$this->assertTrue( $data['ok'] );
		$this->assertSame( array( 'overused' ), array_column( $data['items'][0]['improved']['anchor_notes'], 'code' ) );
	}

	public function test_rest_rerank_failures_are_a_200_with_a_plain_message(): void {
		$this->needs_ai_client();
		$this->corpus();
		$this->reply( 'no json' );

		$data = $this->request(
			'POST',
			'/suggestions/rerank',
			array(
				'post_id' => $this->source,
				'items'   => $this->items(),
			),
			$this->author
		)->get_data();

		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'The AI did not respond; showing suggestions without AI.', $data['message'] );

		add_filter( 'wp_ai_client_prevent_prompt', '__return_true' );
		$vetoed = $this->request(
			'POST',
			'/suggestions/rerank',
			array(
				'post_id' => $this->source,
				'items'   => $this->items(),
			),
			$this->author
		)->get_data();
		$this->assertFalse( $vetoed['ok'] );
		$this->assertSame( Failure::VETOED, $vetoed['reason'] );
		$this->assertStringNotContainsString( 'prompt_', wp_json_encode( $vetoed ), 'Nada técnico al usuario.' );
	}

	public function test_rest_rerank_permissions(): void {
		$this->corpus();
		$body = array(
			'post_id' => $this->source,
			'items'   => $this->items(),
		);

		$this->assertSame( 401, $this->request( 'POST', '/suggestions/rerank', $body, 0 )->get_status(), 'Sin sesión.' );
		$this->assertSame( 403, $this->request( 'POST', '/suggestions/rerank', $body, $this->subscriber )->get_status(), 'Sin edit_posts.' );

		$other_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->assertSame( 403, $this->request( 'POST', '/suggestions/rerank', $body, $other_author )->get_status(), 'Autor de otra entrada: no es la suya.' );

		$this->assertSame(
			404,
			$this->request(
				'POST',
				'/suggestions/rerank',
				array(
					'post_id' => 999999,
					'items'   => $this->items(),
				),
				$this->admin
			)->get_status()
		);
		$this->assertSame( 0, ( class_exists( FakeAiProvider::class, false ) ? FakeAiProvider::$calls : 0 ), 'Ninguna petición negada llegó al modelo.' );
	}

	public function test_rest_rerank_validates_the_body(): void {
		$this->corpus();
		$one = $this->items()[0];

		$this->assertSame( 400, $this->request( 'POST', '/suggestions/rerank', array( 'post_id' => $this->source ), $this->author )->get_status(), 'Sin items.' );
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/suggestions/rerank',
				array(
					'post_id' => $this->source,
					'items'   => array(),
				),
				$this->author
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/suggestions/rerank',
				array(
					'post_id' => $this->source,
					'items'   => array_fill( 0, 21, $one ),
				),
				$this->author
			)->get_status(),
			'Más de 20.'
		);
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/suggestions/rerank',
				array(
					'post_id' => $this->source,
					'items'   => array( array( 'target_id' => 0 ) + $one ),
				),
				$this->author
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/suggestions/rerank',
				array(
					'post_id' => $this->source,
					'items'   => array( array_diff_key( $one, array( 'anchor' => 1 ) ) ),
				),
				$this->author
			)->get_status(),
			'Falta el ancla.'
		);
	}

	public function test_rest_capabilities_for_the_panel_button(): void {
		$this->needs_ai_client();

		$data = $this->request( 'GET', '/ai/capabilities', array( 'count' => 10 ), $this->author )->get_data();

		$this->assertTrue( $data['available'] );
		$this->assertTrue( $data['text'] );
		$this->assertTrue( $data['ready'] );
		$this->assertTrue( $data['enabled'] );
		$this->assertSame( 'ok', $data['budget'] );
		$this->assertSame( 'gpt-4o-mini', $data['model'] );
		$this->assertSame( 'Fake AI', $data['provider']['name'] );
		$this->assertSame( 2180, $data['estimate']['tokens_in'] );
		$this->assertGreaterThan( $data['estimate']['tokens'], $data['needs'], 'El botón enseña lo típico; `needs` es la cota con la que se compara el tope.' );
		$this->assertSame( Reranker::worst_case_tokens( 10 ), $data['needs'] );
		$this->assertGreaterThan( 0.0, $data['estimate']['cost'] );
		$this->assertStringContainsString( 'options-connectors.php', $data['connectors_url'] );

		$this->container( Settings::class )->update( array( 'ai_daily_token_limit' => 0 ) );
		$off = $this->request( 'GET', '/ai/capabilities', array(), $this->author )->get_data();
		$this->assertFalse( $off['enabled'] );
		$this->assertFalse( $off['ready'] );
	}

	public function test_rest_capabilities_say_when_the_limit_is_smaller_than_one_request(): void {
		$this->needs_ai_client();
		$settings = $this->container( Settings::class );

		$settings->update( array( 'ai_daily_token_limit' => 100 ) );
		$small = $this->request( 'GET', '/ai/capabilities', array(), $this->author )->get_data();
		$this->assertFalse( $small['ready'] );
		$this->assertSame( 'too_small', $small['budget'] );
		$this->assertSame( 100, $small['limit'] );
		$this->assertGreaterThan( 100, $small['needs'] );

		$settings->update( array( 'ai_daily_token_limit' => 100000 ) );
		$this->assertSame( 'ok', $this->request( 'GET', '/ai/capabilities', array(), $this->author )->get_data()['budget'] );
	}

	public function test_rest_capabilities_and_status_permissions(): void {
		$this->assertSame( 401, $this->request( 'GET', '/ai/capabilities', array(), 0 )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/ai/capabilities', array(), $this->subscriber )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', '/ai/status', array(), 0 )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/ai/status', array(), $this->author )->get_status(), 'El estado y el gasto son de quien administra.' );
	}

	public function test_rest_status_shows_connector_budget_and_the_last_thirty_days(): void {
		$this->needs_ai_client();
		wp_set_current_user( $this->admin );
		$this->reply( 'ok' );
		FakeAiProvider::$usage = array( 1000000, 0 );
		$this->quick_client()->generate( $this->simple_request()->with_models( array( 'gpt-4o-mini' ) ) );
		$this->quick_client()->generate( $this->simple_request()->with_models( array( 'unpriced-model' ) ) );

		$data = $this->request( 'GET', '/ai/status', array( 'refresh' => true ) )->get_data();

		$this->assertSame( 'connected', $data['state'] );
		$this->assertTrue( $data['json'] );
		$this->assertSame( 'Fake AI', $data['provider']['name'] );
		$this->assertSame( 2000000, $data['budget']['limit'] );
		$this->assertSame( 2000000, $data['budget']['used'] );
		$this->assertTrue( $data['budget']['exhausted'] );
		$this->assertSame( 2, $data['usage']['calls'] );
		$this->assertSame( 2000000, $data['usage']['tokens'] );
		$this->assertEqualsWithDelta( 0.15, $data['usage']['cost'], 1e-6, 'Solo cuenta el modelo con precio.' );
		$this->assertTrue( $data['usage']['cost_partial'] );
		$costs = array_column( $data['usage']['models'], 'cost', 'model' );
		$this->assertNull( $costs['unpriced-model'] );
		$this->assertContains( 'unpriced-model', array_column( $data['models'], 'model' ) );
		$this->assertSame( 'Fake AI', $data['models'][0]['provider_name'] );
	}

	public function test_failure_messages_are_plain_language(): void {
		foreach ( array( Failure::UNAVAILABLE, Failure::VETOED, Failure::BUDGET, Failure::RATE_LIMIT, Failure::NETWORK, Failure::INVALID, Failure::FAILED, Failure::TOO_SMALL, Failure::TOO_MANY, Reranker::NOTHING ) as $reason ) {
			$text = AiController::failure_message( $reason );
			$this->assertNotSame( '', $text );
			$this->assertDoesNotMatchRegularExpression( '/prompt_|Exception|HTTP|\b[45]\d\d\b/', $text, $reason );
		}
		$this->assertSame( 'The AI did not respond; showing suggestions without AI.', AiController::failure_message( Failure::NETWORK ) );
	}

	// ------------------------------------------------------------------ Ajustes y rendimiento.

	public function test_settings_have_ai_defaults_and_sanitize_what_comes_in(): void {
		$settings = $this->container( Settings::class );

		$this->assertSame( 2000000, $settings->ai_daily_token_limit() );
		$this->assertSame( '', $settings->ai_model() );

		$after = $settings->update(
			array(
				'ai_daily_token_limit' => -50,
				'ai_model'             => '<script>alert(1)</script>',
			)
		);
		$this->assertSame( 0, $after['ai_daily_token_limit'], 'Un negativo es cero: sin IA.' );
		$this->assertSame( '', $after['ai_model'] );

		$after = $settings->update(
			array(
				'ai_daily_token_limit' => 5000000000,
				'ai_model'             => 'gpt-4o-mini',
			)
		);
		$this->assertSame( Budget::MAX_LIMIT, $after['ai_daily_token_limit'] );
		$this->assertSame( 'gpt-4o-mini', $after['ai_model'] );

		// Los identificadores de los proveedores pueden llevar más signos (`@`, `+`); no se pierden sin avisar.
		$after = $settings->update( array( 'ai_model' => 'org/modelo@v2+q4:latest' ) );
		$this->assertSame( 'org/modelo@v2+q4:latest', $after['ai_model'] );
		$after = $settings->update( array( 'ai_model' => '  <b>raro</b>  ' ) );
		$this->assertSame( 'raro', $after['ai_model'] );
	}

	public function test_the_ai_settings_save_through_the_settings_route(): void {
		$response = $this->request(
			'POST',
			'/settings',
			array(
				'ai_daily_token_limit' => 123456,
				'ai_model'             => 'llama3.2:3b',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 123456, $response->get_data()['settings']['ai_daily_token_limit'] );
		$this->assertSame( 'llama3.2:3b', $response->get_data()['settings']['ai_model'] );
	}

	public function test_the_plugin_adds_no_autoloaded_option_and_nothing_on_the_front_end(): void {
		global $wpdb;
		$this->needs_ai_client();
		$this->container( Settings::class )->update( array( 'ai_model' => 'gpt-4o-mini' ) );
		$this->container( Capabilities::class )->detect();
		wp_set_current_user( $this->admin );
		$this->reply( 'ok' );
		$this->quick_client()->generate( $this->simple_request() );

		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%magiclinking%' AND autoload IN ('yes','on','auto','auto-on')" ); // phpcs:ignore WordPress.DB

		$this->assertNotEmpty( $names, 'La prueba mira algo.' );
		foreach ( $names as $name ) {
			$this->assertContains( $name, array( 'magiclinking_settings' ), "Opción en autoload: {$name}" );
		}
	}

	public function test_detection_costs_no_model_calls_and_front_end_requests_do_nothing_with_ai(): void {
		$this->needs_ai_client();
		$before = FakeAiProvider::$calls;

		$this->container( Capabilities::class )->detect();
		$this->go_to( home_url( '/' ) );

		$this->assertSame( $before, FakeAiProvider::$calls );
		$this->assertFalse( wp_script_is( 'magiclinking-editor' ) );
	}

	public function test_a_provider_that_throws_unexpectedly_never_reaches_the_user_as_an_exception(): void {
		$this->needs_ai_client();
		FakeAiProvider::$reply = new RuntimeException( 'secret internal detail' );

		$outcome = $this->quick_client()->generate( $this->simple_request() );

		$this->assertInstanceOf( Failure::class, $outcome );
		$this->assertSame( Failure::FAILED, $outcome->code );
		$this->assertStringNotContainsString( 'secret', AiController::failure_message( $outcome->code ) );
	}
}
