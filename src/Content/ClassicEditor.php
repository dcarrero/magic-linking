<?php
/**
 * Inserción de un enlace en contenido del editor clásico (docs/06 §4).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

/**
 * Mismo algoritmo que {@see BlockEditor} sin bloques: se trabaja sobre el HTML completo, se localiza el
 * ancla por su contexto y se sustituye solo el tramo del ancla. Lo que se guarda en el historial y lo que
 * se verifica es el tramo del documento entre líneas en blanco que contiene el ancla (`@` y su byte de
 * inicio hacen de ruta), no el documento entero.
 */
final class ClassicEditor {

	/**
	 * Si un contenido es del editor clásico (sin comentarios de bloque).
	 *
	 * @param string $content Contenido guardado.
	 */
	public static function handles( string $content ): bool {
		return ! BlockEditor::handles( $content );
	}

	/**
	 * Calcula el contenido con el enlace insertado.
	 *
	 * @param string        $content Contenido guardado.
	 * @param InsertRequest $request Petición.
	 *
	 * @throws InsertionException Si no se puede insertar con seguridad; no se ha tocado nada.
	 */
	public function insert( string $content, InsertRequest $request ): Edit {
		$view = new TextView( $content, true );
		$hits = Linker::matches( $view, $request );

		$found   = array();
		$blocked = null;
		foreach ( $hits as [ $a, $b ] ) {
			if ( null !== $view->unsafe() ) {
				throw new InsertionException( InsertionException::UNSUPPORTED, __( 'The post contains markup (script, styles…) that cannot be scanned safely.', 'magic-linking' ) );
			}
			$problem = Linker::blocked( $view, $a, $b, $request->headings );
			if ( null !== $problem ) {
				$blocked ??= $problem;
				continue;
			}
			$found[] = array( $a, $b );
		}

		if ( 1 !== count( $found ) ) {
			if ( array() === $found && null !== $blocked ) {
				throw $blocked;
			}
			throw new InsertionException( InsertionException::TEXT_CHANGED, __( 'The text has changed.', 'magic-linking' ) );
		}

		[ $new_content, $start, $end ] = Linker::wrap( $content, $view, $found[0][0], $found[0][1], $request );
		[ $from, $to ]                 = $this->chunk( $content, $start, $end );
		$delta                         = strlen( $new_content ) - strlen( $content );

		return new Edit(
			$new_content,
			'@' . $from,
			$from,
			$to,
			substr( $content, $from, $to - $from ),
			substr( $new_content, $from, $to + $delta - $from )
		);
	}

	/**
	 * Tramo del documento entre líneas en blanco que contiene los bytes [start, end).
	 *
	 * @param string $content Contenido.
	 * @param int    $start   Primer byte.
	 * @param int    $end     Byte siguiente al último.
	 *
	 * @return array{0: int, 1: int}
	 */
	public function chunk( string $content, int $start, int $end ): array {
		$from = 0;
		$to   = strlen( $content );

		preg_match_all( '~\r?\n[ \t\r\f]*\r?\n\s*~', $content, $blanks, PREG_OFFSET_CAPTURE );
		foreach ( $blanks[0] as [ $blank, $at ] ) {
			$at = (int) $at;
			if ( $at + strlen( $blank ) <= $start ) {
				$from = $at + strlen( $blank );
			} elseif ( $at >= $end ) {
				$to = $at;
				break;
			}
		}

		return array( $from, $to );
	}
}
