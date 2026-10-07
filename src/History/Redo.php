<?php
/**
 * Rehacer un enlace deshecho (docs/06 §5).
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
 * Vuelve a poner un enlace que se deshizo. Es la inserción de F1-09 en sentido inverso y pasa por las mismas
 * garantías: entrada editable y no abierta en el editor, candado por entrada, verificación de
 * {@see Verifier} y fila nueva en el historial antes de escribir.
 *
 * Solo se rehace si el tramo (el bloque, o el trozo entre líneas en blanco en el editor clásico) está
 * exactamente como estaba antes del enlace: es lo que garantiza que se vuelve a escribir el mismo cambio y
 * nada más. Si se editó ese tramo después de deshacer, no se toca nada y se avisa con un enlace al editor.
 * El resto de la entrada puede haberse editado.
 *
 * Rehacer deja una fila `insert` nueva en el mismo lote (la antigua queda como deshecha y es el rastro de lo
 * ocurrido); la pantalla las junta como un único enlace.
 */
final class Redo {

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
	 * Rehace un cambio deshecho.
	 *
	 * @param int      $change_id Fila `insert` de magiclinking_changes con `undone_at`.
	 * @param int|null $user_id   Quien lo pide (null = el usuario actual; 0 = el sistema).
	 */
	public function redo( int $change_id, ?int $user_id = null ): UndoResult {
		$change = $this->changes->get( $change_id );
		if ( null === $change || ChangeRepository::INSERT !== $change['action'] ) {
			return new UndoResult( $change_id, 0, UndoResult::FAILED, __( 'That change does not exist.', 'magic-linking' ), InsertionException::BAD_REQUEST );
		}

		$post_id = $change['post_id'];
		if ( null === $change['undone_at'] ) {
			return new UndoResult( $change_id, $post_id, UndoResult::FAILED, __( 'This link has not been undone, so there is nothing to redo.', 'magic-linking' ), InsertionException::BAD_REQUEST );
		}

		if ( $this->changes->put_back_after( $change ) ) {
			return new UndoResult( $change_id, $post_id, UndoResult::FAILED, __( 'This link was already added again.', 'magic-linking' ), InsertionException::BAD_REQUEST );
		}

		try {
			$new_id = $this->jobs->without_save_indexing(
				fn(): ?int => $this->writer->exclusive( $post_id, fn(): ?int => $this->apply( $change, $user_id ) )
			);
		} catch ( InsertionException $e ) {
			return new UndoResult( $change_id, $post_id, UndoResult::FAILED, $e->getMessage(), $e->reason() );
		}

		if ( null === $new_id ) {
			$url = get_edit_post_link( $post_id, 'raw' );

			return new UndoResult(
				$change_id,
				$post_id,
				UndoResult::MANUAL,
				__( 'This post was edited afterwards; add the link again by hand.', 'magic-linking' ),
				InsertionException::EDITED_AFTER,
				is_string( $url ) ? $url : null
			);
		}

		try {
			$this->jobs->reindex_now( $post_id );
		} catch ( Throwable $e ) {
			PostWriter::log( sprintf( 'No se pudo actualizar el grafo de la entrada %d: %s', $post_id, $e->getMessage() ) );
		}

		// Es el hook de \MagicLinking\Content\Inserter::insert(): rehacer es insertar otra vez.
		do_action( 'magiclinking_link_inserted', $new_id, $post_id, (string) $change['batch_id'] );

		return new UndoResult( $change_id, $post_id, UndoResult::REDONE, __( 'Link added again.', 'magic-linking' ) );
	}

	/**
	 * Rehace todos los cambios deshechos de un lote, del primero al último.
	 *
	 * @param string   $batch_id Lote.
	 * @param int|null $user_id  Quien lo pide (null = el usuario actual).
	 *
	 * @return list<UndoResult> Un resultado por enlace que seguía deshecho.
	 */
	public function redo_batch( string $batch_id, ?int $user_id = null ): array {
		$results = array();
		foreach ( Slots::ordered_ids( $this->changes->inserts_of( array( $batch_id ) )[ $batch_id ] ?? array(), true ) as $change_id ) {
			$results[] = $this->redo( $change_id, $user_id );
		}

		return $results;
	}

