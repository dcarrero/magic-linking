<?php
/**
 * Cómo se usa ya un ancla en el sitio, leído del grafo (F1-23, docs/04 §6.1).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

use MagicLinking\Core\Schema;
use MagicLinking\Engine\Analyzer;
use wpdb;

/**
 * Para pares (ancla, destino, origen) dice en cuántas entradas distintas (sin contar el origen) esa ancla ya lleva a ese
 * mismo destino y a qué otros destinos lleva y desde cuántas. Son las dos cuentas de los avisos de la tarjeta: ancla
 * sobreexplotada y canibalización.
 *
 * - **Mismo destino:** por clave de raíz (`Analyzer::phrase_key()`), como la cuenta que penaliza el motor
 *   (`IndexRepository::anchor_uses()`): «bomba de calor» y «bombas de calor» son la misma ancla. Se leen solo los
 *   enlaces de esos destinos (índice `target_id`) cuyo texto contiene la raíz de alguna ancla, y se confirma por clave.
 * - **Otros destinos:** por el texto del ancla sin distinguir mayúsculas ni acentos (la intercalación de la tabla),
 *   con el índice `anchor` (esquema 7), confirmado después por clave. El grafo no guarda la clave y buscarla por raíz
 *   obligaría a recorrer todos los enlaces del sitio. Una variante de otra raíz («bombas de calor» frente a «bomba de
 *   calor») no se ve como canibalización: el aviso informa, no bloquea.
 *
 * Dos consultas por llamada, sea cual sea el número de pares, y nunca una por sugerencia.
 */
final class AnchorUsage {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Clave de un par en el resultado de {@see self::measure()}.
	 *
	 * @param string $anchor    Ancla.
	 * @param int    $target_id Destino.
	 * @param int    $source_id Origen.
	 */
	public static function key( string $anchor, int $target_id, int $source_id ): string {
		return $source_id . "\0" . $target_id . "\0" . $anchor;
	}

	/**
	 * Uso de cada par: entradas que ya llevan esa ancla al mismo destino (`same`) y los otros destinos a los que
	 * ya la llevan (`elsewhere`, del más usado al menos, con las entradas distintas de cada uno). Los enlaces del propio
	 * origen no cuentan.
	 *
	 * @param array    $pairs    Pares `[ancla, destino, origen]`.
	 * @param Analyzer $analyzer Analizador del idioma con el que se calculan las claves.
	 *
	 * @phpstan-param list<array{0: string, 1: int, 2: int}> $pairs
	 *
	 * @return array<string, array{same: int, elsewhere: list<array{target: int, count: int}>}>
	 */
	public function measure( array $pairs, Analyzer $analyzer ): array {
		$keys     = array();
		$targets  = array();
		$surfaces = array();
		foreach ( $pairs as [ $anchor, $target ] ) {
			$key = $analyzer->phrase_key( $anchor );
			if ( '' !== $key && $target > 0 ) {
				$keys[ $anchor ]  = $key;
				$surfaces[ $key ] = (string) $anchor;
				$targets[]        = $target;
			}
		}
		if ( array() === $keys ) {
			return array();
		}

		$wanted = array_flip( array_values( array_unique( $keys ) ) );
		$same   = $this->same_target( array_values( array_unique( $targets ) ), $wanted, $surfaces, $analyzer );
		$other  = $this->other_targets( array_map( 'strval', array_keys( $keys ) ), $wanted, $analyzer );

		$out = array();
		foreach ( $pairs as [ $anchor, $target, $source ] ) {
			if ( ! isset( $keys[ $anchor ] ) ) {
				continue;
			}

			$key       = $keys[ $anchor ];
			$elsewhere = array();
			foreach ( $other[ $key ] ?? array() as $to => $sources ) {
				$count = count( $sources ) - ( isset( $sources[ $source ] ) ? 1 : 0 );
				if ( $to !== $target && $count > 0 ) {
					$elsewhere[] = array(
						'target' => $to,
						'count'  => $count,
					);
				}
			}
			// Los más usados primero; a igualdad, el de menor ID, para que el resultado sea estable.
			usort( $elsewhere, static fn( array $a, array $b ): int => array( $b['count'], $a['target'] ) <=> array( $a['count'], $b['target'] ) );

			$sources = $same[ $target ][ $key ] ?? array();

			$out[ self::key( $anchor, $target, $source ) ] = array(
				'same'      => count( $sources ) - ( isset( $sources[ $source ] ) ? 1 : 0 ),
				'elsewhere' => $elsewhere,
			);
		}//end foreach

		return $out;
	}

