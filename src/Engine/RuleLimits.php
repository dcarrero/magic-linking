<?php
/**
 * Topes de calidad duros de las reglas automáticas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Los tres topes de docs/04 §6.1 que en las reglas son duros: una regla nunca borra enlaces, solo se niega a añadir más.
 *
 * Son ajustes del usuario para la calidad del enlazado, no topes comerciales: mismo valor por defecto y mismo
 * rango en gratis y en Pro (docs/02 §3.2, D-30). Los valores por defecto viven aquí y en ningún otro sitio;
 * {@see \MagicLinking\Core\Settings::DEFAULTS} los toma de estas constantes.
 */
final class RuleLimits {

	/**
	 * Una saliente interna cada tantas palabras.
	 */
	public const WORDS_PER_LINK = 100;

	/**
	 * Enlaces al mismo destino desde una misma entrada.
	 */
	public const LINKS_PER_TARGET = 1;

	/**
	 * Entradas distintas que pueden usar la misma ancla exacta hacia el mismo destino.
	 */
	public const SAME_ANCHOR = 5;

	/**
	 * Crea los topes.
	 *
	 * @param int $words_per_link   Una saliente interna cada tantas palabras.
	 * @param int $links_per_target Enlaces al mismo destino desde una entrada.
	 * @param int $same_anchor_max  Entradas con la misma ancla hacia el mismo destino.
	 */
	public function __construct(
		public readonly int $words_per_link = self::WORDS_PER_LINK,
		public readonly int $links_per_target = self::LINKS_PER_TARGET,
		public readonly int $same_anchor_max = self::SAME_ANCHOR
	) {
	}

	/**
	 * Si una ancla que ya usan tantas entradas hacia el mismo destino llegó al tope: una entrada más pasaría de las
	 * permitidas. Es el criterio de las reglas (que dejan de aplicarse) y el del aviso de ancla sobreexplotada de la
	 * tarjeta; una sola función para los dos.
	 *
	 * @param int $uses Entradas distintas que ya llevan esa ancla a ese destino.
	 */
	public function same_anchor_reached( int $uses ): bool {
		return $uses >= $this->same_anchor_max;
	}

	/**
	 * Enlaces internos que admite una entrada según su longitud: `max(1, ⌊palabras / N⌋)`.
	 *
	 * @param int $words Palabras de la entrada.
	 */
	public function density_cap( int $words ): int {
		return max( 1, intdiv( max( 0, $words ), max( 1, $this->words_per_link ) ) );
	}
}
