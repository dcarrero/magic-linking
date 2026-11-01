<?php
/**
 * Rutas REST de la IA: reordenar, estado del conector y gasto.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\AI\Budget;
use MagicLinking\AI\Capabilities;
use MagicLinking\AI\Client;
use MagicLinking\AI\Failure;
use MagicLinking\AI\Models;
use MagicLinking\AI\RateLimit;
use MagicLinking\AI\RerankProtocol;
use MagicLinking\AI\Reranker;
use MagicLinking\Core\Module;
use MagicLinking\Jobs\JobRepository;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /suggestions/rerank`, `GET /ai/capabilities`, `GET /ai/status` (docs/03 §6, docs/05).
 *
 * Permisos: reordenar y consultar las capacidades piden `edit_posts` y, para reordenar, `edit_post` sobre la entrada
 * que se edita (la única cuyo texto se envía al modelo, y solo al pulsar el botón). El estado completo, el gasto y
 * el aviso son de quien administra el plugin (`manage_options`). Un fallo de la IA no es un error HTTP: la respuesta
 * lleva `ok: false`, el motivo y un texto breve y sin detalles técnicos, y el panel sigue con el orden léxico.
 */
final class AiController implements Module {

	/**
	 * Días del gasto que se enseña en Ajustes.
	 */
	public const USAGE_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param Reranker      $reranker     Reordenado.
	 * @param Capabilities  $capabilities Capacidades del conector.
	 * @param Client        $client       Cliente de IA.
	 * @param Budget        $budget       Tope diario.
	 * @param JobRepository $jobs        Registro del gasto.
	 * @param AnchorNotes   $notes       Avisos de ancla repetida de las anclas que propone la IA.
	 */
	public function __construct(
		private Reranker $reranker,
		private Capabilities $capabilities,
		private Client $client,
		private Budget $budget,
		private JobRepository $jobs,
		private AnchorNotes $notes
	) {
	}

