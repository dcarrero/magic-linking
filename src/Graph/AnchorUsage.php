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
 * Para pares (ancla, destino) dice en cuántas entradas distintas esa ancla ya lleva a ese mismo destino y a qué otros
 * destinos lleva y desde cuántas. Son las dos cuentas de los avisos de la tarjeta: ancla sobreexplotada y
 * canibalización.
 *
 * - **Mismo destino:** por clave de raíz (`Analyzer::phrase_key()`), como la cuenta que penaliza el motor
 *   (`IndexRepository::anchor_uses()`): «bomba de calor» y «bombas de calor» son la misma ancla.
 * - **Otros destinos:** por el texto del ancla sin distinguir mayúsculas ni acentos (la intercalación de la tabla),
 *   confirmado después por clave. El grafo no guarda la clave y buscarla por raíz obligaría a recorrer todos los
 *   enlaces del sitio; así es una sola consulta por petición. Una variante de otra raíz («bombas de calor» frente a
 *   «bomba de calor») no se ve como canibalización: el aviso informa, no bloquea.
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
	 */
	public static function key( string $anchor, int $target_id ): string {
		return $target_id . "\0" . $anchor;
	}

	/**
	 * Uso de cada par: entradas que ya llevan esa ancla al mismo destino (`same`) y los otros destinos a los que
	 * ya la llevan (`elsewhere`, del más usado al menos, con las entradas distintas de cada uno).
	 *
	 * @param array    $pairs    Pares `[ancla, destino]`.
	 * @param Analyzer $analyzer Analizador del idioma con el que se calculan las claves.
	 *
	 * @phpstan-param list<array{0: string, 1: int}> $pairs
	 *
	 * @return array<string, array{same: int, elsewhere: list<array{target: int, count: int}>}>
	 */
	public function measure( array $pairs, Analyzer $analyzer ): array {
		$keys    = array();
		$targets = array();
		foreach ( $pairs as [ $anchor, $target ] ) {
			$key = $analyzer->phrase_key( $anchor );
			if ( '' !== $key && $target > 0 ) {
				$keys[ $anchor ] = $key;
				$targets[]       = $target;
			}
		}
		if ( array() === $keys ) {
			return array();
		}

		$wanted = array_flip( array_values( array_unique( $keys ) ) );
		$same   = $this->same_target( array_values( array_unique( $targets ) ), $wanted, $analyzer );
		$other  = $this->other_targets( array_keys( $keys ), $wanted, $analyzer );

		$out = array();
		foreach ( $pairs as [ $anchor, $target ] ) {
			if ( ! isset( $keys[ $anchor ] ) ) {
				continue;
			}

			$key       = $keys[ $anchor ];
			$elsewhere = array();
			foreach ( $other[ $key ] ?? array() as $to => $count ) {
				if ( $to !== $target ) {
					$elsewhere[] = array(
						'target' => $to,
						'count'  => $count,
					);
				}
			}
			// Los más usados primero; a igualdad, el de menor ID, para que el resultado sea estable.
			usort( $elsewhere, static fn( array $a, array $b ): int => array( $b['count'], $a['target'] ) <=> array( $a['count'], $b['target'] ) );

			$out[ self::key( $anchor, $target ) ] = array(
				'same'      => $same[ $target ][ $key ] ?? 0,
				'elsewhere' => $elsewhere,
			);
		}//end foreach

		return $out;
	}

	/**
	 * Entradas distintas que llevan cada ancla a cada destino, por clave. Una consulta por el índice de destino.
	 *
	 * @param array    $targets  Destinos.
	 * @param array    $wanted   Claves de las anclas que importan (como claves del array).
	 * @param Analyzer $analyzer Analizador.
	 *
	 * @phpstan-param list<int> $targets
	 * @phpstan-param array<string, int> $wanted
	 *
	 * @return array<int, array<string, int>> Destino → clave → entradas.
	 */
	private function same_target( array $targets, array $wanted, Analyzer $analyzer ): array {
		$wpdb  = $this->wpdb;
		$table = Schema::table( $wpdb->prefix, 'links' );
		$in    = implode( ',', array_fill( 0, count( $targets ), '%d' ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia.
			$wpdb->prepare( "SELECT source_id, target_id, anchor FROM %i WHERE target_id IN ({$in}) AND is_internal = 1 AND is_broken = 0 AND source_id <> target_id", array_merge( array( $table ), $targets ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
			ARRAY_N
		);

		$seen = array();
		$memo = array();
		foreach ( (array) $rows as $row ) {
			$text = (string) $row[2];
			$key  = $memo[ $text ] ??= $analyzer->phrase_key( $text );
			if ( isset( $wanted[ $key ] ) ) {
				$seen[ (int) $row[1] ][ $key ][ (int) $row[0] ] = true;
			}
		}

		$out = array();
		foreach ( $seen as $target => $by_key ) {
			foreach ( $by_key as $key => $sources ) {
				$out[ $target ][ $key ] = count( $sources );
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
	 * @return array<string, array<int, int>> Clave → destino → entradas.
	 */
	private function other_targets( array $anchors, array $wanted, Analyzer $analyzer ): array {
		$wpdb  = $this->wpdb;
		$table = Schema::table( $wpdb->prefix, 'links' );
		$in    = implode( ',', array_fill( 0, count( $anchors ), '%s' ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia.
			$wpdb->prepare( "SELECT source_id, target_id, anchor FROM %i WHERE anchor IN ({$in}) AND target_id IS NOT NULL AND is_internal = 1 AND is_broken = 0 AND source_id <> target_id", array_merge( array( $table ), $anchors ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Marcadores generados.
			ARRAY_N
		);

		$seen = array();
		$memo = array();
		foreach ( (array) $rows as $row ) {
			$text = (string) $row[2];
			$key  = $memo[ $text ] ??= $analyzer->phrase_key( $text );
			if ( isset( $wanted[ $key ] ) ) {
				$seen[ $key ][ (int) $row[1] ][ (int) $row[0] ] = true;
			}
		}

		$out = array();
		foreach ( $seen as $key => $by_target ) {
			foreach ( $by_target as $target => $sources ) {
				$out[ $key ][ $target ] = count( $sources );
			}
		}

		return $out;
	}
}
