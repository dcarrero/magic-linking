<?php
/**
 * Ruta REST para excluir una entrada del análisis y volver a incluirla.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\Index\Exclusions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /exclusions/{post_id} con `excluded` (booleano), para quien puede editar esa entrada (como la marca de pilar).
 * La reindexación la hace {@see \MagicLinking\Jobs\Jobs} al cambiar el metadato.
 */
final class ExclusionController implements Module {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes (tipos que se analizan).
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Engancha las rutas.
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
			'/exclusions/(?P<post_id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_excluded' ),
				'permission_callback' => array( AnalyzedEntry::class, 'can_edit' ),
				'args'                => array(
					'excluded' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Excluye la entrada o vuelve a incluirla.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_excluded( WP_REST_Request $request ) {
		$post = AnalyzedEntry::find( $this->settings, (int) $request['post_id'] );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$excluded = filter_var( $request['excluded'], FILTER_VALIDATE_BOOLEAN );
		Exclusions::set( $post->ID, $excluded );

		return new WP_REST_Response(
			array(
				'post_id'  => $post->ID,
				'excluded' => Exclusions::is_excluded( $post->ID ),
			)
		);
	}
}
