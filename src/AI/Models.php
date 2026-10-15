<?php
/**
 * Modelos de texto preferidos y tabla de precios (docs/05 §4 y §5.1).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\AI;

/**
 * Qué modelo de texto se prefiere y cuánto cuesta. Sin WordPress: solo datos y comparaciones.
 *
 * Las dos listas se revisan en cada versión. Los identificadores se comparan por prefijo, porque los
 * proveedores publican el mismo modelo con sufijos de fecha (`gpt-4o-mini-2024-07-18`) o de ruta
 * (`models/gemini-2.5-flash`).
 */
final class Models {

	/**
	 * Fecha en que se revisó la tabla de precios. Se enseña junto a las estimaciones de coste.
	 */
	public const PRICES_DATE = '2026-10-15';

	/**
	 * Modelos de texto en orden de preferencia: pequeños y rápidos (familias «mini», «flash», «haiku») y sus
	 * equivalentes de Ollama. Se evitan los modelos de razonamiento, que con un tope de salida bajo devuelven texto
	 * vacío y tardan más que el límite del editor (05 §10); los de razonamiento solo entran al final.
	 */
	public const TEXT = array(
		'gpt-4.1-mini',
		'gpt-4o-mini',
		'gemini-2.5-flash-lite',
		'gemini-2.0-flash',
		'gemini-2.5-flash',
		'claude-haiku-4-5',
		'claude-3-5-haiku',
		'llama3.2:3b',
		'llama3.1:8b',
		'qwen2.5:3b',
		'qwen2.5:7b',
		'gemma3:4b',
		'phi4-mini',
		'granite3.3:2b',
		'mistral:7b',
		'gpt-5-nano',
		'gpt-5-mini',
	);

	/**
	 * Dólares por millón de tokens de entrada y de salida, por prefijo de modelo.
	 *
	 * Son precios de lista públicos; los usa solo la estimación que se enseña al usuario. El importe real lo
	 * cobra su proveedor.
	 */
	public const PRICES = array(
		'gpt-4.1-nano'          => array( 0.10, 0.40 ),
		'gpt-4.1-mini'          => array( 0.40, 1.60 ),
		'gpt-4.1'               => array( 2.00, 8.00 ),
		'gpt-4o-mini'           => array( 0.15, 0.60 ),
		'gpt-4o'                => array( 2.50, 10.00 ),
		'gpt-5-nano'            => array( 0.05, 0.40 ),
		'gpt-5-mini'            => array( 0.25, 2.00 ),
		'gemini-2.5-flash-lite' => array( 0.10, 0.40 ),
		'gemini-2.5-flash'      => array( 0.30, 2.50 ),
		'gemini-2.0-flash-lite' => array( 0.075, 0.30 ),
		'gemini-2.0-flash'      => array( 0.10, 0.40 ),
		'claude-haiku-4-5'      => array( 1.00, 5.00 ),
		'claude-3-5-haiku'      => array( 0.80, 4.00 ),
	);

	/**
	 * Identificador sin prefijo de ruta ni mayúsculas.
	 *
	 * @param string $model Identificador del modelo.
	 */
	public static function normalize( string $model ): string {
		$model = strtolower( trim( $model ) );

		return str_starts_with( $model, 'models/' ) ? substr( $model, 7 ) : $model;
	}

	/**
	 * Precio del modelo: [entrada, salida] en dólares por millón de tokens; null si no está en la tabla.
	 *
	 * Gana el prefijo más largo (`gpt-4o-mini` antes que `gpt-4o`).
	 *
	 * @param string $model Identificador del modelo.
	 *
	 * @return array{0: float, 1: float}|null
	 */
	public static function price( string $model ): ?array {
		$model = self::normalize( $model );
		$best  = null;

		foreach ( self::PRICES as $prefix => $price ) {
			if ( str_starts_with( $model, $prefix ) && ( null === $best || strlen( $prefix ) > strlen( $best ) ) ) {
				$best = $prefix;
			}
		}

		return null === $best ? null : self::PRICES[ $best ];
	}

	/**
	 * Elige el modelo de texto de entre los disponibles.
	 *
	 * Orden: el que el usuario fijó si está disponible; el primero de TEXT que lo esté; el más barato de la tabla
	 * de precios; el primero de la lista. Null si no hay ninguno.
	 *
	 * @param array  $available Identificadores de los modelos que pueden generar texto.
	 * @param string $chosen    Modelo fijado por el usuario; vacío = automático.
	 *
	 * @phpstan-param list<string> $available
	 */
	public static function pick( array $available, string $chosen = '' ): ?string {
		if ( array() === $available ) {
			return null;
		}

		if ( '' !== $chosen && in_array( $chosen, $available, true ) ) {
			return $chosen;
		}

		foreach ( self::TEXT as $wanted ) {
			foreach ( $available as $id ) {
				if ( str_starts_with( self::normalize( $id ), $wanted ) ) {
					return $id;
				}
			}
		}

		$cheapest = null;
		$lowest   = INF;
		foreach ( $available as $id ) {
			$price = self::price( $id );
			if ( null !== $price && $price[0] + $price[1] < $lowest ) {
				$lowest   = $price[0] + $price[1];
				$cheapest = $id;
			}
		}

		return $cheapest ?? $available[0];
	}
}
