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
	 * Si el enlace se podría insertar en este contenido, sin escribir nada: lo mismo que haría {@see self::insert()}
	 * (mismos bloques admitidos, misma exigencia de que el contexto aparezca una sola vez) pero solo calculándolo.
	 *
	 * Sirve para no proponer desde el editor frases que el cliente no podrá enlazar (p. ej. el texto de un bloque de
	 * un complemento, que el motor lee pero no se toca).
	 *
	 * @param string        $content Contenido (el guardado o el que hay en el editor).
	 * @param InsertRequest $request Qué enlazar.
	 */
	public function can_insert( string $content, InsertRequest $request ): bool {
		try {
			BlockEditor::handles( $content ) ? ( new BlockEditor() )->insert( $content, $request ) : ( new ClassicEditor() )->insert( $content, $request );
		} catch ( InsertionException ) {
			return false;
		}

		return true;
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
		$result = $this->insert_many( array( $request ), $batch_id )[0];
		if ( $result instanceof InsertionException ) {
			throw $result;
		}

		return $result;
	}

	/**
	 * Inserta varios enlaces en **una misma entrada** con una sola escritura: una revisión, un reindexado y
	 * un candado. Cada enlace se calcula y se verifica por separado sobre el resultado del anterior (cada uno
	 * cambia exactamente un enlace) y tiene su propia fila en el historial, con la huella del contenido que
	 * dejó, de modo que se deshace por separado igual que si se hubieran insertado de uno en uno.
	 *
	 * Un enlace que no se puede insertar no impide los demás; si falla la escritura, ninguno se ha insertado.
	 * Si el contenido llegó a escribirse y lo que falla es un tercero (un oyente de guardado o de
	 * `magiclinking_link_inserted`), los enlaces cuentan como insertados y el error se registra.
	 *
	 * @param InsertRequest[] $requests Peticiones, todas de la misma entrada.
	 * @param string|null     $batch_id Lote; si es null se crea uno.
	 *
	 * @phpstan-param list<InsertRequest> $requests
	 *
	 * @return array<int, InsertResult|InsertionException> Un resultado por petición, en el mismo orden.
	 *
	 * @throws InsertionException Si las peticiones no son todas de la misma entrada y el mismo usuario.
	 */
	public function insert_many( array $requests, ?string $batch_id = null ): array {
		if ( array() === $requests ) {
			return array();
		}

		$post_id = $requests[0]->post_id;
		foreach ( $requests as $request ) {
			if ( $request->post_id !== $post_id || $request->user_id !== $requests[0]->user_id ) {
				throw new InsertionException( InsertionException::BAD_REQUEST, __( 'All the links of one write must be for the same post and the same user.', 'magic-linking' ) );
			}
		}

		$batch_id = $batch_id ?? BatchId::generate();

		try {
			$post = $this->writer->assert_editable( $post_id, $requests[0]->user_id );
			if ( ! in_array( $post->post_type, $this->settings->post_types(), true ) ) {
				return array_fill( 0, count( $requests ), new InsertionException( InsertionException::NOT_ALLOWED, __( 'This content type is not one that Magic Linking analyzes.', 'magic-linking' ) ) );
			}

			// Leer, verificar y escribir con la entrada reservada: dos operaciones a la vez no se pisan.
			// El guardado no indexa por su cuenta: se indexa una sola vez, ya con los cambios verificados y escritos.
			$results = $this->jobs->without_save_indexing(
				fn(): array => $this->writer->exclusive( $post_id, fn(): array => $this->apply_many( $requests, $batch_id ) )
			);
		} catch ( InsertionException $e ) {
			return array_fill( 0, count( $requests ), $e );
		}

		$done = array_filter( $results, static fn( $r ): bool => $r instanceof InsertResult );
		if ( array() !== $done ) {
			$this->refresh( $post_id );
		}

		foreach ( $done as $result ) {
			// El enlace ya está escrito y anotado: un oyente que falla no puede ocultarlo ni impedir los demás avisos.
			try {
				/**
				 * Se ha insertado un enlace.
				 *
				 * @param int    $change_id Fila de magiclinking_changes.
				 * @param int    $post_id   Entrada modificada.
				 * @param string $batch_id  Lote.
				 */
				do_action( 'magiclinking_link_inserted', $result->change_id, $result->post_id, $result->batch_id );
			} catch ( Throwable $e ) {
				PostWriter::log( sprintf( 'Un oyente de magiclinking_link_inserted falló en la entrada %d: %s', $post_id, $e->getMessage() ) );
			}
		}

		return $results;
	}

	/**
	 * Calcula y verifica cada enlace sobre el anterior, anota todos y escribe una vez.
	 *
	 * @param InsertRequest[] $requests Peticiones de la misma entrada.
	 * @param string          $batch_id Lote.
	 *
	 * @phpstan-param list<InsertRequest> $requests
	 *
	 * @return array<int, InsertResult|InsertionException>
	 *
	 * @throws InsertionException Si no se puede escribir con seguridad; no se ha escrito nada.
	 * @throws Throwable Lo que lance WordPress o un complemento al guardar (se relanza tras limpiar el historial).
	 */
	private function apply_many( array $requests, string $batch_id ): array {
		$post_id  = $requests[0]->post_id;
		$original = $this->writer->read( $post_id );
		$content  = $original;
		$results  = array();
		$edits    = array();

		foreach ( $requests as $index => $request ) {
			try {
				$edit  = BlockEditor::handles( $content ) ? ( new BlockEditor() )->insert( $content, $request ) : ( new ClassicEditor() )->insert( $content, $request );
				$check = Verifier::check( $content, $edit->content, array( $edit->start, $edit->end ), 1 );
				if ( ! $check->ok ) {
					PostWriter::log( sprintf( 'Verificación fallida al insertar en la entrada %d: %s.', $post_id, $check->reason ) );
					throw new InsertionException( InsertionException::VERIFY_FAILED, __( 'The change could not be confirmed as only the link; nothing was written.', 'magic-linking' ) );
				}
			} catch ( InsertionException $e ) {
				$results[ $index ] = $e;
				continue;
			}

			$edits[ $index ] = $edit;
			$content         = $edit->content;
		}

		if ( array() === $edits ) {
			return array_values( $results );
		}

		// El historial va primero: si la escritura no llega a hacerse, las filas se retiran.
		$user = $requests[0]->user_id ?? get_current_user_id();
		$ids  = array();
		foreach ( $edits as $index => $edit ) {
			$id = $this->changes->record( $batch_id, $post_id, ChangeRepository::INSERT, $edit->path, $edit->before_html, $edit->after_html, PostWriter::hash( $edit->content ), $user );
			if ( 0 === $id ) {
				foreach ( $ids as $done ) {
					$this->changes->delete( $done );
				}
				throw new InsertionException( InsertionException::WRITE_FAILED, __( 'The change history could not be saved; nothing was written.', 'magic-linking' ) );
			}
			$ids[ $index ] = $id;
		}

		try {
			$this->writer->write( $post_id, $original, $content, $requests[0]->user_id );
		} catch ( Throwable $e ) {
			// Solo se conservan las filas si el contenido nuevo llegó a quedar guardado.
			if ( $this->writer->read( $post_id ) !== $content ) {
				foreach ( $ids as $done ) {
					$this->changes->delete( $done );
				}
				throw $e;
			}

			// El contenido sí se escribió y lo que falló es un tercero (un oyente de guardado): los enlaces están
			// puestos y anotados, así que la operación está hecha y se cuenta como tal.
			PostWriter::log( sprintf( 'Un complemento falló al guardar la entrada %d, pero los enlaces quedaron escritos: %s', $post_id, $e->getMessage() ) );
		}

		foreach ( $edits as $index => $edit ) {
			$results[ $index ] = new InsertResult( $ids[ $index ], $batch_id, $post_id, $edit->path );
		}
		ksort( $results );

		return array_values( $results );
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
