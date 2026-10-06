<?php
/**
 * Resultado de extraer el texto de un contenido.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Encabezados y párrafos del cuerpo, ya sin marcas ni bloques excluidos.
 */
final class ExtractedContent {

	/**
	 * Crea el resultado.
	 *
	 * @param string[]    $headings   Texto de los encabezados.
	 * @param Paragraph[] $paragraphs Párrafos del cuerpo, en orden.
	 *
	 * @phpstan-param list<string> $headings
	 * @phpstan-param list<Paragraph> $paragraphs
	 */
	public function __construct(
		public readonly array $headings,
		public readonly array $paragraphs
	) {
	}
}
