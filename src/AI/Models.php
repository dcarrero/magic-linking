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
	 * Modelos de texto en orden de preferencia, de más barato y rápido a menos: primero los pequeños sin
	 * razonamiento de la nube por precio (`gemini-2.5-flash-lite`, `gpt-4.1-nano`, `gpt-4o-mini`, `gemini-2.0-flash`,
	 * `gpt-4.1-mini`, `gemini-2.5-flash`, «haiku»), después los de Ollama (si el proveedor es local el orden de los
	 * locales no cambia) y al final los de razonamiento. Estos tardan más que el límite del editor y con un tope de
	 * salida bajo devuelven texto vacío (05 §10). Decidido por el usuario el 15-10-2026 (D-61 anexo 9): el
	 * automático es el barato.
	 */
	public const TEXT = array(
		'gemini-2.5-flash-lite',
		'gpt-4.1-nano',
		'gpt-4o-mini',
		'gemini-2.0-flash',
		'gpt-4.1-mini',
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
	 * Son precios de lista públicos (páginas de precios de OpenAI, Google AI y Anthropic; OpenRouter cobra casi lo
	 * mismo), fechados en `PRICES_DATE` y **sin verificar contra las páginas** en la última revisión (D-61 anexo
	 * 9): revisar antes de publicar. Los usa solo la estimación que se enseña al usuario. El importe real lo cobra
	 * su proveedor.
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
	 * Sufijos de variante de OpenRouter (`modelo:sufijo`). Solo estos se quitan al normalizar: los demás dos puntos
	 * son parte del nombre (`llama3.2:3b` de Ollama).
	 */
	private const VARIANTS = array( 'batch', 'free', 'nitro', 'floor', 'thinking', 'online', 'extended', 'exacto', 'beta' );

	/**
	 * Variantes que nunca se eligen en automático ni se aceptan fijadas a mano: `batch` es la API por lotes
	 * (asíncrona, no sirve para una petición del editor); `thinking` razona (05 §10); `online` añade búsqueda web
	 * con coste propio; `free` y `extended` tienen precio o límites que la tabla no recoge.
	 */
	private const AVOID = array( 'batch', 'free', 'thinking', 'online', 'extended', 'beta' );

	/**
	 * Cuántos modelos se recomiendan como máximo en Ajustes.
	 */
	public const RECOMMENDED_MAX = 5;

	/**
	 * Identificador sin proveedor delante, sin variante, en minúsculas y con versiones de Claude con guion.
	 *
	 * Quita todo hasta la última `/` (`models/` de Google, `openai/` y `~openai/` de OpenRouter), la variante
	 * conocida (`:batch`, `:free`…) y pasa `claude-haiku-4.5` a `claude-haiku-4-5`.
	 *
	 * @param string $model Identificador del modelo.
	 */
	public static function normalize( string $model ): string {
		$model = strtolower( trim( $model ) );

		$slash = strrpos( $model, '/' );
		if ( false !== $slash ) {
			$model = substr( $model, $slash + 1 );
		}

		$variant = self::variant( $model );
		if ( '' !== $variant ) {
			$model = substr( $model, 0, -( strlen( $variant ) + 1 ) );
		}

		if ( str_starts_with( $model, 'claude' ) ) {
			$model = (string) preg_replace( '/(\d)\.(\d)/', '$1-$2', $model );
		}

		return $model;
	}

	/**
	 * Variante de OpenRouter del identificador (`batch` en `openai/gpt-4.1-mini:batch`); cadena vacía si no hay.
	 *
	 * @param string $model Identificador del modelo.
	 */
	public static function variant( string $model ): string {
		$model = strtolower( trim( $model ) );
		$colon = strrpos( $model, ':' );
		if ( false === $colon ) {
			return '';
		}

		$suffix = substr( $model, $colon + 1 );

		return in_array( $suffix, self::VARIANTS, true ) ? $suffix : '';
	}

	/**
	 * Si se puede fijar a mano: todo salvo `:batch`, que es asíncrono y no sirve para el editor (el usuario puede
	 * querer un `:free` o un `:thinking` a sabiendas).
	 *
	 * @param string $model Identificador del modelo.
	 */
	public static function allowed( string $model ): bool {
		return 'batch' !== self::variant( $model );
	}

	/**
	 * Si el modelo puede elegirse en automático (no es `:batch`, `:free`…).
	 *
	 * @param string $model Identificador del modelo.
	 */
	public static function usable( string $model ): bool {
		return ! in_array( self::variant( $model ), self::AVOID, true );
	}

	/**
	 * Precio del modelo: [entrada, salida] en dólares por millón de tokens; null si no está en la tabla.
	 *
	 * Gana el prefijo más largo (`gpt-4o-mini` antes que `gpt-4o`). Las variantes `:free`, `:batch`… se tratan como
	 * desconocidas (no se promete un precio que la tabla no recoge). OpenRouter cobra, con poca diferencia, lo que
	 * el proveedor de origen: sirve la misma tabla.
	 *
	 * @param string $model Identificador del modelo.
	 *
	 * @return array{0: float, 1: float}|null
	 */
	public static function price( string $model ): ?array {
		if ( ! self::usable( $model ) ) {
			return null;
		}

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
	 * Los `:batch` quedan fuera siempre, también si el usuario lo fijó (se ignora y se elige en automático); el
	 * automático descarta además `:free`, `:thinking`, `:online`, `:extended` y `:beta` (`usable()`). Orden: el fijado por el usuario si está disponible; el primero
	 * de TEXT que lo esté (a igualdad, el identificador exacto antes que los de fecha, y luego el más corto); el más
	 * barato de la tabla de precios; el primero de los utilizables (sin precio la estimación enseña solo tokens y la
	 * confirmación sigue siendo obligatoria, regla 4). Null si no hay ninguno utilizable.
	 *
	 * @param array  $available Identificadores de los modelos que pueden generar texto.
	 * @param string $chosen    Modelo fijado por el usuario; vacío = automático.
	 *
	 * @phpstan-param list<string> $available
	 */
	public static function pick( array $available, string $chosen = '' ): ?string {
		if ( '' !== $chosen && self::allowed( $chosen ) && in_array( $chosen, $available, true ) ) {
			return $chosen;
		}

		$available = array_values( array_filter( $available, array( self::class, 'usable' ) ) );
		if ( array() === $available ) {
			return null;
		}

		foreach ( self::TEXT as $wanted ) {
			$best = self::best_for( $available, $wanted );
			if ( null !== $best ) {
				return $best;
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

	/**
	 * Modelos que se recomiendan en Ajustes: para cada familia de `TEXT`, en ese orden, el mejor identificador
	 * utilizable de los disponibles; como mucho `RECOMMENDED_MAX`, sin repetir. Es el mismo criterio de `pick()`.
	 *
	 * @param array $available Identificadores disponibles.
	 *
	 * @phpstan-param list<string> $available
	 *
	 * @return list<string>
	 */
	public static function recommended( array $available ): array {
		$available = array_values( array_filter( $available, array( self::class, 'usable' ) ) );
		$out       = array();
		foreach ( self::TEXT as $wanted ) {
			$best = self::best_for( $available, $wanted );
			if ( null !== $best && ! in_array( $best, $out, true ) ) {
				$out[] = $best;
				if ( count( $out ) >= self::RECOMMENDED_MAX ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * El mejor identificador de una familia de `TEXT` entre los disponibles; null si no hay.
	 *
	 * @param array  $available Identificadores.
	 * @param string $wanted    Familia (un valor de `TEXT`).
	 *
	 * @phpstan-param list<string> $available
	 */
	private static function best_for( array $available, string $wanted ): ?string {
		$best = null;
		foreach ( $available as $id ) {
			if ( ! str_starts_with( self::normalize( $id ), $wanted ) ) {
				continue;
			}
			if ( null === $best || self::rank( $id, $wanted ) < self::rank( $best, $wanted ) ) {
				$best = $id;
			}
		}

		return $best;
	}

	/**
	 * Orden entre candidatos al mismo modelo preferido: exacto sin variante, exacto con variante, y después el más corto.
	 *
	 * @param string $id     Identificador disponible.
	 * @param string $wanted Modelo preferido de TEXT.
	 */
	private static function rank( string $id, string $wanted ): int {
		$base = self::normalize( $id );

		return ( $base === $wanted ? 0 : 1000 ) + ( '' === self::variant( $id ) ? 0 : 500 ) + strlen( $base );
	}
}
