<?php
/**
 * Contrato de los módulos del plugin.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Core;

/**
 * Un módulo engancha sus hooks en register() y no hace nada más al crearse.
 */
interface Module {

	/**
	 * Registra los hooks del módulo. Se llama una vez, en plugins_loaded.
	 */
	public function register(): void;
}
