<?php
/**
 * Regla automática de enlazado.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rules;

use MagicLinking\Engine\RuleSpec;

/**
 * Una fila de `magiclinking_rules` ya leída: frase y variantes, destino y opciones (docs/02 §4.4, docs/03 §4.5).
 *
 * El hueco de una palabra no tiene columna propia: viaja en el JSON de `exclude` junto a las exclusiones, así que
 * el esquema no cambia (decisión propuesta en el PR de F1-13).
 */
final class Rule {

	/**
	 * Máximo de variantes de una regla.
	 */
	public const MAX_VARIANTS = 30;

	/**
	 * Máximo de enlaces por entrada que se puede pedir a una regla (el tope real lo pone el ajuste del usuario).
	 */
	public const MAX_PER_POST = 10;

	/**
	 * Crea la regla.
	 *
	 * @param int      $id                 ID (0 si aún no se ha guardado).
	 * @param string[] $variants           Frases que la disparan.
	 * @param int      $target_id          Entrada de destino.
	 * @param string   $target_url         Dirección del destino cuando se guardó la regla (informativa: al enlazar manda `get_permalink()`).
	 * @param string   $lang               Idioma del destino.
	 * @param int      $max_per_post       Enlaces como máximo por entrada.
	 * @param bool     $first_only         Solo la primera aparición de cada frase.
	 * @param bool     $gap                Admitir una palabra más entre dos de la frase.
	 * @param string[] $exclude_post_types Tipos de contenido donde no se aplica.
	 * @param int[]    $exclude_categories Categorías (ID) cuyas entradas quedan fuera.
	 * @param bool     $active             Si se aplica al guardar.
	 * @param string   $created_at         Fecha de creación (GMT, formato MySQL).
	 * @param int      $created_by         Quien la creó.
	 *
	 * @phpstan-param list<string> $variants
	 * @phpstan-param list<string> $exclude_post_types
	 * @phpstan-param list<int> $exclude_categories
	 */
	public function __construct(
		public readonly int $id,
		public readonly array $variants,
		public readonly int $target_id,
		public readonly string $target_url,
		public readonly string $lang,
		public readonly int $max_per_post = 1,
		public readonly bool $first_only = true,
		public readonly bool $gap = false,
		public readonly array $exclude_post_types = array(),
		public readonly array $exclude_categories = array(),
		public readonly bool $active = true,
		public readonly string $created_at = '',
		public readonly int $created_by = 0
	) {
	}

	/**
	 * Lo que necesita el motor.
	 */
	public function spec(): RuleSpec {
		return new RuleSpec( $this->variants, $this->target_id, $this->max_per_post, $this->first_only, $this->gap );
	}

	/**
	 * Huella de lo que hace la regla (frases, destino y opciones que cambian dónde enlaza): si el usuario la edita,
	 * cuenta como una regla nueva a efectos de «ya se aplicó a esta entrada».
	 */
	public function fingerprint(): string {
		return md5( (string) wp_json_encode( array( $this->variants, $this->target_id, $this->gap, $this->first_only, $this->max_per_post ) ) );
	}

	/**
	 * Clave que se anota en el historial con cada enlace de la regla en una entrada: sirve para no volver a aplicar
	 * sola una regla que ya se aplicó (o se deshizo) en esa entrada.
	 *
	 * @param int $post_id Entrada.
	 */
	public function key_for( int $post_id ): string {
		return sha1( sprintf( 'rule|%d|%d|%s', $this->id, $post_id, $this->fingerprint() ) );
	}

	/**
	 * Opciones guardadas en la columna `exclude`.
	 */
	public function options_json(): string {
		return (string) wp_json_encode(
			array(
				'post_types' => $this->exclude_post_types,
				'categories' => $this->exclude_categories,
				'gap'        => $this->gap,
			)
		);
	}

	/**
	 * Regla a partir de una fila de la tabla.
	 *
	 * @param array<string, mixed> $row Fila.
	 */
	public static function from_row( array $row ): self {
		$options = json_decode( (string) ( $row['exclude'] ?? '' ), true );
		$options = is_array( $options ) ? $options : array();
		$lines   = preg_split( '/\R/u', (string) ( $row['phrase'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );

		return new self(
			(int) ( $row['id'] ?? 0 ),
			array_map( 'strval', false === $lines ? array() : $lines ),
			(int) ( $row['target_id'] ?? 0 ),
			(string) ( $row['target_url'] ?? '' ),
			(string) ( $row['lang'] ?? '' ),
			max( 1, (int) ( $row['max_per_post'] ?? 1 ) ),
			1 === (int) ( $row['first_only'] ?? 1 ),
			! empty( $options['gap'] ),
			array_values( array_map( 'strval', (array) ( $options['post_types'] ?? array() ) ) ),
			array_values( array_map( 'intval', (array) ( $options['categories'] ?? array() ) ) ),
			1 === (int) ( $row['active'] ?? 1 ),
			(string) ( $row['created_at'] ?? '' ),
			(int) ( $row['created_by'] ?? 0 )
		);
	}
}
