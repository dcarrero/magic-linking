<?php
/**
 * Rutas REST del historial: listar, deshacer y rehacer.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\History\BatchJob;
use MagicLinking\History\ChangeRepository;
use MagicLinking\History\Reader;
use MagicLinking\History\Redo;
use MagicLinking\History\Undo;
use MagicLinking\History\UndoResult;
use MagicLinking\Jobs\JobRepository;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET /history`, `GET /history/{batch}`, `GET /history/{batch}/changes`, `POST /undo`, `POST /redo` y `GET /history/jobs/{id}`.
 *
 * Todas piden `edit_posts` y, además, `edit_post` sobre **cada** entrada que se va a ver o tocar (docs/03 §6
 * y §12): un grupo solo se lista o se deshace entero si el usuario puede editar todas sus entradas, y un
 * cambio suelto si puede editar la suya. La nonce de REST la exige el núcleo con la autenticación por cookie.
 * No hay filtros por usuario ni por fecha ni exportación: son de Pro (docs/02 §4.5).
 */
final class HistoryController implements Module {

	/**
	 * Patrón de un identificador de lote (ULID).
	 */
	private const BATCH_PATTERN = '[0-9A-HJKMNP-TV-Z]{26}';

	/**
	 * Constructor.
	 *
	 * @param Reader           $reader   Lectura del historial.
	 * @param Undo             $undo     Deshacer.
	 * @param Redo             $redo     Rehacer.
	 * @param BatchJob         $batches  Deshacer y rehacer grupos grandes en segundo plano.
	 * @param ChangeRepository $changes  Historial.
	 * @param JobRepository    $jobs     Procesos.
	 * @param Settings         $settings Ajustes.
	 */
	public function __construct(
		private Reader $reader,
		private Undo $undo,
		private Redo $redo,
		private BatchJob $batches,
		private ChangeRepository $changes,
		private JobRepository $jobs,
		private Settings $settings
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
		$ns    = ReportController::NAMESPACE;
		$batch = array(
			'type'              => 'string',
			'pattern'           => '^' . self::BATCH_PATTERN . '$',
			'validate_callback' => 'rest_validate_request_arg',
		);

		register_rest_route(
			$ns,
			'/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_history' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 50,
					),
					'before'   => $batch,
				),
			)
		);

		register_rest_route(
			$ns,
			'/history/(?P<batch_id>' . self::BATCH_PATTERN . ')',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_group' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		register_rest_route(
			$ns,
			'/history/(?P<batch_id>' . self::BATCH_PATTERN . ')/changes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_changes' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/history/jobs/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		register_rest_route(
			$ns,
			'/history/jobs/(?P<id>\d+)/(?P<action>resume|cancel)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'change_job' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		foreach ( array(
			'undo' => 'post_undo',
			'redo' => 'post_redo',
		) as $route => $callback ) {
			register_rest_route(
				$ns,
				'/' . $route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'batch_id'  => $batch,
						'change_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				)
			);
		}
	}

	/**
	 * Permiso base: editar entradas. Después se comprueba `edit_post` sobre cada entrada afectada.
	 */
	public function can_read(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * GET /history: grupos visibles para el usuario, del más reciente al más antiguo.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function get_history( WP_REST_Request $request ): WP_REST_Response {
		$page   = $this->reader->groups( get_current_user_id(), $request['before'] ?: null, (int) $request['per_page'] ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Cadena vacía o ausente.
		$active = $this->batches->active();

		$items = array();
		foreach ( $page['items'] as $group ) {
			$group['job'] = null !== $active && ( $active['params']['batch_id'] ?? '' ) === $group['batch_id'] ? $this->present_job( $active ) : null;
			$items[]      = $group;
		}

		return new WP_REST_Response(
			array(
				'items'          => $items,
				'next'           => $page['next'],
				'retention_days' => $this->settings->history_retention_days(),
			)
		);
	}

	/**
	 * GET /history/{batch_id}: un grupo (para refrescar su estado).
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_group( WP_REST_Request $request ) {
		$group = $this->reader->group( (string) $request['batch_id'], get_current_user_id() );

		return null === $group ? $this->not_found() : new WP_REST_Response( array( 'group' => $group ) );
	}

	/**
	 * GET /history/{batch_id}/changes: los enlaces de un grupo, paginados.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_changes( WP_REST_Request $request ) {
		$batch_id = (string) $request['batch_id'];
		if ( null === $this->reader->group( $batch_id, get_current_user_id() ) ) {
			return $this->not_found();
		}

		$result = $this->reader->changes_of( $batch_id, (int) $request['page'], (int) $request['per_page'] );

		return new WP_REST_Response(
			array(
				'items'       => $result['items'],
				'total'       => $result['total'],
				'total_pages' => max( 1, (int) ceil( $result['total'] / max( 1, (int) $request['per_page'] ) ) ),
				'page'        => (int) $request['page'],
			)
		);
	}

	/**
	 * GET /history/jobs/{id}: avance de un proceso de deshacer o rehacer en segundo plano.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( WP_REST_Request $request ) {
		$job = $this->jobs->get( (int) $request['id'] );
		if ( null === $job || BatchJob::TYPE !== $job['type'] ) {
			return $this->not_found();
		}

		// Lo ve quien puede ver el grupo (el listado lo adjunta a todos ellos), además de quien lo lanzó y quien administra.
		if ( ! $this->can_follow( $job ) ) {
			return $this->forbidden();
		}

		return new WP_REST_Response( array( 'job' => $this->present_job( $job ) ) );
	}

	/**
	 * POST /history/jobs/{id}/resume|cancel: recupera o cancela un proceso atascado (quien lo lanzó o un administrador).
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function change_job( WP_REST_Request $request ) {
		$job = $this->jobs->get( (int) $request['id'] );
		if ( null === $job || BatchJob::TYPE !== $job['type'] ) {
			return $this->not_found();
		}
		if ( ! $this->can_control( $job ) ) {
			return $this->forbidden();
		}

		$ok = 'cancel' === $request['action'] ? $this->batches->cancel( $job['id'] ) : $this->batches->resume( $job['id'] );
		if ( ! $ok ) {
			return new WP_Error( 'magiclinking_job_state', __( 'That process cannot be changed in its current state.', 'magic-linking' ), array( 'status' => 409 ) );
		}

		$batch = (string) ( $job['params']['batch_id'] ?? '' );

		return new WP_REST_Response(
			array(
				'job'   => $this->present_job( (array) $this->jobs->get( $job['id'] ) ),
				'group' => '' === $batch ? null : $this->reader->group( $batch, get_current_user_id() ),
			)
		);
	}

	/**
	 * Si el usuario puede ver el avance de un proceso.
	 *
	 * @param array<string, mixed> $job Proceso.
	 */
	private function can_follow( array $job ): bool {
		if ( $this->can_control( $job ) ) {
			return true;
		}

		$batch = (string) ( $job['params']['batch_id'] ?? '' );

		return '' !== $batch && null !== $this->reader->group( $batch, get_current_user_id() );
	}

	/**
	 * Si el usuario puede reanudar o cancelar un proceso: quien lo lanzó o quien administra el plugin.
	 *
	 * @param array<string, mixed> $job Proceso.
	 */
	private function can_control( array $job ): bool {
		return get_current_user_id() === (int) $job['created_by'] || current_user_can( 'manage_options' );
	}

	/**
	 * POST /undo: deshace un cambio (`change_id`) o un grupo entero (`batch_id`).
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_undo( WP_REST_Request $request ) {
		return $this->run( $request, false );
	}

	/**
	 * POST /redo: vuelve a poner un enlace deshecho (`change_id`) o todos los de un grupo (`batch_id`).
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_redo( WP_REST_Request $request ) {
		return $this->run( $request, true );
	}

	/**
	 * Deshace o rehace, con los permisos comprobados entrada por entrada.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @param bool            $redo    Rehacer en vez de deshacer.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function run( WP_REST_Request $request, bool $redo ) {
		$batch_id  = (string) ( $request['batch_id'] ?? '' );
		$change_id = (int) ( $request['change_id'] ?? 0 );
		$user      = get_current_user_id();

		if ( ( '' === $batch_id ) === ( 0 === $change_id ) ) {
			return new WP_Error( 'magiclinking_bad_request', __( 'Send either a group or a single change.', 'magic-linking' ), array( 'status' => 400 ) );
		}

		if ( 0 !== $change_id ) {
			$change = $this->changes->get( $change_id );
			if ( null === $change || ChangeRepository::INSERT !== $change['action'] ) {
				return $this->not_found();
			}
			if ( ! $this->reader->can_edit( $user, $change['post_id'] ) ) {
				return $this->forbidden();
			}
			if ( $this->batches->is_busy( $change['batch_id'] ) ) {
				return $this->busy_batch();
			}

			$result = $redo ? $this->redo->redo( $change_id, $user ) : $this->undo->revert( $change_id, $user );

			return new WP_REST_Response(
				array(
					'results' => array( $this->present_result( $result ) ),
					'group'   => $this->reader->group( $change['batch_id'], $user ),
					'job'     => null,
				)
			);
		}//end if

		if ( null === $this->reader->group( $batch_id, $user ) ) {
			return $this->not_found();
		}
		if ( array() !== $this->reader->not_editable( $batch_id, $user ) ) {
			return $this->forbidden();
		}
		if ( $this->batches->is_busy( $batch_id ) ) {
			return $this->busy_batch();
		}

		// Un grupo grande va por Action Scheduler: de uno en uno por sitio, con su avance en magiclinking_jobs.
		if ( count( $this->reader->pending( $batch_id, $redo ) ) > BatchJob::sync_limit() ) {
			$active = $this->batches->active();
			if ( null !== $active ) {
				return new WP_Error(
					'magiclinking_job_running',
					__( 'Another large undo is already running. Wait for it to finish.', 'magic-linking' ),
					array(
						'status' => 409,
						'job'    => $this->present_job( $active ),
					)
				);
			}

			$job = $this->batches->start( $batch_id, $redo, $user );

			return new WP_REST_Response(
				array(
					'results' => array(),
					'group'   => $this->reader->group( $batch_id, $user ),
					'job'     => null === $job ? null : $this->present_job( $job ),
				),
				202
			);
		}//end if

		$results = $redo ? $this->redo->redo_batch( $batch_id, $user ) : $this->undo->revert_batch( $batch_id, $user );

		return new WP_REST_Response(
			array(
				'results' => array_map( array( $this, 'present_result' ), $results ),
				'group'   => $this->reader->group( $batch_id, $user ),
				'job'     => null,
			)
		);
	}

	/**
	 * Resultado de un cambio listo para la API.
	 *
	 * @param UndoResult $result Resultado.
	 *
	 * @return array<string, mixed>
	 */
	private function present_result( UndoResult $result ): array {
		$post = get_post( $result->post_id );

		return array(
			'change_id'  => $result->change_id,
			'post_id'    => $result->post_id,
			'post_title' => $post instanceof WP_Post ? get_the_title( $post ) : null,
			'status'     => $result->status,
			'message'    => $result->message,
			'edit_url'   => $result->edit_url,
		);
	}

	/**
	 * Proceso de deshacer o rehacer listo para la API.
	 *
	 * @param array<string, mixed> $job Proceso.
	 *
	 * @return array<string, mixed>
	 */
	private function present_job( array $job ): array {
		$base    = (array) JobsController::present( $job );
		$updated = strtotime( (string) $job['updated_at'] . ' UTC' );
		$params  = (array) $job['params'];

		return array(
			'id'          => $base['id'],
			'status'      => $base['status'],
			'mode'        => 'redo' === ( $params['mode'] ?? '' ) ? 'redo' : 'undo',
			'batch_id'    => (string) ( $params['batch_id'] ?? '' ),
			'total'       => $base['total'],
			'done'        => $base['done'],
			'percent'     => $base['percent'],
			'counts'      => (object) ( is_array( $params['counts'] ?? null ) ? $params['counts'] : array() ),
			'issues'      => is_array( $params['issues'] ?? null ) ? $params['issues'] : array(),
			'error'       => $base['error'],
			'can_control' => $this->can_control( $job ),
			'stalled'     => in_array( $job['status'], array( JobRepository::QUEUED, JobRepository::RUNNING ), true ) && false !== $updated && ( time() - $updated ) > 10 * MINUTE_IN_SECONDS,
			'created_at'  => $base['created_at'],
			'updated_at'  => $base['updated_at'],
		);
	}

	/**
	 * Error 409: el lote ya lo está tratando un proceso en segundo plano.
	 */
	private function busy_batch(): WP_Error {
		return new WP_Error( 'magiclinking_batch_busy', __( 'This batch is being processed in the background. Wait for it to finish or cancel it.', 'magic-linking' ), array( 'status' => 409 ) );
	}

	/**
	 * Error 404.
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'magiclinking_not_found', __( 'That change does not exist.', 'magic-linking' ), array( 'status' => 404 ) );
	}

	/**
	 * Error 403.
	 */
	private function forbidden(): WP_Error {
		return new WP_Error( 'magiclinking_forbidden', __( 'You do not have permission to edit one of the entries involved.', 'magic-linking' ), array( 'status' => rest_authorization_required_code() ) );
	}
}