	/**
	 * Engancha el registro de rutas.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registra las rutas.
	 */
	public function routes(): void {
		$ns = ReportController::NAMESPACE;

		register_rest_route(
			$ns,
			'/suggestions/rerank',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rerank' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'content' => array( 'type' => 'string' ),
					'items'   => array(
						'type'              => 'array',
						'required'          => true,
						'minItems'          => 1,
						'maxItems'          => RerankProtocol::MAX_CANDIDATES,
						'validate_callback' => 'rest_validate_request_arg',
						'items'             => array(
							'type'       => 'object',
							'required'   => array( 'target_id', 'sentence', 'anchor', 'offset' ),
							'properties' => array(
								'target_id' => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'sentence'  => array(
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 5000,
								),
								'anchor'    => array(
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 500,
								),
								'offset'    => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ai/capabilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_capabilities' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'count' => array(
						'type'    => 'integer',
						'default' => RerankProtocol::MAX_CANDIDATES,
						'minimum' => 1,
						'maximum' => RerankProtocol::MAX_CANDIDATES,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ai/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'refresh' => array( 'type' => 'boolean' ),
				),
			)
		);
	}

	/**
	 * Permiso base: editar entradas.
	 */
	public function can_edit_posts(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permiso: administrar el plugin.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permiso: editar entradas y, si la entrada existe, editar esa (una que no existe da 404 en la ruta).
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function can_edit_post( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$id = (int) $request['post_id'];

		return ! get_post( $id ) instanceof WP_Post || current_user_can( 'edit_post', $id );
	}

	/**
	 * POST /suggestions/rerank: reordena con IA las salientes de la entrada abierta.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rerank( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		if ( ! get_post( $post_id ) instanceof WP_Post ) {
			return new WP_Error( 'magiclinking_not_found', __( 'That entry does not exist.', 'magic-linking' ), array( 'status' => 404 ) );
		}

		$items = array();
		foreach ( (array) $request['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$items[] = array(
				'target_id' => (int) ( $item['target_id'] ?? 0 ),
				'sentence'  => (string) ( $item['sentence'] ?? '' ),
				'anchor'    => (string) ( $item['anchor'] ?? '' ),
				'offset'    => (int) ( $item['offset'] ?? 0 ),
			);
		}

		$content = $request->has_param( 'content' ) ? (string) $request['content'] : null;
		if ( null !== $content && strlen( $content ) > SuggestionsController::MAX_DRAFT ) {
			return new WP_Error( 'magiclinking_too_large', __( 'The content is too long to analyze.', 'magic-linking' ), array( 'status' => 413 ) );
		}

		// Un freno simple por usuario y minuto: cada llamada gasta del tope de todo el sitio.
		$result = RateLimit::allow( get_current_user_id() )
			? $this->reranker->rerank( $post_id, $items, $content )
			: array(
				'ok'     => false,
				'reason' => Failure::TOO_MANY,
			);
		if ( ! $result['ok'] ) {
			$result['message'] = self::failure_message( (string) $result['reason'], (int) ( $result['needs'] ?? 0 ) );
		} else {
			$result = $this->with_anchor_notes( $post_id, $result );
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Añade a cada ancla mejor que propone la IA sus avisos de ancla repetida (docs/07 §3.3), medidos todos de una vez:
	 * así la tarjeta no tiene que preguntarlos uno a uno cuando el usuario acepta el ancla.
	 *
	 * @param int                  $post_id Entrada abierta (el origen).
	 * @param array<string, mixed> $result  Resultado del reordenado.
	 *
	 * @return array<string, mixed>
	 */
	private function with_anchor_notes( int $post_id, array $result ): array {
		$pairs = array();
		foreach ( (array) ( $result['items'] ?? array() ) as $row ) {
			if ( is_array( $row ) && is_array( $row['improved'] ?? null ) && (int) $row['target_id'] > 0 ) {
				$pairs[] = array( (string) $row['improved']['anchor'], (int) $row['target_id'], $post_id );
			}
		}
		if ( array() === $pairs ) {
			return $result;
		}

		$this->notes->prime( $pairs );
		foreach ( $result['items'] as $n => $row ) {
			if ( is_array( $row ) && is_array( $row['improved'] ?? null ) && (int) $row['target_id'] > 0 ) {
				$result['items'][ $n ]['improved']['anchor_notes'] = $this->notes->for_anchor( (string) $row['improved']['anchor'], (int) $row['target_id'], $post_id );
			}
		}

		return $result;
	}

	/**
	 * Texto breve, sin detalles técnicos, para cada motivo por el que no hubo reordenado (docs/05 §8).
	 *
	 * @param string $reason Motivo (`Failure::*` o `Reranker::NOTHING`).
	 * @param int    $needs  Tokens que necesita una petición (para el motivo `limit_too_small`).
	 */
	public static function failure_message( string $reason, int $needs = 0 ): string {
		switch ( $reason ) {
			case Failure::TOO_SMALL:
				return sprintf(
					/* translators: %s: tokens one AI request needs, formatted. */
					__( 'The daily AI limit you set is lower than what one request needs (about %s tokens); raise it in Settings → AI. Showing suggestions without AI.', 'magic-linking' ),
					number_format_i18n( $needs )
				);
			case Failure::TOO_MANY:
				return __( 'You are asking for AI too often; wait a minute and try again. Showing suggestions without AI.', 'magic-linking' );
			case Failure::UNAVAILABLE:
				return __( 'There is no AI connected on this site, so the suggestions are shown without AI.', 'magic-linking' );
			case Failure::VETOED:
				return __( 'AI is turned off for this action on this site; showing suggestions without AI.', 'magic-linking' );
			case Failure::BUDGET:
				return __( 'The daily AI limit you set has been reached; showing suggestions without AI. It will be available again tomorrow.', 'magic-linking' );
			case Failure::TIMEOUT:
				return __( 'The AI provider took too long to answer; showing suggestions without AI. Try again, or pick a faster model in Settings → AI.', 'magic-linking' );
			case Failure::UPSTREAM:
				return __( 'The AI provider had a problem on its side; showing suggestions without AI. Try again in a moment.', 'magic-linking' );
			case Failure::RATE_LIMIT:
				return __( 'Your AI provider is limiting requests right now; showing suggestions without AI. Try again in a moment.', 'magic-linking' );
			case Reranker::NOTHING:
				return __( 'None of these suggestions can be sent to AI; showing them as they are.', 'magic-linking' );
			default:
				return __( 'The AI did not respond; showing suggestions without AI.', 'magic-linking' );
		}//end switch
	}

	/**
	 * GET /ai/capabilities: lo que necesita el botón del panel (sin llamar al modelo).
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function get_capabilities( WP_REST_Request $request ): WP_REST_Response {
		$caps = $this->capabilities->detect();
		$base = $this->base( $caps );

		$count    = (int) $request['count'];
		$estimate = Reranker::typical_estimate( $count, $caps['model'], $caps['local'] );
		$needs    = Reranker::worst_case_tokens( $count );

		// `ok` si cabe una petición; `exhausted` si hoy ya no cabe; `too_small` si el tope es menor que una petición
		// (esperar a mañana no lo arregla); `off` con el tope a 0.
		if ( ! $this->budget->enabled() ) {
			$budget = 'off';
		} elseif ( $this->budget->limit() < $needs ) {
			$budget = 'too_small';
		} else {
			$budget = $this->budget->allows( $needs ) ? 'ok' : 'exhausted';
		}

		return new WP_REST_Response(
			array(
				'available'      => $base['available'],
				'text'           => $caps['text'],
				'enabled'        => $this->budget->enabled(),
				'ready'          => $caps['text'] && 'ok' === $budget,
				'budget'         => $budget,
				'limit'          => $this->budget->limit(),
				'needs'          => $needs,
				'provider'       => $base['provider'],
				'model'          => $base['model'],
				'local'          => $caps['local'],
				'timeout'        => Reranker::timeout( $caps['local'] ),
				'estimate'       => $estimate + array( 'prices_date' => Models::PRICES_DATE ),
				'connectors_url' => $base['connectors_url'],
			)
		);
	}

	/**
	 * GET /ai/status: estado del conector, tope y gasto de los últimos 30 días para Ajustes.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		$caps = $this->capabilities->detect( (bool) $request['refresh'] );
		$base = $this->base( $caps );

		$usage  = $this->jobs->ai_usage( self::USAGE_DAYS );
		$models = array();
		$total  = 0.0;
		$part   = false;
		foreach ( $usage['models'] as $row ) {
			$local  = $this->client->is_local( $row['provider'] );
			$priced = $local || null !== Models::price( $row['model'] );
			$cost   = $priced ? ( $local ? 0.0 : $row['cost'] ) : null;
			if ( null === $cost ) {
				$part = true;
			} else {
				$total += $cost;
			}
			$models[] = array(
				'provider' => $row['provider'],
				'model'    => $row['model'],
				'calls'    => $row['calls'],
				'tokens'   => $row['tokens'],
				'local'    => $local,
				'cost'     => $cost,
			);
		}

		return new WP_REST_Response(
			array(
				'state'          => $this->capabilities->state(),
				'available'      => $base['available'],
				'text'           => $caps['text'],
				'json'           => $caps['json'],
				'local'          => $caps['local'],
				'provider'       => $base['provider'],
				'model'          => $base['model'],
				'models'         => $this->model_list( $caps['models'] ),
				'last_failure'   => $this->last_failure(),
				'connectors_url' => $base['connectors_url'],
				'budget'         => array(
					'limit'     => $this->budget->limit(),
					'used'      => $this->budget->used_today(),
					'remaining' => $this->budget->remaining(),
					'exhausted' => $this->budget->exhausted(),
				),
				'usage'          => array(
					'days'         => self::USAGE_DAYS,
					'calls'        => $usage['calls'],
					'failed'       => $usage['failed'],
					'tokens'       => $usage['tokens'],
					'cost'         => $total,
					'cost_partial' => $part,
					'models'       => $models,
					'prices_date'  => Models::PRICES_DATE,
				),
			)
		);
	}

	/**
	 * Modelos para el selector de Ajustes: sin `:batch`, con los recomendados primero (en el orden de preferencia) y
	 * después el resto por nombre, cada uno con su precio por millón de tokens y si es local. Se calcula aquí para que
	 * el JavaScript no repita la lógica de `Models`.
	 *
	 * @param array $models Modelos de `Capabilities::detect()`.
	 *
	 * @phpstan-param list<array{provider: string, provider_name: string, model: string, name: string, strict?: bool}> $models
	 *
	 * @return list<array{provider: string, provider_name: string, model: string, name: string, local: bool, price: array{0: float, 1: float}|null, recommended: bool}>
	 */
	private function model_list( array $models ): array {
		$seen = array();
		$rows = array();
		foreach ( $models as $m ) {
			if ( isset( $seen[ $m['model'] ] ) || ! Models::allowed( $m['model'] ) ) {
				continue;
			}
			$seen[ $m['model'] ] = true;
			$rows[]              = $m;
		}

		$ids    = array_column( array_filter( $rows, static fn( array $m ): bool => $m['strict'] ?? true ), 'model' );
		$recom  = Models::recommended( array() === $ids ? array_column( $rows, 'model' ) : $ids );
		$rank   = array_flip( $recom );
		$sorted = $rows;
		usort(
			$sorted,
			static function ( array $a, array $b ) use ( $rank ): int {
				$ra = $rank[ $a['model'] ] ?? PHP_INT_MAX;
				$rb = $rank[ $b['model'] ] ?? PHP_INT_MAX;

				return $ra === $rb ? strcasecmp( $a['model'], $b['model'] ) : $ra <=> $rb;
			}
		);

		return array_map(
			fn( array $m ): array => array(
				'provider'      => $m['provider'],
				'provider_name' => $m['provider_name'],
				'model'         => $m['model'],
				'name'          => $m['name'],
				'strict'        => $m['strict'] ?? true,
				'local'         => $this->client->is_local( $m['provider'] ),
				'price'         => Models::price( $m['model'] ),
				'recommended'   => isset( $rank[ $m['model'] ] ),
			),
			$sorted
		);
	}

	/**
	 * Última llamada de IA fallida con su detalle técnico saneado (solo `manage_options`, esta ruta lo exige).
	 *
	 * @return array{at: string, model: string, provider: string, code: string, message: string}|null
	 */
	private function last_failure(): ?array {
		$row = $this->jobs->last_ai_failure();
		if ( null === $row ) {
			return null;
		}

		$code = strstr( $row['error'], ': ', true );
		$code = false === $code ? $row['error'] : $code;

		return array(
			'at'       => str_replace( ' ', 'T', $row['at'] ) . 'Z',
			'model'    => $row['model'],
			'provider' => $row['provider'],
			'code'     => $code,
			'message'  => Failure::scrub( (string) substr( $row['error'], strlen( $code ) > 0 ? strlen( $code ) + 2 : 0 ) ),
		);
	}

	/**
	 * Campos comunes de las dos respuestas.
	 *
	 * @param array $caps Resultado de `Capabilities::detect()`.
	 *
	 * @phpstan-param array{provider: array{id: string, name: string}|null, model: string|null, ...} $caps
	 *
	 * @return array{available: bool, provider: array{id: string, name: string}|null, model: string|null, connectors_url: string}
	 */
	private function base( array $caps ): array {
		return array(
			'available'      => $this->client->available(),
			'provider'       => $caps['provider'],
			'model'          => $caps['model'],
			'connectors_url' => Capabilities::connectors_url(),
		);
	}
}
