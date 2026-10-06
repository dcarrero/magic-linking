<?php
/**
 * Contrato del extractor de texto.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Convierte el contenido guardado de una entrada en texto limpio.
 *
 * Quita shortcodes, comentarios de bloque y bloques excluidos (código,
 * preformateado, tablas, citas, botones, navegación, bloques reutilizables),
 * separa los encabezados del cuerpo y marca los tramos que ya son enlace. El
 * banco usa una implementación con DOMDocument; la del plugin se decide más adelante.
 */
interface ContentExtractor {

	/**
	 * Extrae encabezados y párrafos.
	 *
	 * @param string $content Contenido guardado (bloques o editor clásico).
	 */
	public function extract( string $content ): ExtractedContent;
}
