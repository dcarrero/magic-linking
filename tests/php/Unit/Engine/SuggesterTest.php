<?php
/**
 * Motor completo sobre un corpus sintético: recuperación, anclas y motivos.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\Analyzer;
use MagicLinking\Engine\MemoryVectors;
use MagicLinking\Engine\Phrase;
use MagicLinking\Engine\PhraseFinder;
use MagicLinking\Engine\Reason;
use MagicLinking\Engine\Retriever;
use MagicLinking\Engine\Scorer;
use MagicLinking\Engine\Semantic;
use MagicLinking\Engine\Suggestion;
use PHPUnit\Framework\TestCase;

final class SuggesterTest extends TestCase {

	private Corpus $corpus;

	protected function setUp(): void {
		$this->corpus = new Corpus();
	}

	/**
	 * @param list<Suggestion> $suggestions
	 * @return list<int>
	 */
	private static function targets( array $suggestions ): array {
		return array_map( static fn( Suggestion $s ): int => $s->target, $suggestions );
	}

	/**
	 * @return list<string>
	 */
	private static function codes( Suggestion $suggestion ): array {
		return array_map( static fn( Reason $r ): string => $r->code, $suggestion->reasons );
	}

	public function test_outgoing_retrieval_excludes_self_linked_and_other_topics(): void {
		$retriever = new Retriever( $this->corpus->index );
		$document  = $this->corpus->source->get( 4 );
		$this->assertNotNull( $document );
		$weights = $this->corpus->indexer->weigh( Analyzer::for_language( 'es' )->analyze( $document ), $this->corpus->index->stats( 'es' ) );

		$candidates = $retriever->outgoing( $weights, 'es', array( 4, 2 ) );

		$this->assertArrayNotHasKey( 4, $candidates );
		$this->assertArrayNotHasKey( 2, $candidates );
		$this->assertArrayNotHasKey( 5, $candidates, 'la cocina no comparte términos' );
		$this->assertSame( 1, array_key_first( $candidates ) );
	}

	public function test_incoming_retrieval_uses_target_phrases_and_skips_existing_sources(): void {
		$retriever = new Retriever( $this->corpus->index );
		$meta      = $this->corpus->index->meta( 2 );
		$this->assertNotNull( $meta );
		$phrases = ( new PhraseFinder() )->target_phrases( $meta, $this->corpus->index->terms( 2 ), Analyzer::for_language( 'es' ) );

		$origins = $retriever->incoming( $meta, $phrases, array( 2, 4 ) );

		$this->assertContains( 1, $origins );
		$this->assertContains( 3, $origins );
		$this->assertNotContains( 4, $origins );
		$this->assertNotContains( 2, $origins );
	}

	public function test_outgoing_suggestions_with_reasons(): void {
		$document = $this->corpus->source->get( 3 );
		$this->assertNotNull( $document );
		$suggestions = $this->corpus->suggester()->outgoing( $document );

		$this->assertNotEmpty( $suggestions );
		$this->assertNotContains( 5, self::targets( $suggestions ) );
		$this->assertNotContains( 3, self::targets( $suggestions ) );

		$by_target = array();
		foreach ( $suggestions as $suggestion ) {
			$by_target[ $suggestion->target ] = $suggestion;
			$this->assertGreaterThanOrEqual( Scorer::THRESHOLD, $suggestion->score->value );
			$this->assertSame( $suggestion->anchor, substr( $suggestion->sentence, $suggestion->offset, strlen( $suggestion->anchor ) ), 'el ancla es literal' );
		}

		$this->assertArrayHasKey( 1, $by_target );
		$this->assertSame( 'aerotermia', $by_target[1]->anchor );
		$this->assertContains( Reason::ANCHOR_TITLE, self::codes( $by_target[1] ) );
		$this->assertContains( Reason::SHARED_TERMS, self::codes( $by_target[1] ) );
		$this->assertContains( Reason::ORPHAN, self::codes( $by_target[1] ) );
		$this->assertContains( 'aerotermia', $by_target[1]->reasons[0]->args['terms'] );
	}

	public function test_orphan_reason_only_when_true(): void {
		$document = $this->corpus->source->get( 1 );
		$this->assertNotNull( $document );
		foreach ( $this->corpus->suggester()->outgoing( $document ) as $suggestion ) {
			$orphan = in_array( Reason::ORPHAN, self::codes( $suggestion ), true );
			$this->assertSame( array() === $this->corpus->index->linking_to( $suggestion->target ), $orphan );
		}
	}

	public function test_one_link_per_sentence_and_no_repeated_anchor(): void {
		$document = $this->corpus->source->get( 1 );
		$this->assertNotNull( $document );
		$suggestions = $this->corpus->suggester( array(), new Scorer( array(), 0.0 ) )->outgoing( $document );

		$sentences = array();
		$anchors   = array();
		foreach ( $suggestions as $suggestion ) {
			$sentences[] = $suggestion->sentence;
			$anchors[]   = Analyzer::for_language( 'es' )->phrase_key( $suggestion->anchor );
		}
		$this->assertSame( $sentences, array_values( array_unique( $sentences ) ) );
		$this->assertSame( $anchors, array_values( array_unique( $anchors ) ) );
		$this->assertSame( count( $suggestions ), count( array_unique( self::targets( $suggestions ) ) ) );
	}

	public function test_existing_links_are_respected_or_ignored_in_gold_mode(): void {
		$document = $this->corpus->source->get( 4 );
		$this->assertNotNull( $document );
		$linked = 'La bomba de calor alimenta el suelo radiante con agua templada.';
		$loose  = new Scorer( array(), 0.0 );

		$respect = $this->corpus->suggester( array(), $loose )->outgoing( $document );
		$this->assertNotContains( 2, self::targets( $respect ) );
		$this->assertNotContains( $linked, array_map( static fn( Suggestion $s ): string => $s->sentence, $respect ), 'la frase ya tiene enlace' );

		$gold = $this->corpus->suggester( array( 'existing_links' => false ), $loose )->outgoing( $document );
		$this->assertContains( $linked, array_map( static fn( Suggestion $s ): string => $s->sentence, $gold ) );
	}

	public function test_never_suggest_and_unlike_target_penalty(): void {
		$document = $this->corpus->source->get( 2 );
		$this->assertNotNull( $document );
		$loose = new Scorer( array(), -1.0 );

		$legal = null;
		foreach ( $this->corpus->suggester( array(), $loose )->outgoing( $document ) as $suggestion ) {
			if ( 7 === $suggestion->target ) {
				$legal = $suggestion;
			}
		}
		$this->assertNotNull( $legal );
		$this->assertSame( array( 'unlike_target' => 0.3 ), array_intersect_key( $legal->score->penalties, array( 'unlike_target' => 0 ) ) );

		$this->assertNotContains( 7, self::targets( $this->corpus->suggester( array( 'never' => array( 7 ) ), $loose )->outgoing( $document ) ) );
	}

	public function test_incoming_suggestions(): void {
		$suggestions = $this->corpus->suggester()->incoming( 2 );

		$this->assertNotEmpty( $suggestions );
		$sources = array_map( static fn( Suggestion $s ): int => $s->source, $suggestions );
		$this->assertNotContains( 4, $sources, 'ya enlaza a la bomba de calor' );
		$this->assertNotContains( 5, $sources );
		$this->assertContains( 1, $sources );
		foreach ( $suggestions as $suggestion ) {
			$this->assertSame( 2, $suggestion->target );
			$this->assertSame( $suggestion->anchor, substr( $suggestion->sentence, $suggestion->offset, strlen( $suggestion->anchor ) ) );
			$this->assertContains( Reason::SHARED_TERMS, self::codes( $suggestion ) );
		}

		$this->assertSame( array(), $this->corpus->suggester( array( 'never' => array( 2 ) ) )->incoming( 2 ) );
	}

	public function test_whole_title_is_the_title_phrase_and_its_ngrams_are_ngrams(): void {
		$meta = $this->corpus->index->meta( 2 );
		$this->assertNotNull( $meta );
		$kinds = array();
		foreach ( ( new PhraseFinder() )->target_phrases( $meta, $this->corpus->index->terms( 2 ), Analyzer::for_language( 'es' ) ) as $phrase ) {
			$kinds[ $phrase->key() ] = $phrase->kind;
		}

		$this->assertSame( Phrase::TITLE, $kinds[ Analyzer::for_language( 'es' )->phrase_key( 'Bomba de calor para calefacción' ) ] );
		$this->assertSame( Phrase::NGRAM, $kinds['bomb de calor'] );
	}

	/**
	 * Vectores sintéticos: el origen 3 (placas solares) está al lado de 2 y lejos de 1.
	 *
	 * @param list<int> $only IDs que tienen vector (todos si está vacía).
	 */
	private static function vectors( array $only = array() ): MemoryVectors {
		$all   = array(
			1 => array( 0.0, 1.0, 0.0 ),
			2 => array( 1.0, 0.1, 0.0 ),
			3 => array( 1.0, 0.0, 0.0 ),
			4 => array( 0.7, 0.7, 0.0 ),
			5 => array( 0.0, 0.0, 1.0 ),
			6 => array( 0.0, 0.1, 1.0 ),
			7 => array( 0.5, 0.5, 0.5 ),
		);
		$store = new MemoryVectors();
		foreach ( $all as $id => $vector ) {
			if ( array() === $only || in_array( $id, $only, true ) ) {
				$store->put( $id, 'es', $vector );
			}
		}
		return $store;
	}

	/**
	 * @param list<Suggestion> $suggestions
	 * @return array<int, float>
	 */
	private static function relevance( array $suggestions ): array {
		$out = array();
		foreach ( $suggestions as $s ) {
			$out[ $s->target ] = $s->score->signals['relevance'];
		}
		return $out;
	}

	public function test_without_vectors_semantic_options_change_nothing(): void {
		$document = $this->corpus->source->get( 3 );
		$this->assertNotNull( $document );
		$loose = new Scorer( array(), 0.0, Scorer::DENSITY, 0.0 );

		$plain    = $this->corpus->suggester( array(), $loose )->outgoing( $document );
		$weighted = $this->corpus->suggester( array( 'semantic_weight' => 1.0 ), $loose )->outgoing( $document );
		$this->assertEquals( $plain, $weighted );
	}

	public function test_blend_mixes_lexical_and_rescaled_cosine(): void {
		$document = $this->corpus->source->get( 3 );
		$this->assertNotNull( $document );
		$loose   = new Scorer( array(), 0.0, Scorer::DENSITY, 0.0 );
		$vectors = self::vectors();

		// Una sola frase anclable para 1 y 2: gana la de más relevancia.
		$this->assertSame( array( 1 ), self::targets( $this->corpus->suggester( array(), $loose )->outgoing( $document ) ) );
		$cosine = $this->corpus->suggester( array( 'semantic_weight' => 1.0 ), $loose, $vectors )->outgoing( $document );
		$this->assertSame( array( 2 ), self::targets( $cosine ), 'la más cercana por coseno' );
		$this->assertEqualsWithDelta( 1.0, $cosine[0]->score->signals['relevance'], 1e-9 );

		// Relevancia = (léxica / máximo + coseno reescalado en el lote) / 2.
		$weights    = $this->corpus->indexer->weigh( Analyzer::for_language( 'es' )->analyze( $document ), $this->corpus->index->stats( 'es' ) );
		$candidates = ( new Retriever( $this->corpus->index ) )->outgoing( $weights, 'es', array( 3 ) );
		$query      = $vectors->vector( 3 );
		$this->assertNotNull( $query );
		$cosines = array();
		foreach ( array_keys( $candidates ) as $target ) {
			$other = $vectors->vector( $target );
			$this->assertNotNull( $other );
			$cosines[ $target ] = Semantic::dot( $query, $other );
		}
		$scaled = Semantic::rescale( $cosines );
		$max    = max( $candidates );

		$half = $this->corpus->suggester( array( 'semantic_weight' => 0.5 ), $loose, $vectors )->outgoing( $document );
		$this->assertNotEmpty( $half );
		foreach ( $half as $s ) {
			$this->assertEqualsWithDelta( ( $candidates[ $s->target ] / $max + $scaled[ $s->target ] ) / 2, $s->score->signals['relevance'], 1e-9 );
		}
	}

	public function test_semantic_reason_only_for_close_targets(): void {
		$document = $this->corpus->source->get( 3 );
		$this->assertNotNull( $document );
		$loose = new Scorer( array(), 0.0, Scorer::DENSITY, 0.0 );

		foreach ( $this->corpus->suggester( array( 'semantic_weight' => 0.5 ), $loose, self::vectors() )->outgoing( $document ) as $s ) {
			$this->assertSame( 2 === $s->target, in_array( Reason::SEMANTIC, self::codes( $s ), true ), 'destino ' . $s->target );
		}
		foreach ( $this->corpus->suggester( array(), $loose )->outgoing( $document ) as $s ) {
			$this->assertNotContains( Reason::SEMANTIC, self::codes( $s ) );
		}
	}

	public function test_target_without_vector_keeps_lexical_relevance(): void {
		$document = $this->corpus->source->get( 3 );
		$this->assertNotNull( $document );
		$loose = new Scorer( array(), 0.0, Scorer::DENSITY, 0.0 );

		$lexical = self::relevance( $this->corpus->suggester( array(), $loose )->outgoing( $document ) );
		$partial = self::relevance( $this->corpus->suggester( array( 'semantic_weight' => 0.5 ), $loose, self::vectors( array( 2, 3, 4 ) ) )->outgoing( $document ) );
		$this->assertArrayHasKey( 1, $partial );
		$this->assertEqualsWithDelta( $lexical[1], $partial[1], 1e-9 );
	}

	public function test_semantic_retrieval_takes_candidates_from_vectors_and_anchors_from_text(): void {
		$document = $this->corpus->source->get( 3 );
		$this->assertNotNull( $document );
		$loose = new Scorer( array(), 0.0, Scorer::DENSITY, 0.0 );

		$lexical = self::targets( $this->corpus->suggester( array(), $loose )->outgoing( $document ) );
		$this->assertContains( 1, $lexical );

		$knn = $this->corpus->suggester(
			array(
				'semantic_weight'    => 0.5,
				'semantic_retrieval' => true,
			),
			$loose,
			self::vectors( array( 2, 3, 5, 6 ) )
		)->outgoing( $document );
		$this->assertSame( array( 2 ), self::targets( $knn ), 'solo candidatas con vector; 5 y 6 no tienen frase anclable' );
		$this->assertSame( $knn[0]->anchor, substr( $knn[0]->sentence, $knn[0]->offset, strlen( $knn[0]->anchor ) ) );

		$explicit = $this->corpus->suggester( array( 'semantic_retrieval' => true ), $loose, self::vectors( array( 2, 5, 6 ) ) )->outgoing( $document, array( 1.0, 0.0, 0.0 ) );
		$this->assertSame( array( 2 ), self::targets( $explicit ), 'vector de la entrada abierta pasado a mano' );

		$none = $this->corpus->suggester( array( 'semantic_retrieval' => true ), $loose, self::vectors( array( 2, 5, 6 ) ) )->outgoing( $document );
		$this->assertSame( $lexical, self::targets( $none ), 'sin vector del origen, léxico' );
	}
}
