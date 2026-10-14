<?php
/**
 * Ruta REST con la que el panel del editor avisa de los enlaces que ha puesto (F1-34, D-58).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Content\InsertionException;
use MagicLinking\Core\Module;
use MagicLinking\History\EditorLinks;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /history/editor`: tras guardar la entrada, el panel dice qué enlaces puso en el editor. El servidor
 * comprueba que siguen en el contenido guardado y los apunta en el Historial; **no escribe nada en la entrada**
 * ({@see EditorLinks}). Pide `edit_posts` y, si la entrada existe (404 con código propio si no), `edit_post` sobre ella; la nonce de REST la exige el núcleo
 * con la autenticación por cookie.
 */
final class EditorHistoryController implements Module {

	/**
	 * Enlaces como máximo por petición (el panel apunta los de una sesión de edición).
	 */
	public const MAX_LINKS = 50;

	/**
	 * Constructor.
	 *
	 * @param EditorLinks $links Registro de enlaces del editor.
	 */
	public function __construct( private EditorLinks $links ) {
	}

	/**
	 * Engancha el registro de rutas.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registra la ruta.
	 */
	public function routes(): void {
		register_rest_route(
			ReportController::NAMESPACE,
			'/history/editor',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_editor' ),
				'permission_callback' => array( $this, 'can_record' ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'links'   => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => self::MAX_LINKS,
						// Cada elemento se valida en el servidor y se contesta por separado: uno mal formado no hunde el resto.
						'items'    => array( 'type' => 'object' ),
					),
				),
			)
		);
	}

	/**
	 * Permiso base: editar entradas. El permiso sobre la entrada indicada se comprueba después de saber que existe.
	 */
	public function can_record(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * POST /history/editor.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_editor( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		if ( ! $this->recordable( $post_id ) ) {
			return new WP_Error( 'magiclinking_post_gone', __( 'That entry does not exist.', 'magic-linking' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'magiclinking_forbidden', __( 'You do not have permission to edit one of the entries involved.', 'magic-linking' ), array( 'status' => rest_authorization_required_code() ) );
		}

		$links = array_map( static fn( $link ): array => (array) $link, array_values( (array) $request['links'] ) );

		try {
			$outcome = $this->links->record( $post_id, get_current_user_id(), $links );
		} catch ( InsertionException $e ) {
			return new WP_Error( 'magiclinking_busy', $e->getMessage(), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( $outcome );
	}

	/**
	 * Si la entrada es una de verdad (ni revisión ni autoguardado ni inexistente).
	 *
	 * @param int $post_id Entrada.
	 */
	private function recordable( int $post_id ): bool {
		$post = get_post( $post_id );

		return null !== $post && ! in_array( $post->post_type, array( 'revision', 'attachment', 'nav_menu_item' ), true );
	}
}
