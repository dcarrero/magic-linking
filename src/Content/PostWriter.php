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
	 * Quién tiene la entrada abierta en el editor (bloqueo `_edit_lock` vigente), si no es `$user_id`.
	 *
	 * @param int $post_id ID.
	 * @param int $user_id Quien quiere editar (0 = el sistema: cualquier bloqueo cuenta).
	 *
	 * @return int ID del usuario que la tiene abierta; 0 si nadie.
	 */
	public function locked_by( int $post_id, int $user_id ): int {
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

		return $owner > 0 && $owner !== $user_id && $time > time() - $window ? $owner : 0;
	}

	/**
	 * Comprueba que se puede modificar la entrada.
	 *
	 * @param int      $post_id ID.
	 * @param int|null $user_id Quien lo pide (null = el usuario actual; 0 = el sistema).
	 *
	 * @return WP_Post La entrada.
	 *
	 * @throws InsertionException Si no existe, no se puede tocar, el usuario no puede editarla o la tiene abierta otro.
	 */
	public function assert_editable( int $post_id, ?int $user_id ): WP_Post {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || in_array( $post->post_status, self::NEVER_STATUS, true ) ) {
			throw new InsertionException( InsertionException::NO_POST, __( 'The post does not exist or is in the trash.', 'magic-linking' ) );
		}

		$user = $user_id ?? get_current_user_id();
		if ( $user > 0 && ! user_can( $user, 'edit_post', $post_id ) ) {
			throw new InsertionException( InsertionException::NOT_ALLOWED, __( 'You do not have permission to edit this post.', 'magic-linking' ) );
		}

		$owner = $this->locked_by( $post_id, $user );
		if ( $owner > 0 ) {
			$data = get_userdata( $owner );
			$name = false === $data ? __( 'someone else', 'magic-linking' ) : $data->display_name;
			throw new InsertionException(
				InsertionException::LOCKED,
				/* translators: %s: name of the person who has the post open. */
				sprintf( __( 'This post is being edited by %s; try again later.', 'magic-linking' ), $name )
			);
		}

		return $post;
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
	 * @param int      $post_id ID.
	 * @param string   $old     Contenido que se leyó y sobre el que se calculó el cambio.
	 * @param string   $next    Contenido nuevo.
	 * @param int|null $user_id Quien lo hace (null = el usuario actual).
	 *
	 * @throws InsertionException Si cambió entre la lectura y la escritura, si alguien abrió la entrada o si la escritura falla o altera el contenido.
	 */
	public function write( int $post_id, string $old, string $next, ?int $user_id ): void {
		$user = $user_id ?? get_current_user_id();

		// Última comprobación antes de escribir: lo que está guardado es lo que se leyó y nadie la abrió entre tanto.
		if ( $this->read( $post_id ) !== $old ) {
			throw new InsertionException( InsertionException::TEXT_CHANGED, __( 'The text has changed.', 'magic-linking' ) );
		}
		$owner = $this->locked_by( $post_id, $user );
		if ( $owner > 0 ) {
			throw new InsertionException( InsertionException::LOCKED, __( 'This post has just been opened in the editor; try again later.', 'magic-linking' ) );
		}

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

		// Un filtro (kses para quien no tiene unfiltered_html, otro complemento) puede haber tocado más que el enlace.
		if ( $this->read( $post_id ) !== $next ) {
			$this->restore( $post_id, $old );
			self::log( sprintf( 'La escritura de la entrada %d alteró el contenido; restaurado.', $post_id ) );
			throw new InsertionException( InsertionException::WRITE_FAILED, __( 'WordPress altered the content when saving it (security filters); the original was restored and the link was not inserted.', 'magic-linking' ) );
		}
	}

	/**
	 * Devuelve el contenido anterior a la base de datos sin pasar por ningún filtro.
	 *
	 * @param int    $post_id ID.
	 * @param string $old     Contenido anterior.
	 */
	private function restore( int $post_id, string $old ): void {
		$this->wpdb->update( $this->wpdb->posts, array( 'post_content' => $old ), array( 'ID' => $post_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Devolver los bytes exactos.
		clean_post_cache( $post_id );
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
