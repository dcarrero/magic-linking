<?php
/**
 * Rutas REST de las sugerencias y de insertar enlaces.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Content\InsertionException;
use MagicLinking\Content\Inserter;
use MagicLinking\Content\InsertRequest;
use MagicLinking\Content\PostWriter;
use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\Engine\Suggestion;
use MagicLinking\History\BatchId;
use MagicLinking\History\Reader;
use MagicLinking\I18n\Language;
use MagicLinking\Index\SuggestionCache;
use MagicLinking\Index\Suggestions;
use Throwable;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET|POST /suggestions/outbound`, `GET /suggestions/inbound` y `POST /links`.
 *
 * Permisos (docs/03 §6 y §12): todas piden `edit_posts` y, además, `edit_post` sobre la entrada abierta; insertar
 * pide `edit_post` sobre **cada** entrada que se toca, comprobado antes de escribir nada (si falla una, no se
 * toca ninguna). La nonce de REST la exige el núcleo con la autenticación por cookie. Las entrantes listan todos los
 * orígenes, cada uno con `can_insert`: los que el usuario no puede editar no llevan `insert` ni `edit_url` y
 * `POST /links` los rechaza con 403. Sin sesión, 401; sin permiso, 403.
 *
 * El índice léxico sin construir no es un error: las listas van vacías con `ready: false` y `state:
 * "index_not_ready"` (la pantalla enseña el avance con `/status`). Con una caché de objetos persistente el
 * resultado del motor se guarda 10 minutos ({@see SuggestionCache}); con el contenido del editor (POST) nunca.
 */
final class SuggestionsController implements Module {

	/**
	 * Enlaces que admite una petición de insertar: el mismo tope que deshacer en el acto (`magiclinking_undo_sync_limit`).
	 */
	public const MAX_LINKS = 25;

	/**
	 * Tamaño máximo del contenido del editor que se analiza, en bytes.
	 */
	public const MAX_DRAFT = 2000000;

	/**
	 * Constructor.
	 *
	 * @param Suggestions         $suggestions Sugerencias.
	 * @param Inserter            $inserter    Inserción segura.
	 * @param Reader              $reader      Historial (para devolver el grupo).
	 * @param Settings            $settings    Ajustes.
	 * @param Language            $language    Idioma por entrada.
	 * @param SuggestionPresenter $presenter   Forma de la respuesta.
	 */
	public function __construct(
		private Suggestions $suggestions,
		private Inserter $inserter,
		private Reader $reader,
		private Settings $settings,
		private Language $language,
		private SuggestionPresenter $presenter
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
		$ns      = ReportController::NAMESPACE;
		$post_id = array(
			'type'              => 'integer',
			'minimum'           => 1,
			'required'          => true,
			'validate_callback' => 'rest_validate_request_arg',
		);

		register_rest_route(
			$ns,
			'/suggestions/outbound',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_outbound' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => array( 'post_id' => $post_id ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_outbound' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => array(
						'post_id' => $post_id,
						'content' => array(
							'type'     => 'string',
							'required' => true,
						),
						'title'   => array( 'type' => 'string' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/suggestions/inbound',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_inbound' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => $post_id,
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 50,
					),
				),
			)
		);

