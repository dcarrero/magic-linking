<?php
/**
 * Rutas REST de las sugerencias y de insertar enlaces.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\PostTitle;
use MagicLinking\Content\Destination;
use MagicLinking\Content\InsertionException;
use MagicLinking\Content\Inserter;
use MagicLinking\Content\InsertRequest;
use MagicLinking\Content\PostWriter;
use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\Engine\AlternativeAnchors;
use MagicLinking\Engine\Analyzer;
use MagicLinking\Engine\AnchorCheck;
use MagicLinking\Engine\IgnoredWords;
use MagicLinking\Engine\Suggester;
use MagicLinking\Engine\Suggestion;
use MagicLinking\Graph\GraphRepository;
use MagicLinking\History\AiOrigin;
use MagicLinking\History\BatchId;
use MagicLinking\History\Reader;
use MagicLinking\I18n\Language;
use MagicLinking\Index\EngineTiming;
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
	 * Analizador por entrada, calculado una vez por petición.
	 *
	 * @var array<int, Analyzer>
	 */
	private array $analyzers = array();

	/**
	 * Patrones de las palabras ignoradas, calculados una vez por petición.
	 *
	 * @var list<string>|null
	 */
	private ?array $ignored = null;

	/**
	 * Constructor.
	 *
	 * @param Suggestions         $suggestions Sugerencias.
	 * @param Inserter            $inserter    Inserción segura.
	 * @param Reader              $reader      Historial (para devolver el grupo).
	 * @param Settings            $settings    Ajustes.
	 * @param SuggestionPresenter $presenter   Forma de la respuesta.
	 * @param Destination         $destination Comprobación del destino.
	 * @param Language            $language    Idioma por entrada (para comprobar un ancla ajustada).
	 * @param GraphRepository     $graph       Grafo de enlaces (para la unicidad de las anclas ajustadas).
	 * @param AnchorNotes         $notes       Avisos de ancla repetida (para la comprobación de un ancla ajustada).
	 */
	public function __construct(
		private Suggestions $suggestions,
		private Inserter $inserter,
		private Reader $reader,
		private Settings $settings,
		private SuggestionPresenter $presenter,
		private Destination $destination,
		private Language $language,
		private GraphRepository $graph,
		private AnchorNotes $notes
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
						'post_id'    => $post_id,
						'content'    => array(
							'type'     => 'string',
							'required' => true,
						),
						'title'      => array( 'type' => 'string' ),
						// Categorías y etiquetas que el editor tiene ahora (aún sin guardar): taxonomía lógica → IDs de término.
						'terms'      => array(
							'type'                 => 'object',
							'properties'           => array(
								'category' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'integer' ),
								),
								'post_tag' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'integer' ),
								),
							),
							'additionalProperties' => false,
						),
						// Nombres de etiquetas escritos en el editor clásico: se envían aparte, nunca se toman por IDs.
						'term_names' => array(
							'type'                 => 'object',
							'properties'           => array(
								'category' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'post_tag' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
							'additionalProperties' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/suggestions/current-links',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_current_links' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id' => $post_id,
					'content' => array(
						'type'     => 'string',
						'required' => true,
					),
					'title'   => array( 'type' => 'string' ),
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
						'minimum' => 1,
						'maximum' => 50,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/suggestions/anchor',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_anchor' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'   => $post_id,
					'sentence'  => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
						'maxLength' => 5000,
					),
					'offset'    => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
					),
					'anchor'    => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
						'maxLength' => 500,
					),
					// Destino del enlace y anclas que el panel ya ha elegido para otros destinos: con ellos se comprueba
					// que la ancla no lleve a dos destinos desde este origen (docs/04 §5.3).
					'target_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'taken'     => array(
						'type'     => 'array',
						'maxItems' => 100,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'anchor'    => array(
									'type'      => 'string',
									'maxLength' => 500,
								),
								'target_id' => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
							),
						),
					),
				),
			)
		);

		$link = array(
			'type'       => 'object',
			'properties' => array(
				'post_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'target_id'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'sentence'       => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 5000,
				),
				'offset'         => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'anchor'         => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 500,
				),
				// F1-22: el usuario ajustó el ancla dentro de la frase; el servidor la vuelve a comprobar (docs/04 §5.5).
				'adjusted'       => array( 'type' => 'boolean' ),
				// F1-38: lo declara el cliente y solo informa al Historial (no sirve de auditoría); el servidor lo sanea y no
				// cambia lo que se escribe.
				'ai_recommended' => array( 'type' => 'boolean' ),
				'ai_anchor'      => array( 'type' => 'boolean' ),
				'ai_model'       => array(
					'type'      => 'string',
					'maxLength' => AiOrigin::MODEL_MAX,
				),
				'block_path'     => array(
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
	 * POST /suggestions/anchor: comprueba un ancla que el usuario ha ajustado dentro de la frase de una sugerencia
	 * (docs/07 §3.2). No escribe nada: dice si se puede enlazar (`valid`), por qué no (`errors`) y qué es dudoso
	 * (`warnings`), cada uno con su código y su texto.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_anchor( WP_REST_Request $request ) {
		$this->forget_anchor_cache();
		$post_id = (int) $request['post_id'];
		if ( ! get_post( $post_id ) instanceof WP_Post ) {
			return $this->not_found();
		}

		$taken = $request->has_param( 'taken' ) && is_array( $request['taken'] ) ? $request['taken'] : array();
		$check = $this->check_anchor( $post_id, (string) $request['sentence'], (int) $request['offset'], (string) $request['anchor'], (int) $request['target_id'], $taken );

		$notes = array();
		if ( $check->valid() && (int) $request['target_id'] > 0 ) {
			$this->notes->prime( array( array( (string) $request['anchor'], (int) $request['target_id'], $post_id ) ) );
			$notes = $this->notes->for_anchor( (string) $request['anchor'], (int) $request['target_id'], $post_id );
		}

		return new WP_REST_Response(
			array(
				'valid'    => $check->valid(),
				'errors'   => AnchorMessages::describe( $check->errors ),
				'warnings' => AnchorMessages::describe( $check->warnings ),
				// Avisos de ancla repetida (docs/07 §3.3): solo con un ancla válida y un destino con el que compararla.
				'notes'    => $notes,
			)
		);
	}

	/**
	 * POST /suggestions/current-links: a quién enlaza ya la entrada con lo que hay ahora en el editor, sin calcular
	 * sugerencias. El panel lo pide tras enlazar o deshacer para que el bloque «Enlaces actuales» sea siempre el real.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_current_links( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		if ( ! get_post( $post_id ) instanceof WP_Post ) {
			return $this->not_found();
		}
		$content = (string) $request['content'];
		if ( strlen( $content ) > self::MAX_DRAFT ) {
			return new WP_Error( 'magiclinking_too_large', __( 'The content is too long to analyze.', 'magic-linking' ), array( 'status' => 413 ) );
		}
		$title = $request->has_param( 'title' ) ? wp_strip_all_tags( (string) $request['title'] ) : null;

		return new WP_REST_Response( array( 'current_links' => $this->presenter->current_links( $this->suggestions->current_links( $post_id, $content, $title ) ) ) );
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

		$terms = $request->has_param( 'terms' ) && is_array( $request['terms'] ) ? $request['terms'] : null;

		$names = $request->has_param( 'term_names' ) && is_array( $request['term_names'] ) ? $request['term_names'] : null;

		return $this->outbound( (int) $request['post_id'], $content, $title, $terms, $names );
	}

	/**
	 * Salientes de una entrada, guardada o con el contenido del editor.
	 *
	 * @param int         $post_id ID.
	 * @param string|null $content Contenido del editor; null = el guardado.
	 * @param string|null $title   Título del editor.
	 * @param array|null  $terms   IDs de categorías y etiquetas del editor, sin guardar.
	 * @param array|null  $names   Nombres de etiquetas del editor clásico, sin guardar.
	 *
	 * @phpstan-param array<string, mixed>|null $terms
	 * @phpstan-param array<string, mixed>|null $names
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function outbound( int $post_id, ?string $content, ?string $title, ?array $terms = null, ?array $names = null ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}

		$state = $this->suggestions->state( $post, false, null !== $content );
		if ( 'ok' !== $state ) {
			return $this->empty( $state );
		}

		// Solo se guardan las de una entrada publicada: la de un borrador cambia con cada guardado y no invalida nada.
		// Los enlaces actuales salen del mismo documento que analiza el motor; si las sugerencias vienen de la caché, se
		// guardan también (misma clave de renovación) y, sin caché, se leen del grafo.
		$links = null;
		if ( null !== $content ) {
			$found = $this->suggestions->outgoing( $post_id, array(), $content, $title, $terms, $names, $links );
		} elseif ( 'publish' === $post->post_status ) {
			$started = hrtime( true );
			$hit     = true;
			$found   = SuggestionCache::remember(
				'out',
				$post_id,
				function () use ( $post_id, &$hit ): array {
					$hit = false;
					return $this->suggestions->outgoing( $post_id );
				}
			);
			if ( $hit ) {
				// Servida desde la caché: también cuenta para la latencia que ve el usuario (pestaña Estado).
				$this->suggestions->record_cached( EngineTiming::OUTGOING, hrtime( true ) - $started );
			}
			$links = SuggestionCache::remember( 'links', $post_id, fn(): array => $this->suggestions->current_links( $post_id ) );
		} else {
			$found = $this->suggestions->outgoing( $post_id, array(), null, null, null, null, $links );
		}//end if
		$links ??= $this->suggestions->current_links( $post_id, $content, $title );

		// Solo se proponen frases donde el editor puede poner el enlace (docs/06 §2): el motor lee más
		// contenedores que los bloques de texto admitidos y una sugerencia imposible volvería siempre.
		$found = $this->insertable( null !== $content ? $content : $post->post_content, $post_id, $found );
		// Las alternativas no repiten el ancla de otra sugerencia que se muestra (docs/04 §5.3).
		$found = AlternativeAnchors::prune( $found, $this->analyzer( $post_id ) );

		// Sin sugerencias: solo se culpa al filtro Exigir si de verdad es la causa (con él no hay candidatas y sin él sí).
		$required = array() === $found && $this->suggestions->outgoing_limited( $post_id, $content, $title, $terms, $names ) ? $this->settings->filters_in_mode( Suggester::REQUIRE ) : array();

		$this->presenter->prime( $found );

		return new WP_REST_Response(
			array(
				'ready'         => true,
				'state'         => 'ok',
				'draft'         => null !== $content,
				'total'         => count( $found ),
				'items'         => array_map( array( $this->presenter, 'present' ), $found ),
				'post_id'       => $post_id,
				// Filtros en modo Exigir (type, category, tag) que son la causa de que no haya sugerencias; si no, vacío.
				'required'      => $required,
				// A quién enlaza ya la entrada, para el bloque «Enlaces actuales» (docs/07 §3.3).
				'current_links' => $this->presenter->current_links( $links ),
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
		$requests = array();
		foreach ( $found as $key => $suggestion ) {
			try {
				$requests[ $key ] = InsertRequest::from_sentence( $post_id, home_url( '/' ), $suggestion->sentence, $suggestion->offset, $suggestion->anchor );
			} catch ( InsertionException ) {
				continue;
			}
			// Las frases alternativas, por el mismo camino: una que no se puede enlazar no se ofrece (docs/07 §3.1).
			foreach ( $suggestion->alternatives as $n => $alternative ) {
				try {
					$requests[ $key . ':' . $n ] = InsertRequest::from_sentence( $post_id, home_url( '/' ), $alternative->sentence, $alternative->offset, $alternative->anchor );
				} catch ( InsertionException ) {
					continue;
				}
			}
		}

		$fits = $this->inserter->can_insert_many( $content, $post_id, $requests );
		$kept = array();
		foreach ( $found as $key => $suggestion ) {
			if ( ! ( $fits[ $key ] ?? false ) ) {
				continue;
			}

			$alternatives = array();
			foreach ( $suggestion->alternatives as $n => $alternative ) {
				if ( $fits[ $key . ':' . $n ] ?? false ) {
					$alternatives[] = $alternative;
				}
			}

			$kept[] = count( $alternatives ) === count( $suggestion->alternatives ) ? $suggestion : $suggestion->with_alternatives( $alternatives );
		}

		return $kept;
	}

	/**
	 * Deja en cada sugerencia entrante solo las frases alternativas que se podrían enlazar en el contenido guardado de
	 * su origen (la principal no se toca). Solo se carga el contenido de los orígenes que tienen alternativas.
	 *
	 * @param Suggestion[] $page Sugerencias de la página.
	 *
	 * @phpstan-param list<Suggestion> $page
	 *
	 * @return list<Suggestion>
	 */
	private function insertable_alternatives( array $page ): array {
		$out = array();
		foreach ( $page as $suggestion ) {
			$source = get_post( $suggestion->source );
			if ( array() === $suggestion->alternatives || ! $source instanceof WP_Post ) {
				$out[] = $suggestion;
				continue;
			}

			$requests = array();
			foreach ( $suggestion->alternatives as $n => $alternative ) {
				try {
					$requests[ $n ] = InsertRequest::from_sentence( $source->ID, home_url( '/' ), $alternative->sentence, $alternative->offset, $alternative->anchor );
				} catch ( InsertionException ) {
					continue;
				}
			}

			$fits  = $this->inserter->can_insert_many( $source->post_content, $source->ID, $requests );
			$kept  = array_values( array_filter( $suggestion->alternatives, static fn( int $n ): bool => $fits[ $n ] ?? false, ARRAY_FILTER_USE_KEY ) );
			$out[] = count( $kept ) === count( $suggestion->alternatives ) ? $suggestion : $suggestion->with_alternatives( $kept );
		}

		return $out;
	}

	/**
	 * GET /suggestions/inbound: las que superan el umbral hacia la entrada, paginadas, solo desde orígenes editables.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_inbound( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$page    = (int) $request['page'];
		// Sin `per_page` manda el ajuste del usuario (entrantes por página).
		$per_page = $request->has_param( 'per_page' ) ? (int) $request['per_page'] : (int) $this->settings->all()['inbound_per_page'];
		$post     = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}

		$state = $this->suggestions->state( $post, true, false );
		if ( 'ok' !== $state ) {
			return $this->empty( $state, $page, $per_page );
		}

		$started = hrtime( true );
		$hit     = true;
		$all     = SuggestionCache::remember(
			'in',
			$post_id,
			function () use ( $post_id, &$hit ): array {
				$hit = false;
				return $this->suggestions->incoming( $post_id );
			}
		);
		if ( $hit ) {
			$this->suggestions->record_cached( EngineTiming::INCOMING, hrtime( true ) - $started );
		}

		// El motor da la mejor frase de cada origen y se listan todas (07 §3); `can_insert` dice cuáles puede
		// insertar el usuario. Los permisos solo se miran en la página que se enseña, con sus entradas ya cargadas.
		$cut = $this->insertable_alternatives( array_slice( $all, ( $page - 1 ) * $per_page, $per_page ) );
		$this->presenter->prime( $cut );

		$total    = count( $all );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$required = array() === $all && $this->suggestions->incoming_limited( $post_id ) ? $this->settings->filters_in_mode( Suggester::REQUIRE ) : array();

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
				'required'    => $required,
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
	 * Cada enlace puede traer `ai_recommended`, `ai_anchor` y `ai_model` (F1-38). Hoy el panel de entrantes no usa la IA y la
	 * interfaz no los envía aquí (las salientes los mandan en el aviso de guardado); queda listo el servidor para cuando la
	 * IA reordene también las entrantes. La marca la declara el cliente: es informativa.
	 *
	 * @param WP_REST_Request $request Petición.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_links( WP_REST_Request $request ) {
		$this->forget_anchor_cache();
		$links = $request['links'];
		if ( ! is_array( $links ) || array() === $links ) {
			$links = array(
				array(
					'post_id'        => $request['post_id'],
					'target_id'      => $request['target_id'],
					'sentence'       => $request['sentence'],
					'offset'         => $request['offset'],
					'anchor'         => $request['anchor'],
					'block_path'     => $request['block_path'],
					'adjusted'       => $request['adjusted'],
					'ai_recommended' => $request['ai_recommended'],
					'ai_anchor'      => $request['ai_anchor'],
					'ai_model'       => $request['ai_model'],
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

			// Un ancla ajustada por el usuario pasa otra vez por los descartes de docs/04 §5.3 y por las palabras ignoradas;
			// la del motor ya los pasó. El servidor no puede saber qué ancla venía del motor, así que sin `adjusted` no aplica esos
			// descartes (la IA o las reglas proponen otras anclas); lo que sí exige siempre es que el tramo sea literal y que
			// empiece y acabe en límite de palabra.
			$problem = ! empty( $link['adjusted'] )
				? $this->check_anchor( $post_id, (string) $link['sentence'], (int) $link['offset'], (string) $link['anchor'] )->errors
				: ( AnchorCheck::cuts_word( (string) $link['sentence'], (int) $link['offset'], (string) $link['anchor'] ) ? array( AnchorCheck::NOT_WORD_BOUNDARY ) : array() );
			if ( array() !== $problem ) {
				$results[ $index ] = $this->failure( $base, new InsertionException( InsertionException::BAD_ANCHOR, AnchorMessages::text( $problem[0] ) ) );
				continue;
			}

			$path = isset( $link['block_path'] ) && is_string( $link['block_path'] ) && '' !== $link['block_path'] ? $link['block_path'] : null;
			try {
				$request = InsertRequest::from_sentence( $post_id, $url, (string) $link['sentence'], (int) $link['offset'], (string) $link['anchor'], array(), $path, null, false, AiOrigin::from_input( $link ) );
			} catch ( InsertionException $e ) {
				$results[ $index ] = $this->failure( $base, $e );
				continue;
			}

			$groups[ $post_id ][ $index ] = array( $request, $base, ! empty( $link['adjusted'] ), (string) $link['anchor'] );
		}//end foreach

		foreach ( $groups as $post_id => $items ) {
			// Una misma ancla (por raíz) no lleva a dos destinos desde un origen (docs/04 §5.3). Se exige solo a las anclas
			// ajustadas por el usuario, como los descartes de §5.3: las de la IA, las reglas o el Informe no cambian. Un
			// enlace que choca con otro del mismo lote espera a ver si ese se escribe; si el otro falla, se reintenta.
			// Sale con `break`: cada vuelta escribe al menos un enlace.
			do {
				$now    = array();
				$later  = array();
				$claims = array();
				foreach ( $items as $index => $item ) {
					[ $request, $base, $adjusted, $anchor ] = $item;
					if ( $adjusted ) {
						if ( $this->goes_elsewhere( $post_id, $base['target_id'], $anchor ) ) {
							$results[ $index ] = $this->failure( $base, $this->anchor_taken() );
							continue;
						}
						$key = $this->analyzer( $post_id )->phrase_key( $anchor );
						if ( isset( $claims[ $key ] ) && $claims[ $key ][0] !== $base['target_id'] ) {
							$later[ $index ] = $item;
							continue;
						}
						$claims[ $key ] = array( $base['target_id'], $index );
					}
					$now[ $index ] = $item;
				}

				$failed = $this->write_group( $now, $batch, $results );

				if ( array() === $later ) {
					break;
				}
				// Si nadie de los que frenaron a los demás dejó de escribirse, no hay más que esperar.
				$freed = array_filter( $claims, static fn( array $claim ): bool => isset( $failed[ $claim[1] ] ) );
				if ( array() === $freed ) {
					foreach ( $later as $index => $item ) {
						$results[ $index ] = $this->failure( $item[1], $this->anchor_taken() );
					}
					break;
				}
				$items = $later;
			} while ( true );
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
	 * Comprobación completa de un ancla ajustada: los descartes de docs/04 §5.3, las palabras ignoradas de los ajustes y,
	 * si se conoce el destino, que la ancla (por raíz) no lleve ya a otro destino desde este origen, según el grafo y las
	 * anclas que el panel ya ha elegido. El analizador de cada entrada y los patrones de palabras ignoradas se calculan
	 * una sola vez por petición.
	 *
	 * @param int    $post_id   Entrada donde irá el enlace.
	 * @param string $sentence  Frase.
	 * @param int    $offset    Byte donde empieza el ancla.
	 * @param string $anchor    Ancla.
	 * @param int    $target_id Destino del enlace; 0 = no se comprueba la unicidad.
	 * @param array  $taken     Anclas ya elegidas en el panel para otros destinos: `{anchor, target_id}`.
	 *
	 * @phpstan-param list<array<string, mixed>> $taken
	 */
	private function check_anchor( int $post_id, string $sentence, int $offset, string $anchor, int $target_id = 0, array $taken = array() ): AnchorCheck {
		$analyzer = $this->analyzer( $post_id );
		if ( null === $this->ignored ) {
			$this->ignored = IgnoredWords::patterns( $this->settings->all()['ignored_words'] );
		}

		$check = AnchorCheck::of( $analyzer, $sentence, $offset, $anchor );
		if ( ! $check->valid() ) {
			return $check;
		}
		if ( IgnoredWords::matches( $this->ignored, $anchor ) ) {
			return new AnchorCheck( array( AnchorCheck::IGNORED_WORD ), $check->warnings );
		}
		if ( $target_id > 0 && $this->goes_elsewhere( $post_id, $target_id, $anchor, $taken ) ) {
			return new AnchorCheck( array( AnchorCheck::ANCHOR_TAKEN ), $check->warnings );
		}

		return $check;
	}

	/**
	 * Analizador del idioma de una entrada, calculado una vez por petición.
	 *
	 * @param int $post_id Entrada.
	 */
	private function analyzer( int $post_id ): Analyzer {
		if ( ! isset( $this->analyzers[ $post_id ] ) ) {
			$this->analyzers[ $post_id ] = Analyzer::for_language( $this->language->for_post( $post_id ) );
		}

		return $this->analyzers[ $post_id ];
	}

	/**
	 * Si la ancla (por raíz, con la clave del motor) ya lleva desde esa entrada a un destino distinto: según el grafo
	 * (que se reindexa al escribir; una edición a mano aún no reindexada no se ve) o según otras anclas ya elegidas.
	 *
	 * @param int    $post_id   Origen.
	 * @param int    $target_id Destino del enlace que se quiere poner.
	 * @param string $anchor    Ancla.
	 * @param array  $extra     Otras anclas ya elegidas: `{anchor, target_id}`.
	 *
	 * @phpstan-param list<array<string, mixed>> $extra
	 */
	private function goes_elsewhere( int $post_id, int $target_id, string $anchor, array $extra = array() ): bool {
		$analyzer = $this->analyzer( $post_id );
		$key      = $analyzer->phrase_key( $anchor );

		foreach ( $this->graph->links_from( array( $post_id ) )[ $post_id ] ?? array() as [ $target, $text ] ) {
			if ( null !== $target && $target !== $target_id && $analyzer->phrase_key( $text ) === $key ) {
				return true;
			}
		}
		foreach ( $extra as $other ) {
			if ( (int) ( $other['target_id'] ?? 0 ) !== $target_id && $analyzer->phrase_key( (string) ( $other['anchor'] ?? '' ) ) === $key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Cada petición empieza sin lo calculado por la anterior (los ajustes pueden haber cambiado).
	 */
	private function forget_anchor_cache(): void {
		$this->analyzers = array();
		$this->ignored   = null;
	}

	/**
	 * La negativa de una ancla que ya lleva a otro destino desde el mismo origen.
	 */
	private function anchor_taken(): InsertionException {
		return new InsertionException( InsertionException::BAD_ANCHOR, AnchorMessages::text( AnchorCheck::ANCHOR_TAKEN ) );
	}

	/**
	 * Escribe los enlaces de una entrada con una sola escritura y anota el resultado de cada uno.
	 *
	 * @param array  $items   Índice → [petición, datos, ajustada, ancla].
	 * @param string $batch   Lote.
	 * @param array  $results Resultados por índice (se rellena).
	 *
	 * @phpstan-param array<int, array{0: InsertRequest, 1: array<string, mixed>, 2: bool, 3: string}> $items
	 * @phpstan-param array<int, array<string, mixed>> $results
	 *
	 * @return array<int, true> Índices que no se escribieron.
	 */
	private function write_group( array $items, string $batch, array &$results ): array {
		if ( array() === $items ) {
			return array();
		}

		$requests = array_column( array_values( $items ), 0 );
		$indexes  = array_keys( $items );
		$failed   = array();

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
			if ( $result instanceof InsertionException ) {
				$failed[ $index ]  = true;
				$results[ $index ] = $this->failure( $base, $result );
				continue;
			}

			$results[ $index ] = array_merge(
				$base,
				array(
					'status'    => 'inserted',
					'change_id' => $result->change_id,
					'path'      => $result->path,
				)
			);
		}

		return $failed;
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
		return $this->destination->resolve( $post_id, $target_id );
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

		return $post instanceof WP_Post ? PostTitle::of( $post ) : null;
	}

	/**
	 * Error 404.
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'magiclinking_not_found', __( 'That entry does not exist.', 'magic-linking' ), array( 'status' => 404 ) );
	}
}
