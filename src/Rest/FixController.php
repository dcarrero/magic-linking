<?php
/**
 * Rutas REST para arreglar un enlace roto desde la lista.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\PostTitle;
use MagicLinking\Content\Destination;
use MagicLinking\Content\FixRequest;
use MagicLinking\Content\InsertionException;
use MagicLinking\Content\PostWriter;
use MagicLinking\Content\Repairer;
use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\Graph\BrokenRepository;
use MagicLinking\History\Reader;
use MagicLinking\I18n\Language;
use Throwable;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WeakMap;

/**
 * `GET /broken/{id}/targets` (buscar el nuevo destino) y `POST /broken/{id}/fix` (cambiar el destino o quitar el
 * enlace), donde `{id}` es la fila de `magiclinking_links` que enseña la lista de rotos.
 *
 * Permisos (docs/03 §6): `edit_post` sobre la entrada de **origen** del enlace, comprobado antes de leer nada más;
 * la nonce de REST la exige el núcleo con la autenticación por cookie. El destino lo elige el usuario pero la
 * dirección la pone el servidor: tiene que ser una entrada publicada, legible, de un tipo que se analiza, sin
 * contraseña, en el mismo idioma y distinta del origen ({@see Destination}).
 */
final class FixController implements Module {

	public const NAMESPACE = 'magic-linking/v1';

	/**
	 * Destinos que devuelve la búsqueda como máximo.
	 */
	private const MAX_TARGETS = 20;

	/**
	 * Páginas de 40 entradas que se recorren como máximo buscando destinos del mismo idioma.
	 */
	private const MAX_PAGES = 25;

	/**
	 * Fila leída en cada petición (el permiso y la ruta preguntan por la misma).
	 *
	 * @var WeakMap<WP_REST_Request, array<int, mixed>>|null
	 */
	private ?WeakMap $rows = null;

	/**
	 * Constructor.
	 *
	 * @param BrokenRepository $broken      Lista de rotos.
	 * @param Repairer         $repairer    Arreglo seguro.
	 * @param Destination      $destination Comprobación del destino.
	 * @param Settings         $settings    Ajustes.
	 * @param Language         $language    Idioma por entrada.
	 * @param Reader           $reader      Historial (para devolver el grupo).
	 */
	public function __construct(
		private BrokenRepository $broken,
		private Repairer $repairer,
		private Destination $destination,
		private Settings $settings,
		private Language $language,
		private Reader $reader
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
		$id = array(
			'type'     => 'integer',
			'required' => true,
			'minimum'  => 1,
		);

		register_rest_route(
			self::NAMESPACE,
			'/broken/(?P<id>\d+)/targets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_targets' ),
				'permission_callback' => array( $this, 'can_fix' ),
				'args'                => array(
					'id'     => $id,
					'search' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/broken/(?P<id>\d+)/fix',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_fix' ),
				'permission_callback' => array( $this, 'can_fix' ),
				'args'                => array(
					'id'        => $id,
					'mode'      => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( FixRequest::RETARGET, FixRequest::UNLINK ),
					),
					'target_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
	}

