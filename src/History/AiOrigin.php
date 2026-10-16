<?php
/**
 * Marca de un enlace que recomendó la IA (F1-38).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\History;

/**
 * Lo que el panel dice de un enlace que el usuario puso a partir de «Mejorar con IA»: la IA lo recomendaba, se usó el
 * ancla que propuso ella y con qué modelo. Es solo información para el Historial: no toca el contenido (regla 5 de
 * CLAUDE.md, nada de atributos en el enlace) y no da ningún permiso, así que el servidor no se fía del cliente para
 * nada más que esto y lo sanea: dos booleanos y una cadena corta de texto plano.
 */
final class AiOrigin {

	/**
	 * Longitud máxima del identificador del modelo (la columna `ai_model`).
	 */
	public const MODEL_MAX = 100;

	/**
	 * Constructor.
	 *
	 * @param bool   $anchor Si se usó el ancla que propuso la IA.
	 * @param string $model  Identificador exacto del modelo, saneado; vacío si no se dijo.
	 */
	public function __construct( public readonly bool $anchor, public readonly string $model ) {
	}

	/**
	 * Marca a partir de lo que llega del cliente (`ai_recommended`, `ai_anchor`, `ai_model`).
	 *
	 * Solo existe si la IA recomendaba el enlace: el ancla y el modelo no significan nada sin eso.
	 *
	 * @param array $input Enlace tal como llega.
	 *
	 * @phpstan-param array<array-key, mixed> $input
	 *
	 * @return self|null Null si el enlace no lo recomendó la IA.
	 */
	public static function from_input( array $input ): ?self {
		if ( ! self::truthy( $input['ai_recommended'] ?? null ) ) {
			return null;
		}

		$model = is_string( $input['ai_model'] ?? null ) ? sanitize_text_field( $input['ai_model'] ) : '';

		return new self( self::truthy( $input['ai_anchor'] ?? null ), trim( mb_substr( $model, 0, self::MODEL_MAX, 'UTF-8' ) ) );
	}

	/**
	 * Si un valor del cliente es un «sí» (booleano, 1 o «1»/«true»).
	 *
	 * @param mixed $value Valor.
	 */
	private static function truthy( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}
}
