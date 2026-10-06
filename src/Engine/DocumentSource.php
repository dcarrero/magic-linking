<?php
/**
 * Contrato de acceso a los documentos completos.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * De dónde saca el motor el texto de una entrada: el JSON del banco o, en el
 * plugin, las entradas de WordPress.
 */
interface DocumentSource {

	/**
	 * Documento de una entrada, o null si no existe.
	 *
	 * @param int $id ID de la entrada.
	 */
	public function get( int $id ): ?Document;

	/**
	 * Todos los documentos, en un orden estable.
	 *
	 * @return iterable<Document>
	 */
	public function all(): iterable;
}
