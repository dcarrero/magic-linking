<?php
/**
 * Al guardar una entrada, marca como deshechos los enlaces del editor ya registrados que ya no están (F1-34, D-58).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\History;

use MagicLinking\Content\OpenTag;
use MagicLinking\Core\Installer;
use MagicLinking\Core\Module;
use WP_HTML_Tag_Processor;
use WP_Post;

/**
 * El panel registra un enlace del editor cuando el usuario guarda; si después lo quita (con el panel, con el editor o a
 * mano) y vuelve a guardar, el Historial debería dejar de darlo por puesto. Eso no depende del cliente: en cada guardado
 * real de una entrada (ni revisión ni autoguardado a una revisión) se comprueba cada fila `insert` de origen `editor`
 * aún activa y, si en el contenido no hay ningún `<a>` cuyo `href` sea el del enlace registrado y cuyo texto (sin
 * etiquetas, normalizado) sea su ancla, se marca como deshecha. No escribe nada en la entrada.
 *
 * La comprobación usa el procesador de etiquetas de WordPress, no cadenas: atributos añadidos después, otro orden de
 * atributos o una barra final no cambian el resultado. Si queda uno o más enlaces iguales la fila sigue activa (con dos
 * idénticos y uno quitado no se puede saber cuál era). Solo mira las {@see self::MAX_ROWS} filas activas más recientes
 * de la entrada; las más antiguas se quedan como estaban.
 */
final class EditorReconciler implements Module {

	/**
	 * Filas del editor que se comprueban como máximo por guardado (las más recientes).
	 */
	public const MAX_ROWS = 200;

	/**
	 * Constructor.
	 *
	 * @param ChangeRepository $changes Historial.
	 */
	public function __construct( private ChangeRepository $changes ) {
	}

	/**
	 * Engancha el guardado.
	 */
	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'on_saved' ), 30, 2 );
	}

	/**
	 * Se ha guardado una entrada.
	 *
	 * @param int          $post_id ID.
	 * @param WP_Post|null $post    Entrada.
	 */
	public function on_saved( int $post_id, ?WP_Post $post = null ): void {
		$post ??= get_post( $post_id );
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || (int) get_option( Installer::DB_VERSION_OPTION, 0 ) < 5 ) {
			return;
		}

		$rows = $this->changes->active_editor_rows( $post_id, self::MAX_ROWS );
		if ( array() === $rows ) {
			return;
		}

		$present = null;
		foreach ( $rows as $row ) {
			$link = Undo::inserted_link( $row['before_html'], $row['after_html'] );
			if ( null === $link ) {
				continue;
			}

			$present ??= self::links( $post->post_content );
			$href      = self::normalize_url( OpenTag::href( $link['open'] ) );
			$text      = self::normalize( wp_strip_all_tags( $link['inner'] ) );
			if ( ! in_array( array( $href, $text ), $present, true ) ) {
				$this->changes->mark_undone( $row['id'] );
			}
		}
	}

	/**
	 * Todos los enlaces del contenido como pares (dirección normalizada, texto normalizado).
	 *
	 * @param string $content Contenido.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	public static function links( string $content ): array {
		$found = array();
		$tags  = new WP_HTML_Tag_Processor( $content );
		$href  = null;
		$text  = '';

		while ( $tags->next_token() ) {
			$type = $tags->get_token_type();
			if ( '#tag' === $type && 'A' === $tags->get_tag() ) {
				if ( $tags->is_tag_closer() ) {
					if ( null !== $href ) {
						$found[] = array( $href, self::normalize( $text ) );
					}
					$href = null;
					continue;
				}

				$value = $tags->get_attribute( 'href' );
				$href  = is_string( $value ) ? self::normalize_url( $value ) : null;
				$text  = '';
			} elseif ( '#text' === $type && null !== $href ) {
				$text .= $tags->get_modifiable_text();
			}
		}

		return $found;
	}

	/**
	 * Dirección comparable: sin espacios ni barra final.
	 *
	 * @param string $url Dirección.
	 */
	public static function normalize_url( string $url ): string {
		return untrailingslashit( trim( $url ) );
	}

	/**
	 * Texto comparable: entidades decodificadas, sin espacios de ancho cero y espacios (también el duro) colapsados.
	 *
	 * @param string $text Texto.
	 */
	public static function normalize( string $text ): string {
		$plain = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain = (string) preg_replace( '/[\x{200B}\x{FEFF}]+/u', '', $plain );

		return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $plain ) );
	}
}
