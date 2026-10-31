<?php
/**
 * Dónde y cuánto enlaza una regla automática en una entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Aplica una {@see RuleSpec} a un documento con el mismo buscador de frases que el motor de sugerencias
 * ({@see PhraseFinder}, docs/04 §4.2: raíces, variantes, hueco de una palabra, sin enlazar dentro de un
 * enlace ni con anclas genéricas) y los topes duros de docs/04 §6.1 ({@see RuleLimits}).
 *
 * Es puro: no escribe nada ni toca WordPress. Nunca propone quitar ni cambiar un enlace; solo añadir.
 */
final class RuleMatcher {

	/**
	 * Constructor.
	 *
	 * @param PhraseFinder $finder Buscador de frases del motor.
	 */
	public function __construct( private PhraseFinder $finder = new PhraseFinder() ) {
	}

	/**
	 * Enlaces que la regla pondría en el documento.
	 *
	 * @param Document              $source Entrada tal como está ahora, con sus enlaces internos.
	 * @param RuleSpec              $rule   Regla.
	 * @param RuleLimits            $limits Topes duros.
	 * @param callable              $sites  Recibe la clave de un ancla y devuelve en cuántas entradas distintas del sitio (sin contar esta) ya enlaza al destino de la regla.
	 * @param array                 $ignored_words Palabras o frases ignoradas (Ajustes): un ancla que las contiene como palabra completa no se enlaza.
	 * @param AnalyzedDocument|null $analyzed Documento ya analizado (con frases); si falta, se analiza aquí. Permite analizar una sola vez para muchas reglas.
	 * @return RulePlan
	 *
	 * @phpstan-param callable(string): int $sites
	 * @phpstan-param list<string> $ignored_words
	 */
	public function plan( Document $source, RuleSpec $rule, RuleLimits $limits, callable $sites, array $ignored_words = array(), ?AnalyzedDocument $analyzed = null ): RulePlan {
		if ( $source->id === $rule->target_id ) {
			return new RulePlan( array(), RulePlan::SELF );
		}

		$analyzer   = Analyzer::for_language( $source->lang );
		$analyzed ??= $analyzer->analyze( $source, true );

		// Topes de la entrada: nunca se quita nada, solo se calcula cuánto cabe.
		// Los enlaces rotos (sin destino) no llenan el cupo: el lector no puede seguirlos y el usuario los va a arreglar o
		// quitar, así que una entrada con enlaces muertos no está «llena». Ojo: el informe de «sobrecargada» (D-34) sí
		// cuenta cada saliente interna, rota o no; la diferencia va por el lado permisivo y se anota como decisión.
		$working      = count( array_filter( $source->links, static fn( $link ): bool => null !== $link->target ) );
		$room_density = $limits->density_cap( $analyzed->words ) - $working;
		if ( $room_density <= 0 ) {
			return new RulePlan( array(), RulePlan::DENSITY );
		}
		$already = 0;
		foreach ( $source->links as $link ) {
			if ( $link->target === $rule->target_id ) {
				++$already;
			}
		}
		$room_target = $limits->links_per_target - $already;
		if ( $room_target <= 0 ) {
			return new RulePlan( array(), RulePlan::TARGET_LINKED );
		}
		$room = min( max( 1, $rule->max_per_post ), $room_density, $room_target );

		$phrases = $this->phrases( $rule, $analyzer );
		if ( array() === $phrases ) {
			return new RulePlan( array(), RulePlan::NO_MATCH );
		}

		// Un ancla que la entrada ya usa hacia otro destino no se repite (docs/04 §6.1, canibalización).
		$blocked = array();
		foreach ( $source->links as $link ) {
			if ( null !== $link->target ) {
				$blocked[ $analyzer->phrase_key( $link->anchor ) ] = $link->target;
			}
		}
		$found = $this->finder->find( $analyzed, $phrases, $analyzer, array(), $blocked, $rule->target_id );

		$ignored  = IgnoredWords::patterns( $ignored_words );
		$matches  = array();
		$taken    = array();
		$seen     = array();
		$overused = false;
		$cache    = array();
		foreach ( $found as $match ) {
			if ( count( $matches ) >= $room ) {
				break;
			}
			if ( $rule->first_only && isset( $seen[ $match->phrase ] ) ) {
				continue;
			}
			if ( IgnoredWords::matches( $ignored, $match->anchor ) ) {
				continue;
			}
			$cache[ $match->key ] ??= $sites( $match->key );
			if ( $limits->same_anchor_reached( $cache[ $match->key ] ) ) {
				$overused = true;
				continue;
			}
			$end = $match->offset + strlen( $match->anchor );
			foreach ( $taken[ $match->sentence ] ?? array() as [ $from, $to ] ) {
				if ( $match->offset < $to && $end > $from ) {
					continue 2;
				}
			}
			$taken[ $match->sentence ][] = array( $match->offset, $end );
			$seen[ $match->phrase ]      = true;
			$matches[]                   = new RuleMatch( $analyzed->sentences[ $match->sentence ]->text, $match->offset, $match->anchor, $match->key );
		}//end foreach

		if ( array() === $matches ) {
			return new RulePlan( array(), $overused ? RulePlan::ANCHOR_OVERUSE : RulePlan::NO_MATCH );
		}

		return new RulePlan( $matches );
	}

	/**
	 * Frases de la regla: una por variante, con la clave de raíz de cada palabra.
	 *
	 * @param RuleSpec $rule     Regla.
	 * @param Analyzer $analyzer Analizador del idioma de la entrada.
	 * @return list<Phrase>
	 */
	public function phrases( RuleSpec $rule, Analyzer $analyzer ): array {
		$phrases = array();
		foreach ( $rule->variants as $variant ) {
			$keys = $analyzer->keys( $analyzer->tokenizer()->tokenize( $variant ) );
			$key  = implode( ' ', $keys );
			if ( array() === $keys || isset( $phrases[ $key ] ) || count( $keys ) > PhraseFinder::MAX_WORDS ) {
				continue;
			}
			$phrases[ $key ] = new Phrase( $keys, Phrase::FOCUS, $rule->gap );
		}
		return array_values( $phrases );
	}
}
