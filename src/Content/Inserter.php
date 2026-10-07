<?php
/**
 * Inserción segura de un enlace en el servidor (docs/06 §3 y §4).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

use MagicLinking\Core\Settings;
use MagicLinking\Jobs\Jobs;
use MagicLinking\History\BatchId;
use MagicLinking\History\ChangeRepository;
use Throwable;

/**
 * Orquesta una inserción: comprueba que se puede tocar la entrada, localiza la frase y el ancla
 * ({@see BlockEditor} o {@see ClassicEditor}), verifica el resultado con {@see Verifier}, guarda el cambio
 * en el historial **antes** de escribir y escribe con {@see PostWriter}. Si algo no cuadra se detiene con
 * una {@see InsertionException} y no se ha escrito nada.
 *
 * Es el motor de escritura que usan la API, el panel, el informe y las reglas; no tiene pantalla ni rutas.
 */
final class Inserter {

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
	 * Inserta un enlace.
	 *
	 * @param InsertRequest $request  Qué enlazar y dónde.
	 * @param string|null   $batch_id Lote al que pertenece (una acción del usuario = un lote); si es null se crea uno.
	 *
	 * @throws InsertionException Si no se puede insertar con seguridad. No se ha escrito nada.
	 */
	public function insert( InsertRequest $request, ?string $batch_id = null ): InsertResult {
		$post = $this->writer->assert_editable( $request->post_id, $request->user_id );
		if ( ! in_array( $post->post_type, $this->settings->post_types(), true ) ) {
			throw new InsertionException( InsertionException::NOT_ALLOWED, __( 'This content type is not one that Magic Linking analyzes.', 'magic-linking' ) );
		}

		$batch_id = $batch_id ?? BatchId::generate();

		// Leer, verificar y escribir con la entrada reservada: dos operaciones a la vez no se pisan.
		// El guardado no indexa por su cuenta: se indexa una sola vez, ya con el cambio verificado y escrito.
		$result = $this->jobs->without_save_indexing(
			fn(): InsertResult => $this->writer->exclusive( $request->post_id, fn(): InsertResult => $this->apply( $request, $batch_id ) )
		);

		$this->refresh( $request->post_id );

		/**
		 * Se ha insertado un enlace.
		 *
		 * @param int    $change_id Fila de magiclinking_changes.
		 * @param int    $post_id   Entrada modificada.
		 * @param string $batch_id  Lote.
		 */
		do_action( 'magiclinking_link_inserted', $result->change_id, $result->post_id, $result->batch_id );

		return $result;
	}

	/**
	 * Calcula, verifica, anota y escribe.
	 *
	 * @param InsertRequest $request  Petición.
	 * @param string        $batch_id Lote.
	 *
	 * @throws InsertionException Si no se puede insertar con seguridad; no se ha escrito nada.
	 * @throws Throwable Lo que lance WordPress o un complemento al guardar (se relanza tras limpiar el historial).
	 */
	private function apply( InsertRequest $request, string $batch_id ): InsertResult {
		$content = $this->writer->read( $request->post_id );
		$edit    = BlockEditor::handles( $content ) ? ( new BlockEditor() )->insert( $content, $request ) : ( new ClassicEditor() )->insert( $content, $request );

		$check = Verifier::check( $content, $edit->content, array( $edit->start, $edit->end ), 1 );
		if ( ! $check->ok ) {
			PostWriter::log( sprintf( 'Verificación fallida al insertar en la entrada %d: %s.', $request->post_id, $check->reason ) );
			throw new InsertionException( InsertionException::VERIFY_FAILED, __( 'The change could not be confirmed as only the link; nothing was written.', 'magic-linking' ) );
		}

		$user = $request->user_id ?? get_current_user_id();

		// El historial va primero: si la escritura no llega a hacerse, la fila se retira.
		$id = $this->changes->record( $batch_id, $request->post_id, ChangeRepository::INSERT, $edit->path, $edit->before_html, $edit->after_html, PostWriter::hash( $edit->content ), $user );
		if ( 0 === $id ) {
			throw new InsertionException( InsertionException::WRITE_FAILED, __( 'The change history could not be saved; nothing was written.', 'magic-linking' ) );
		}

		try {
			$this->writer->write( $request->post_id, $content, $edit->content, $request->user_id );
		} catch ( Throwable $e ) {
			// Solo se conserva la fila si el contenido nuevo llegó a quedar guardado.
			if ( $this->writer->read( $request->post_id ) !== $edit->content ) {
				$this->changes->delete( $id );
			}
			throw $e;
		}

		return new InsertResult( $id, $batch_id, $request->post_id, $edit->path );
	}

	/**
	 * Reindexa la entrada una vez (el grafo y el índice léxico).
	 *
	 * @param int $post_id Entrada.
	 */
	private function refresh( int $post_id ): void {
		try {
			$this->jobs->reindex_now( $post_id );
		} catch ( Throwable $e ) {
			// El enlace ya está escrito; un fallo del índice se reintenta en el siguiente guardado o en el recálculo nocturno.
			PostWriter::log( sprintf( 'No se pudo actualizar el grafo de la entrada %d: %s', $post_id, $e->getMessage() ) );
		}
	}
}
