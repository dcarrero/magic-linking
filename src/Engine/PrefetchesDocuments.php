<?php
/**
 * Fuente de documentos que los trae por lotes.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Para los orígenes de las sugerencias entrantes: el motor avisa de los que va a pedir con
 * {@see DocumentSource::get()} y la fuente los carga de una vez (cachés de entradas, enlaces…).
 */
interface PrefetchesDocuments {

	/**
	 * Carga por adelantado unos documentos.
	 *
	 * @param int[] $ids IDs.
	 *
	 * @phpstan-param list<int> $ids
	 */
	public function prefetch( array $ids ): void;
}
