<?php
/**
 * Lectura y escritura segura del contenido de una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

use WP_Error;
use WP_Post;
use wpdb;

/**
 * Lo único que toca `post_content`: comprueba quién puede editar y que nadie más esté editando (docs/06
 * §2), lee el contenido tal cual está guardado y lo escribe con `wp_update_post()` (revisiones, `kses`
 * según las capacidades del usuario actual, `post_date` intacto). Después de escribir comprueba que lo
 * guardado es exactamente lo previsto; si un filtro (`kses`, otro complemento) lo ha alterado, devuelve el
 * contenido anterior y lo comunica.
 */
final class PostWriter {

	/**
	 * Estados de una entrada que nunca se toca.
	 */
	private const NEVER_STATUS = array( 'trash', 'auto-draft', 'inherit' );

	/**
	 * Conexión.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Huella del contenido completo, la que se guarda en el historial para saber si alguien lo editó después.
	 *
	 * @param string $content Contenido.
	 */
	public static function hash( string $content ): string {
		return sha1( $content );
	}

	/**
	 * Quién tiene la entrada abierta en el editor (bloqueo `_edit_lock` vigente), sea quien sea: también
	 * el propio usuario, que puede tenerla abierta en otra pestaña con cambios sin guardar (docs/06 §1.5).
	 *
	 * @param int $post_id ID.
	 *
	 * @return int ID del usuario que la tiene abierta; 0 si nadie.
	 */
	public function locked_by( int $post_id ): int {
		wp_cache_delete( $post_id, 'post_meta' );
		$lock = get_post_meta( $post_id, '_edit_lock', true );
		if ( ! is_string( $lock ) || '' === $lock ) {
			return 0;
		}

		$parts = explode( ':', $lock );
		$time  = (int) $parts[0];
		$owner = isset( $parts[1] ) ? (int) $parts[1] : 0;

		/** Este filtro es el de WordPress (`wp_check_post_lock()`): ventana en segundos de un bloqueo vigente. */
		$window = (int) apply_filters( 'wp_check_post_lock_window', 150 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro del núcleo.

		return $owner > 0 && $time > time() - $window ? $owner : 0;
	}

	/**
	 * Comprueba que se puede modificar la entrada.
	 *
	 * @param int      $post_id ID.
	 * @param int|null $user_id Quien lo pide: null = el usuario actual, que tiene que haber iniciado sesión y poder editarla; 0 = el sistema (solo si se pide expresamente).
	 *
	 * @return WP_Post La entrada.
	 *
	 * @throws InsertionException Si no existe, no se puede tocar, el usuario no puede editarla o la tiene abierta alguien.
	 */
	public function assert_editable( int $post_id, ?int $user_id ): WP_Post {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || in_array( $post->post_status, self::NEVER_STATUS, true ) ) {
			throw new InsertionException( InsertionException::NO_POST, __( 'The post does not exist or is in the trash.', 'magic-linking' ) );
		}

		$user = $user_id ?? get_current_user_id();
		if ( ( null === $user_id && $user <= 0 ) || ( $user > 0 && ! user_can( $user, 'edit_post', $post_id ) ) ) {
			throw new InsertionException( InsertionException::NOT_ALLOWED, __( 'You do not have permission to edit this post.', 'magic-linking' ) );
		}

		$owner = $this->locked_by( $post_id );
		if ( $owner > 0 ) {
			throw $this->locked( $owner, $user );
		}

		return $post;
	}

