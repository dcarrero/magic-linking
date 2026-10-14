<?php
/**
 * Registro en el historial de los enlaces que el usuario puso en el editor (F1-34, D-58).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\History;

use MagicLinking\Content\BlockEditor;
use MagicLinking\Content\BlockMap;
use MagicLinking\Content\ClassicEditor;
use MagicLinking\Content\InsertionException;
use MagicLinking\Content\PostWriter;
use MagicLinking\Content\Verifier;
use WP_HTML_Tag_Processor;
use WP_Post;

/**
 * Los enlaces salientes del panel se insertan en el cliente y se guardan con la entrada, así que el servidor
 * no los vio pasar. Tras guardar, el panel dice cuáles puso; este registro **no escribe nada en la entrada**
 * (regla 5 de CLAUDE.md): lee el contenido guardado, comprueba que cada enlace está (un `<a>` con la
 * dirección del destino y ese texto de ancla, una sola vez en todo el documento), reconstruye el tramo
 * «antes» quitando solo ese `<a>`, pasa el par por el {@see Verifier} (el mismo cambio, a la inversa, que
 * habría hecho el servidor) y lo guarda como una fila `insert` de origen `editor`. A partir de ahí Deshacer y
 * Rehacer del Historial funcionan como con cualquier otra inserción: verificación byte a byte, quitar solo el
 * enlace si la entrada cambió y aviso al editor si ni así se puede.
 *
 * Si el enlace no está, aparece más de una vez o el verificador no lo da por bueno, no se registra nada.
 * La clave idempotente (entrada, sugerencia, destino, ancla e identificador de inserción del cliente) evita
 * duplicados si se guarda varias veces o se repite la petición.
 */
final class EditorLinks {

	public const RECORDED   = 'recorded';
	public const DUPLICATE  = 'duplicate';
	public const NOT_FOUND  = 'not_found';
	public const AMBIGUOUS  = 'ambiguous';
	public const UNVERIFIED = 'unverified';
	public const INVALID    = 'invalid';

	/**
	 * Constructor.
	 *
	 * @param PostWriter       $writer  Lectura del contenido y candado por entrada.
	 * @param ChangeRepository $changes Historial.
	 */
	public function __construct( private PostWriter $writer, private ChangeRepository $changes ) {
	}

	/**
	 * Clave idempotente de un enlace del editor.
	 *
	 * @param int    $post_id      Entrada.
	 * @param string $suggestion   Clave de la sugerencia de la que sale.
	 * @param int    $target_id    Destino.
	 * @param string $anchor       Ancla.
	 * @param string $insertion_id Identificador de la inserción, generado por el cliente.
	 */
	public static function key( int $post_id, string $suggestion, int $target_id, string $anchor, string $insertion_id ): string {
		return sha1( implode( "\n", array( $post_id, $suggestion, $target_id, $anchor, $insertion_id ) ) );
	}

	/**
	 * Registra los enlaces que siguen en el contenido guardado.
	 *
	 * @param int   $post_id Entrada (el llamador ya comprobó `edit_post`).
	 * @param int   $user_id Quien los puso.
	 * @param array $links   Enlaces que el cliente dice haber puesto.
	 *
	 * @phpstan-param list<array{insertion_id: string, suggestion: string, target_id: int, anchor: string}> $links
	 *
	 * @return array{batch_id: string|null, results: list<array{insertion_id: string, status: string, change_id: int}>}
	 *
	 * @throws InsertionException Si otra operación tiene la entrada y no la suelta a tiempo.
	 */
	public function record( int $post_id, int $user_id, array $links ): array {
		/**
		 * Resultado de la reserva.
		 *
		 * @var array{batch_id: string|null, results: list<array{insertion_id: string, status: string, change_id: int}>} $outcome
		 */
		$outcome = $this->writer->exclusive( $post_id, fn(): array => $this->run( $post_id, $user_id, $links ) );

		return $outcome;
	}

