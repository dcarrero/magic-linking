<?php
/**
 * Indexado: BM25 por campo, bonificación de n-gramas y top-K.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\Analyzer;
use MagicLinking\Engine\Indexer;
use MagicLinking\Engine\MemoryIndex;
use PHPUnit\Framework\TestCase;

final class IndexerTest extends TestCase {

	/**
	 * Idioma sin palabras vacías ni stemmer: las cuentas se hacen a mano.
	 */
	private function tiny( array $config = array() ): MemoryIndex {
		$source = new ArraySource();
		$source->add( 1, 'alfa', array( 'alfa beta' ), 'xx' );
		$source->add( 2, 'gamma', array( 'beta beta gamma' ), 'xx' );
		$source->add( 3, 'delta', array( 'alfa delta' ), 'xx' );
		$index = new MemoryIndex();
		( new Indexer( $config ) )->build( $source, $index );
		return $index;
	}

	private static function bm25( int $tf, int $len, float $avg ): float {
		return $tf * 2.2 / ( $tf + 1.2 * ( 0.25 + 0.75 * $len / $avg ) );
	}

	public function test_stats(): void {
		$stats = $this->tiny()->stats( 'xx' );

		$this->assertSame( 3, $stats->count );
		$this->assertEqualsWithDelta( 10 / 3, $stats->average, 1e-9 );
		$this->assertSame( 2, $stats->df( 'beta' ) );
		$this->assertSame( 0, $stats->df( 'gamma' ), 'df = 1 se olvida (min_df = 2)' );
		$this->assertEqualsWithDelta( log( 1 + 1.5 / 2.5 ), $stats->idf( 'beta' ), 1e-9 );
	}

	public function test_bm25_weight_of_a_body_term(): void {
		$terms = $this->tiny()->terms( 2 );

		$this->assertEqualsWithDelta( log( 1.6 ) * self::bm25( 2, 4, 10 / 3 ), $terms['beta'], 1e-9 );
	}

	public function test_field_multipliers_add_up(): void {
		$source = new ArraySource();
		$source->add( 1, 'gamma', array( 'beta beta gamma' ), 'xx' );
		$source->add( 2, 'otro', array( 'gamma' ), 'xx' );
		$index   = new MemoryIndex();
		$indexer = new Indexer();
		$indexer->build( $source, $index );

		$stats    = $index->stats( 'xx' );
		$document = $source->get( 1 );
		$this->assertNotNull( $document );
		$analyzed = Analyzer::for_language( 'xx' )->analyze( $document );
		$weights  = $indexer->weigh( $analyzed, $stats );

		$tf1 = self::bm25( 1, 4, $stats->average );
		$this->assertEqualsWithDelta( $stats->idf( 'gamma' ) * ( 3 * $tf1 + 1 * $tf1 ), $weights['gamma'], 1e-9 );
	}

	public function test_ngram_bonus(): void {
		$source = new ArraySource();
		$source->add( 1, 'uno', array( 'centro datos' ), 'xx' );
		$source->add( 2, 'dos', array( 'centro datos' ), 'xx' );
		$source->add( 3, 'tres', array( 'centro datos' ), 'xx' );
		$source->add( 4, 'cuatro', array( 'otra cosa' ), 'xx' );

		$with    = new MemoryIndex();
		$without = new MemoryIndex();
		( new Indexer() )->build( $source, $with );
		( new Indexer( array( 'ngram_bonus' => false ) ) )->build( $source, $without );

		$this->assertEqualsWithDelta( 1.5 * $without->terms( 1 )['centro datos'], $with->terms( 1 )['centro datos'], 1e-9 );
		$this->assertEqualsWithDelta( $without->terms( 1 )['centro'], $with->terms( 1 )['centro'], 1e-9 );
	}

	public function test_top_k_and_min_df(): void {
		$index = $this->tiny( array( 'k' => 1 ) );

		$this->assertSame( array( 'beta' ), array_keys( $index->terms( 2 ) ) );
		$this->assertSame( array( 'alfa' ), array_keys( $index->terms( 3 ) ), 'delta tiene df = 1' );

		$all = $this->tiny(
			array(
				'min_df'   => 1,
				'solid_df' => 1,
			)
		);
		$this->assertArrayHasKey( 'beta beta gamma', $all->terms( 2 ), 'con min_df = 1 entran los términos únicos' );
	}

	public function test_casual_ngrams_get_no_bonus_and_stay_out_of_the_top(): void {
		$source = new ArraySource();
		$source->add( 1, 'uno', array( 'reafirma apoyo total', 'apoyo' ), 'xx' );
		$source->add( 2, 'dos', array( 'reafirma apoyo total', 'apoyo' ), 'xx' );
		$source->add( 3, 'tres', array( 'otra cosa' ), 'xx' );
		$index   = new MemoryIndex();
		$indexer = new Indexer();
		$indexer->build( $source, $index );

		// df = 2, una vez y solo en el cuerpo: casual.
		$this->assertArrayNotHasKey( 'reafirma apoyo total', $index->terms( 1 ) );
		$this->assertArrayNotHasKey( 'reafirma apoyo', $index->terms( 1 ) );
		$this->assertArrayHasKey( 'apoyo', $index->terms( 1 ) );

		$document = $source->get( 1 );
		$this->assertNotNull( $document );
		$analyzed = Analyzer::for_language( 'xx' )->analyze( $document );
		$stats    = $index->stats( 'xx' );
		$plain    = ( new Indexer( array( 'ngram_bonus' => false ) ) )->weigh( $analyzed, $stats );
		$this->assertEqualsWithDelta( $plain['reafirma apoyo total'], $indexer->weigh( $analyzed, $stats )['reafirma apoyo total'], 1e-9, 'sin bonificación' );
	}

	public function test_ngram_in_a_strong_field_keeps_bonus_and_top(): void {
		$source = new ArraySource();
		$source->add( 1, 'bomba calor', array( 'la bomba calor funciona' ), 'xx' );
		$source->add( 2, 'dos', array( 'otra bomba calor aqui' ), 'xx' );
		$source->add( 3, 'tres', array( 'nada que ver' ), 'xx' );
		$index   = new MemoryIndex();
		$indexer = new Indexer();
		$indexer->build( $source, $index );

		$this->assertArrayHasKey( 'bomba calor', $index->terms( 1 ), 'en el título: no es casual' );
		$this->assertArrayNotHasKey( 'bomba calor', $index->terms( 2 ), 'solo una vez en el cuerpo y df = 2: casual' );

		$document = $source->get( 1 );
		$this->assertNotNull( $document );
		$analyzed = Analyzer::for_language( 'xx' )->analyze( $document );
		$stats    = $index->stats( 'xx' );
		$plain    = ( new Indexer( array( 'ngram_bonus' => false ) ) )->weigh( $analyzed, $stats );
		$this->assertEqualsWithDelta( 1.5 * $plain['bomba calor'], $indexer->weigh( $analyzed, $stats )['bomba calor'], 1e-9 );
	}

	public function test_repeated_ngram_is_not_casual(): void {
		$source = new ArraySource();
		$source->add( 1, 'uno', array( 'bomba calor. Otra bomba calor.' ), 'xx' );
		$source->add( 2, 'dos', array( 'una bomba calor' ), 'xx' );
		$source->add( 3, 'tres', array( 'nada' ), 'xx' );
		$index = new MemoryIndex();
		( new Indexer() )->build( $source, $index );

		$this->assertArrayHasKey( 'bomba calor', $index->terms( 1 ) );
	}

	public function test_terms_are_sorted_by_weight(): void {
		$terms = ( new Corpus() )->index->terms( 1 );

		$this->assertNotEmpty( $terms );
		$sorted = $terms;
		arsort( $sorted );
		$this->assertSame( $sorted, $terms );
		$this->assertLessThanOrEqual( Indexer::DEFAULTS['k'], count( $terms ) );
	}

	public function test_stemmed_terms_and_fields(): void {
		$corpus = new Corpus();

		// «aerotermia» en el título pesa más que en el cuerpo de otra entrada.
		$this->assertArrayHasKey( 'aerotermi', $corpus->index->terms( 1 ) );
		$this->assertGreaterThan( $corpus->index->terms( 2 )['aerotermi'] ?? 0.0, $corpus->index->terms( 1 )['aerotermi'] );
		$this->assertArrayHasKey( 'bomb de calor', $corpus->index->terms( 2 ) );
	}

	public function test_meta_and_links(): void {
		$corpus = new Corpus();
		$meta   = $corpus->index->meta( 4 );

		$this->assertNotNull( $meta );
		$this->assertSame( 1, $meta->links );
		$this->assertSame( array( 4 ), $corpus->index->linking_to( 2 ) );
		$this->assertSame( 1, $corpus->index->anchor_uses( 'bomb de calor', 2 ) );
	}
}
