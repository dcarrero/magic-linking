<?php
/**
 * Ajustes por REST.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\Jobs\Jobs;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET y POST /settings, solo para administradores.
 */
final class SettingsController implements Module {

	/**
	 * Ajustes.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Procesos.
	 *
	 * @var Jobs
	 */
	private Jobs $jobs;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes.
	 * @param Jobs     $jobs     Procesos.
	 */
	public function __construct( Settings $settings, Jobs $jobs ) {
		$this->settings = $settings;
		$this->jobs     = $jobs;
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
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'post_types'                   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'low_inbound_threshold'        => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 20,
						),
						'words_per_link'               => array(
							'type'    => 'integer',
							'minimum' => 20,
							'maximum' => 1000,
						),
						Installer::DELETE_DATA_SETTING => array( 'type' => 'boolean' ),
					),
				),
			)
		);
	}

	/**
	 * Permiso: administrar el plugin.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /settings.
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->payload() );
	}

	/**
	 * POST /settings. Si cambian los tipos de contenido, lanza un indexado nuevo (no cuesta nada).
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$before = $this->settings->post_types();
		$input  = array_intersect_key( $request->get_json_params() ?: $request->get_body_params(), $this->settings->all() ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- JSON o formulario.
		$after  = $this->settings->update( $input );

		$job = null;
		if ( $before !== $after['post_types'] ) {
			$job = JobsController::present( $this->jobs->start_index( get_current_user_id() ) );
		}

		return new WP_REST_Response( $this->payload() + array( 'job' => $job ) );
	}

	/**
	 * Ajustes y tipos de contenido disponibles.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		$types = array();
		foreach ( Settings::available_post_types() as $name => $label ) {
			$types[] = array(
				'name'  => $name,
				'label' => $label,
			);
		}

		return array(
			'settings'   => $this->settings->all(),
			'post_types' => $types,
		);
	}
}
