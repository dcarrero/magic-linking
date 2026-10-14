<?php
/**
 * Arreglo seguro de un enlace roto en el servidor (docs/06 §9).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

use MagicLinking\Core\Settings;
use MagicLinking\History\BatchId;
use MagicLinking\History\ChangeRepository;
use MagicLinking\Jobs\Jobs;
use Throwable;

/**
 * Hermano de {@see Inserter} para enlaces que ya existen: cambiar el destino de uno roto o quitarlo dejando su
 * texto. Comprueba que se puede tocar la entrada, localiza el enlace ({@see LinkFixer}), verifica el resultado
 * con {@see Verifier}, guarda el cambio en el historial **antes** de escribir y escribe con {@see PostWriter}.
 * Si algo no cuadra se detiene con una {@see InsertionException} y no se ha escrito nada.
 *
 * Una acción = un lote = un cambio en el Historial; se deshace y se rehace con las mismas piezas que una inserción.
 */
final class Repairer {

	/**
	 * Constructor.
	 *
	 * @param PostWriter       $writer   Lectura y escritura del contenido.
	 * @param ChangeRepository $changes  Historial.
	 * @param Jobs             $jobs     Indexado (se reindexa una vez tras escribir).
	 * @param Settings         $settings Ajustes.
	 */
	public function __construct(
		private PostWriter $writer,
		private ChangeRepository $changes,
		private Jobs $jobs,
		private Settings $settings
	) {
	}

	/**
	 * Arregla un enlace.
	 *
	 * @param FixRequest  $request  Qué enlace y qué hacer.
	 * @param string|null $batch_id Lote al que pertenece; si es null se crea uno.
	 *
	 * @throws InsertionException Si no se puede arreglar con seguridad. No se ha escrito nada.
	 */
	public function fix( FixRequest $request, ?string $batch_id = null ): InsertResult {
		$batch_id = $batch_id ?? BatchId::generate();
		$post_id  = $request->post_id;

		$post = $this->writer->assert_editable( $post_id, $request->user_id );
		if ( ! in_array( $post->post_type, $this->settings->post_types(), true ) ) {
			throw new InsertionException( InsertionException::NOT_ALLOWED, __( 'This content type is not one that Magic Linking analyzes.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
		}

		// Leer, verificar y escribir con la entrada reservada; el guardado no indexa por su cuenta: se indexa una vez, ya con el cambio escrito.
		$result = $this->jobs->without_save_indexing(
			fn(): InsertResult => $this->writer->exclusive( $post_id, fn(): InsertResult => $this->apply( $request, $batch_id ) )
		);

		try {
			$this->jobs->reindex_now( $post_id );
		} catch ( Throwable $e ) {
			// El contenido ya está escrito; un fallo del índice se reintenta en el siguiente guardado o en el recálculo nocturno.
			PostWriter::log( sprintf( 'No se pudo actualizar el grafo de la entrada %d: %s', $post_id, $e->getMessage() ) );
		}

		// El cambio ya está escrito y anotado: un oyente que falla no puede ocultarlo.
		try {
			/**
			 * Se ha arreglado un enlace roto (cambiando su destino o quitándolo).
			 *
			 * @param int    $change_id Fila de magiclinking_changes.
			 * @param int    $post_id   Entrada modificada.
			 * @param string $batch_id  Lote.
			 * @param string $action    `retarget` o `unlink`.
			 */
			do_action( 'magiclinking_link_fixed', $result->change_id, $result->post_id, $result->batch_id, $request->action() );
		} catch ( Throwable $e ) {
			PostWriter::log( sprintf( 'Un oyente de magiclinking_link_fixed falló en la entrada %d: %s', $post_id, $e->getMessage() ) );
		}

		return $result;
	}

	/**
	 * Calcula y verifica el cambio, lo anota y escribe.
	 *
	 * @param FixRequest $request  Petición.
	 * @param string     $batch_id Lote.
	 *
	 * @throws InsertionException Si no se puede escribir con seguridad; no se ha escrito nada.
	 * @throws Throwable Lo que lance WordPress o un complemento al guardar (se relanza tras limpiar el historial).
	 */
	private function apply( FixRequest $request, string $batch_id ): InsertResult {
		$post_id  = $request->post_id;
		$original = $this->writer->read( $post_id );

		$fixer = new LinkFixer( $this->jobs->indexer()->parser() );
		$edit  = $fixer->fix( $original, $request );
		$check = Verifier::for_action( $request->action(), $original, $edit->content, array( $edit->start, $edit->end ) );

		if ( ! $check->ok ) {
			PostWriter::log( sprintf( 'Verificación fallida al arreglar un enlace de la entrada %d: %s.', $post_id, $check->reason ) );
			throw new InsertionException( InsertionException::VERIFY_FAILED, __( 'The change could not be confirmed as only the link; nothing was written.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
		}

		// El historial va primero: si la escritura no llega a hacerse, la fila se retira.
		$user = $request->user_id ?? get_current_user_id();
		$id   = $this->changes->record( $batch_id, $post_id, $request->action(), $edit->path, $edit->before_html, $edit->after_html, PostWriter::hash( $edit->content ), $user );
		if ( 0 === $id ) {
			throw new InsertionException( InsertionException::WRITE_FAILED, __( 'The change history could not be saved; nothing was written.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
		}

		try {
			$this->writer->write( $post_id, $original, $edit->content, $request->user_id );
		} catch ( Throwable $e ) {
			// Solo se conserva la fila si el contenido nuevo llegó a quedar guardado.
			if ( $this->writer->read( $post_id ) !== $edit->content ) {
				$this->changes->delete( $id );
				throw $e;
			}

			// El contenido sí se escribió y lo que falló es un tercero (un oyente de guardado): está hecho.
			PostWriter::log( sprintf( 'Un complemento falló al guardar la entrada %d, pero el enlace quedó arreglado: %s', $post_id, $e->getMessage() ) );
		}

		return new InsertResult( $id, $batch_id, $post_id, $edit->path );
	}
}
