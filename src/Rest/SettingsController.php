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
use MagicLinking\I18n\Language;
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
	 * Idioma.
	 *
	 * @var Language|null
	 */
	private ?Language $language;

	/**
	 * Metadato de usuario donde se guarda que el asistente de arranque se cerró para siempre (docs/07 §2, regla 7).
	 */
	public const WIZARD_META = 'magiclinking_wizard_dismissed';

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Ajustes.
	 * @param Jobs          $jobs     Procesos.
	 * @param Language|null $language Idioma (para el asistente).
	 */
	public function __construct( Settings $settings, Jobs $jobs, ?Language $language = null ) {
		$this->settings = $settings;
		$this->jobs     = $jobs;
		$this->language = $language;
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
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 20,
						),
						'words_per_link'               => array(
							'type'    => 'integer',
							'minimum' => 20,
							'maximum' => 1000,
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
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
						'inbound_per_page'             => array(
							'type'    => 'integer',
							'minimum' => 5,
							'maximum' => 50,
						),
						'same_anchor_max'              => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
						'ignored_urls'                 => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'ignored_words'                => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
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
			update_user_meta( get_current_user_id(), self::WIZARD_META, '1' );
		} else {
			delete_user_meta( get_current_user_id(), self::WIZARD_META );
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
			'dismissed'    => (bool) get_user_meta( get_current_user_id(), self::WIZARD_META, true ),
			'language'     => $label,
			'multilingual' => null !== $this->language && $this->language->plugin_active(),
			'ai'           => self::ai_state(),
		);
	}

	/**
	 * Estado del conector de IA de WordPress, sin hacer ninguna llamada de red (docs/05 §2): `connected` si el
	 * cliente de IA puede generar texto, `none` si no hay ninguno conectado y `unavailable` si WordPress no lo trae.
	 */
	public static function ai_state(): string {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return 'unavailable';
		}

		try {
			$builder = wp_ai_client_prompt( 'ping' );

			return method_exists( $builder, 'is_supported_for_text_generation' ) && $builder->is_supported_for_text_generation() ? 'connected' : 'none';
		} catch ( \Throwable $e ) {
			return 'none';
		}
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
		$before = $this->settings->post_types();
		$scope  = $this->settings->link_scope();
		$input  = array_intersect_key( $request->get_json_params() ?: $request->get_body_params(), $this->settings->all() ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- JSON o formulario.
		$after  = $this->settings->update( $input );

		$job = null;
		if ( $scope !== $this->settings->link_scope() ) {
			// Cambia lo que se lee de cada entrada, no su contenido: hay que volver a leerlas todas y recalcular el índice.
			$job = JobsController::present( $this->jobs->start_index( get_current_user_id(), true, true, false, true ) );
		} elseif ( $before !== $after['post_types'] ) {
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
