<?php
/**
 * El motor avisa al almacén de lo que va a puntuar (Preloads) y le pide el df de la entrada abierta (TermStats).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\PhraseFinder;
use MagicLinking\Engine\Scorer;
use MagicLinking\Engine\Suggester;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StoreHooksTest extends TestCase {

	private Corpus $corpus;

	private SpyIndex $spy;

	protected function setUp(): void {
		$this->corpus = new Corpus();
		$this->spy    = new SpyIndex( $this->corpus->index );
	}

	private function suggester(): Suggester {
		return new Suggester( $this->spy, $this->corpus->source, $this->corpus->indexer, new PhraseFinder(), new Scorer(), null, array( 'now' => Corpus::NOW ) );
	}

	public function test_outgoing_asks_for_the_df_of_the_source_terms_and_not_for_the_whole_vocabulary(): void {
		$document = $this->corpus->source->get( 3 );
		$this->assertNotNull( $document );

		$this->suggester()->outgoing( $document );

		$this->assertContains( 'stats_for', $this->spy->log );
		$this->assertNotContains( 'stats', $this->spy->log );
		$this->assertNotEmpty( $this->spy->asked );
	}

	public function test_outgoing_preloads_the_candidates_once_and_releases(): void {
		$document = $this->corpus->source->get( 3 );
		$this->assertNotNull( $document );

		$suggestions = $this->suggester()->outgoing( $document );

		$this->assertNotEmpty( $suggestions );
		$this->assertCount( 1, $this->spy->preloaded );
		$this->assertNotContains( 3, $this->spy->preloaded[0], 'la entrada abierta no es candidata' );
		$this->assertSame( 'release', end( $this->spy->log ) );
	}

	public function test_incoming_preloads_the_target_and_its_origins_and_releases(): void {
		$suggestions = $this->suggester()->incoming( 2 );

		$this->assertNotEmpty( $suggestions );
		$this->assertCount( 1, $this->spy->preloaded );
		$this->assertContains( 2, $this->spy->preloaded[0] );
		$this->assertSame( 'release', end( $this->spy->log ) );
	}

	public function test_candidates_are_preloaded_before_a_required_filter_looks_at_them(): void {
		$document = $this->corpus->source->get( 3 );
		$this->assertNotNull( $document );
		$suggester = new Suggester(
			$this->spy,
			$this->corpus->source,
			$this->corpus->indexer,
			new PhraseFinder(),
			new Scorer(),
			null,
			array(
				'now'     => Corpus::NOW,
				'filters' => array( 'type' => Suggester::REQUIRE ),
			)
		);

		$suggester->outgoing( $document );

		$first_meta = array_search( 'meta', $this->spy->log, true );
		$this->assertNotFalse( $first_meta );
		$this->assertLessThan( $first_meta, array_search( 'preload', $this->spy->log, true ), 'el filtro Exigir consulta entradas sin precargar' );
	}

	public function test_the_store_is_released_even_if_the_calculation_fails(): void {
		$this->spy->fail_on_preload = true;
		$document                   = $this->corpus->source->get( 3 );
		$this->assertNotNull( $document );

		try {
			$this->suggester()->outgoing( $document );
			$this->fail( 'debía fallar' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'release', end( $this->spy->log ) );
		}
	}

	public function test_a_plain_store_gives_the_same_results(): void {
		$plain    = new Suggester( $this->corpus->index, $this->corpus->source, $this->corpus->indexer, new PhraseFinder(), new Scorer(), null, array( 'now' => Corpus::NOW ) );
		$document = $this->corpus->source->get( 3 );
		$this->assertNotNull( $document );

		$this->assertEquals( $plain->outgoing( $document ), $this->suggester()->outgoing( $document ) );
		$this->assertEquals( $plain->incoming( 2 ), $this->suggester()->incoming( 2 ) );
	}
}
