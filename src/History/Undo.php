<?php
/**
 * Deshacer cambios del historial (docs/06 §5).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\History;

use MagicLinking\Content\BlockMap;
use MagicLinking\Content\InsertionException;
use MagicLinking\Content\PostWriter;
use MagicLinking\Content\Verifier;
use MagicLinking\Jobs\Jobs;
use Throwable;

/**
 * Deshace enlaces insertados. Mínimo de F1-09 (regla 5 de CLAUDE.md: «se puede deshacer»):
 *
 * - Si el contenido no ha cambiado desde el enlace (misma huella), se restituye el tramo guardado como
 *   «antes»: el documento vuelve a ser idéntico byte a byte.
 * - Si se editó después, se busca el `<a>` exacto que se insertó y, si aparece una sola vez en el
 *   documento, se quita solo ese enlace; si no, no se toca nada y se avisa con un enlace al editor.
 *
 * En los dos casos pasa por el mismo {@see Verifier} que la inserción y deja su propio registro
 * (`remove`). La conservación, la purga, la pantalla de historial y rehacer (`Redo`) son de F1-10.
 */
final class Undo {

	/**
	 * Constructor.
	 *
	 * @param PostWriter       $writer  Lectura y escritura del contenido.
	 * @param ChangeRepository $changes Historial.
	 * @param Jobs             $jobs    Indexado.
	 */
	public function __construct(
		private PostWriter $writer,
		private ChangeRepository $changes,
		private Jobs $jobs
	) {
	}

	/**
	 * Deshace todos los cambios de un lote, del último al primero.
	 *
	 * @param string   $batch_id Lote.
	 * @param int|null $user_id  Quien lo pide (null = el usuario actual).
	 *
	 * @return list<UndoResult> Un resultado por cambio, en el orden en que se han deshecho.
	 */
	public function revert_batch( string $batch_id, ?int $user_id = null ): array {
		$results = array();

		// Un enlace puesto, quitado y vuelto a poner (rehacer, F1-10) cuenta una vez: vale su fila más reciente.
		$latest = array();
		foreach ( $this->changes->batch( $batch_id ) as $change ) {
			if ( ChangeRepository::INSERT === $change['action'] ) {
				$latest[ md5( implode( '|', array( $change['post_id'], (string) $change['block_path'], $change['before_html'], $change['after_html'] ) ) ) ] = $change;
			}
		}

		foreach ( array_reverse( array_values( $latest ) ) as $change ) {
			$results[] = $this->revert( $change['id'], $user_id );
		}

		$undone = count(
			array_filter(
				$results,
				static fn( UndoResult $result ): bool => in_array( $result->status, array( UndoResult::RESTORED, UndoResult::LINK_REMOVED, UndoResult::GONE ), true )
			)
		);
		if ( $undone > 0 ) {
			/**
			 * Se ha deshecho un lote (al menos uno de sus cambios).
			 *
			 * @param string $batch_id Lote.
			 * @param int    $undone   Cuántos cambios se han deshecho ahora.
			 */
			do_action( 'magiclinking_batch_undone', $batch_id, $undone );
		}

		return $results;
	}

	/**
	 * Deshace un cambio.
	 *
	 * @param int      $change_id Fila de magiclinking_changes.
	 * @param int|null $user_id   Quien lo pide (null = el usuario actual; 0 = el sistema).
	 */
	public function revert( int $change_id, ?int $user_id = null ): UndoResult {
		$change = $this->changes->get( $change_id );
		if ( null === $change || ChangeRepository::INSERT !== $change['action'] ) {
			return new UndoResult( $change_id, 0, UndoResult::FAILED, __( 'That change does not exist.', 'magic-linking' ), InsertionException::BAD_REQUEST );
		}

		$post_id = $change['post_id'];
		if ( null !== $change['undone_at'] ) {
			return new UndoResult( $change_id, $post_id, UndoResult::ALREADY, __( 'This change was already undone.', 'magic-linking' ) );
		}

		try {
			$status = $this->jobs->without_save_indexing(
				fn(): ?string => $this->writer->exclusive( $post_id, fn(): ?string => $this->apply( $change, $user_id ) )
			);
		} catch ( InsertionException $e ) {
			return new UndoResult( $change_id, $post_id, UndoResult::FAILED, $e->getMessage(), $e->reason() );
		}

		if ( null === $status ) {
			return $this->manual( $change );
		}

		$this->changes->mark_undone( $change_id );
		$this->refresh( $post_id );

		$messages = array(
			UndoResult::RESTORED     => __( 'Link undone.', 'magic-linking' ),
			UndoResult::LINK_REMOVED => __( 'The post was edited afterwards: only the link was removed.', 'magic-linking' ),
			UndoResult::GONE         => __( 'The link is no longer in the post.', 'magic-linking' ),
		);

		return new UndoResult( $change_id, $post_id, $status, $messages[ $status ] );
	}

