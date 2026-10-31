<?php
/**
 * Origen de las sugerencias entrantes, analizado en ligero.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Resultado de {@see Analyzer::analyze_for_anchors()}: un origen de las sugerencias entrantes, con sus frases y las formas
 * para mostrar de los términos del destino. No lleva el recuento de términos, así que no se puede pesar ni indexar:
 * {@see Indexer} y {@see Scorer} piden un {@see AnalyzedDocument}.
 */
final class AnchorSource extends AnchorText {
}