	/**
	 * Fragmentos de texto que tiene que contener el enlace de otra forma de la misma ancla, uno por clave: el comienzo de
	 * su raíz más larga. Solo vale si esa raíz aparece tal cual en el texto de la ancla; si alguna no aparece (el
	 * lematizador cambia letras: «ß» por «ss», terminaciones, diéresis) no se puede adelantar nada en SQL sin perder
	 * variantes y se devuelve null: se leen todos los enlaces del destino y se confirma por clave.
	 *
	 * @param array $surfaces Texto de la ancla por su clave de raíz.
	 *
	 * @phpstan-param array<string, string> $surfaces
	 *
	 * @return list<string>|null
	 */
	public static function stem_substrings( array $surfaces ): ?array {
		$out = array();
		foreach ( $surfaces as $key => $surface ) {
			$longest = '';
			foreach ( explode( ' ', (string) $key ) as $stem ) {
				if ( mb_strlen( $stem ) > mb_strlen( $longest ) ) {
					$longest = $stem;
				}
			}
			// Dos formas de una misma raíz no siempre comparten todas sus letras: se pide solo el comienzo.
			$part = mb_substr( $longest, 0, max( 3, mb_strlen( $longest ) - 1 ) );
			if ( '' === $part || false === mb_strpos( mb_strtolower( $surface ), mb_strtolower( $part ) ) ) {
				return null;
			}
			$out[ $part ] = true;
		}

		return array() === $out ? null : array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * Entradas distintas que llevan cada ancla a cada destino, por clave. Una consulta por el índice de destino que solo
	 * trae los enlaces cuyo texto contiene la raíz de alguna de las anclas.
	 *
	 * @param array    $targets  Destinos.
	 * @param array    $wanted   Claves de las anclas que importan (como claves del array).
	 * @param array    $surfaces Texto de cada ancla por su clave.
	 * @param Analyzer $analyzer Analizador.
	 *
	 * @phpstan-param list<int> $targets
	 * @phpstan-param array<string, int> $wanted
	 * @phpstan-param array<string, string> $surfaces
	 *
	 * @return array<int, array<string, array<int, true>>> Destino → clave → entradas.
	 */
	private function same_target( array $targets, array $wanted, array $surfaces, Analyzer $analyzer ): array {
		$wpdb  = $this->wpdb;
		$table = Schema::table( $wpdb->prefix, 'links' );
		$in    = implode( ',', array_fill( 0, count( $targets ), '%d' ) );

		$substrings = self::stem_substrings( $surfaces );
		$likes      = array_map( static fn( string $text ): string => '%' . $wpdb->esc_like( $text ) . '%', $substrings ?? array() );
		$any        = array() === $likes ? '1 = 1' : implode( ' OR ', array_fill( 0, count( $likes ), 'anchor LIKE %s' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia.
			$wpdb->prepare( "SELECT source_id, target_id, anchor FROM %i WHERE target_id IN ({$in}) AND is_internal = 1 AND is_broken = 0 AND source_id <> target_id AND ({$any})", array_merge( array( $table ), $targets, $likes ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
			ARRAY_N
		);

		$out  = array();
		$memo = array();
		foreach ( (array) $rows as $row ) {
			$text = (string) $row[2];
			$key  = $memo[ $text ] ??= $analyzer->phrase_key( $text );
			if ( isset( $wanted[ $key ] ) ) {
				$out[ (int) $row[1] ][ $key ][ (int) $row[0] ] = true;
			}
		}

		return $out;
	}

	/**
	 * Entradas distintas que llevan cada ancla (mismo texto, sin distinguir mayúsculas ni acentos) a cada destino.
	 *
	 * @param array    $anchors  Textos de las anclas.
	 * @param array    $wanted   Claves de las anclas que importan (como claves del array).
	 * @param Analyzer $analyzer Analizador.
	 *
	 * @phpstan-param list<string> $anchors
	 * @phpstan-param array<string, int> $wanted
	 *
	 * @return array<string, array<int, array<int, true>>> Clave → destino → entradas.
	 */
	private function other_targets( array $anchors, array $wanted, Analyzer $analyzer ): array {
		$wpdb  = $this->wpdb;
		$table = Schema::table( $wpdb->prefix, 'links' );
		$in    = implode( ',', array_fill( 0, count( $anchors ), '%s' ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia.
			$wpdb->prepare( "SELECT source_id, target_id, anchor FROM %i WHERE anchor IN ({$in}) AND target_id IS NOT NULL AND is_internal = 1 AND is_broken = 0 AND source_id <> target_id", array_merge( array( $table ), $anchors ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
			ARRAY_N
		);

		$out  = array();
		$memo = array();
		foreach ( (array) $rows as $row ) {
			$text = (string) $row[2];
			$key  = $memo[ $text ] ??= $analyzer->phrase_key( $text );
			if ( isset( $wanted[ $key ] ) ) {
				$out[ $key ][ (int) $row[1] ][ (int) $row[0] ] = true;
			}
		}

		return $out;
	}
}