	/**
	 * Comprueba y registra con la entrada reservada.
	 *
	 * @param int   $post_id Entrada.
	 * @param int   $user_id Usuario.
	 * @param array $links   Enlaces.
	 *
	 * @phpstan-param list<array{insertion_id: string, suggestion: string, target_id: int, anchor: string}> $links
	 *
	 * @return array{batch_id: string|null, results: list<array{insertion_id: string, status: string, change_id: int}>}
	 */
	private function run( int $post_id, int $user_id, array $links ): array {
		$content = $this->writer->read( $post_id );
		$blocks  = BlockEditor::handles( $content );
		$map     = $blocks ? BlockMap::parse( $content ) : null;
		$batch   = null;
		$results = array();

		foreach ( $links as $link ) {
			$insertion = $link['insertion_id'];
			$key       = self::key( $post_id, $link['suggestion'], $link['target_id'], $link['anchor'], $insertion );

			$existing = $this->changes->id_for_origin_key( $key );
			if ( 0 !== $existing ) {
				$results[] = $this->result( $insertion, self::DUPLICATE, $existing );
				continue;
			}

			$url = $this->target_url( $link['target_id'], $post_id, $user_id );
			if ( '' === $url || '' === self::normalize( $link['anchor'] ) ) {
				$results[] = $this->result( $insertion, self::INVALID );
				continue;
			}

			if ( $blocks && null === $map ) {
				$results[] = $this->result( $insertion, self::UNVERIFIED );
				continue;
			}

			$found = $this->matches( $content, $map, $url, $link['anchor'] );
			if ( array() === $found ) {
				$results[] = $this->result( $insertion, self::NOT_FOUND );
				continue;
			}
			if ( count( $found ) > 1 ) {
				$results[] = $this->result( $insertion, self::AMBIGUOUS );
				continue;
			}

			$edit = $this->edit( $content, $found[0] );
			if ( null === $edit ) {
				PostWriter::log( sprintf( 'El enlace del editor en la entrada %d no se pudo verificar; no se registra.', $post_id ) );
				$results[] = $this->result( $insertion, self::UNVERIFIED );
				continue;
			}

			if ( $this->changes->has_active_copy( $post_id, $edit['before'], $edit['after'] ) ) {
				$results[] = $this->result( $insertion, self::DUPLICATE );
				continue;
			}

			$batch ??= BatchId::generate();
			$id      = $this->changes->record(
				$batch,
				$post_id,
				ChangeRepository::INSERT,
				$edit['path'],
				$edit['before'],
				$edit['after'],
				PostWriter::hash( $content ),
				$user_id,
				ChangeRepository::ORIGIN_EDITOR,
				$key
			);
			if ( 0 === $id ) {
				$results[] = $this->result( $insertion, self::UNVERIFIED );
				continue;
			}

			$results[] = $this->result( $insertion, self::RECORDED, $id );
		}//end foreach

		$recorded = array_filter( $results, static fn( array $result ): bool => self::RECORDED === $result['status'] );

		return array(
			'batch_id' => array() === $recorded ? null : $batch,
			'results'  => $results,
		);
	}

	/**
	 * Resultado de un enlace.
	 *
	 * @param string $insertion Identificador de la inserción.
	 * @param string $status    Estado.
	 * @param int    $change_id Fila del historial, si hay.
	 *
	 * @return array{insertion_id: string, status: string, change_id: int}
	 */
	private function result( string $insertion, string $status, int $change_id = 0 ): array {
		return array(
			'insertion_id' => $insertion,
			'status'       => $status,
			'change_id'    => $change_id,
		);
	}

	/**
	 * Dirección del destino, si existe, es otra entrada y el usuario puede leerla. La pone el servidor: el cliente
	 * solo dice el `id`.
	 *
	 * @param int $target_id Destino.
	 * @param int $post_id   Entrada que enlaza.
	 * @param int $user_id   Usuario.
	 */
	private function target_url( int $target_id, int $post_id, int $user_id ): string {
		if ( $target_id <= 0 || $target_id === $post_id ) {
			return '';
		}

		$target = get_post( $target_id );
		if ( ! $target instanceof WP_Post || ( $user_id > 0 && ! user_can( $user_id, 'read_post', $target_id ) ) ) {
			return '';
		}

		return (string) get_permalink( $target );
	}

