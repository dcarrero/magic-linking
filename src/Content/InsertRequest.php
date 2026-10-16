<?php
/**
 * Petición de insertar un enlace en una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

use MagicLinking\Engine\Suggestion;
use MagicLinking\History\AiOrigin;

/**
 * Lo que hace falta para enlazar un ancla en el texto de una entrada: la entrada, el destino, el ancla y su
 * contexto (hasta 30 caracteres a cada lado, docs/06 §3), y, si se conoce, el bloque en el que estaba.
 *
 * Es inmutable y no toca WordPress: los valores salen de una {@see Suggestion} (u otra frase elegida por
 * el usuario) y los valida el servicio al aplicarlos.
 */
final class InsertRequest {

	/**
	 * Caracteres de contexto a cada lado del ancla.
	 */
	public const CONTEXT = 30;

	/**
	 * Constructor.
	 *
	 * @param int           $post_id    Entrada en la que se inserta (el origen).
	 * @param string        $url        Dirección del destino.
	 * @param string        $anchor     Texto que se enlaza, tal cual está en la entrada.
	 * @param string        $before     Texto que precede al ancla (el final se usa como contexto).
	 * @param string        $after      Texto que sigue al ancla (el principio se usa como contexto).
	 * @param string|null   $block_path Ruta del bloque (`3.0.1`) si se conoce; si no, se busca en todo el documento.
	 * @param array         $attributes Atributos extra del enlace, solo `target` y `rel`.
	 * @param int|null      $user_id    Quien lo pide (null = el usuario actual; 0 = el sistema, sin comprobar capacidades).
	 * @param bool          $headings   Permitir enlaces en encabezados (ajuste del usuario).
	 * @param bool          $quotes     Permitir enlaces en citas (ajuste del usuario).
	 * @param bool          $tables     Permitir enlaces en tablas (ajuste del usuario).
	 * @param AiOrigin|null $ai       Si el enlace lo recomendó la IA (F1-38); solo se guarda en el historial.
	 *
	 * @phpstan-param array<string, string> $attributes
	 */
	public function __construct(
		public readonly int $post_id,
		public readonly string $url,
		public readonly string $anchor,
		public readonly string $before = '',
		public readonly string $after = '',
		public readonly ?string $block_path = null,
		public readonly array $attributes = array(),
		public readonly ?int $user_id = null,
		public readonly bool $headings = false,
		public readonly bool $quotes = false,
		public readonly bool $tables = false,
		public readonly ?AiOrigin $ai = null
	) {
	}

	/**
	 * La misma petición con los ajustes del usuario aplicados: donde se puede enlazar (basta con que lo permita la
	 * petición o el ajuste) y los atributos del enlace (los de la petición mandan sobre los del ajuste).
	 *
	 * @param array<string, bool>   $scope      Ajuste `headings`, `quotes` y `tables`.
	 * @param array<string, string> $attributes Atributos `target` y `rel` configurados.
	 */
	public function scoped( array $scope, array $attributes ): self {
		return new self(
			$this->post_id,
			$this->url,
			$this->anchor,
			$this->before,
			$this->after,
			$this->block_path,
			array_merge( $attributes, $this->attributes ),
			$this->user_id,
			$this->headings || ! empty( $scope['headings'] ),
			$this->quotes || ! empty( $scope['quotes'] ),
			$this->tables || ! empty( $scope['tables'] ),
			$this->ai
		);
	}

	/**
	 * Petición a partir de una frase del motor: la frase, el desplazamiento del ancla en bytes y el ancla.
	 *
	 * @param int           $post_id    Entrada origen.
	 * @param string        $url        Dirección del destino.
	 * @param string        $sentence   Frase del texto de la entrada (la que devuelve el motor).
	 * @param int           $offset     Byte de la frase donde empieza el ancla.
	 * @param string        $anchor     Ancla (un tramo literal de la frase).
	 * @param array         $attributes Atributos extra del enlace.
	 * @param string|null   $block_path Ruta del bloque, si se conoce.
	 * @param int|null      $user_id    Ver el constructor.
	 * @param bool          $headings   Ver el constructor.
	 * @param AiOrigin|null $ai       Ver el constructor.
	 *
	 * @phpstan-param array<string, string> $attributes
	 *
	 * @throws InsertionException Si el ancla no es un tramo literal de la frase.
	 */
	public static function from_sentence( int $post_id, string $url, string $sentence, int $offset, string $anchor, array $attributes = array(), ?string $block_path = null, ?int $user_id = null, bool $headings = false, ?AiOrigin $ai = null ): self {
		if ( '' === $anchor || $offset < 0 || substr( $sentence, $offset, strlen( $anchor ) ) !== $anchor ) {
			throw new InsertionException( InsertionException::BAD_REQUEST, __( 'The anchor is not part of the sentence.', 'magic-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mensaje ya traducido; quien lo muestra lo escapa al imprimirlo.
		}

		return new self(
			$post_id,
			$url,
			$anchor,
			substr( $sentence, 0, $offset ),
			substr( $sentence, $offset + strlen( $anchor ) ),
			$block_path,
			$attributes,
			$user_id,
			$headings,
			false,
			false,
			$ai
		);
	}

	/**
	 * Petición a partir de una sugerencia del motor.
	 *
	 * @param Suggestion  $suggestion Sugerencia saliente (su origen es la entrada que se edita).
	 * @param string      $url        Dirección del destino.
	 * @param array       $attributes Atributos extra del enlace.
	 * @param string|null $block_path Ruta del bloque, si se conoce.
	 * @param int|null    $user_id    Ver el constructor.
	 * @param bool        $headings   Ver el constructor.
	 *
	 * @phpstan-param array<string, string> $attributes
	 *
	 * @throws InsertionException Si el ancla no es un tramo literal de la frase.
	 */
	public static function from_suggestion( Suggestion $suggestion, string $url, array $attributes = array(), ?string $block_path = null, ?int $user_id = null, bool $headings = false ): self {
		return self::from_sentence( $suggestion->source, $url, $suggestion->sentence, $suggestion->offset, $suggestion->anchor, $attributes, $block_path, $user_id, $headings );
	}

	/**
	 * Texto que hay que encontrar en el documento: contexto anterior, ancla y contexto posterior, con los
	 * espacios colapsados como en el motor.
	 *
	 * @return array{0: string, 1: string, 2: string} Contexto anterior, ancla y contexto posterior.
	 */
	public function needle(): array {
		$before = self::collapse( $this->before );
		$after  = self::collapse( $this->after );

		// El contexto se corta por la izquierda y por la derecha a CONTEXT caracteres.
		$before = mb_strlen( $before, 'UTF-8' ) > self::CONTEXT ? mb_substr( $before, -self::CONTEXT, null, 'UTF-8' ) : $before;
		$after  = mb_strlen( $after, 'UTF-8' ) > self::CONTEXT ? mb_substr( $after, 0, self::CONTEXT, 'UTF-8' ) : $after;

		return array( $before, self::collapse( $this->anchor ), $after );
	}

	/**
	 * Colapsa cualquier secuencia de espacios (incluido el no separable) en uno.
	 *
	 * @param string $text Texto.
	 */
	private static function collapse( string $text ): string {
		return (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text );
	}
}
