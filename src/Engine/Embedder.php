<?php
/**
 * Contrato del proveedor de vectores.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Convierte textos en vectores. El motor no sabe de dónde salen: en el plugin
 * lo implementará el cliente de IA de WordPress (`wp_ai_client_embedding()`):
 * el plugin no habla con ningún proveedor de IA por su cuenta; en las
 * pruebas se usa un embedder de mentira.
 */
interface Embedder {

	/**
	 * Identificador estable del modelo (`proveedor/modelo`): los vectores solo
	 * se comparan con los del mismo modelo.
	 */
	public function model(): string;

	/**
	 * Un vector por texto, en el mismo orden.
	 *
	 * @param string[] $texts Textos.
	 * @return list<list<float>>
	 *
	 * @phpstan-param list<string> $texts
	 */
	public function embed( array $texts ): array;
}
