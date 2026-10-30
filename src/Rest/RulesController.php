<?php
/**
 * Rutas REST de las reglas automáticas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Module;
use MagicLinking\Core\PostTitle;
use MagicLinking\Core\Settings;
use MagicLinking\Rules\Rule;
use MagicLinking\Rules\RuleRepository;
use MagicLinking\Rules\RuleService;
use MagicLinking\Rules\RuleValidator;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET|POST /rules`, `POST|DELETE /rules/{id}` y, para aplicar una regla a entradas que ya existen, **de una en una**:
 * `GET /rules/{id}/candidates` (búsqueda reanudable por cursor), `GET /rules/{id}/preview` y `POST /rules/{id}/apply`.
 * Aplicar una regla a todas las existentes de una vez es de Pro (F3-05) y no está aquí.
 *
 * Permisos (docs/03 §6): gestionar reglas pide `manage_options`; aplicar una a una entrada pide además `edit_post`
 * sobre ella, comprobado antes de escribir. La nonce de REST la exige el núcleo con la autenticación por cookie.
 */
final class RulesController implements Module {

	/**
	 * Reglas por página por defecto.
	 */
	public const PER_PAGE = 50;

	/**
	 * Constructor.
	 *
	 * @param RuleRepository $rules     Reglas.
	 * @param RuleValidator  $validator Validación.
	 * @param RuleService    $service   Aplicación.
	 * @param Settings       $settings  Ajustes.
	 */
	public function __construct(
		private RuleRepository $rules,
		private RuleValidator $validator,
		private RuleService $service,
		private Settings $settings
	) {
	}

