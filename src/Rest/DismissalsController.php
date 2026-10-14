<?php
/**
 * Rutas REST para descartar una sugerencia y deshacer el descarte.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\Index\DismissalRepository;
use MagicLinking\Index\SuggestionCache;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /suggestions/dismissals` y `DELETE /suggestions/dismissals/{origen}/{destino}` (docs/04 §11).
 *
 * El par es «enlazar desde el origen al destino», sea cual sea el panel desde el que se descarta, y vale para
 * todo el sitio. Permisos (docs/03 §6 y §12): `edit_posts` y `edit_post` sobre el **origen**; la nonce de REST la
 * exige el núcleo con la autenticación por cookie. Descartar y deshacerlo son idempotentes. Cada cambio renueva
 * la época de {@see SuggestionCache}: la lista guardada deja de servir. También olvida los pares de una entrada
 * que se borra.
 */
final class DismissalsController implements Module {

	/**
	 * Constructor.
	 *
	 * @param DismissalRepository $dismissals Pares descartados.
	 * @param Settings            $settings   Ajustes (tipos que se analizan).
	 */
	public function __construct( private DismissalRepository $dismissals, private Settings $settings ) {
	}

	/**
	 * Engancha las rutas y la limpieza al borrar una entrada.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'deleted_post', array( $this, 'on_deleted' ), 10, 2 );
	}

	/**
	 * Una entrada se borra: olvida sus pares y renueva la caché si había alguno.
	 *
	 * Sale pronto con lo que nunca tiene pares (revisiones, autoguardados, adjuntos, menús y tipos que no se
	 * analizan) y mientras el esquema 4 no está instalado (véase Jobs::on_post_deleted()).
	 *
	 * @param int          $post_id ID.
	 * @param WP_Post|null $post    Entrada borrada.
	 */
	public function on_deleted( int $post_id, ?WP_Post $post = null ): void {
		$post ??= get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $this->settings->post_types(), true ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( (int) get_option( Installer::DB_VERSION_OPTION, 0 ) < 4 ) {
			return;
		}

		if ( $this->dismissals->forget_post( $post_id ) > 0 ) {
			SuggestionCache::bump();
		}
	}

	/**
	 * Registra las rutas.
	 */
	public function routes(): void {
		$id = array(
			'type'              => 'integer',
			'minimum'           => 1,
			'required'          => true,
			'validate_callback' => 'rest_validate_request_arg',
		);

		register_rest_route(
			ReportController::NAMESPACE,
			'/suggestions/dismissals',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss' ),
				'permission_callback' => array( $this, 'can_edit_source' ),
				'args'                => array(
					'source_id' => $id,
					'target_id' => $id,
				),
			)
		);

		register_rest_route(
			ReportController::NAMESPACE,
			'/suggestions/dismissals/(?P<source_id>\d+)/(?P<target_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'restore' ),
				'permission_callback' => array( $this, 'can_edit_source' ),
			)
		);
	}

	/**
	 * Permiso: editar entradas y, si el origen existe, poder editarlo (uno que no existe da 404 en la ruta).
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function can_edit_source( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$source = (int) $request['source_id'];

		return ! get_post( $source ) instanceof WP_Post || current_user_can( 'edit_post', $source );
	}

	/**
	 * POST: descarta el par.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss( WP_REST_Request $request ) {
		$source = (int) $request['source_id'];
		$target = (int) $request['target_id'];

		if ( $source === $target ) {
			return new WP_Error( 'magiclinking_bad_request', __( 'An entry cannot link to itself.', 'magic-linking' ), array( 'status' => 400 ) );
		}
		// Una sola respuesta para «no existe» y «no se puede usar»: no sirve para saber qué entradas privadas hay.
		if ( ! $this->usable_source( $source ) || ! $this->usable_target( $target ) ) {
			return new WP_Error( 'magiclinking_not_found', __( 'That entry does not exist.', 'magic-linking' ), array( 'status' => 404 ) );
		}
		Installer::ensure_current();
		if ( ! $this->dismissals->add( $source, $target, get_current_user_id() ) ) {
			return $this->failed();
		}
		SuggestionCache::bump();

		return new WP_REST_Response(
			array(
				'dismissed' => true,
				'source_id' => $source,
				'target_id' => $target,
			)
		);
	}

	/**
	 * DELETE: deshace el descarte del par.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore( WP_REST_Request $request ) {
		$source = (int) $request['source_id'];
		$target = (int) $request['target_id'];

		Installer::ensure_current();
		if ( ! $this->dismissals->remove( $source, $target ) ) {
			return $this->failed();
		}
		SuggestionCache::bump();

		return new WP_REST_Response(
			array(
				'dismissed' => false,
				'source_id' => $source,
				'target_id' => $target,
			)
		);
	}

	/**
	 * Si la entrada puede ser origen de un descarte: existe y es de un tipo que se analiza, y no es un autoborrador.
	 *
	 * @param int $id ID.
	 */
	private function usable_source( int $id ): bool {
		$post = get_post( $id );

		return $post instanceof WP_Post && 'auto-draft' !== $post->post_status && in_array( $post->post_type, $this->settings->post_types(), true );
	}

	/**
	 * Si la entrada puede ser destino: de un tipo que se analiza y publicada, o que el usuario puede leer.
	 *
	 * @param int $id ID.
	 */
	private function usable_target( int $id ): bool {
		$post = get_post( $id );

		return $post instanceof WP_Post
			&& in_array( $post->post_type, $this->settings->post_types(), true )
			&& ( 'publish' === $post->post_status || current_user_can( 'read_post', $id ) );
	}

	/**
	 * Error 500: la base de datos no guardó el cambio.
	 */
	private function failed(): WP_Error {
		return new WP_Error( 'magiclinking_dismiss_failed', __( 'The change could not be saved. Try again.', 'magic-linking' ), array( 'status' => 500 ) );
	}
}
