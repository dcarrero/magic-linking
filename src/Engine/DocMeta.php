<?php
/**
 * Datos de una entrada indexada que el motor necesita sin cargar su texto.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Lo que guarda magiclinking_docs de cada entrada, más título y slug.
 */
final class DocMeta {

	/**
	 * Crea los datos.
	 *
	 * @param int      $id     ID de la entrada.
	 * @param string   $type   Tipo de contenido.
	 * @param string   $lang   Idioma.
	 * @param string   $title  Título.
	 * @param string   $slug   Slug.
	 * @param int      $date   Fecha (marca de tiempo).
	 * @param int      $words  Palabras del cuerpo.
	 * @param int      $length Palabras no vacías (doc_len).
	 * @param int      $links  Enlaces internos salientes.
	 * @param string[] $focus  Frases objetivo.
	 * @param array    $taxonomies Taxonomía → valores (filtros por tipo o taxonomía).
	 *
	 * @phpstan-param list<string> $focus
	 * @phpstan-param array<string, list<string>> $taxonomies
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly string $lang,
		public readonly string $title,
		public readonly string $slug,
		public readonly int $date,
		public readonly int $words,
		public readonly int $length,
		public readonly int $links,
		public readonly array $focus = array(),
		public readonly array $taxonomies = array()
	) {
	}
}
