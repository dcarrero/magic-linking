<?php
/**
 * Estado de enlazado de una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

/**
 * Huérfana, poco enlazada, sobrecargada o bien.
 *
 * - Huérfana: ninguna entrante.
 * - Poco enlazada: menos entrantes que el umbral (por defecto 2), pero alguna.
 * - Sobrecargada: más salientes internas de las que caben a razón de un enlace cada N palabras
 *   (por defecto 100), con un mínimo de un enlace admitido aunque el texto sea corto.
 * Si se dan varias, manda la primera de esa lista.
 */
final class LinkStatus {

	public const ORPHAN = 'orphan';
	public const LOW    = 'low';
	public const OVER   = 'over';
	public const OK     = 'ok';

	/**
	 * Máximo de salientes internas que admite un texto.
	 *
	 * @param int $word_count     Palabras del texto.
	 * @param int $words_per_link Una saliente cada tantas palabras.
	 */
	public static function outbound_limit( int $word_count, int $words_per_link ): int {
		return max( 1, intdiv( $word_count, max( 1, $words_per_link ) ) );
	}

	/**
	 * Estado a partir de los recuentos.
	 *
	 * @param int $inbound        Entrantes.
	 * @param int $outbound       Salientes internas.
	 * @param int $word_count     Palabras.
	 * @param int $threshold      Umbral de «poco enlazada».
	 * @param int $words_per_link Una saliente cada tantas palabras.
	 */
	public static function of( int $inbound, int $outbound, int $word_count, int $threshold, int $words_per_link ): string {
		if ( 0 === $inbound ) {
			return self::ORPHAN;
		}
		if ( $inbound < $threshold ) {
			return self::LOW;
		}
		if ( $outbound > self::outbound_limit( $word_count, $words_per_link ) ) {
			return self::OVER;
		}

		return self::OK;
	}

	/**
	 * Etiqueta para el usuario.
	 *
	 * @param string $status Uno de los estados.
	 */
	public static function label( string $status ): string {
		switch ( $status ) {
			case self::ORPHAN:
				return __( 'Orphan', 'magic-linking' );
			case self::LOW:
				return __( 'Under-linked', 'magic-linking' );
			case self::OVER:
				return __( 'Over-linked', 'magic-linking' );
			default:
				return __( 'OK', 'magic-linking' );
		}
	}
}
