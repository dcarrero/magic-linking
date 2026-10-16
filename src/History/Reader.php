<?php
/**
 * Lectura del historial para la pantalla y la API REST.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\History;

use MagicLinking\Content\OpenTag;
use Generator;
use WP_Post;

/**
 * Grupos (lotes) del historial con lo que necesita la pantalla *Historial* (docs/07), sin tope de número
 * de acciones: se pagina con un cursor (el último lote mostrado) porque quién ve qué depende de los
 * permisos de cada entrada y no se puede contar en SQL.
 *
 * Un usuario ve un grupo si puede editar **todas** sus entradas (docs/03 §6): es lo mismo que se exige para
 * deshacerlo. De una entrada que ya no existe decide `edit_others_posts`.
 */
final class Reader {

	/**
	 * Cambios que se leen de golpe al exportar.
	 */
	public const EXPORT_PAGE = 500;

	/**
	 * Lotes que se leen de la base de datos por vuelta al buscar los que el usuario puede ver.
	 */
	private const CHUNK = 40;

	/**
	 * Vueltas como máximo por petición (acota el trabajo de un usuario que ve pocos grupos).
	 */
	private const MAX_ROUNDS = 10;

	/**
	 * Decisiones de permiso ya tomadas en esta petición: usuario → entrada → sí o no.
	 *
	 * @var array<int, array<int, bool>>
	 */
	private array $allowed = array();

	/**
	 * Títulos de destino ya resueltos en esta petición: dirección → título (null = sin título que enseñar).
	 *
	 * @var array<string, string|null>
	 */
	private array $targets = array();

	/**
	 * Cambios por entrada que trae cada entrada en el listado (el resto se pide aparte).
	 */
	public const FIRST_CHANGES = 10;

	/**
	 * Constructor.
	 *
	 * @param ChangeRepository $changes Historial.
	 */
	public function __construct( private ChangeRepository $changes ) {
	}

	/**
	 * Grupos visibles para un usuario, del más reciente al más antiguo.
	 *
	 * @param int         $user_id  Quien mira.
	 * @param string|null $before   Cursor: solo los anteriores a este lote.
	 * @param int         $per_page Grupos por página.
	 *
	 * @return array{items: list<array<string, mixed>>, next: string|null} `next` es el cursor de la página siguiente, o null si no hay más.
	 */
	public function groups( int $user_id, ?string $before, int $per_page ): array {
		$items  = array();
		$cursor = $before;

		for ( $round = 0; $round < self::MAX_ROUNDS; $round++ ) {
			$ids = $this->changes->batch_ids( $cursor, self::CHUNK );
			if ( array() === $ids ) {
				return array(
					'items' => $items,
					'next'  => null,
				);
			}

			$rows = $this->changes->inserts_of( $ids );
			$this->prime( $rows );

			foreach ( $ids as $id ) {
				$cursor = $id;
				$slots  = Slots::collapse( $rows[ $id ] ?? array() );
				if ( array() === $slots || ! $this->can_see( $user_id, $slots ) ) {
					continue;
				}

				$items[] = $this->summary( $id, $slots, $rows[ $id ] );

				if ( count( $items ) > $per_page ) {
					array_pop( $items );

					return array(
						'items' => $items,
						'next'  => (string) $items[ count( $items ) - 1 ]['batch_id'],
					);
				}
			}

			if ( count( $ids ) < self::CHUNK ) {
				return array(
					'items' => $items,
					'next'  => null,
				);
			}
		}//end for

		// Se agotaron las vueltas sin llegar al final: se sigue desde el último lote mirado.
		return array(
			'items' => $items,
			'next'  => $cursor,
		);
	}