	/**
	 * Engancha las rutas.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registra las rutas.
	 */
	public function routes(): void {
		$ns     = ReportController::NAMESPACE;
		$fields = array(
			'phrases'            => array(
				'type'  => array( 'array', 'string' ),
				'items' => array( 'type' => 'string' ),
			),
			'target_id'          => array( 'type' => 'integer' ),
			'max_per_post'       => array( 'type' => 'integer' ),
			'first_only'         => array( 'type' => 'boolean' ),
			'word_gap'           => array( 'type' => 'boolean' ),
			'exclude_post_types' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'exclude_categories' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'active'             => array( 'type' => 'boolean' ),
		);

		register_rest_route(
			$ns,
			'/rules',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_rules' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => self::PER_PAGE,
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $fields,
				),
			)
		);

		register_rest_route(
			$ns,
			'/rules/options',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'options' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			$ns,
			'/rules/targets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'targets' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'search' => array(
						'type'      => 'string',
						'maxLength' => 100,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/rules/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $fields,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/rules/(?P<id>\d+)/candidates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'candidates' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'cursor' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'limit'  => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 50,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/rules/(?P<id>\d+)/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/rules/(?P<id>\d+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);
	}

	/**
	 * Permiso: gestionar reglas es de quien gestiona el sitio.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /rules.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function list_rules( WP_REST_Request $request ): WP_REST_Response {
		Installer::ensure_current();
		$per_page = (int) $request['per_page'];
		$page     = $this->rules->page( (int) $request['page'], $per_page );

		$response = new WP_REST_Response(
			array(
				'items'  => array_map( array( $this, 'present' ), $page['items'] ),
				'total'  => $page['total'],
				'limits' => $this->limits(),
			)
		);
		$response->header( 'X-WP-Total', (string) $page['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $page['total'] / $per_page ) ) );

		return $response;
	}

	/**
	 * POST /rules.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		Installer::ensure_current();
		$rule = $this->validator->validate( $this->input( $request ), null, get_current_user_id() );
		if ( $rule instanceof WP_Error ) {
			return $rule;
		}
		$id = $this->rules->insert( $rule );
		if ( $id < 1 ) {
			return $this->failed();
		}
		$saved = $this->rules->find( $id );

		return new WP_REST_Response( array( 'rule' => null === $saved ? null : $this->present( $saved ) ), 201 );
	}

	/**
	 * POST /rules/{id}.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		Installer::ensure_current();
		$existing = $this->rules->find( (int) $request['id'] );
		if ( null === $existing ) {
			return $this->not_found();
		}
		$rule = $this->validator->validate( $this->input( $request ), $existing, get_current_user_id() );
		if ( $rule instanceof WP_Error ) {
			return $rule;
		}
		if ( ! $this->rules->update( $rule ) ) {
			return $this->failed();
		}
		$saved = $this->rules->find( $existing->id );

		return new WP_REST_Response( array( 'rule' => null === $saved ? null : $this->present( $saved ) ) );
	}

	/**
	 * DELETE /rules/{id}.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		Installer::ensure_current();
		$id = (int) $request['id'];
		if ( null === $this->rules->find( $id ) ) {
			return $this->not_found();
		}
		if ( ! $this->rules->delete( $id ) ) {
			return $this->failed();
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * GET /rules/options: tipos de contenido y categorías para las exclusiones.
	 */
	public function options(): WP_REST_Response {
		$types = array();
		$all   = Settings::available_post_types();
		foreach ( $this->settings->post_types() as $name ) {
			$types[] = array(
				'name'  => $name,
				'label' => $all[ $name ] ?? $name,
			);
		}
		$categories = array();
		$terms      = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 300,
				'orderby'    => 'name',
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$categories[] = array(
				'id'   => (int) $term->term_id,
				'name' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			);
		}

		return new WP_REST_Response(
			array(
				'post_types' => $types,
				'categories' => $categories,
				'limits'     => $this->limits(),
			)
		);
	}

	/**
	 * GET /rules/targets: entradas publicadas que se pueden elegir como destino, por título.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function targets( WP_REST_Request $request ): WP_REST_Response {
		$search = trim( (string) $request['search'] );
		$items  = array();
		if ( '' !== $search && array() !== $this->settings->post_types() ) {
			$query = new \WP_Query(
				array(
					'post_type'              => $this->settings->post_types(),
					'post_status'            => 'publish',
					's'                      => $search,
					'posts_per_page'         => 20,
					'has_password'           => false,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
				)
			);
			foreach ( $query->posts as $post ) {
				if ( $post instanceof WP_Post ) {
					$items[] = array(
						'id'    => $post->ID,
						'title' => PostTitle::of( $post ),
						'type'  => $post->post_type,
						'url'   => (string) get_permalink( $post ),
					);
				}
			}
		}//end if

		return new WP_REST_Response( array( 'items' => $items ) );
	}

	/**
	 * GET /rules/{id}/candidates: entradas donde la regla encaja, con la vista previa de cada una.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function candidates( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rule = $this->rules->find( (int) $request['id'] );
		if ( null === $rule ) {
			return $this->not_found();
		}
		$found = $this->service->scan( $rule, get_current_user_id(), (int) $request['cursor'], (int) $request['limit'], 3.0 );

		foreach ( $found['items'] as &$item ) {
			$item = $this->present_preview( $item );
		}
		unset( $item );

		return new WP_REST_Response( $found );
	}

	/**
	 * GET /rules/{id}/preview.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rule = $this->rules->find( (int) $request['id'] );
		$post = get_post( (int) $request['post_id'] );
		if ( null === $rule || ! $post instanceof WP_Post ) {
			return $this->not_found();
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $this->forbidden();
		}

		return new WP_REST_Response( $this->present_preview( $this->service->preview( $post, $rule ) ) );
	}

	/**
	 * POST /rules/{id}/apply: aplica la regla a **una** entrada; el servidor vuelve a calcular qué enlazar (no se fía de la vista previa).
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rule = $this->rules->find( (int) $request['id'] );
		$post = get_post( (int) $request['post_id'] );
		if ( null === $rule || ! $post instanceof WP_Post ) {
			return $this->not_found();
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $this->forbidden();
		}

		$outcome = $this->service->apply( $post, $rule, get_current_user_id() );
		$reasons = RuleService::reasons();

		return new WP_REST_Response(
			array(
				'post_id'         => $outcome->post_id,
				'rule_id'         => $outcome->rule_id,
				'inserted'        => $outcome->inserted,
				'failed'          => $outcome->failed,
				'skipped'         => $outcome->skipped,
				'skipped_message' => null === $outcome->skipped ? '' : ( $reasons[ $outcome->skipped ] ?? '' ),
				'batch_id'        => $outcome->batch_id,
			)
		);
	}

	/**
	 * Campos de la petición que son de la regla.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return array<string, mixed>
	 */
	private function input( WP_REST_Request $request ): array {
		$params = $request->get_params();
		$fields = array( 'phrases', 'target_id', 'max_per_post', 'first_only', 'word_gap', 'exclude_post_types', 'exclude_categories', 'active' );
		$input  = array();
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				$input[ $field ] = $params[ $field ];
			}
		}

		return $input;
	}

	/**
	 * Una regla tal como la ve la pantalla.
	 *
	 * @param Rule $rule Regla.
	 *
	 * @return array<string, mixed>
	 */
	private function present( Rule $rule ): array {
		$target = get_post( $rule->target_id );

		return array(
			'id'                 => $rule->id,
			'phrases'            => $rule->variants,
			'target_id'          => $rule->target_id,
			'target_title'       => $target instanceof WP_Post ? PostTitle::of( $target ) : '',
			'target_url'         => $target instanceof WP_Post && 'publish' === $target->post_status ? (string) get_permalink( $target ) : $rule->target_url,
			'target_available'   => $target instanceof WP_Post && 'publish' === $target->post_status,
			'lang'               => $rule->lang,
			'max_per_post'       => $rule->max_per_post,
			'first_only'         => $rule->first_only,
			'word_gap'           => $rule->gap,
			'exclude_post_types' => $rule->exclude_post_types,
			'exclude_categories' => $rule->exclude_categories,
			'active'             => $rule->active,
			'created_at'         => $rule->created_at,
		);
	}

	/**
	 * Vista previa con los enlaces para editar y ver la entrada y el motivo ya dicho con palabras.
	 *
	 * @param array{post_id: int, title: string, skipped: string|null, links: list<array{anchor: string, before: string, after: string}>} $preview Vista previa.
	 *
	 * @return array<string, mixed>
	 */
	private function present_preview( array $preview ): array {
		$reasons = RuleService::reasons();

		return $preview + array(
			'skipped_message' => null === $preview['skipped'] ? '' : ( $reasons[ $preview['skipped'] ] ?? '' ),
			'edit_url'        => (string) get_edit_post_link( $preview['post_id'], 'raw' ),
			'view_url'        => (string) get_permalink( $preview['post_id'] ),
		);
	}

	/**
	 * Topes duros vigentes (ajustes del usuario, iguales en gratis y Pro).
	 *
	 * @return array{words_per_link: int, links_per_target: int, same_anchor_max: int}
	 */
	private function limits(): array {
		$limits = $this->settings->rule_limits();

		return array(
			'words_per_link'   => $limits->words_per_link,
			'links_per_target' => $limits->links_per_target,
			'same_anchor_max'  => $limits->same_anchor_max,
		);
	}

	/**
	 * Error 404.
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'magiclinking_not_found', __( 'That rule or entry does not exist.', 'magic-linking' ), array( 'status' => 404 ) );
	}

	/**
	 * Error 403.
	 */
	private function forbidden(): WP_Error {
		return new WP_Error( 'magiclinking_forbidden', __( 'You do not have permission to edit this entry.', 'magic-linking' ), array( 'status' => 403 ) );
	}

	/**
	 * Error 500.
	 */
	private function failed(): WP_Error {
		return new WP_Error( 'magiclinking_rule_failed', __( 'The rule could not be saved. Try again.', 'magic-linking' ), array( 'status' => 500 ) );
	}
}
