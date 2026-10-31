<?php
/**
 * Ruta REST de la casilla «Contenido pilar» del panel del editor.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\Index\Pillars;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET|POST /pillar/{post_id}` (docs/07 §3.4, docs/04 §6).
 *
 * Permisos: `edit_post` sobre la entrada, que además tiene que ser de un tipo que se analiza. La nonce de REST la
 * exige el núcleo con la autenticación por cookie. Guardar es inmediato (no espera a guardar la entrada) y
 * renueva la época de las sugerencias guardadas. Si Yoast SEO o Rank Math ya la marcan, la respuesta lo dice y
 * el interfaz la enseña marcada sin dejar cambiarla aquí.
 */
final class PillarController implements Module {

	/**
	 * Constructor.
	 *
	 * @param Pillars  $pillars  Contenido pilar.
	 * @param Settings $settings Ajustes (tipos que se analizan).
	 */
	public function __construct( private Pillars $pillars, private Settings $settings ) {
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
		register_rest_route(
			ReportController::NAMESPACE,
			'/pillar/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pillar' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_pillar' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'pillar' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Permiso: poder editar la entrada (una que no existe da 404 en la ruta).
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function can_edit( WP_REST_Request $request ): bool {
		$post_id = (int) $request['post_id'];

		return current_user_can( 'edit_posts' ) && ( ! get_post( $post_id ) instanceof WP_Post || current_user_can( 'edit_post', $post_id ) );
	}

	/**
	 * GET: estado de la casilla.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_pillar( WP_REST_Request $request ) {
		$post = $this->post( (int) $request['post_id'] );

		return $post instanceof WP_Error ? $post : new WP_REST_Response( $this->state( $post->ID ) );
	}

	/**
	 * POST: marca o desmarca la casilla propia.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_pillar( WP_REST_Request $request ) {
		$post = $this->post( (int) $request['post_id'] );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$this->pillars->set_own( $post->ID, filter_var( $request['pillar'], FILTER_VALIDATE_BOOLEAN ) );

		return new WP_REST_Response( $this->state( $post->ID ) );
	}

	/**
	 * La entrada, si existe y es de un tipo que se analiza.
	 *
	 * @param int $post_id ID.
	 *
	 * @return WP_Post|WP_Error
	 */
	private function post( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $this->settings->post_types(), true ) ) {
			return new WP_Error( 'magiclinking_not_found', __( 'That entry does not exist.', 'magic-linking' ), array( 'status' => 404 ) );
		}

		return $post;
	}

	/**
	 * Estado de la casilla: la propia, la de un plugin de SEO (`yoast` o `rank_math`) y si la entrada cuenta como pilar.
	 *
	 * @param int $post_id ID.
	 *
	 * @return array{post_id: int, pillar: bool, own: bool, external: string|null}
	 */
	private function state( int $post_id ): array {
		$own      = $this->pillars->own( $post_id );
		$external = $this->pillars->external( $post_id );

		return array(
			'post_id'  => $post_id,
			'pillar'   => $own || null !== $external,
			'own'      => $own,
			'external' => $external,
		);
	}
}
