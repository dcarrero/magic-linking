<?php
/**
 * Entrada ya extraída, lista para analizar.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Campos de una entrada que usa el motor, sin WordPress.
 */
final class Document {

	/**
	 * Crea el documento.
	 *
	 * @param int         $id         ID de la entrada.
	 * @param string      $type       Tipo de contenido («post», «page»…).
	 * @param string      $lang       Código de idioma.
	 * @param string      $title      Título.
	 * @param string      $slug       Slug (último tramo de la URL).
	 * @param int         $date       Fecha de la última versión conocida (marca de tiempo).
	 * @param string[]    $headings   Encabezados.
	 * @param Paragraph[] $paragraphs Párrafos del cuerpo.
	 * @param Link[]      $links      Enlaces internos que ya existen en la entrada.
	 * @param string[]    $focus      Frases objetivo (palabra clave foco).
	 * @param array       $taxonomies Taxonomía (`category`, `post_tag`…) → valores (slugs); para los filtros por taxonomía.
	 *
	 * @phpstan-param list<string> $headings
	 * @phpstan-param list<Paragraph> $paragraphs
	 * @phpstan-param list<Link> $links
	 * @phpstan-param list<string> $focus
	 * @phpstan-param array<string, list<string>> $taxonomies
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly string $lang,
		public readonly string $title,
		public readonly string $slug = '',
		public readonly int $date = 0,
		public readonly array $headings = array(),
		public readonly array $paragraphs = array(),
		public readonly array $links = array(),
		public readonly array $focus = array(),
		public readonly array $taxonomies = array()
	) {
	}

	/**
	 * IDs de las entradas a las que ya enlaza, sin repetir y sin ella misma.
	 *
	 * @return list<int>
	 */
	public function linked(): array {
		$ids = array();
		foreach ( $this->links as $link ) {
			if ( null !== $link->target && $link->target !== $this->id ) {
				$ids[ $link->target ] = true;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * La misma entrada como si no tuviera enlaces internos (para medir el motor sin los enlaces existentes):
	 * el texto de las anclas se queda, los enlaces desaparecen.
	 */
	public function without_links(): self {
		$paragraphs = array_map( static fn( Paragraph $p ): Paragraph => new Paragraph( $p->text ), $this->paragraphs );

		return new self( $this->id, $this->type, $this->lang, $this->title, $this->slug, $this->date, $this->headings, $paragraphs, array(), $this->focus, $this->taxonomies );
	}
}
