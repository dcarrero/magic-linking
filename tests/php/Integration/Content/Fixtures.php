<?php
/**
 * Ayudas comunes de las pruebas de inserción.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Content;

use MagicLinking\Content\InsertRequest;

/**
 * Las clases que lo usan declaran `private const URL` (los rasgos no pueden tener constantes en PHP 8.1).
 */
trait Fixtures {

	/**
	 * Un párrafo de bloques, tal como lo guarda el editor.
	 *
	 * @param string $html Contenido del `<p>`.
	 */
	private function p( string $html ): string {
		return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Documento de bloques: los bloques separados por una línea en blanco, como en WordPress.
	 *
	 * @param string ...$blocks Bloques.
	 */
	private function doc( string ...$blocks ): string {
		return implode( "\n\n", $blocks );
	}

	/**
	 * Petición con el contexto dado.
	 *
	 * @param string      $anchor Ancla.
	 * @param string      $before Texto anterior.
	 * @param string      $after  Texto posterior.
	 * @param string|null $path   Ruta del bloque.
	 * @param int         $post   Entrada.
	 * @param bool        $heads  Permitir encabezados.
	 */
	private function req( string $anchor, string $before = '', string $after = '', ?string $path = null, int $post = 1, bool $heads = false ): InsertRequest {
		return new InsertRequest( $post, self::URL, $anchor, $before, $after, $path, array(), 0, $heads );
	}

	/**
	 * Enlace tal como lo escribe el plugin.
	 *
	 * @param string $inner Contenido del enlace.
	 */
	private function a( string $inner ): string {
		return '<a href="' . self::URL . '">' . $inner . '</a>';
	}
}
