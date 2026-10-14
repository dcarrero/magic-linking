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
use MagicLinking\Content\OpenTag;
use MagicLinking\Content\PostWriter;
use MagicLinking\Content\Verifier;
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
	 * @phpstan-param list<array<string, mixed>> $links
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
	 * El documento se recorre una vez para todos los enlaces y las claves y los tramos ya registrados se leen con una
	 * consulta cada uno, no una por enlace: la reserva de la entrada dura lo mínimo.
	 *
	 * @param int   $post_id Entrada.
	 * @param int   $user_id Usuario.
	 * @param array $links   Enlaces, tal como llegan (se validan aquí uno a uno).
	 *
	 * @phpstan-param list<array<string, mixed>> $links
	 *
	 * @return array{batch_id: string|null, results: list<array{insertion_id: string, status: string, change_id: int}>}
	 */
	private function run( int $post_id, int $user_id, array $links ): array {
		$content = $this->writer->read( $post_id );
		$blocks  = BlockEditor::handles( $content );
		$map     = $blocks ? BlockMap::parse( $content ) : null;
		$batch   = null;
		$results = array();

		// Validación de cada elemento: uno mal formado se queda en `invalid` sin afectar al resto.
		$clean = array();
		$keys  = array();
		foreach ( $links as $index => $link ) {
			$insertion = is_string( $link['insertion_id'] ?? null ) ? $link['insertion_id'] : '';
			$suggest   = is_string( $link['suggestion'] ?? null ) ? $link['suggestion'] : '';
			$anchor    = is_string( $link['anchor'] ?? null ) ? $link['anchor'] : '';
			$target    = is_int( $link['target_id'] ?? null ) ? $link['target_id'] : 0;

			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $insertion ) || strlen( $suggest ) > 300 || strlen( $anchor ) > 500 || '' === self::normalize( $anchor ) || $target <= 0 ) {
				$results[ $index ] = $this->result( $insertion, self::INVALID );
				continue;
			}

			$clean[ $index ] = array( $insertion, self::key( $post_id, $suggest, $target, $anchor, $insertion ), $target, $anchor );
			$keys[]          = $clean[ $index ][1];
		}

		$existing = $this->changes->ids_for_origin_keys( $keys );
		$active   = $this->changes->active_inserts_of_post( $post_id );
		$anchors  = $blocks && null === $map ? null : $this->anchors( $content, $map );
		$urls     = array();

		foreach ( $clean as $index => [ $insertion, $key, $target, $anchor ] ) {
			if ( isset( $existing[ $key ] ) ) {
				$results[ $index ] = $this->result( $insertion, self::DUPLICATE, $existing[ $key ] );
				continue;
			}

			$urls[ $target ] ??= $this->target_url( $target, $post_id, $user_id );
			if ( '' === $urls[ $target ] ) {
				$results[ $index ] = $this->result( $insertion, self::INVALID );
				continue;
			}

			if ( null === $anchors ) {
				$results[ $index ] = $this->result( $insertion, self::UNVERIFIED );
				continue;
			}

			$found = $this->matches( $anchors, $urls[ $target ], $anchor );
			if ( array() === $found ) {
				$results[ $index ] = $this->result( $insertion, self::NOT_FOUND );
				continue;
			}
			if ( count( $found ) > 1 ) {
				$results[ $index ] = $this->result( $insertion, self::AMBIGUOUS );
				continue;
			}

			$edit = $this->edit( $content, $found[0] );
			if ( null === $edit ) {
				PostWriter::log( sprintf( 'El enlace del editor en la entrada %d no se pudo verificar; no se registra.', $post_id ) );
				$results[ $index ] = $this->result( $insertion, self::UNVERIFIED );
				continue;
			}

			foreach ( $active as $row ) {
				if ( $row['before_html'] === $edit['before'] && $row['after_html'] === $edit['after'] ) {
					$results[ $index ] = $this->result( $insertion, self::DUPLICATE, $row['id'] );
					continue 2;
				}
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
				$results[ $index ] = $this->result( $insertion, self::UNVERIFIED );
				continue;
			}

			$active[]          = $this->changes->get( $id ) ?? array();
			$results[ $index ] = $this->result( $insertion, self::RECORDED, $id );
		}//end foreach

		$this->reconcile( $content, $active );
		ksort( $results );
		$results  = array_values( $results );
		$recorded = array_filter( $results, static fn( array $result ): bool => self::RECORDED === $result['status'] );

		return array(
			'batch_id' => array() === $recorded ? null : $batch,
			'results'  => $results,
		);
	}

	/**
	 * Marca como deshechos los enlaces del editor ya registrados que ya no están en el contenido guardado (el usuario
	 * los quitó con el panel o a mano y guardó). No escribe en la entrada; es lo que haría Deshacer al encontrar el
	 * enlace «ya quitado» y deja el Historial al día (D-58).
	 *
	 * @param string $content Contenido guardado.
	 * @param array  $active  Inserciones puestas de la entrada.
	 *
	 * @phpstan-param list<array<string, mixed>> $active
	 */
	private function reconcile( string $content, array $active ): void {
		foreach ( $active as $row ) {
			if ( ! isset( $row['id'], $row['origin'] ) || ChangeRepository::ORIGIN_EDITOR !== $row['origin'] ) {
				continue;
			}

			$link = Undo::inserted_link( (string) $row['before_html'], (string) $row['after_html'] );
			if ( null !== $link && ! str_contains( $content, $link['open'] ) && ! str_contains( $content, $link['exact'] ) ) {
				$this->changes->mark_undone( (int) $row['id'] );
			}
		}
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
	 * Todos los `<a>` del documento, con su posición, su dirección y su texto normalizado (una sola pasada).
	 *
	 * En un documento de bloques solo se miran los tramos de HTML propio de cada bloque (nunca los comentarios
	 * de bloque, cuyo JSON podría contener algo parecido a un enlace).
	 *
	 * @param string        $content Contenido guardado.
	 * @param BlockMap|null $map     Mapa de bloques; null en el editor clásico.
	 *
	 * @return list<array{start: int, end: int, open: int, inner: string, path: string|null, from: int, to: int, href: string, text: string}> `start`/`end`: el `<a>` entero; `open`: longitud de la etiqueta de apertura; `path`/`from`/`to`: el tramo que se guarda (bloque o trozo clásico).
	 */
	private function anchors( string $content, ?BlockMap $map ): array {
		$found  = array();
		$spans  = array();
		$chunks = new ClassicEditor();
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

				$start                     = $from + $at;
				$end                       = $from + $close + 4;
				[ $chunk_from, $chunk_to ] = null === $map ? $chunks->chunk( $content, $start, $end ) : array( $node_start, $node_end );

				$found[] = array(
					'start' => $start,
					'end'   => $end,
					'open'  => strlen( $tag ),
					'inner' => $inner,
					'path'  => null === $map ? '@' . $chunk_from : (string) $path,
					'from'  => $chunk_from,
					'to'    => $chunk_to,
					'href'  => OpenTag::href( $tag ),
					'text'  => self::normalize( wp_strip_all_tags( $inner ) ),
				);
			}//end foreach
		}//end foreach

		return $found;
	}

	/**
	 * Los `<a>` que apuntan a `$url` con ese texto.
	 *
	 * @param array  $anchors Resultado de {@see self::anchors()}.
	 * @param string $url     Dirección del destino.
	 * @param string $anchor  Texto del ancla.
	 *
	 * @phpstan-param list<array{start: int, end: int, open: int, inner: string, path: string|null, from: int, to: int, href: string, text: string}> $anchors
	 *
	 * @return list<array{start: int, end: int, open: int, inner: string, path: string|null, from: int, to: int, href: string, text: string}>
	 */
	private function matches( array $anchors, string $url, string $anchor ): array {
		$wanted = self::normalize( $anchor );

		return array_values( array_filter( $anchors, static fn( array $found ): bool => $found['text'] === $wanted && self::same_url( $found['href'], $url ) ) );
	}

	/**
	 * Quita el `<a>` encontrado del documento, comprueba que añadirlo es un cambio válido y devuelve el tramo
	 * antes y después tal como lo habría guardado la inserción del servidor.
	 *
	 * @param string $content Contenido guardado (con el enlace).
	 * @param array  $found   Enlace encontrado.
	 *
	 * @phpstan-param array{start: int, end: int, open: int, inner: string, path: string|null, from: int, to: int, href: string, text: string} $found
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