	/**
	 * Un grupo, si existe y el usuario puede verlo.
	 *
	 * @param string $batch_id Lote.
	 * @param int    $user_id  Quien mira.
	 *
	 * @return array<string, mixed>|null
	 */
	public function group( string $batch_id, int $user_id ): ?array {
		$rows = $this->changes->inserts_of( array( $batch_id ) );
		if ( ! isset( $rows[ $batch_id ] ) ) {
			return null;
		}

		$this->prime( $rows );
		$slots = Slots::collapse( $rows[ $batch_id ] );

		return $this->can_see( $user_id, $slots ) ? $this->summary( $batch_id, $slots, $rows[ $batch_id ] ) : null;
	}

	/**
	 * Entradas de un lote que el usuario no puede editar (lo que impide deshacerlo).
	 *
	 * @param string $batch_id Lote.
	 * @param int    $user_id  Quien lo pide.
	 *
	 * @return list<int> IDs; vacío si puede con todas.
	 */
	public function not_editable( string $batch_id, int $user_id ): array {
		$rows = $this->changes->inserts_of( array( $batch_id ) );
		$this->prime( $rows );

		$denied = array();
		foreach ( $rows[ $batch_id ] ?? array() as $row ) {
			if ( ! $this->can_edit( $user_id, $row['post_id'] ) ) {
				$denied[ $row['post_id'] ] = $row['post_id'];
			}
		}

		return array_values( $denied );
	}

	/**
	 * Entradas que toca un lote.
	 *
	 * @param string $batch_id Lote.
	 *
	 * @return list<int>
	 */
	public function posts_of_batch( string $batch_id ): array {
		return array_values( array_unique( array_column( $this->changes->inserts_of( array( $batch_id ) )[ $batch_id ] ?? array(), 'post_id' ) ) );
	}

	/**
	 * Si el usuario puede editar una entrada (si ya no existe, decide `edit_others_posts`; el 0 es el sistema y puede todo).
	 *
	 * @param int $user_id Quien pregunta.
	 * @param int $post_id Entrada.
	 */
	public function can_edit( int $user_id, int $post_id ): bool {
		// El usuario 0 es el sistema (WP-CLI sin `--user`): no se comprueban capacidades, como en PostWriter.
		if ( 0 === $user_id ) {
			return true;
		}

		if ( ! isset( $this->allowed[ $user_id ][ $post_id ] ) ) {
			$this->allowed[ $user_id ][ $post_id ] = get_post( $post_id ) instanceof WP_Post
				? user_can( $user_id, 'edit_post', $post_id )
				: user_can( $user_id, 'edit_others_posts' );
		}

		return $this->allowed[ $user_id ][ $post_id ];
	}

