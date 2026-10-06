<?php
/**
 * Motivo de una sugerencia.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Código y datos de un motivo. El texto se forma fuera del motor (en el plugin
 * con __(), en el banco en castellano), porque el motor no depende de WordPress.
 */
final class Reason {

	/**
	 * Comparten términos. Datos: `terms` (list<string>, formas para mostrar).
	 */
	public const SHARED_TERMS = 'shared_terms';

	/**
	 * El ancla coincide con el título del destino.
	 */
	public const ANCHOR_TITLE = 'anchor_title';

	/**
	 * El ancla coincide con la frase objetivo del destino.
	 */
	public const ANCHOR_FOCUS = 'anchor_focus';

	/**
	 * El destino es huérfano. Datos: `inbound` (int).
	 */
	public const ORPHAN = 'orphan';

	/**
	 * Muy parecidas por significado (solo con vectores).
	 */
	public const SEMANTIC = 'semantic';

	/**
	 * Crea el motivo.
	 *
	 * @param string $code Código (constantes de esta clase).
	 * @param array  $args Datos del motivo.
	 *
	 * @phpstan-param array<string, mixed> $args
	 */
	public function __construct(
		public readonly string $code,
		public readonly array $args = array()
	) {
	}
}
