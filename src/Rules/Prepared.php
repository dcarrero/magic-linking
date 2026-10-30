<?php
/**
 * Una entrada ya leída y analizada para aplicarle reglas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rules;

use MagicLinking\Engine\AnalyzedDocument;
use MagicLinking\Engine\Document;

/**
 * El documento del motor y su análisis con frases. Se construye una vez por guardado y se reutiliza con todas las reglas
 * mientras ninguna escriba en la entrada.
 */
final class Prepared {

	/**
	 * Crea la entrada preparada.
	 *
	 * @param Document         $document Documento con sus enlaces internos de ahora.
	 * @param AnalyzedDocument $analyzed Análisis con frases.
	 */
	public function __construct(
		public readonly Document $document,
		public readonly AnalyzedDocument $analyzed
	) {
	}
}