	/**
	 * Calcula, verifica, anota y escribe el deshacer de un cambio.
	 *
	 * @param array<string, mixed> $change  Fila del historial.
	 * @param int|null             $user_id Quien lo pide.
	 *
	 * @return string|null `restored`, `link_removed`, `already_gone` o null si no se puede deshacer solo.
	 *
	 * @throws InsertionException Si no se puede escribir con seguridad; no se ha tocado nada.
	 * @throws Throwable Lo que lance WordPress o un complemento al guardar (se relanza tras limpiar el historial).
	 */
	private function apply( array $change, ?int $user_id ): ?string {
		$post_id = (int) $change['post_id'];

		$this->writer->assert_editable( $post_id, $user_id );
		$content = $this->writer->read( $post_id );

		$whole = $this->restore_block( $content, $change );
		if ( null !== $whole ) {
			[ $next, $range ] = $whole;
			$status           = UndoResult::RESTORED;
		} else {
			$link = self::inserted_link( (string) $change['before_html'], (string) $change['after_html'] );
			if ( null === $link ) {
				return null;
			}

			$count = substr_count( $content, $link['exact'] );
			if ( 0 === $count && ! str_contains( $content, $link['open'] ) ) {
				// Borrado a mano o recuperado de una revisión: no hay nada que quitar.
				return UndoResult::GONE;
			}
			if ( 1 !== $count ) {
				return null;
			}

			$start = (int) strpos( $content, $link['exact'] );
			$end   = $start + strlen( $link['exact'] );

			$next   = substr( $content, 0, $start ) . $link['inner'] . substr( $content, $end );
			$range  = array( $start, $end );
			$status = UndoResult::LINK_REMOVED;
		}//end if

		$check = Verifier::check( $content, $next, $range, -1 );
		if ( ! $check->ok ) {
			PostWriter::log( sprintf( 'Verificación fallida al deshacer el cambio %d: %s.', (int) $change['id'], $check->reason ) );
			throw new InsertionException( InsertionException::VERIFY_FAILED, __( 'The change could not be confirmed as only removing the link; nothing was written.', 'magic-linking' ) );
		}

		$user = $user_id ?? get_current_user_id();
		$id   = $this->changes->record(
			(string) $change['batch_id'],
			$post_id,
			ChangeRepository::REMOVE,
			null === $change['block_path'] ? null : (string) $change['block_path'],
			substr( $content, $range[0], $range[1] - $range[0] ),
			substr( $next, $range[0], $range[1] + strlen( $next ) - strlen( $content ) - $range[0] ),
			PostWriter::hash( $next ),
			$user
		);
		if ( 0 === $id ) {
			throw new InsertionException( InsertionException::WRITE_FAILED, __( 'The change history could not be saved; nothing was written.', 'magic-linking' ) );
		}

		try {
			$this->writer->write( $post_id, $content, $next, $user_id );
		} catch ( Throwable $e ) {
			// Solo se conserva el registro si el contenido nuevo llegó a quedar guardado.
			if ( $this->writer->read( $post_id ) !== $next ) {
				$this->changes->delete( $id );
			}
			throw $e;
		}

		return $status;
	}

