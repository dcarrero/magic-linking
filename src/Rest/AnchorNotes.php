<?php
/**
 * Avisos de ancla repetida de la tarjeta: canibalización y ancla sobreexplotada (F1-23, docs/07 §3.3).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Rest;

use MagicLinking\Core\PostTitle;
use MagicLinking\Core\Settings;
use MagicLinking\Engine\Analyzer;
use MagicLinking\Graph\AnchorUsage;
use MagicLinking\I18n\Language;
use WP_Post;

/**
 * Convierte el uso que ya tiene un ancla ({@see AnchorUsage}) en avisos con su texto en el idioma del usuario.
 * Los avisos informan; el usuario decide (la regla «misma ancla → un destino por origen» vive en el servidor, D-69).
 *
 * - `cannibalization`: la ancla ya lleva a otro destino desde otras entradas.
 * - `overused`: la ancla ya lleva a este mismo destino desde más entradas que el ajuste «misma ancla hacia el mismo
 *   destino» (la cuenta que penaliza el motor, `04 §6.1`).
 *
 * Se miden varios pares de una vez ({@see self::prime()}) y después se leen sin tocar la base de datos.
 */
final class AnchorNotes {

	/**
	 * Uso ya medido, por clave de par.
	 *
	 * @var array<string, array{same: int, elsewhere: list<array{target: int, count: int}>}>
	 */
	private array $measured = array();

	/**
	 * Constructor.
	 *
	 * @param AnchorUsage $usage    Uso de las anclas en el grafo.
	 * @param Language    $language Idioma por entrada.
	 * @param Settings    $settings Ajustes (`same_anchor_max`).
	 */
	public function __construct( private AnchorUsage $usage, private Language $language, private Settings $settings ) {
	}

	/**
	 * Mide de una vez (dos consultas por idioma) los pares que se van a presentar. Lo medido antes se olvida: el grafo
	 * cambia entre dos peticiones y este servicio vive tanto como el proceso.
	 *
	 * @param array $items Ancla, destino y origen de cada una.
	 * @param bool  $reset Olvidar lo medido antes (false = añadir a ello).
	 *
	 * @phpstan-param list<array{0: string, 1: int, 2: int}> $items
	 */
	public function prime( array $items, bool $reset = true ): void {
		if ( $reset ) {
			$this->measured = array();
		}

		$by_language = array();
		foreach ( $items as [ $anchor, $target, $source ] ) {
			if ( ! isset( $this->measured[ AnchorUsage::key( $anchor, $target ) ] ) ) {
				$by_language[ $this->language->for_post( $source ) ][] = array( $anchor, $target );
			}
		}

		foreach ( $by_language as $language => $pairs ) {
			$this->measured += $this->usage->measure( $pairs, Analyzer::for_language( $language ) ) + $this->unseen( $pairs );
		}
	}

	/**
	 * Avisos de un ancla hacia un destino desde un origen. Si el par no se midió antes, se mide solo.
	 *
	 * @param string $anchor Ancla.
	 * @param int    $target Destino.
	 * @param int    $source Origen (su idioma decide cómo se comparan las anclas).
	 *
	 * @return list<array{code: string, text: string}>
	 */
	public function for_anchor( string $anchor, int $target, int $source ): array {
		$key = AnchorUsage::key( $anchor, $target );
		if ( ! isset( $this->measured[ $key ] ) ) {
			$this->prime( array( array( $anchor, $target, $source ) ), false );
		}
		$use = $this->measured[ $key ] ?? array(
			'same'      => 0,
			'elsewhere' => array(),
		);

		$notes = array();

		// Solo cuentan los destinos que existen y no están en la papelera.
		$elsewhere = array_values(
			array_filter(
				$use['elsewhere'],
				static function ( array $row ): bool {
					$post = get_post( $row['target'] );
					return $post instanceof WP_Post && 'trash' !== $post->post_status;
				}
			)
		);
		if ( array() !== $elsewhere ) {
			$top   = $elsewhere[0];
			$more  = count( $elsewhere ) - 1;
			$title = PostTitle::of( $top['target'] );
			/* translators: 1: title of the destination the anchor already links to, 2: number of entries that use it. */
			$text = sprintf( _n( 'This anchor already links to “%1$s” in %2$d entry.', 'This anchor already links to “%1$s” in %2$d entries.', $top['count'], 'magic-linking' ), $title, $top['count'] );
			if ( $more > 0 ) {
				/* translators: %d: number of other destinations the same anchor links to. */
				$text .= ' ' . sprintf( _n( 'It also links to %d other destination.', 'It also links to %d other destinations.', $more, 'magic-linking' ), $more );
			}
			$notes[] = array(
				'code' => 'cannibalization',
				'text' => $text,
			);
		}

		if ( $use['same'] > (int) $this->settings->all()['same_anchor_max'] ) {
			$notes[] = array(
				'code' => 'overused',
				/* translators: %d: number of entries that already use this anchor for this destination. */
				'text' => sprintf( _n( 'This anchor is already used in %d entry for this destination.', 'This anchor is already used in %d entries for this destination.', $use['same'], 'magic-linking' ), $use['same'] ),
			);
		}

		return $notes;
	}

	/**
	 * Pares sin uso: los que {@see AnchorUsage::measure()} no devolvió (ancla sin contenido) se dan por sin avisos.
	 *
	 * @param array $pairs Pares.
	 *
	 * @phpstan-param list<array{0: string, 1: int}> $pairs
	 *
	 * @return array<string, array{same: int, elsewhere: list<array{target: int, count: int}>}>
	 */
	private function unseen( array $pairs ): array {
		$out = array();
		foreach ( $pairs as [ $anchor, $target ] ) {
			$out[ AnchorUsage::key( $anchor, $target ) ] = array(
				'same'      => 0,
				'elsewhere' => array(),
			);
		}

		return $out;
	}
}