	/**
	 * Enlaces de un grupo, uno por hueco, paginados.
	 *
	 * @param string $batch_id Lote.
	 * @param int    $page     Página (desde 1).
	 * @param int    $per_page Elementos por página.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function changes_of( string $batch_id, int $page, int $per_page ): array {
		$rows   = $this->changes->inserts_of( array( $batch_id ) )[ $batch_id ] ?? array();
		$origin = self::origin_of( $rows );
		$slots  = Slots::collapse( $rows );
		$total  = count( $slots );
		$slice  = array_slice( $slots, max( 0, $page - 1 ) * $per_page, $per_page );
		$full   = $this->changes->get_many( array_column( $slice, 'id' ) );

		_prime_post_caches( array_column( $slice, 'post_id' ), false, false );

		$items = array();
		foreach ( $slice as $slot ) {
			$change = $full[ $slot['id'] ] ?? null;
			if ( null === $change ) {
				continue;
			}

			$post = get_post( $change['post_id'] );
			$edit = $post instanceof WP_Post && current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID, 'raw' ) : null;
			$view = self::describe( $change );

			$items[] = array(
				'id'           => $change['id'],
				'post_id'      => $change['post_id'],
				'post_title'   => $post instanceof WP_Post ? get_the_title( $post ) : null,
				'edit_url'     => is_string( $edit ) ? $edit : null,
				'kind'         => $change['action'],
				'origin'       => $origin,
				'ai'           => self::ai_of( $change ),
				'anchor'       => $view['anchor'],
				'url'          => $view['url'],
				'previous_url' => $view['previous_url'],
				'state'        => null === $change['undone_at'] ? 'active' : 'undone',
				'created_at'   => (string) mysql_to_rfc3339( $change['created_at'] ),
				'undone_at'    => null === $change['undone_at'] ? null : (string) mysql_to_rfc3339( $change['undone_at'] ),
			);
		}//end foreach

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Entradas con sus cambios, paginadas por entrada y resueltas en SQL (F1-36, D-59). Cada entrada lleva sus
	 * recuentos y sus {@see self::FIRST_CHANGES} cambios más recientes; el resto, con {@see self::post_changes()}.
	 *
	 * Coherente con `edit_post` (D-59): la consulta deja pasar las entradas de los tipos en los que el usuario tiene
	 * `edit_others_<tipo>` y, del resto, las suyas (así el total y las páginas cuadran para autores, coautores de
	 * otro tipo y roles a medida). Después se comprueba `edit_post` entrada por entrada: si una regla fina (otro
	 * plugin, estado de la entrada) la niega, se omite de la página y el total queda como cota superior.
	 *
	 * @param int   $user_id  Quien mira.
	 * @param array $filters  `status` (`active`, `undone`), `origin` (`plugin`, `editor`), `ai` (solo los que recomendó la IA), `search` y `post_id` (solo esa entrada).
	 * @param int   $page     Página (desde 1).
	 * @param int   $per_page Entradas por página.
	 *
	 * @phpstan-param array{status?: string, origin?: string, ai?: bool, search?: string, post_id?: int} $filters
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function post_groups( int $user_id, array $filters, int $page, int $per_page ): array {
		$result = $this->changes->post_groups( $filters + array( 'restrict' => $this->restriction( $user_id ) ), $page, $per_page );

		$post_ids = array_column( $result['items'], 'post_id' );
		if ( array() !== $post_ids ) {
			_prime_post_caches( $post_ids, false, false );
		}

		$visible = array_values( array_filter( $result['items'], fn( array $group ): bool => $this->can_edit( $user_id, $group['post_id'] ) ) );
		$origin  = (string) ( $filters['origin'] ?? '' );
		$recent  = $this->changes->recent_ids_of_posts( array_column( $visible, 'post_id' ), self::FIRST_CHANGES, $origin, ! empty( $filters['ai'] ) );
		$full    = $this->changes->get_many( array_merge( array(), ...array_values( $recent ) ) );
		$context = $this->change_context( $full );

		$items = array();
		foreach ( $visible as $group ) {
			$post_id = $group['post_id'];
			$post    = get_post( $post_id );
			$shown   = array();
			foreach ( $recent[ $post_id ] ?? array() as $id ) {
				if ( isset( $full[ $id ] ) ) {
					$shown[] = $this->present_change( $full[ $id ], $context );
				}
			}

			$items[] = array(
				'post_id'    => $post_id,
				'post_title' => $post instanceof WP_Post ? get_the_title( $post ) : null,
				'edit_url'   => $post instanceof WP_Post ? $this->edit_link( $post ) : null,
				'view_url'   => $post instanceof WP_Post && 'publish' === $post->post_status ? (string) get_permalink( $post ) : null,
				'total'      => $group['total'],
				'active'     => $group['active'],
				'undone'     => $group['total'] - $group['active'],
				'last_at'    => (string) mysql_to_rfc3339( $group['last_at'] ),
				'changes'    => $shown,
			);
		}//end foreach

		return array(
			'items' => $items,
			'total' => $result['total'],
		);
	}

	/**
	 * Una entrada con sus recuentos y sus cambios más recientes, si el usuario puede verla y tiene cambios.
	 *
	 * @param int    $user_id Quien mira.
	 * @param int    $post_id Entrada.
	 * @param string $origin  Solo cambios de este origen (`plugin`, `editor`) o vacío.
	 * @param bool   $ai      Solo los que recomendó la IA.
	 *
	 * @return array<string, mixed>|null
	 */
	public function post_group( int $user_id, int $post_id, string $origin = '', bool $ai = false ): ?array {
		return $this->post_groups( $user_id, array( 'post_id' => $post_id ) + ( '' === $origin ? array() : array( 'origin' => $origin ) ) + ( $ai ? array( 'ai' => true ) : array() ), 1, 1 )['items'][0] ?? null;
	}

