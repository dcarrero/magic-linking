<?php
/**
 * Lectura del historial para la pantalla y la API REST.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\History;

use WP_HTML_Tag_Processor;
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
		$slots = Slots::collapse( $this->changes->inserts_of( array( $batch_id ) )[ $batch_id ] ?? array() );
		$total = count( $slots );
		$slice = array_slice( $slots, max( 0, $page - 1 ) * $per_page, $per_page );
		$full  = $this->changes->get_many( array_column( $slice, 'id' ) );

		_prime_post_caches( array_column( $slice, 'post_id' ), false, false );

		$items = array();
		foreach ( $slice as $slot ) {
			$change = $full[ $slot['id'] ] ?? null;
			if ( null === $change ) {
				continue;
			}

			$post = get_post( $change['post_id'] );
			$link = Undo::inserted_link( $change['before_html'], $change['after_html'] );
			$edit = $post instanceof WP_Post && current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID, 'raw' ) : null;

			$items[] = array(
				'id'         => $change['id'],
				'post_id'    => $change['post_id'],
				'post_title' => $post instanceof WP_Post ? get_the_title( $post ) : null,
				'edit_url'   => is_string( $edit ) ? $edit : null,
				'anchor'     => null === $link ? '' : self::plain( $link['inner'] ),
				'url'        => null === $link ? '' : self::href( $link['open'] ),
				'state'      => null === $change['undone_at'] ? 'active' : 'undone',
				'created_at' => (string) mysql_to_rfc3339( $change['created_at'] ),
				'undone_at'  => null === $change['undone_at'] ? null : (string) mysql_to_rfc3339( $change['undone_at'] ),
			);
		}//end foreach

		return array(
			'items' => $items,
			'total' => $total,
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
		$ids = array();
		foreach ( Slots::collapse( $this->changes->inserts_of( array( $batch_id ) )[ $batch_id ] ?? array() ) as $slot ) {
			if ( ( null !== $slot['undone_at'] ) === $undone ) {
				$ids[] = $slot['id'];
			}
		}

		return $undone ? $ids : array_reverse( $ids );
	}

	/**
	 * Si el usuario puede editar todas las entradas de un grupo.
	 *
	 * @param int                                                                                                        $user_id Quien mira.
	 * @param list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string}> $slots   Huecos.
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
	 * @param array<string, list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string}>> $rows Inserciones por lote.
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
	 * @param string                                                                                                     $batch_id Lote.
	 * @param list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string}> $slots    Huecos (ya juntos).
	 * @param list<array{id: int, post_id: int, user_id: int, created_at: string, undone_at: string|null, slot: string}> $rows     Todas las inserciones.
	 *
	 * @return array<string, mixed>
	 */
	private function summary( string $batch_id, array $slots, array $rows ): array {
		$posts  = array();
		$undone = 0;
		foreach ( $slots as $slot ) {
			$posts[ $slot['post_id'] ] = true;
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
			'created_at' => (string) mysql_to_rfc3339( $first['created_at'] ),
			'user_id'    => $user,
			'user_name'  => self::user_name( $user ),
			'links'      => count( $slots ),
			'active'     => count( $slots ) - $undone,
			'undone'     => $undone,
			'posts'      => count( $posts ),
			'titles'     => $titles,
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

	/**
	 * Valor de `href` de una etiqueta de apertura `<a …>`.
	 *
	 * @param string $open Etiqueta.
	 */
	private static function href( string $open ): string {
		$tags = new WP_HTML_Tag_Processor( $open );
		if ( ! $tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
			return '';
		}

		$href = $tags->get_attribute( 'href' );

		return is_string( $href ) ? $href : '';
	}
}