	/**
	 * Ejecuta un trabajo con la entrada reservada: dos inserciones o deshaceres a la vez sobre la misma
	 * entrada no se pisan (`GET_LOCK` de MySQL, por sitio y entrada, liberado siempre al terminar).
	 *
	 * @template T
	 *
	 * @param int           $post_id ID.
	 * @param callable(): T $work    Leer, verificar y escribir.
	 *
	 * @return T
	 *
	 * @throws InsertionException Si otra operación tiene la entrada y no la suelta a tiempo.
	 */
	public function exclusive( int $post_id, callable $work ): mixed {
		$wpdb = $this->wpdb;
		$name = sprintf( 'magiclinking_post_%d_%d', get_current_blog_id(), $post_id );

		/**
		 * Segundos que se espera a que otra operación suelte una entrada antes de negarse.
		 *
		 * @param int $seconds Segundos (5 por defecto).
		 * @param int $post_id Entrada.
		 */
		$wait = max( 0, (int) apply_filters( 'magiclinking_post_lock_timeout', 5, $post_id ) );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $name, $wait ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Candado de MySQL.

		if ( null !== $got && 1 !== (int) $got ) {
			throw new InsertionException( InsertionException::BUSY, __( 'Another change is being applied to this post; try again in a moment.', 'magic-linking' ) );
		}
		if ( null === $got ) {
			self::log( sprintf( 'GET_LOCK no está disponible; se continúa sin candado en la entrada %d.', $post_id ) );
		}

		try {
			return $work();
		} finally {
			if ( null !== $got ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Candado de MySQL.
			}
		}
	}

	/**
	 * Contenido guardado de una entrada, leído de la base de datos (sin cachés ni filtros).
	 *
	 * @param int $post_id ID.
	 */
	public function read( int $post_id ): string {
		$wpdb    = $this->wpdb;
		$content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lectura exacta, sin cachés ni filtros.

		return is_string( $content ) ? $content : '';
	}

