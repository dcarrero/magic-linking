<?php
/**
 * Rutas REST del informe y de los enlaces rotos.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\Module;
use MagicLinking\Graph\BrokenRepository;
use MagicLinking\Graph\ReportRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET /report y GET /broken, para quien puede editar entradas.
 */
final class ReportController implements Module {

	public const NAMESPACE = 'magic-linking/v1';

	/**
	 * Informe.
	 *
	 * @var ReportRepository
	 */
	private ReportRepository $report;

	/**
	 * Rotos.
	 *
	 * @var BrokenRepository
	 */
	private BrokenRepository $broken;

	/**
	 * Constructor.
	 *
	 * @param ReportRepository $report Consulta del informe.
	 * @param BrokenRepository $broken Consulta de rotos.
	 */
	public function __construct( ReportRepository $report, BrokenRepository $broken ) {
		$this->report = $report;
		$this->broken = $broken;
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
		$paging = array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			),
			'search'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/report',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_report' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => $paging + array(
					'filter'    => array(
						'type'    => 'string',
						'default' => 'all',
						'enum'    => ReportRepository::FILTERS,
					),
					'post_type' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'lang'      => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'orderby'   => array(
						'type'    => 'string',
						'default' => 'inbound',
						'enum'    => ReportRepository::order_keys(),
					),
					'order'     => array(
						'type'    => 'string',
						'default' => 'asc',
						'enum'    => array( 'asc', 'desc' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/broken',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_broken' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => $paging + array(
					'post_id' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);
	}

	/**
	 * Permiso: editar entradas.
	 */
	public function can_read(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * GET /report.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function get_report( WP_REST_Request $request ): WP_REST_Response {
		$page   = (int) $request['page'];
		$result = $this->report->query(
			array(
				'filter'    => (string) $request['filter'],
				'search'    => (string) $request['search'],
				'post_type' => (string) $request['post_type'],
				'lang'      => (string) $request['lang'],
				'orderby'   => (string) $request['orderby'],
				'order'     => (string) $request['order'],
				'page'      => $page,
				'per_page'  => (int) $request['per_page'],
			)
		);

		return $this->paged(
			array(
				'items'   => $result['items'],
				'summary' => $this->report->summary(),
			),
			$result['total'],
			$page,
			(int) $request['per_page']
		);
	}

	/**
	 * GET /broken.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function get_broken( WP_REST_Request $request ): WP_REST_Response {
		$page   = (int) $request['page'];
		$result = $this->broken->query(
			array(
				'post_id'  => (int) $request['post_id'],
				'search'   => (string) $request['search'],
				'page'     => $page,
				'per_page' => (int) $request['per_page'],
			)
		);

		return $this->paged( array( 'items' => $result['items'] ), $result['total'], $page, (int) $request['per_page'] );
	}

	/**
	 * Respuesta paginada, con las cabeceras estándar de WordPress.
	 *
	 * @param array<string, mixed> $body     Cuerpo.
	 * @param int                  $total    Total de filas.
	 * @param int                  $page     Página.
	 * @param int                  $per_page Filas por página.
	 */
	private function paged( array $body, int $total, int $page, int $per_page ): WP_REST_Response {
		$pages    = max( 1, (int) ceil( $total / max( 1, $per_page ) ) );
		$response = new WP_REST_Response(
			$body + array(
				'total'       => $total,
				'total_pages' => $pages,
				'page'        => $page,
			)
		);
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );

		return $response;
	}
}
