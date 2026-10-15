<?php
/**
 * Estimación de tokens y de coste (docs/05 §5.1).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\AI;

/**
 * Contador aproximado de tokens y precio estimado. Sin WordPress.
 *
 * Es una estimación para decidir y para el tope diario, no una factura: el recuento real lo devuelve el
 * proveedor y es el que se registra.
 */
final class CostEstimator {

	/**
	 * Caracteres por token en castellano y demás idiomas distintos del inglés (05 §5.1; con
	 * `granite-embedding` se midieron 4,4, así que sobreestima un poco a propósito).
	 */
	public const CHARS_PER_TOKEN = 3.6;

	/**
	 * Caracteres por token en inglés.
	 */
	public const CHARS_PER_TOKEN_EN = 4.0;

	/**
	 * Tokens que cuesta un texto, redondeado hacia arriba.
	 *
	 * @param string $text Texto.
	 * @param string $lang Código de idioma («es», «en»…); el inglés usa 4 caracteres por token y el resto, 3,6.
	 */
	public static function tokens( string $text, string $lang = 'es' ): int {
		$length = mb_strlen( $text, 'UTF-8' );
		if ( 0 === $length ) {
			return 0;
		}

		$per = 'en' === strtolower( substr( $lang, 0, 2 ) ) ? self::CHARS_PER_TOKEN_EN : self::CHARS_PER_TOKEN;

		return (int) ceil( $length / $per );
	}

	/**
	 * Coste en dólares de una petición.
	 *
	 * @param int         $tokens_in  Tokens de entrada.
	 * @param int         $tokens_out Tokens de salida.
	 * @param string|null $model      Modelo; null si no se conoce.
	 * @param bool        $local      El modelo es local: el coste es cero.
	 *
	 * @return float|null Null si el modelo no está en la tabla de precios (entonces solo se enseñan los tokens).
	 */
	public static function cost( int $tokens_in, int $tokens_out, ?string $model, bool $local = false ): ?float {
		if ( $local ) {
			return 0.0;
		}

		$price = null === $model ? null : Models::price( $model );
		if ( null === $price ) {
			return null;
		}

		return ( $tokens_in * $price[0] + $tokens_out * $price[1] ) / 1000000;
	}

	/**
	 * Estimación completa de una petición.
	 *
	 * @param int         $tokens_in  Tokens de entrada.
	 * @param int         $tokens_out Tokens de salida previstos.
	 * @param string|null $model      Modelo.
	 * @param bool        $local      El modelo es local.
	 *
	 * @return array{tokens_in: int, tokens_out: int, tokens: int, cost: float|null}
	 */
	public static function estimate( int $tokens_in, int $tokens_out, ?string $model, bool $local = false ): array {
		return array(
			'tokens_in'  => $tokens_in,
			'tokens_out' => $tokens_out,
			'tokens'     => $tokens_in + $tokens_out,
			'cost'       => self::cost( $tokens_in, $tokens_out, $model, $local ),
		);
	}
}
