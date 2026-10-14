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
 * ({@see EditorLinks}). Pide `edit_posts` y `edit_post` sobre la entrada; la nonce de REST la exige el núcleo
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
						'items'    => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'insertion_id', 'suggestion', 'target_id', 'anchor' ),
							'properties'           => array(
								'insertion_id' => array(
									'type'    => 'string',
									'pattern' => '^[A-Za-z0-9_-]{8,64}$',
								),
								'suggestion'   => array(
									'type'      => 'string',
									'maxLength' => 300,
								),
								'target_id'    => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'anchor'       => array(
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 500,
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Permiso: editar entradas y la entrada indicada.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function can_record( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_posts' ) && current_user_can( 'edit_post', (int) $request['post_id'] );
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
			return new WP_Error( 'magiclinking_not_found', __( 'That entry does not exist.', 'magic-linking' ), array( 'status' => 404 ) );
		}

		$links = array();
		foreach ( (array) $request['links'] as $link ) {
			$link    = (array) $link;
			$links[] = array(
				'insertion_id' => (string) ( $link['insertion_id'] ?? '' ),
				'suggestion'   => (string) ( $link['suggestion'] ?? '' ),
				'target_id'    => (int) ( $link['target_id'] ?? 0 ),
				'anchor'       => (string) ( $link['anchor'] ?? '' ),
			);
		}

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
