<?php
/**
 * REST de los avisos: cerrar uno para siempre.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Admin\Notices;
use MagicLinking\Core\Module;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /notices/dismiss` (docs/07 §5). La autenticación por cookie exige el nonce de REST de WordPress.
 */
final class NoticesController implements Module {

	/**
	 * Constructor.
	 *
	 * @param Notices $notices Registro de avisos.
	 */
	public function __construct( private Notices $notices ) {}

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
		register_rest_route(
			ReportController::NAMESPACE,
			'/notices/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			ReportController::NAMESPACE,
			'/notices/seen',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'seen' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Apunta que el aviso se ha enseñado de verdad (la pantalla lo avisa al pintarlo).
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function seen( WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( '' === $id || ! $this->notices->seen( get_current_user_id(), $id ) ) {
			return new WP_Error( 'magiclinking_unknown_notice', __( 'That notice does not exist.', 'magic-linking' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'seen' => $id ) );
	}

	/**
	 * Cierra un aviso para el usuario actual.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss( WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( '' === $id || ! $this->notices->dismiss( get_current_user_id(), $id ) ) {
			return new WP_Error( 'magiclinking_unknown_notice', __( 'That notice does not exist.', 'magic-linking' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'dismissed' => $id ) );
	}
}