	/**
	 * Camino 1: el contenido es exactamente el que dejó el cambio; se devuelve el tramo original.
	 *
	 * @param string $content Contenido actual.
	 * @param array  $change  Fila del historial.
	 *
	 * @phpstan-param array<string, mixed> $change
	 *
	 * @return array{0: string, 1: array{0: int, 1: int}}|null Contenido nuevo y rango [inicio, fin) del tramo en el actual.
	 */
	private function restore_block( string $content, array $change ): ?array {
		if ( PostWriter::hash( $content ) !== $change['content_hash_after'] ) {
			return null;
		}

		$after = (string) $change['after_html'];
		$path  = (string) $change['block_path'];

		if ( str_starts_with( $path, '@' ) ) {
			$start = (int) substr( $path, 1 );
		} else {
			$map  = BlockMap::parse( $content );
			$node = null === $map ? null : $map->find( $path );
			if ( null === $node ) {
				return null;
			}
			$start = $node->start;
		}

		$end = $start + strlen( $after );
		if ( $start < 0 || $end > strlen( $content ) || substr( $content, $start, $end - $start ) !== $after ) {
			return null;
		}

		return array( substr( $content, 0, $start ) . $change['before_html'] . substr( $content, $end ), array( $start, $end ) );
	}

	/**
	 * El enlace que añadió un cambio: su `<a …>` entero, lo que envuelve y la suma de ambos con `</a>`.
	 *
	 * Se obtiene del propio historial: el «después» es el «antes» con un `<a>` y su `</a>` más.
	 *
	 * @param string $before HTML antes.
	 * @param string $after  HTML después.
	 *
	 * @return array{exact: string, inner: string, open: string}|null
	 */
	public static function inserted_link( string $before, string $after ): ?array {
		foreach ( self::positions( $after, '<a ' ) as $open_at ) {
			$close = strpos( $after, '>', $open_at );
			if ( false === $close ) {
				return null;
			}

			$open = substr( $after, $open_at, $close - $open_at + 1 );
			$rest = substr( $after, 0, $open_at ) . substr( $after, $close + 1 );

			foreach ( self::positions( $rest, '</a>', $open_at ) as $end_at ) {
				if ( substr( $rest, 0, $end_at ) . substr( $rest, $end_at + 4 ) === $before ) {
					$inner = substr( $rest, $open_at, $end_at - $open_at );

					return array(
						'exact' => $open . $inner . '</a>',
						'inner' => $inner,
						'open'  => $open,
					);
				}
			}
		}//end foreach

		return null;
	}

	/**
	 * Posiciones de todas las apariciones de un texto, desde un byte.
	 *
	 * @param string $haystack Texto.
	 * @param string $needle   Lo que se busca.
	 * @param int    $from     Primer byte donde buscar.
	 *
	 * @return list<int>
	 */
	private static function positions( string $haystack, string $needle, int $from = 0 ): array {
		$found = array();
		$at    = strpos( $haystack, $needle, $from );
		while ( false !== $at ) {
			$found[] = $at;
			$at      = strpos( $haystack, $needle, $at + 1 );
		}

		return $found;
	}

	/**
	 * Reindexa la entrada una vez tras escribir.
	 *
	 * @param int $post_id Entrada.
	 */
	private function refresh( int $post_id ): void {
		try {
			$this->jobs->reindex_now( $post_id );
		} catch ( Throwable $e ) {
			PostWriter::log( sprintf( 'No se pudo actualizar el grafo de la entrada %d: %s', $post_id, $e->getMessage() ) );
		}
	}

	/**
	 * No se puede deshacer solo: se avisa y se enlaza al editor.
	 *
	 * @param array $change Fila del historial.
	 *
	 * @phpstan-param array<string, mixed> $change
	 */
	private function manual( array $change ): UndoResult {
		$url = get_edit_post_link( (int) $change['post_id'], 'raw' );

		return new UndoResult(
			(int) $change['id'],
			(int) $change['post_id'],
			UndoResult::MANUAL,
			__( 'This post was edited afterwards; remove the link by hand.', 'magic-linking' ),
			InsertionException::EDITED_AFTER,
			is_string( $url ) ? $url : null
		);
	}
}
