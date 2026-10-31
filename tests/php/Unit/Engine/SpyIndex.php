<?php
/**
 * Índice en memoria que anota las llamadas de las interfaces opcionales del almacén.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\DocMeta;
use MagicLinking\Engine\IndexedDoc;
use MagicLinking\Engine\IndexRepository;
use MagicLinking\Engine\IndexStats;
use MagicLinking\Engine\MemoryIndex;
use MagicLinking\Engine\Preloads;
use MagicLinking\Engine\TermStats;
use RuntimeException;

/**
 * Un índice en memoria que anota las llamadas de las interfaces opcionales.
 */
final class SpyIndex implements IndexRepository, TermStats, Preloads {

	/** @var list<string> */
	public array $log = array();

	/** @var list<list<int>> */
	public array $preloaded = array();

	/** @var list<string> */
	public array $asked = array();

	/**
	 * Lecturas de ficha y de términos por entrada: «meta:ID», «terms:ID» y «linking_to:ID».
	 *
	 * @var list<string>
	 */
	public array $reads = array();

	/**
	 * Candidatas que devuelve `similar()` en vez de las del índice (ID → similitud); null: las del índice.
	 *
	 * @var array<int, float>|null
	 */
	public ?array $force_similar = null;

	public bool $fail_on_preload = false;

	public function __construct( public MemoryIndex $inner ) {
	}

	public function preload( array $ids ): void {
		$this->log[]       = 'preload';
		$this->preloaded[] = $ids;
		if ( $this->fail_on_preload ) {
			throw new RuntimeException( 'fallo al precargar' );
		}
	}

	public function release(): void {
		$this->log[] = 'release';
	}

	public function stats_for( string $lang, array $terms, bool $keep = false ): array {
		$this->log[] = 'stats_for';
		$this->asked = array_merge( $this->asked, $terms );
		return array( $this->inner->stats( $lang ), array() );
	}

	public function count_terms( string $lang, array $terms, int $length ): void {
		$this->inner->count_terms( $lang, $terms, $length );
	}

	public function stats( string $lang ): IndexStats {
		$this->log[] = 'stats';
		return $this->inner->stats( $lang );
	}

	public function put( IndexedDoc $doc ): void {
		$this->inner->put( $doc );
	}

	public function meta( int $id ): ?DocMeta {
		$this->log[]   = 'meta';
		$this->reads[] = 'meta:' . $id;
		return $this->inner->meta( $id );
	}

	public function terms( int $id ): array {
		$this->reads[] = 'terms:' . $id;
		return $this->inner->terms( $id );
	}

	public function similar( array $weights, string $lang, int $limit, array $exclude = array(), array $needs = array() ): array {
		if ( null !== $this->force_similar ) {
			return $this->force_similar;
		}
		return $this->inner->similar( $weights, $lang, $limit, $exclude, $needs );
	}

	public function containing( array $terms, string $lang, int $limit, array $exclude = array(), array $needs = array() ): array {
		return $this->inner->containing( $terms, $lang, $limit, $exclude, $needs );
	}

	public function matching( string $lang, array $needs ): array {
		$this->log[] = 'matching';
		return $this->inner->matching( $lang, $needs );
	}

	public function similarity( int $a, int $b ): float {
		return $this->inner->similarity( $a, $b );
	}

	public function linking_to( int $target ): array {
		$this->reads[] = 'linking_to:' . $target;
		return $this->inner->linking_to( $target );
	}

	public function anchor_uses( string $anchor, int $target ): int {
		return $this->inner->anchor_uses( $anchor, $target );
	}
}