	/**
	 * Cambios para exportar, uno por fila y sin tope, de página en página para no cargar todo en memoria (F1-38).
	 * Valores sin traducir, pensados como datos: el origen va en inglés, las fechas en ISO 8601 en la zona del sitio.
	 * Solo ve lo que el usuario puede editar, como la pantalla.
	 *
	 * @param int   $user_id Quien exporta (0 = el sistema).
	 * @param array $filters `status` (`active`, `undone`), `origin` (`plugin`, `editor`), `ai` y `search`.
	 *
	 * @phpstan-param array{status?: string, origin?: string, ai?: bool, search?: string} $filters
	 *
	 * @return Generator<int, array<int, string|int>>
	 */
	public function export_rows( int $user_id, array $filters ): Generator {
		$filters += array( 'restrict' => $this->restriction( $user_id ) );
		$after    = 0;

		while ( true ) {
			$ids = $this->changes->export_ids( $filters, $after, self::EXPORT_PAGE );
			if ( array() === $ids ) {
				return;
			}
			$after = max( $ids );
			$full  = $this->changes->get_many( $ids );
			_prime_post_caches( array_unique( array_column( $full, 'post_id' ) ), false, false );
			cache_users( array_map( 'intval', array_unique( array_column( $full, 'user_id' ) ) ) );

			foreach ( $ids as $id ) {
				$change = $full[ $id ] ?? null;
				if ( null === $change || ! $this->can_edit( $user_id, $change['post_id'] ) ) {
					continue;
				}

				$view = self::describe( $change );
				$post = get_post( $change['post_id'] );
				$user = $change['user_id'] > 0 ? get_userdata( $change['user_id'] ) : false;

				yield array(
					$change['id'],
					(string) get_date_from_gmt( $change['created_at'], DATE_ATOM ),
					$change['user_id'] > 0 ? ( false === $user ? '' : $user->user_login ) : 'system',
					$change['action'],
					null === $change['undone_at'] ? 'active' : 'undone',
					$change['post_id'],
					$post instanceof WP_Post ? $post->post_title : '',
					'' !== $view['url'] ? $view['url'] : (string) $view['previous_url'],
					$view['anchor'],
					self::export_origin( $change ),
					$change['batch_id'],
					$change['ai_recommended'] ? 1 : 0,
					$change['ai_anchor'] ? 1 : 0,
					$change['ai_model'],
				);
			}//end foreach
		}//end while
	}

	/**
	 * Origen de un cambio con la etiqueta de la pantalla, sin traducir.
	 *
	 * @param array $change Fila del historial.
	 *
	 * @phpstan-param array{origin: string, action: string} $change
	 */
	private static function export_origin( array $change ): string {
		if ( ChangeRepository::ORIGIN_EDITOR === self::origin_of( array( $change ) ) ) {
			return 'Editor panel';
		}

		return ChangeRepository::INSERT === $change['action'] ? "Another entry's panel" : 'Broken links';
	}

	/**
	 * Límite en SQL de las entradas que ve un usuario: null si las ve todas.
	 *
	 * @param int $user_id Quien mira (0 = el sistema).
	 *
	 * @return array{types: list<string>, author: int, deleted: bool}|null
	 */
	private function restriction( int $user_id ): ?array {
		if ( 0 === $user_id ) {
			return null;
		}

		$types = array();
		$all   = true;
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( user_can( $user_id, $type->cap->edit_others_posts ) ) {
				$types[] = $type->name;
			} else {
				$all = false;
			}
		}

