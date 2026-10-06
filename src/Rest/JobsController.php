<?php
/**
 * Rutas REST del estado del índice y de los procesos.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\Module;
use MagicLinking\Jobs\Jobs;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET /status (editores), POST /index, GET /jobs y POST /jobs/{id}/pause|resume|cancel (administradores).
 */
final class JobsController implements Module {

	/**
	 * Procesos.
	 *
	 * @var Jobs
	 */
	private Jobs $jobs;

	/**
	 * Constructor.
	 *
	 * @param Jobs $jobs Procesos.
	 */
	public function __construct( Jobs $jobs ) {
		$this->jobs = $jobs;
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
		register_rest_route(
			ReportController::NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			)
		);

		register_rest_route(
			ReportController::NAMESPACE,
			'/index',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_index' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'force' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			ReportController::NAMESPACE,
			'/jobs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_jobs' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			ReportController::NAMESPACE,
			'/jobs/(?P<id>\d+)/(?P<action>pause|resume|cancel)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'change_job' ),
				'permission_callback' => array( $this, 'can_manage' ),
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
	 * GET /status.
	 */
	public function get_status(): WP_REST_Response {
		$status = $this->jobs->status();

		return new WP_REST_Response(
			array(
				'indexed'   => $status['indexed'],
				'eligible'  => $status['eligible'],
				'pending'   => $status['pending'],
				'job'       => self::present( $status['job'] ),
				'stalled'   => $status['stalled'],
				'eta'       => $status['eta'],
				'last_job'  => self::present( $status['last_job'] ),
				'last_done' => self::present( $status['last_done'] ),
			)
		);
	}

	/**
	 * POST /index: lanza el análisis de todo el sitio (o devuelve el que ya está en marcha).
	 *
	 * Por defecto salta las entradas que no han cambiado; con `force` las vuelve a analizar todas.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function start_index( WP_REST_Request $request ): WP_REST_Response {
		$force = true === $request['force'];

		return new WP_REST_Response( array( 'job' => self::present( $this->jobs->start_index( get_current_user_id(), true, $force ) ) ), 202 );
	}

	/**
	 * GET /jobs.
	 */
	public function get_jobs(): WP_REST_Response {
		$status = $this->jobs->status();

		return new WP_REST_Response(
			array(
				'active' => self::present( $status['job'] ),
				'latest' => self::present( $status['last_job'] ),
			)
		);
	}

	/**
	 * POST /jobs/{id}/{pause|resume|cancel}.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function change_job( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$action = (string) $request['action'];

		if ( null === $this->jobs->repository()->get( $id ) ) {
			return new WP_Error( 'magiclinking_job_not_found', __( 'That process does not exist.', 'magic-linking' ), array( 'status' => 404 ) );
		}

		switch ( $action ) {
			case 'pause':
				$ok = $this->jobs->pause( $id );
				break;
			case 'resume':
				$ok = $this->jobs->resume( $id );
				break;
			default:
				$ok = $this->jobs->cancel( $id );
		}

		if ( ! $ok ) {
			return new WP_Error( 'magiclinking_job_state', __( 'That process cannot be changed in its current state.', 'magic-linking' ), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'job' => self::present( $this->jobs->repository()->get( $id ) ) ) );
	}

	/**
	 * Proceso listo para la API.
	 *
	 * @param array<string, mixed>|null $job Proceso.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function present( ?array $job ): ?array {
		if ( null === $job ) {
			return null;
		}

		$total = (int) $job['total'];
		$done  = (int) $job['done'];

		return array(
			'id'         => (int) $job['id'],
			'type'       => (string) $job['type'],
			'status'     => (string) $job['status'],
			'total'      => $total,
			'done'       => min( $done, max( $total, $done ) ),
			'percent'    => $total > 0 ? min( 100, (int) floor( 100 * $done / $total ) ) : ( 'done' === $job['status'] ? 100 : 0 ),
			'error'      => (string) $job['error'],
			'force'      => ! empty( $job['params']['force'] ),
			'changed'    => (int) ( $job['params']['changed'] ?? 0 ),
			'unchanged'  => (int) ( $job['params']['same'] ?? 0 ),
			'created_at' => (string) mysql_to_rfc3339( (string) $job['created_at'] ),
			'updated_at' => (string) mysql_to_rfc3339( (string) $job['updated_at'] ),
		);
	}
}
