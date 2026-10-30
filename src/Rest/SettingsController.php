<?php
/**
 * Ajustes por REST.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\AI\Capabilities;
use MagicLinking\AI\Models;
use MagicLinking\Core\Installer;
use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\I18n\Language;
use MagicLinking\Jobs\Jobs;
use WP_Error;
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
	 * Idioma.
	 *
	 * @var Language|null
	 */
	private ?Language $language;

	/**
	 * Capacidades del conector de IA (docs/05 §3).
	 *
	 * @var Capabilities|null
	 */
	private ?Capabilities $capabilities;

	/**
	 * Metadato de usuario donde se guarda que el asistente de arranque se cerró para siempre (docs/07 §2, regla 7).
	 */
	public const WIZARD_META = 'magiclinking_wizard_dismissed';

	/**
	 * Clave del metadato en este sitio. Lleva el prefijo de las tablas del sitio (como las opciones de usuario de
	 * WordPress): en una red cada sitio cierra su asistente y al desinstalar se borra solo la clave de ese sitio.
	 */
	public static function wizard_meta_key(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . self::WIZARD_META;
	}

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Ajustes.
	 * @param Jobs              $jobs     Procesos.
	 * @param Language|null     $language Idioma (para el asistente).
	 * @param Capabilities|null $capabilities Capacidades del conector de IA (para el asistente).
	 */
	public function __construct( Settings $settings, Jobs $jobs, ?Language $language = null, ?Capabilities $capabilities = null ) {
		$this->settings     = $settings;
		$this->jobs         = $jobs;
		$this->language     = $language;
		$this->capabilities = $capabilities;
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
			'/wizard',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_wizard' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_wizard' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'dismissed' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);

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
							'type' => 'integer',
						),
						'words_per_link'               => array(
							'type' => 'integer',
						),
						Settings::RETENTION_SETTING    => array(
							'type' => 'integer',
							'enum' => Settings::RETENTION_CHOICES,
						),
						Installer::DELETE_DATA_SETTING => array( 'type' => 'boolean' ),
						'link_in_headings'             => array( 'type' => 'boolean' ),
						'link_in_quotes'               => array( 'type' => 'boolean' ),
						'link_in_tables'               => array( 'type' => 'boolean' ),
						'open_in_new_tab'              => array( 'type' => 'boolean' ),
						'extra_rel'                    => array( 'type' => 'string' ),
						'outbound_shown'               => array(
							'type' => 'integer',
						),
						'inbound_per_page'             => array(
							'type' => 'integer',
						),
						'same_anchor_max'              => array(
							'type' => 'integer',
						),
						'links_per_target_max'         => array(
							'type' => 'integer',
						),
						'ignored_urls'                 => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'ignored_words'                => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'ai_daily_token_limit'         => array(
							'type' => 'integer',
						),
						'ai_model'                     => array(
							'type'              => 'string',
							'validate_callback' => static function ( $value ) {
								if ( is_string( $value ) && ! Models::allowed( $value ) ) {
									return new WP_Error( 'magiclinking_ai_model_batch', __( 'That model works in batches, not live, so it cannot be used in the editor. Pick another one or leave it on automatic.', 'magic-linking' ), array( 'status' => 400 ) );
								}

								return true;
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Datos del asistente de arranque: si el usuario lo cerró, el idioma y el estado de la IA.
	 */
	public function get_wizard(): WP_REST_Response {
		return new WP_REST_Response( $this->wizard() );
	}

	/**
	 * Cierra el asistente para este usuario (`dismissed` verdadero) o lo vuelve a abrir.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function update_wizard( WP_REST_Request $request ): WP_REST_Response {
		if ( filter_var( $request['dismissed'], FILTER_VALIDATE_BOOLEAN ) ) {
			update_user_meta( get_current_user_id(), self::wizard_meta_key(), '1' );
		} else {
			delete_user_meta( get_current_user_id(), self::wizard_meta_key() );
		}

		return new WP_REST_Response( $this->wizard() );
	}

	/**
	 * Estado del asistente.
	 *
	 * @return array{dismissed: bool, language: string, multilingual: bool, ai: string}
	 */
	private function wizard(): array {
		$code  = null === $this->language ? Language::normalize( get_locale() ) : $this->language->site();
		$label = $code;
		if ( class_exists( \Locale::class ) ) {
			$name = \Locale::getDisplayLanguage( $code, determine_locale() );
			if ( is_string( $name ) && $name !== $code ) {
				$label = $name;
			}
		}

		return array(
			'dismissed'    => (bool) get_user_meta( get_current_user_id(), self::wizard_meta_key(), true ),
			'language'     => $label,
			'multilingual' => null !== $this->language && $this->language->plugin_active(),
			'ai'           => null === $this->capabilities ? 'unavailable' : $this->capabilities->state(),
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
	 * POST /settings. Si cambian los tipos de contenido, lanza un indexado nuevo; si cambia qué texto se lee
	 * (encabezados, citas, tablas), lanza uno que reconstruye el índice léxico. Ninguno cuesta nada.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$types         = $this->settings->post_types();
		$scope         = $this->settings->link_scope();
		$input         = array_intersect_key( $request->get_json_params() ?: $request->get_body_params(), $this->settings->all() ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- JSON o formulario.
		$after         = $this->settings->update( $input );
		$types_changed = $types !== $after['post_types'];
		$scope_changed = $scope !== $this->settings->link_scope();

		$job = null;
		if ( $types_changed || $scope_changed ) {
			$job = JobsController::present( $this->restart_index( $types_changed, $scope_changed ) );
		}

		return new WP_REST_Response( $this->payload() + array( 'job' => $job ) );
	}

	/**
	 * Lanza el indexado que pide un cambio de ajustes. Si ya hay uno en marcha se cancela y se rehace, porque
	 * habría leído las entradas con el ajuste anterior y el índice quedaría con dos criterios mezclados.
	 *
	 * @param bool $types Han cambiado los tipos de contenido (hay que pasar también por el grafo).
	 * @param bool $scope Ha cambiado qué texto se lee (hay que releer todas y reconstruir el índice léxico).
	 *
	 * @return array{id: int, type: string, status: string, total: int, done: int, params: array<string, mixed>, error: string, created_by: int, created_at: string, updated_at: string}
	 */
	private function restart_index( bool $types, bool $scope ): array {
		$graph  = $types;
		$active = $this->jobs->repository()->active( Jobs::TYPE_INDEX );
		if ( null !== $active ) {
			// Lo que el proceso cancelado no llegó a hacer del grafo lo hace el nuevo.
			$graph = $graph || ! empty( $active['params']['graph'] );
			$this->jobs->cancel( $active['id'] );
		}

		return $this->jobs->start_index( get_current_user_id(), true, $scope, $graph, $scope );
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