	/**
	 * Permiso: poder editar la entrada de origen del enlace. Un enlace que no existe pasa y da 404 en la ruta (no
	 * se revela nada: no hay datos que filtrar).
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function can_fix( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$row = $this->occurrence( $request );

		return null === $row || current_user_can( 'edit_post', $row['source_id'] );
	}

	/**
	 * GET /broken/{id}/targets: entradas publicadas a las que se podría apuntar, por título.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_targets( WP_REST_Request $request ) {
		$row = $this->occurrence( $request );
		if ( null === $row ) {
			return $this->not_found();
		}

		$types = $this->settings->post_types();
		$items = array();
		if ( array() !== $types ) {
			$lang   = $this->language->for_post( $row['source_id'] );
			$search = (string) $request['search'];
			$paged  = 1;
			$pages  = 1;

			// El idioma sale de WPML, Polylang o un filtro, por entrada: se sigue pasando de página hasta llenar la lista.
			do {
				$query = new WP_Query(
					array(
						'post_type'              => $types,
						'post_status'            => 'publish',
						'has_password'           => false,
						'post__not_in'           => array( $row['source_id'] ),
						's'                      => $search,
						'search_columns'         => array( 'post_title' ),
						'posts_per_page'         => self::MAX_TARGETS * 2,
						'paged'                  => $paged,
						'orderby'                => '' === $search ? 'date' : 'relevance',
						'order'                  => 'DESC',
						'ignore_sticky_posts'    => true,
						'update_post_term_cache' => false,
						'update_post_meta_cache' => false,
					)
				);
				$pages = (int) $query->max_num_pages;

				$this->language->prime( array_map( static fn( $post ): int => $post instanceof WP_Post ? $post->ID : 0, $query->posts ) );
				foreach ( $query->posts as $post ) {
					if ( ! $post instanceof WP_Post || ! current_user_can( 'read_post', $post->ID ) || $this->language->for_post( $post->ID ) !== $lang ) {
						continue;
					}

					$type    = get_post_type_object( $post->post_type );
					$title   = PostTitle::of( $post );
					$items[] = array(
						'id'         => $post->ID,
						'title'      => '' !== trim( $title ) ? $title : __( '(no title)', 'magic-linking' ),
						'url'        => (string) get_permalink( $post ),
						'type_label' => null === $type ? $post->post_type : (string) $type->labels->singular_name,
					);
					if ( count( $items ) >= self::MAX_TARGETS ) {
						break 2;
					}
				}

				++$paged;
			} while ( $paged <= $pages && $paged <= self::MAX_PAGES );
		}//end if

		return new WP_REST_Response( array( 'items' => $items ) );
	}

	/**
	 * POST /broken/{id}/fix: cambia el destino del enlace roto o lo quita dejando su texto.
	 *
	 * Cuerpo: `{ "mode": "retarget", "target_id": 123 }` o `{ "mode": "unlink" }`. Responde 200 con el cambio y el
	 * grupo del Historial para deshacerlo; 409 si el contenido ya no coincide con la lista, la entrada está abierta
	 * en un editor o hay otra operación en curso; 422 si la verificación o los filtros de guardado lo impiden. En
	 * ningún error se ha escrito nada.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_fix( WP_REST_Request $request ) {
		$row = $this->occurrence( $request );
		if ( null === $row ) {
			return $this->not_found();
		}
		if ( 0 === $row['broken'] ) {
			return new WP_Error( 'magiclinking_not_broken', __( 'This link is no longer broken. Reload the list.', 'magic-linking' ), array( 'status' => 409 ) );
		}

		$mode    = (string) $request['mode'];
		$new_url = null;
		if ( FixRequest::RETARGET === $mode ) {
			$new_url = $this->destination->resolve( $row['source_id'], (int) $request['target_id'] );
			if ( $new_url instanceof InsertionException ) {
				return new WP_Error( 'magiclinking_' . $new_url->reason(), $new_url->getMessage(), array( 'status' => 400 ) );
			}
			if ( ! current_user_can( 'read_post', (int) $request['target_id'] ) ) {
				return new WP_Error( 'magiclinking_bad_target', __( 'The destination is not a published entry that can be linked.', 'magic-linking' ), array( 'status' => 400 ) );
			}
		}

		try {
			$result = $this->repairer->fix(
				new FixRequest( $row['source_id'], $mode, $row['url'], $row['ordinal'], $row['count'], $row['anchor'], $new_url )
			);
		} catch ( InsertionException $e ) {
			return new WP_Error( 'magiclinking_' . $e->reason(), $e->getMessage(), array( 'status' => $this->status_of( $e->reason() ) ) );
		} catch ( Throwable $e ) {
			PostWriter::log( sprintf( 'Fallo inesperado al arreglar un enlace de la entrada %d: %s', $row['source_id'], $e->getMessage() ) );

			return new WP_Error( 'magiclinking_write_failed', __( 'The link could not be fixed; nothing was changed.', 'magic-linking' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'status'    => 'fixed',
				'mode'      => $mode,
				'link_id'   => $row['id'],
				'change_id' => $result->change_id,
				'batch_id'  => $result->batch_id,
				'post_id'   => $result->post_id,
				'group'     => $this->reader->group( $result->batch_id, get_current_user_id() ),
			)
		);
	}

	/**
	 * Código HTTP de cada motivo de negativa.
	 *
	 * @param string $reason Motivo de {@see InsertionException}.
	 */
	private function status_of( string $reason ): int {
		switch ( $reason ) {
			case InsertionException::NO_POST:
				return 404;
			case InsertionException::NOT_ALLOWED:
				return (int) rest_authorization_required_code();
			case InsertionException::BAD_REQUEST:
			case InsertionException::BAD_TARGET:
			case InsertionException::LANGUAGE:
				return 400;
			case InsertionException::LOCKED:
			case InsertionException::BUSY:
			case InsertionException::TEXT_CHANGED:
			case InsertionException::LINK_GONE:
				return 409;
			default:
				return 422;
		}
	}

	/**
	 * El enlace de la petición, leído una sola vez por petición.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return array{id: int, source_id: int, url: string, anchor: string, broken: int, ordinal: int, count: int}|null
	 */
	private function occurrence( WP_REST_Request $request ): ?array {
		$this->rows ??= new WeakMap();
		if ( ! isset( $this->rows[ $request ] ) ) {
			$this->rows[ $request ] = array( $this->broken->occurrence( (int) $request['id'] ) );
		}

		// Forma de la fila: la de BrokenRepository::occurrence().
		/**
		 * Fila guardada.
		 *
		 * @var array{id: int, source_id: int, url: string, anchor: string, broken: int, ordinal: int, count: int}|null $row
		 */
		$row = $this->rows[ $request ][0];

		return $row;
	}

	/**
	 * Error 404.
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'magiclinking_not_found', __( 'That link does not exist.', 'magic-linking' ), array( 'status' => 404 ) );
	}
}