	/**
	 * Comprueba, verifica, anota y escribe.
	 *
	 * @param array<string, mixed> $change  Fila del historial.
	 * @param int|null             $user_id Quien lo pide.
	 *
	 * @return int|null Fila nueva del historial; null si el tramo ya no es el de antes del enlace.
	 *
	 * @throws InsertionException Si no se puede escribir con seguridad; no se ha tocado nada.
	 * @throws Throwable Lo que lance WordPress o un complemento al guardar (se relanza tras limpiar el historial).
	 */
	private function apply( array $change, ?int $user_id ): ?int {
		$post_id = (int) $change['post_id'];

		$this->writer->assert_editable( $post_id, $user_id );
		$content = $this->writer->read( $post_id );

		$before = (string) $change['before_html'];
		$after  = (string) $change['after_html'];
		$path   = (string) $change['block_path'];

		$start = $this->locate( $content, $before, $path );
		if ( null === $start ) {
			return null;
		}
		$end = $start + strlen( $before );

		$next  = substr( $content, 0, $start ) . $after . substr( $content, $end );
		$check = Verifier::check( $content, $next, array( $start, $end ), 1 );
		if ( ! $check->ok ) {
			PostWriter::log( sprintf( 'Verificación fallida al rehacer el cambio %d: %s.', (int) $change['id'], $check->reason ) );
			throw new InsertionException( InsertionException::VERIFY_FAILED, __( 'The change could not be confirmed as only the link; nothing was written.', 'magic-linking' ) );
		}

		$user = $user_id ?? get_current_user_id();
		$id   = $this->changes->record( (string) $change['batch_id'], $post_id, ChangeRepository::INSERT, '' === $path ? null : $path, $before, $after, PostWriter::hash( $next ), $user );
		if ( 0 === $id ) {
			throw new InsertionException( InsertionException::WRITE_FAILED, __( 'The change history could not be saved; nothing was written.', 'magic-linking' ) );
		}

		try {
			$this->writer->write( $post_id, $content, $next, $user_id );
		} catch ( Throwable $e ) {
			// Solo se conserva la fila si el contenido nuevo llegó a quedar guardado.
			if ( $this->writer->read( $post_id ) !== $next ) {
				$this->changes->delete( $id );
			}
			throw $e;
		}

		return $id;
	}

	/**
	 * Dónde está ahora el tramo tal como estaba antes del enlace.
	 *
	 * Se busca por contenido: la ruta guardada (el índice del bloque, o el byte en el editor clásico) solo es una
	 * pista, porque una edición anterior en la entrada desplaza ambas y el tramo puede estar intacto. El tramo exacto
	 * tiene que aparecer una sola vez; si aparece varias, solo vale el que está donde decía la pista.
	 *
	 * @param string $content Contenido actual.
	 * @param string $before  Tramo antes del enlace.
	 * @param string $path    Ruta guardada.
	 *
	 * @return int|null Byte donde empieza; null si no está o no se puede saber cuál es.
	 */
	private function locate( string $content, string $before, string $path ): ?int {
		if ( '' === $before ) {
			return null;
		}

		$found = array();
		$at    = strpos( $content, $before );
		while ( false !== $at ) {
			$found[] = $at;
			if ( count( $found ) > 1 ) {
				break;
			}
			$at = strpos( $content, $before, $at + 1 );
		}

		if ( 1 === count( $found ) ) {
			return $found[0];
		}
		if ( array() === $found ) {
			return null;
		}

		// Más de uno: manda la pista.
		if ( str_starts_with( $path, '@' ) ) {
			$hint = (int) substr( $path, 1 );
		} else {
			$map  = BlockMap::parse( $content );
			$node = null === $map ? null : $map->find( $path );
			$hint = null === $node ? -1 : $node->start;
		}

		return substr( $content, $hint, strlen( $before ) ) === $before && $hint >= 0 ? $hint : null;
	}
}