		$link = array(
			'type'       => 'object',
			'properties' => array(
				'post_id'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'target_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'sentence'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 5000,
				),
				'offset'     => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'anchor'     => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 500,
				),
				'block_path' => array(
					'type'    => array( 'string', 'null' ),
					'pattern' => '^[0-9]+(\.[0-9]+)*$',
				),
			),
		);

		register_rest_route(
			$ns,
			'/links',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_links' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array_merge(
					$link['properties'],
					array(
						'links' => array(
							'type'              => 'array',
							'minItems'          => 1,
							'maxItems'          => self::MAX_LINKS,
							'items'             => $link,
							'validate_callback' => 'rest_validate_request_arg',
						),
					)
				),
			)
		);
	}

	/**
	 * Permiso base de las rutas que no se refieren a una entrada.
	 */
	public function can_edit_posts(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permiso: editar entradas y, si la entrada existe, editar esa (una que no existe da 404 en la ruta).
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function can_edit_post( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$id = (int) $request['post_id'];

		return ! get_post( $id ) instanceof WP_Post || current_user_can( 'edit_post', $id );
	}

	/**
	 * GET /suggestions/outbound: las de la entrada tal como está guardada.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_outbound( WP_REST_Request $request ) {
		return $this->outbound( (int) $request['post_id'], null, null );
	}

	/**
	 * POST /suggestions/outbound: las de lo que el usuario tiene ahora en el editor, sin guardar.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_outbound( WP_REST_Request $request ) {
		$content = (string) $request['content'];
		if ( strlen( $content ) > self::MAX_DRAFT ) {
			return new WP_Error( 'magiclinking_too_large', __( 'The content is too long to analyze.', 'magic-linking' ), array( 'status' => 413 ) );
		}

		$title = $request->has_param( 'title' ) ? wp_strip_all_tags( (string) $request['title'] ) : null;

		return $this->outbound( (int) $request['post_id'], $content, $title );
	}

	/**
	 * Salientes de una entrada, guardada o con el contenido del editor.
	 *
	 * @param int         $post_id ID.
	 * @param string|null $content Contenido del editor; null = el guardado.
	 * @param string|null $title   Título del editor.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function outbound( int $post_id, ?string $content, ?string $title ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}

		$state = $this->suggestions->state( $post, false, null !== $content );
		if ( 'ok' !== $state ) {
			return $this->empty( $state );
		}

		// Solo se guardan las de una entrada publicada: la de un borrador cambia con cada guardado y no invalida nada.
		if ( null !== $content ) {
			$found = $this->suggestions->outgoing( $post_id, array(), $content, $title );
		} elseif ( 'publish' === $post->post_status ) {
			$found = SuggestionCache::remember( 'out', $post_id, fn(): array => $this->suggestions->outgoing( $post_id ) );
		} else {
			$found = $this->suggestions->outgoing( $post_id );
		}

		// Solo se proponen frases donde el editor puede poner el enlace (docs/06 §2): el motor lee más
		// contenedores que los bloques de texto admitidos y una sugerencia imposible volvería siempre.
		$found = $this->insertable( null !== $content ? $content : $post->post_content, $post_id, $found );

		$this->presenter->prime( $found );

		return new WP_REST_Response(
			array(
				'ready'   => true,
				'state'   => 'ok',
				'draft'   => null !== $content,
				'total'   => count( $found ),
				'items'   => array_map( array( $this->presenter, 'present' ), $found ),
				'post_id' => $post_id,
			)
		);
	}

	/**
	 * Deja las sugerencias cuya frase se podría enlazar en el contenido dado.
	 *
	 * @param string       $content Contenido de la entrada origen (el guardado o el del editor).
	 * @param int          $post_id Entrada origen.
	 * @param Suggestion[] $found   Sugerencias del motor.
	 *
	 * @return list<Suggestion>
	 */
	private function insertable( string $content, int $post_id, array $found ): array {
		$kept = array();
		foreach ( $found as $suggestion ) {
			try {
				$request = InsertRequest::from_sentence( $post_id, home_url( '/' ), $suggestion->sentence, $suggestion->offset, $suggestion->anchor );
			} catch ( InsertionException ) {
				continue;
			}
			if ( $this->inserter->can_insert( $content, $request ) ) {
				$kept[] = $suggestion;
			}
		}

		return $kept;
	}

	/**
	 * GET /suggestions/inbound: las que superan el umbral hacia la entrada, paginadas, solo desde orígenes editables.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_inbound( WP_REST_Request $request ) {
		$post_id  = (int) $request['post_id'];
		$page     = (int) $request['page'];
		$per_page = (int) $request['per_page'];
		$post     = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}

		$state = $this->suggestions->state( $post, true, false );
		if ( 'ok' !== $state ) {
			return $this->empty( $state, $page, $per_page );
		}

		$all = SuggestionCache::remember( 'in', $post_id, fn(): array => $this->suggestions->incoming( $post_id ) );

		// El motor da la mejor frase de cada origen y se listan todas (07 §3); `can_insert` dice cuáles puede
		// insertar el usuario. Los permisos solo se miran en la página que se enseña, con sus entradas ya cargadas.
		$cut = array_slice( $all, ( $page - 1 ) * $per_page, $per_page );
		$this->presenter->prime( $cut );

		$total = count( $all );
		$pages = max( 1, (int) ceil( $total / $per_page ) );

		$response = new WP_REST_Response(
			array(
				'ready'       => true,
				'state'       => 'ok',
				'total'       => $total,
				'total_pages' => $pages,
				'page'        => $page,
				'per_page'    => $per_page,
				'items'       => array_map( array( $this->presenter, 'present' ), $cut ),
				'post_id'     => $post_id,
			)
		);
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );

		return $response;
	}

	/**
	 * POST /links: inserta uno o varios enlaces (un lote, una sola acción del usuario).
	 *
	 * Cuerpo: `{ "links": [ {post_id, target_id, sentence, offset, anchor, block_path?} … ] }` o, para uno solo,
	 * esos campos sueltos. `post_id` es la entrada donde se escribe (el origen) y `target_id` a quien enlaza; la
	 * dirección sale del servidor. Es el `insert` que devuelven las sugerencias.
	 *
	 * Responde 200 con un resultado por enlace (`inserted` o `failed` con `reason` y `message`) y el `batch_id`
	 * para deshacer. 403 sin tocar nada si el usuario no puede editar alguna de las entradas.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_links( WP_REST_Request $request ) {
		$links = $request['links'];
		if ( ! is_array( $links ) || array() === $links ) {
			$links = array(
				array(
					'post_id'    => $request['post_id'],
					'target_id'  => $request['target_id'],
					'sentence'   => $request['sentence'],
					'offset'     => $request['offset'],
					'anchor'     => $request['anchor'],
					'block_path' => $request['block_path'],
				),
			);
		}

		$links = array_values( $links );
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) || (int) ( $link['post_id'] ?? 0 ) < 1 || (int) ( $link['target_id'] ?? 0 ) < 1 || ! is_string( $link['sentence'] ?? null ) || ! is_string( $link['anchor'] ?? null ) || ! isset( $link['offset'] ) ) {
				return new WP_Error( 'magiclinking_bad_request', __( 'Each link needs the post, the destination, the sentence, the offset and the anchor.', 'magic-linking' ), array( 'status' => 400 ) );
			}
		}

		// Los permisos, todos antes de escribir: si no se puede con una entrada, no se toca ninguna.
		foreach ( $links as $link ) {
			$post = get_post( (int) $link['post_id'] );
			if ( $post instanceof WP_Post && ! current_user_can( 'edit_post', $post->ID ) ) {
				return new WP_Error( 'magiclinking_forbidden', __( 'You do not have permission to edit one of the entries involved.', 'magic-linking' ), array( 'status' => rest_authorization_required_code() ) );
			}
		}

		$batch   = BatchId::generate();
		$results = array();
		$groups  = array();

		// Primero se valida cada enlace; los válidos se agrupan por entrada para escribir cada entrada una sola vez.
		foreach ( $links as $index => $link ) {
			$post_id   = (int) $link['post_id'];
			$target_id = (int) $link['target_id'];
			$base      = array(
				'index'      => $index,
				'post_id'    => $post_id,
				'target_id'  => $target_id,
				'post_title' => $this->title( $post_id ),
			);

			$url = $this->destination( $post_id, $target_id );
			if ( $url instanceof InsertionException ) {
				$results[ $index ] = $this->failure( $base, $url );
				continue;
			}

			$path = isset( $link['block_path'] ) && is_string( $link['block_path'] ) && '' !== $link['block_path'] ? $link['block_path'] : null;
			try {
				$request = InsertRequest::from_sentence( $post_id, $url, (string) $link['sentence'], (int) $link['offset'], (string) $link['anchor'], array(), $path );
			} catch ( InsertionException $e ) {
				$results[ $index ] = $this->failure( $base, $e );
				continue;
			}

			$groups[ $post_id ][ $index ] = array( $request, $base );
		}//end foreach

		foreach ( $groups as $items ) {
			$requests = array_column( array_values( $items ), 0 );
			$indexes  = array_keys( $items );

			try {
				$done = $this->inserter->insert_many( $requests, $batch );
			} catch ( Throwable $e ) {
				PostWriter::log( sprintf( 'Fallo inesperado al insertar enlaces en la entrada %d: %s', $requests[0]->post_id, $e->getMessage() ) );
				$done = array_fill(
					0,
					count( $requests ),
					new InsertionException( InsertionException::WRITE_FAILED, __( 'The link could not be saved; nothing was changed.', 'magic-linking' ) )
				);
			}

			foreach ( $indexes as $position => $index ) {
				$base   = $items[ $index ][1];
				$result = $done[ $position ];

				$results[ $index ] = $result instanceof InsertionException
					? $this->failure( $base, $result )
					: array_merge(
						$base,
						array(
							'status'    => 'inserted',
							'change_id' => $result->change_id,
							'path'      => $result->path,
						)
					);
			}
		}//end foreach

		ksort( $results );
		$results  = array_values( $results );
		$inserted = count( array_filter( $results, static fn( array $r ): bool => 'inserted' === $r['status'] ) );

		// Una sola invalidación por petición (cada escritura ya renovó la época si tocaba una publicada).
		if ( $inserted > 0 ) {
			SuggestionCache::bump();
		}

		return new WP_REST_Response(
			array(
				'batch_id' => $inserted > 0 ? $batch : null,
				'inserted' => $inserted,
				'failed'   => count( $results ) - $inserted,
				'results'  => $results,
				'group'    => $inserted > 0 ? $this->reader->group( $batch, get_current_user_id() ) : null,
			)
		);
	}

	/**
	 * Resultado de un enlace que no se ha insertado.
	 *
	 * @param array<string, mixed> $base Datos del enlace.
	 * @param InsertionException   $e    Motivo.
	 *
	 * @return array<string, mixed>
	 */
	private function failure( array $base, InsertionException $e ): array {
		return array_merge(
			$base,
			array(
				'status'  => 'failed',
				'reason'  => $e->reason(),
				'message' => $e->getMessage(),
			)
		);
	}

	/**
	 * Dirección del destino, tras comprobar que es una entrada publicada de un tipo que se analiza, que no es
	 * el propio origen y que está en el mismo idioma (regla 9). La dirección nunca la dicta el cliente.
	 *
	 * @param int $post_id   Origen.
	 * @param int $target_id Destino.
	 *
	 * @return string|InsertionException La dirección, o el motivo por el que no se puede enlazar a ese destino.
	 */
	private function destination( int $post_id, int $target_id ): string|InsertionException {
		$target = get_post( $target_id );
		if ( ! $target instanceof WP_Post || 'publish' !== $target->post_status || ! in_array( $target->post_type, $this->settings->post_types(), true ) || '' !== $target->post_password || $target_id === $post_id ) {
			return new InsertionException( 'bad_target', __( 'The destination is not a published entry that can be linked.', 'magic-linking' ) );
		}
		if ( $this->language->for_post( $post_id ) !== $this->language->for_post( $target_id ) ) {
			return new InsertionException( 'language_mismatch', __( 'The destination is in a different language, so no link is added.', 'magic-linking' ) );
		}

		$url = (string) get_permalink( $target );
		if ( '' === $url ) {
			return new InsertionException( 'bad_target', __( 'The destination is not a published entry that can be linked.', 'magic-linking' ) );
		}

		return $url;
	}

	/**
	 * Respuesta sin sugerencias y por qué.
	 *
	 * @param string $state    Estado.
	 * @param int    $page     Página.
	 * @param int    $per_page Por página.
	 */
	private function empty( string $state, int $page = 1, int $per_page = 10 ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ready'       => 'index_not_ready' !== $state,
				'state'       => $state,
				'total'       => 0,
				'total_pages' => 1,
				'page'        => $page,
				'per_page'    => $per_page,
				'items'       => array(),
			)
		);
	}

	/**
	 * Título de una entrada para los resultados.
	 *
	 * @param int $post_id ID.
	 */
	private function title( int $post_id ): ?string {
		$post = get_post( $post_id );

		return $post instanceof WP_Post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : null;
	}

	/**
	 * Error 404.
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'magiclinking_not_found', __( 'That entry does not exist.', 'magic-linking' ), array( 'status' => 404 ) );
	}
}