	/**
	 * Los `<a>` del documento que apuntan a `$url` con ese texto, con su posición.
	 *
	 * En un documento de bloques solo se miran los tramos de HTML propio de cada bloque (nunca los comentarios
	 * de bloque, cuyo JSON podría contener algo parecido a un enlace).
	 *
	 * @param string        $content Contenido guardado.
	 * @param BlockMap|null $map     Mapa de bloques; null en el editor clásico.
	 * @param string        $url     Dirección del destino.
	 * @param string        $anchor  Texto del ancla.
	 *
	 * @return list<array{start: int, end: int, open: int, inner: string, path: string|null, from: int, to: int}> `start`/`end`: el `<a>` entero; `open`: longitud de la etiqueta de apertura; `path`/`from`/`to`: el tramo que se guarda (bloque o trozo clásico).
	 */
	private function matches( string $content, ?BlockMap $map, string $url, string $anchor ): array {
		$wanted = self::normalize( $anchor );
		$found  = array();

		$spans = array();
		if ( null === $map ) {
			$spans[] = array( 0, strlen( $content ), null, 0, 0 );
		} else {
			foreach ( $map->walk() as $node ) {
				foreach ( $node->segments as [ $from, $to ] ) {
					$spans[] = array( $from, $to, $node->path, $node->start, $node->end );
				}
			}
		}

		foreach ( $spans as [ $from, $to, $path, $node_start, $node_end ] ) {
			$html = substr( $content, $from, $to - $from );
			if ( 1 > (int) preg_match_all( '~<a(?=[\s>])((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>~i', $html, $opens, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $opens[0] as [ $tag, $at ] ) {
				$at    = (int) $at;
				$close = stripos( $html, '</a>', $at + strlen( $tag ) );
				if ( false === $close ) {
					continue;
				}

				$inner = substr( $html, $at + strlen( $tag ), $close - $at - strlen( $tag ) );
				if ( 1 === preg_match( '~<a(?=[\s>])~i', $inner ) ) {
					// Un enlace dentro de otro: no es el que puso el panel.
					continue;
				}

				$href = self::href( $tag );
				if ( ! self::same_url( $href, $url ) || self::normalize( wp_strip_all_tags( $inner ) ) !== $wanted ) {
					continue;
				}

				$start                     = $from + $at;
				$end                       = $from + $close + 4;
				[ $chunk_from, $chunk_to ] = null === $map ? ( new ClassicEditor() )->chunk( $content, $start, $end ) : array( $node_start, $node_end );

				$found[] = array(
					'start' => $start,
					'end'   => $end,
					'open'  => strlen( $tag ),
					'inner' => $inner,
					'path'  => null === $map ? '@' . $chunk_from : (string) $path,
					'from'  => $chunk_from,
					'to'    => $chunk_to,
				);
			}//end foreach
		}//end foreach

		return $found;
	}

	/**
	 * Quita el `<a>` encontrado del documento, comprueba que añadirlo es un cambio válido y devuelve el tramo
	 * antes y después tal como lo habría guardado la inserción del servidor.
	 *
	 * @param string $content Contenido guardado (con el enlace).
	 * @param array  $found   Enlace encontrado.
	 *
	 * @phpstan-param array{start: int, end: int, open: int, inner: string, path: string|null, from: int, to: int} $found
	 *
	 * @return array{path: string, before: string, after: string}|null Null si el verificador no lo da por bueno.
	 */
	private function edit( string $content, array $found ): ?array {
		$without = substr( $content, 0, $found['start'] ) . $found['inner'] . substr( $content, $found['end'] );
		$delta   = strlen( $content ) - strlen( $without );
		$range   = array( $found['from'], $found['to'] - $delta );

		if ( null === $found['path'] || $range[1] < $range[0] || ! Verifier::check( $without, $content, $range, 1 )->ok ) {
			return null;
		}

		$before = substr( $without, $range[0], $range[1] - $range[0] );
		$after  = substr( $content, $found['from'], $found['to'] - $found['from'] );

		// Lo que Deshacer buscará sin el contenido original: tiene que dar exactamente este enlace.
		$link = Undo::inserted_link( $before, $after );
		if ( null === $link || substr( $content, $found['start'], $found['end'] - $found['start'] ) !== $link['exact'] || substr_count( $content, $link['exact'] ) !== 1 ) {
			return null;
		}

		return array(
			'path'   => $found['path'],
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * Valor de `href` de una etiqueta de apertura.
	 *
	 * @param string $open Etiqueta `<a …>`.
	 */
	private static function href( string $open ): string {
		$tags = new WP_HTML_Tag_Processor( $open );
		if ( ! $tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
			return '';
		}

		$href = $tags->get_attribute( 'href' );

		return is_string( $href ) ? $href : '';
	}

	/**
	 * Si dos direcciones son la misma (sin distinguir la barra final).
	 *
	 * @param string $href Dirección del enlace.
	 * @param string $url  Dirección del destino.
	 */
	private static function same_url( string $href, string $url ): bool {
		return '' !== $href && untrailingslashit( trim( $href ) ) === untrailingslashit( $url );
	}

	/**
	 * Texto comparable: entidades decodificadas, espacios (también el duro) colapsados.
	 *
	 * @param string $text Texto.
	 */
	private static function normalize( string $text ): string {
		$plain = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain = (string) preg_replace( '/[\x{200B}\x{FEFF}]+/u', '', $plain );

		return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $plain ) );
	}
}