	/**
	 * Escribe el contenido nuevo si el actual sigue siendo el que se leyó.
	 *
	 * Antes de escribir pasa el contenido por la misma sanitización que hará `wp_update_post()` (los filtros
	 * `*_save_pre`, `kses` según el usuario actual) y se niega si cambiaría algo más que el enlace. Después
	 * relee la fila entera como red de seguridad: solo `post_content`, `post_modified` y `post_modified_gmt`
	 * pueden ser distintos; si no, devuelve la fila original (y borra la revisión que se creó).
	 *
	 * @param int      $post_id ID.
	 * @param string   $old     Contenido que se leyó y sobre el que se calculó el cambio.
	 * @param string   $next    Contenido nuevo.
	 * @param int|null $user_id Quien lo hace (null = el usuario actual).
	 *
	 * @throws InsertionException Si cambió entre la lectura y la escritura, si alguien abrió la entrada, si la escritura falla o si WordPress altera algo más que el enlace.
	 */
	public function write( int $post_id, string $old, string $next, ?int $user_id ): void {
		$row = $this->row( $post_id );
		if ( null === $row || (string) $row['post_content'] !== $old ) {
			throw new InsertionException( InsertionException::TEXT_CHANGED, __( 'The text has changed.', 'magic-linking' ) );
		}

		$owner = $this->locked_by( $post_id );
		if ( $owner > 0 ) {
			throw $this->locked( $owner, $user_id ?? get_current_user_id(), true );
		}

		// Lo que los filtros de guardado harían con este contenido, antes de escribir nada.
		$candidate                 = $row;
		$candidate['post_content'] = $next;
		$sanitized                 = wp_unslash( sanitize_post( wp_slash( $candidate ), 'db' ) );
		foreach ( $candidate as $field => $value ) {
			if ( (string) ( $sanitized[ $field ] ?? '' ) !== (string) $value ) {
				self::log( sprintf( 'Los filtros de guardado alterarían el campo %s de la entrada %d; no se escribe.', $field, $post_id ) );
				throw $this->altered( false );
			}
		}

		$revisions = array_map( 'intval', array_values( wp_get_post_revisions( $post_id, array( 'fields' => 'ids' ) ) ) );

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $next,
				)
			),
			true
		);
		if ( $result instanceof WP_Error ) {
			throw new InsertionException( InsertionException::WRITE_FAILED, __( 'WordPress could not save the post; nothing was changed.', 'magic-linking' ) );
		}

		// Red de seguridad: nada salvo el contenido y la fecha de modificación puede haber cambiado.
		$after = $this->row( $post_id );
		if ( null === $after || $this->differs( $row, $after, $next ) ) {
			$this->restore( $post_id, $row, $revisions );
			self::log( sprintf( 'La escritura de la entrada %d alteró más que el enlace; restaurada.', $post_id ) );
			throw $this->altered( true );
		}
	}

	/**
	 * Fila completa de la entrada, sin cachés ni filtros.
	 *
	 * @param int $post_id ID.
	 *
	 * @return array<string, string|null>|null
	 */
	private function row( int $post_id ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lectura exacta, sin cachés ni filtros.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Si la fila de después difiere de la de antes en algo más que el contenido nuevo y la fecha de modificación.
	 *
	 * @param array<string, string|null> $before Antes.
	 * @param array<string, string|null> $after  Después.
	 * @param string                     $next   Contenido esperado.
	 */
	private function differs( array $before, array $after, string $next ): bool {
		if ( (string) $after['post_content'] !== $next ) {
			return true;
		}

		foreach ( $before as $field => $value ) {
			if ( in_array( $field, array( 'post_content', 'post_modified', 'post_modified_gmt' ), true ) ) {
				continue;
			}
			if ( (string) ( $after[ $field ] ?? '' ) !== (string) $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Devuelve la fila original sin pasar por ningún filtro y borra las revisiones que se crearon al escribir.
	 *
	 * @param int   $post_id   ID.
	 * @param array $row       Fila original.
	 * @param array $revisions Revisiones que había antes de escribir.
	 *
	 * @phpstan-param array<string, string|null> $row
	 * @phpstan-param list<int> $revisions
	 */
	private function restore( int $post_id, array $row, array $revisions ): void {
		foreach ( array_values( wp_get_post_revisions( $post_id, array( 'fields' => 'ids' ) ) ) as $revision ) {
			if ( ! in_array( (int) $revision, $revisions, true ) ) {
				wp_delete_post_revision( (int) $revision );
			}
		}

		unset( $row['ID'] );
		$this->wpdb->update( $this->wpdb->posts, $row, array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Devolver la fila exacta.
		clean_post_cache( $post_id );
	}

	/**
	 * Excepción de entrada abierta.
	 *
	 * @param int  $owner Quien la tiene abierta.
	 * @param int  $user  Quien quiere editar.
	 * @param bool $late  Se detecta justo antes de escribir.
	 */
	private function locked( int $owner, int $user, bool $late = false ): InsertionException {
		if ( $owner === $user ) {
			return new InsertionException( InsertionException::LOCKED, __( 'This post is open in your editor (maybe in another tab); add the link from there or close it and try again.', 'magic-linking' ) );
		}

		$data = get_userdata( $owner );
		$name = false === $data ? __( 'someone else', 'magic-linking' ) : $data->display_name;

		return new InsertionException(
			InsertionException::LOCKED,
			$late
				/* translators: %s: name of the person who has the post open. */
				? sprintf( __( 'This post has just been opened by %s; try again later.', 'magic-linking' ), $name )
				/* translators: %s: name of the person who has the post open. */
				: sprintf( __( 'This post is being edited by %s; try again later.', 'magic-linking' ), $name )
		);
	}

	/**
	 * Excepción de contenido alterado por los filtros de guardado.
	 *
	 * @param bool $restored Si se había llegado a escribir y se ha devuelto el original.
	 */
	private function altered( bool $restored ): InsertionException {
		return new InsertionException(
			InsertionException::ALTERED,
			$restored
				? __( 'WordPress altered the content when saving it (security filters); the original was restored and the link was not inserted.', 'magic-linking' )
				: __( 'WordPress would alter the content when saving it (security filters); nothing was written and the link was not inserted.', 'magic-linking' )
		);
	}

	/**
	 * Anota un motivo en el registro de depuración de WordPress, si está activo.
	 *
	 * @param string $message Mensaje.
	 */
	public static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Magic Linking] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Registro de depuración pedido en docs/06 §6.
		}
	}
}