		return $all ? null : array(
			'types'   => $types,
			'author'  => $user_id,
			'deleted' => user_can( $user_id, 'edit_others_posts' ),
		);
	}

	/**
	 * Una página de los cambios de una entrada, del más reciente al más antiguo.
	 *
	 * @param int    $user_id  Quien mira.
	 * @param int    $post_id  Entrada.
	 * @param int    $before   Cursor: solo los cambios con id menor que este (0 = desde el principio).
	 * @param int    $per_page Cambios por página.
	 * @param string $origin   Origen (`plugin`, `editor`) o vacío.
	 * @param bool   $ai       Solo los que recomendó la IA.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}|null Null si no la puede ver.
	 */
	public function post_changes( int $user_id, int $post_id, int $before, int $per_page, string $origin = '', bool $ai = false ): ?array {
		if ( ! $this->can_edit( $user_id, $post_id ) ) {
			return null;
		}

		$result  = $this->changes->post_ids_page( $post_id, $before, $per_page, $origin, $ai );
		$full    = $this->changes->get_many( $result['ids'] );
		$context = $this->change_context( $full );

		$items = array();
		foreach ( $result['ids'] as $id ) {
			if ( isset( $full[ $id ] ) ) {
				$items[] = $this->present_change( $full[ $id ], $context );
			}
		}

		return array(
			'items' => $items,
			'total' => $result['total'],
		);
	}

	/**
	 * Ids de los cambios de una entrada que se pueden deshacer (puestos) o rehacer (deshechos), con el tramo contado una vez.
	 *
	 * @param int    $post_id Entrada.
	 * @param bool   $undone  true = los deshechos.
	 * @param string $origin  Solo de este origen o vacío.
	 * @param bool   $ai      Solo los que recomendó la IA.
	 *
	 * @return list<int> Del más reciente al más antiguo.
	 */
	public function pending_of_post( int $post_id, bool $undone, string $origin = '', bool $ai = false ): array {
		return $this->changes->pending_ids_of_post( $post_id, $undone, $origin, $ai );
	}

	/**
	 * Lo que hace falta de fuera para presentar un conjunto de cambios: de cuántas entradas es cada lote y las
	 * entradas de destino de las direcciones (una resolución por dirección distinta).
	 *
	 * @param array $full Cambios con su HTML, por id.
	 *
	 * @phpstan-param array<int, array{id: int, batch_id: string, post_id: int, action: string, before_html: string, after_html: string, user_id: int, created_at: string, undone_at: string|null, origin: string, ai_recommended: bool, ai_anchor: bool, ai_model: string}> $full
	 *
	 * @return array{batches: array<string, int>}
	 */
	private function change_context( array $full ): array {
		$users = array_unique( array_column( $full, 'user_id' ) );
		if ( array() !== $users ) {
			cache_users( array_map( 'intval', $users ) );
		}

		$urls = array();
		foreach ( $full as $change ) {
			$view = self::describe( $change );
			$url  = '' !== $view['url'] ? $view['url'] : (string) $view['previous_url'];
			if ( '' !== $url ) {
				$urls[ $url ] = $url;
			}
		}
		$this->resolve_targets( array_values( $urls ) );

		return array( 'batches' => $this->changes->batch_post_counts( array_values( array_unique( array_column( $full, 'batch_id' ) ) ) ) );
	}

	/**
	 * Un cambio listo para la API, con su destino resuelto.
	 *
	 * @param array $change  Fila del historial con HTML.
	 * @param array $context Ver {@see self::change_context()}.
	 *
	 * @phpstan-param array{id: int, batch_id: string, post_id: int, action: string, before_html: string, after_html: string, user_id: int, created_at: string, undone_at: string|null, origin: string, ai_recommended: bool, ai_anchor: bool, ai_model: string} $change
	 * @phpstan-param array{batches: array<string, int>} $context
	 *
	 * @return array<string, mixed>
	 */
	private function present_change( array $change, array $context ): array {
		$view   = self::describe( $change );
		$shown  = '' !== $view['url'] ? $view['url'] : (string) $view['previous_url'];
		$target = $this->target_of( $shown );

		return array(
			'id'           => $change['id'],
			'post_id'      => $change['post_id'],
			'batch_id'     => $change['batch_id'],
			'batch_posts'  => $context['batches'][ $change['batch_id'] ] ?? 1,
			'kind'         => $change['action'],
			'origin'       => '' === $change['origin'] ? ChangeRepository::ORIGIN_PLUGIN : $change['origin'],
			'ai'           => self::ai_of( $change ),
			'anchor'       => $view['anchor'],
			'url'          => $view['url'],
			'previous_url' => $view['previous_url'],
			'target_title' => $target,
			'user_id'      => $change['user_id'],
			'user_name'    => self::user_name( $change['user_id'] ),
			'state'        => null === $change['undone_at'] ? 'active' : 'undone',
			'created_at'   => (string) mysql_to_rfc3339( $change['created_at'] ),
			'undone_at'    => null === $change['undone_at'] ? null : (string) mysql_to_rfc3339( $change['undone_at'] ),
		);
	}

	/**
	 * Título de la entrada a la que lleva una dirección interna ya resuelta con {@see self::resolve_targets()}; null si
	 * es externa, no se reconoce o el usuario no puede leerla.
	 *
	 * @param string $url Dirección.
	 */
	private function target_of( string $url ): ?string {
		return $this->targets[ $url ] ?? null;
	}

	/**
	 * Resuelve de una vez las direcciones internas de una página a los títulos de sus entradas: una consulta por
	 * `post_name` (el último tramo de la ruta) en vez de `url_to_postid()` por dirección. Si varias entradas
	 * comparten ese nombre se elige la que tiene exactamente ese enlace permanente; `?p=` y `?page_id=` se leen directos.
	 *
	 * @param array $urls Direcciones.
	 *
	 * @phpstan-param list<string> $urls
	 */
	private function resolve_targets( array $urls ): void {
		global $wpdb;

		$slugs = array();
		$ids   = array();
		foreach ( $urls as $url ) {
			if ( array_key_exists( $url, $this->targets ) ) {
				continue;
			}
			$this->targets[ $url ] = null;
			if ( ! $this->is_internal( $url ) ) {
				continue;
			}

			$query = array();
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			foreach ( array( 'p', 'page_id' ) as $key ) {
				if ( isset( $query[ $key ] ) && is_string( $query[ $key ] ) && ctype_digit( $query[ $key ] ) ) {
					$ids[ $url ] = (int) $query[ $key ];
				}
			}
			$segments = array_values( array_filter( explode( '/', (string) wp_parse_url( $url, PHP_URL_PATH ) ), static fn( string $part ): bool => '' !== $part ) );
			if ( ! isset( $ids[ $url ] ) && array() !== $segments ) {
				$slugs[ $url ] = sanitize_title( rawurldecode( (string) end( $segments ) ) );
			}
		}//end foreach

		$by_name = array();
		if ( array() !== $slugs ) {
			$names        = array_values( array_unique( $slugs ) );
			$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
			$found        = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','nav_menu_item','attachment') AND post_name IN ({$placeholders})", ...$names ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Una consulta por página; lista de marcadores generada con array_fill().
			foreach ( $found as $row ) {
				$by_name[ (string) $row['post_name'] ][] = (int) $row['ID'];
			}
		}

		$all = array_merge( $ids, ...array_values( $by_name ) );
		if ( array() !== $all ) {
			_prime_post_caches( array_values( array_unique( $all ) ), true, false );
		}

		foreach ( $slugs as $url => $slug ) {
			// Solo vale la entrada cuyo enlace permanente tiene exactamente esa ruta (descarta archivos con el mismo nombre).
			$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			foreach ( $by_name[ $slug ] ?? array() as $candidate ) {
				if ( trim( (string) wp_parse_url( (string) get_permalink( $candidate ), PHP_URL_PATH ), '/' ) === $path ) {
					$ids[ $url ] = $candidate;
					break;
				}
			}
		}

		foreach ( $ids as $url => $id ) {
			$post = get_post( $id );
			if ( $post instanceof WP_Post && 'publish' === $post->post_status && current_user_can( 'read_post', $id ) ) {
				$this->targets[ $url ] = get_the_title( $post );
			}
		}
	}

	/**
	 * Si una dirección es de este sitio.
	 *
	 * @param string $url Dirección.
	 */
	private function is_internal( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$own  = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && is_string( $own ) && strtolower( $host ) === strtolower( $own );
	}

	/**
	 * Enlace para editar una entrada, si el usuario actual puede.
	 *
	 * @param WP_Post $post Entrada.
	 */
	private function edit_link( WP_Post $post ): ?string {
		$edit = current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID, 'raw' ) : null;

		return is_string( $edit ) ? $edit : null;
	}

	/**
	 * Qué enlace tocó un cambio: su texto, la dirección a la que apunta ahora y, si se cambió, la anterior.
	 *
	 * @param array $change Fila del historial.
	 *
	 * @phpstan-param array{action: string, before_html: string, after_html: string} $change
	 *
	 * @return array{anchor: string, url: string, previous_url: string|null}
	 */
	private static function describe( array $change ): array {
		$none = array(
			'anchor'       => '',
			'url'          => '',
			'previous_url' => null,
		);

		if ( ChangeRepository::RETARGET === $change['action'] ) {
			$tags = Undo::retargeted_tags( $change['before_html'], $change['after_html'] );

			return null === $tags ? $none : array(
				'anchor'       => self::plain( $tags['inner'] ),
				'url'          => OpenTag::href( $tags['new'] ),
				'previous_url' => OpenTag::href( $tags['old'] ),
			);
		}

		if ( ChangeRepository::UNLINK === $change['action'] ) {
			$link = Undo::removed_link( $change['before_html'], $change['after_html'] );

			return null === $link ? $none : array(
				'anchor'       => self::plain( $link['inner'] ),
				'url'          => '',
				'previous_url' => OpenTag::href( $link['open'] ),
			);
		}

		$link = Undo::inserted_link( $change['before_html'], $change['after_html'] );

		return null === $link ? $none : array(
			'anchor'       => self::plain( $link['inner'] ),
			'url'          => OpenTag::href( $link['open'] ),
			'previous_url' => null,
		);
	}

	/**
	 * Ids de los cambios de un grupo en un estado: los que hay que deshacer (activos, del último al primero)
	 * o rehacer (deshechos, del primero al último).
	 *
	 * @param string $batch_id Lote.
	 * @param bool   $undone   true = los deshechos (para rehacer).
	 *
	 * @return list<int>
	 */
	public function pending( string $batch_id, bool $undone ): array {
		return Slots::ordered_ids( $this->changes->inserts_of( array( $batch_id ) )[ $batch_id ] ?? array(), $undone );
	}

	/**
	 * Si el usuario puede editar todas las entradas de un grupo.
	 *
	 * @param int                                                                                                                                   $user_id Quien mira.
	 * @param list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string, action: string, ai?: bool}> $slots   Huecos.
	 */
	private function can_see( int $user_id, array $slots ): bool {
		foreach ( $slots as $slot ) {
			if ( ! $this->can_edit( $user_id, $slot['post_id'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Carga de una vez las entradas de varios lotes (una consulta en vez de una por entrada).
	 *
	 * @param array<string, list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string, action: string, ai?: bool}>> $rows Inserciones por lote.
	 */
	private function prime( array $rows ): void {
		$ids = array();
		foreach ( $rows as $batch ) {
			foreach ( $batch as $row ) {
				$ids[ $row['post_id'] ] = $row['post_id'];
			}
		}

		if ( array() !== $ids ) {
			_prime_post_caches( array_values( $ids ), false, false );
		}
	}

	/**
	 * Resumen de un grupo.
	 *
	 * @param string                                                                                                                                $batch_id Lote.
	 * @param list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string, action: string, ai?: bool}> $slots    Huecos (ya juntos).
	 * @param list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string, action: string, ai?: bool}> $rows     Todas las inserciones.
	 *
	 * @return array<string, mixed>
	 */
	private function summary( string $batch_id, array $slots, array $rows ): array {
		$posts  = array();
		$undone = 0;
		$ai     = 0;
		foreach ( $slots as $slot ) {
			$posts[ $slot['post_id'] ] = true;
			if ( ! empty( $slot['ai'] ) ) {
				++$ai;
			}
			if ( null !== $slot['undone_at'] ) {
				++$undone;
			}
		}

		$titles = array();
		foreach ( array_slice( array_keys( $posts ), 0, 3 ) as $post_id ) {
			$post     = get_post( $post_id );
			$titles[] = $post instanceof WP_Post ? get_the_title( $post ) : '';
		}

		$first = $rows[0];
		$user  = $first['user_id'];

		return array(
			'batch_id'   => $batch_id,
			'kind'       => $first['action'],
			'created_at' => (string) mysql_to_rfc3339( $first['created_at'] ),
			'user_id'    => $user,
			'user_name'  => self::user_name( $user ),
			'origin'     => self::origin_of( $rows ),
			'links'      => count( $slots ),
			'ai_links'   => $ai,
			'active'     => count( $slots ) - $undone,
			'undone'     => $undone,
			'posts'      => count( $posts ),
			'titles'     => $titles,
		);
	}

	/**
	 * De dónde viene un lote: `editor` si el usuario puso los enlaces en el editor y el servidor solo los
	 * registró (F1-34, D-58); `plugin` si los escribió Magic Linking. Lo dice la primera fila (un lote es de un
	 * solo origen; rehacer añade filas al final).
	 *
	 * @param array $rows Inserciones del lote, en orden.
	 *
	 * @phpstan-param list<array{origin?: string}> $rows
	 */
	private static function origin_of( array $rows ): string {
		return ChangeRepository::ORIGIN_EDITOR === ( $rows[0]['origin'] ?? '' ) ? ChangeRepository::ORIGIN_EDITOR : ChangeRepository::ORIGIN_PLUGIN;
	}

	/**
	 * Lo que se sabe de la IA en un cambio (F1-38): null si no la recomendó; si no, si se usó su ancla y con qué modelo.
	 *
	 * @param array $change Fila del historial.
	 *
	 * @phpstan-param array{ai_recommended: bool, ai_anchor: bool, ai_model: string} $change
	 *
	 * @return array{anchor: bool, model: string}|null
	 */
	private static function ai_of( array $change ): ?array {
		$ai = AiOrigin::from_row( $change );

		return null === $ai
			? null
			: array(
				'anchor' => $ai->anchor,
				'model'  => $ai->model,
			);
	}

	/**
	 * Nombre para mostrar de quien hizo un cambio.
	 *
	 * @param int $user_id Usuario (0 = sistema).
	 */
	private static function user_name( int $user_id ): string {
		if ( 0 === $user_id ) {
			return __( 'Automatic', 'magic-linking' );
		}

		$user = get_userdata( $user_id );

		return false === $user ? __( 'Deleted user', 'magic-linking' ) : $user->display_name;
	}

	/**
	 * Texto de un fragmento de HTML.
	 *
	 * @param string $html HTML.
	 */
	private static function plain( string $html ): string {
		return mb_substr( trim( wp_specialchars_decode( wp_strip_all_tags( $html ), ENT_QUOTES ) ), 0, 255 );
	}
}
