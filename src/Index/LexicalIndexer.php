<?php
/**
 * Indexado léxico persistente: lleva el motor de la Fase 0 a las tablas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use MagicLinking\Engine\Analyzer;
use MagicLinking\Engine\Indexer;
use MagicLinking\Engine\IndexedDoc;
use MagicLinking\Jobs\JobRepository;
use WP_Post;

/**
 * Analiza entradas con {@see Analyzer} y guarda su vocabulario y sus K términos principales.
 *
 * Hay dos maneras de trabajar:
 *
 * - **Construcción** (primer indexado o recálculo): el peso de un término depende de su df y de
 *   la longitud media, que dependen de todo el corpus. Se hace en dos pasadas: {@see self::scan()}
 *   cuenta df y longitudes de todas las entradas y {@see self::weigh()} calcula los pesos y guarda
 *   los postings con los df ya definitivos.
 * - **Incremental** ({@see self::index_changed()}): una entrada nueva o modificada se pesa con los df
 *   actuales. Solo las nuevas suman a la df de los términos que ya están en el vocabulario; el
 *   vocabulario no guarda los términos con df de 0 o de 1, así que un término que hasta ahora
 *   estaba en una sola entrada no cuenta como compartido hasta el siguiente recálculo
 *   ({@see self::is_stale()}, `03 §5`). Las entradas borradas tampoco restan.
 *
 * `content_hash` (ver {@see Fingerprint}) lo escribe el grafo. Este indexador lleva su propia huella
 * (`lex_hash`) y su propia fecha (`lex_at`) en `magiclinking_docs`, que solo escribe cuando las
 * postings de la entrada ya están guardadas: si el grafo anota el contenido nuevo y el índice léxico
 * falla, la entrada sigue pareciendo desfasada y se reintenta; y un reanálisis del grafo sin cambios
 * de contenido no cuenta como cambio del corpus.
 *
 * Una construcción deja una marca persistente ({@see self::BUILDING_OPTION}) desde que empieza hasta
 * que termina entera. Mientras esté, el índice no se da por construido: una construcción cancelada
 * o fallida (con df a medias) se rehace en la siguiente pasada en vez de quedarse así.
 */
final class LexicalIndexer {

	/**
	 * Tipo de proceso con el que se anota un recálculo terminado (para saber cuánto ha cambiado el corpus desde entonces).
	 */
	public const TYPE_RECOUNT = 'recount';

	/**
	 * Opción (sin autoload) que existe mientras hay una construcción empezada y sin terminar; guarda el ID
	 * del proceso que la empezó, y solo ese proceso puede darla por terminada.
	 */
	public const BUILDING_OPTION = 'magiclinking_lexical_building';

	/**
	 * Fracción de entradas nuevas o modificadas a partir de la cual conviene recalcular df.
	 */
	public const STALE_RATIO = 0.05;

	/**
	 * Almacén.
	 *
	 * @var TableRepository
	 */
	private TableRepository $repository;

	/**
	 * Entradas como documentos.
	 *
	 * @var PostSource
	 */
	private PostSource $source;

	/**
	 * Procesos (para anotar los recálculos).
	 *
	 * @var JobRepository
	 */
	private JobRepository $jobs;

	/**
	 * Cálculo de pesos.
	 *
	 * @var Indexer
	 */
	private Indexer $engine;

	/**
	 * Constructor.
	 *
	 * @param TableRepository $repository Almacén.
	 * @param PostSource      $source     Entradas.
	 * @param JobRepository   $jobs       Procesos.
	 * @param Indexer|null    $engine     Cálculo de pesos; null para la configuración por defecto.
	 */
	public function __construct( TableRepository $repository, PostSource $source, JobRepository $jobs, ?Indexer $engine = null ) {
		$this->repository = $repository;
		$this->source     = $source;
		$this->jobs       = $jobs;
		$this->engine     = $engine ?? new Indexer();
	}

	/**
	 * Almacén.
	 */
	public function repository(): TableRepository {
		return $this->repository;
	}

	/**
	 * Si ya hay un índice léxico construido: con entradas y sin una construcción a medias.
	 */
	public function is_built(): bool {
		return ! $this->is_building() && $this->repository->has_lexical();
	}

	/**
	 * Si hay una construcción empezada que no ha llegado a terminar (en curso, cancelada o fallida).
	 */
	public function is_building(): bool {
		return false !== get_option( self::BUILDING_OPTION, false );
	}

	// ------------------------------------------------------------------ Construcción.

	/**
	 * Empieza una construcción: las frecuencias se vuelven a contar desde cero.
	 *
	 * @param int $job_id Proceso que la hace.
	 */
	public function begin_build( int $job_id ): void {
		update_option( self::BUILDING_OPTION, $job_id, false );
		$this->repository->reset_df();
	}

	/**
	 * Primera pasada: cuenta df y longitudes de unas entradas y guarda sus filas.
	 *
	 * @param array<int, int> $ids   IDs.
	 * @param callable|null   $alive Devuelve false si el proceso ya no debe escribir (se canceló mientras analizaba): se descarta lo contado.
	 *
	 * @return int Entradas contadas (las no elegibles no cuentan).
	 */
	public function scan( array $ids, ?callable $alive = null ): int {
		$rows = array();

		foreach ( $this->documents( $ids ) as $item ) {
			$analyzer = Analyzer::for_language( $item['doc']->lang );
			$analyzed = $analyzer->analyze( $item['doc'] );

			$this->repository->count_terms( $item['doc']->lang, $analyzed->terms(), $analyzed->length );
			$rows[] = $this->row( $item['doc']->id, $item['doc']->type, $item['doc']->lang, $analyzed->length );
		}

		if ( null !== $alive && ! $alive() ) {
			$this->repository->discard_counts();

			return 0;
		}

		$this->repository->flush_counts();
		$this->repository->save_docs( $rows );

		return count( $rows );
	}

	/**
	 * Entre las dos pasadas: descarta del vocabulario lo que no puede unir dos entradas.
	 */
	public function end_scan(): void {
		$this->repository->prune_terms( $this->engine->config()['min_df'] );
	}

	/**
	 * Segunda pasada: pesa unas entradas con los df definitivos y guarda sus K términos principales.
	 *
	 * @param array<int, int> $ids   IDs.
	 * @param callable|null   $alive Devuelve false si el proceso ya no debe escribir.
	 *
	 * @return int Entradas pesadas.
	 */
	public function weigh( array $ids, ?callable $alive = null ): int {
		$indexed = array();
		$hashes  = array();

		foreach ( $this->documents( $ids ) as $item ) {
			$indexed[]                  = $this->weigh_document( $item['doc'] );
			$hashes[ $item['doc']->id ] = $item['hash'];
		}

		if ( null !== $alive && ! $alive() ) {
			return 0;
		}

		$this->repository->put_many( $indexed );
		$this->repository->mark_lexical( $hashes );

		return count( $indexed );
	}

	/**
	 * Anota que el recálculo de df ha terminado: la construcción deja de estar a medias.
	 *
	 * Si la construcción pertenece ya a otro proceso (este se canceló y lo sustituyeron), no hace nada.
	 *
	 * @param int $docs   Entradas con índice léxico.
	 * @param int $job_id Proceso que termina la construcción.
	 */
	public function record_build( int $docs, int $job_id ): void {
		if ( (int) get_option( self::BUILDING_OPTION, 0 ) !== $job_id ) {
			return;
		}
		delete_option( self::BUILDING_OPTION );

		$id = $this->jobs->create( self::TYPE_RECOUNT, $docs, array(), 0 );
		$this->jobs->set_progress( $id, $docs, $docs, array( 'docs' => $docs ) );
		$this->jobs->set_status( $id, JobRepository::DONE );
	}

	/**
	 * Si el corpus ha cambiado lo bastante desde el último recálculo (más de un 5 % de entradas
	 * nuevas, modificadas o borradas) como para volver a calcular df.
	 */
	public function is_stale(): bool {
		// Una construcción cancelada o fallida deja df a medias: hay que rehacerla.
		if ( $this->is_building() ) {
			return true;
		}

		$docs = $this->repository->count_lexical();
		if ( 0 === $docs ) {
			return false;
		}

		$last = $this->jobs->latest_done( self::TYPE_RECOUNT );
		if ( null === $last ) {
			return true;
		}

		$changed = $this->repository->count_changed_since( $last['updated_at'] ) + abs( $docs - (int) ( $last['params']['docs'] ?? 0 ) );

		return $changed > self::STALE_RATIO * max( 1, $docs );
	}

	// ------------------------------------------------------------------ Incremental.

	/**
	 * Indexa las entradas nuevas o cambiadas (huella léxica, idioma o longitud distintas de lo guardado)
	 * y salta las demás. La huella léxica se anota al final: si algo falla antes, se reintenta.
	 *
	 * @param array<int, int> $ids IDs.
	 *
	 * @return array{indexed: int, unchanged: int} Entradas indexadas y saltadas por no haber cambiado.
	 */
	public function index_changed( array $ids ): array {
		$before   = $this->repository->rows( $ids );
		$analyzed = array();
		$skipped  = 0;
		$rows     = array();
		$fresh    = array();
		$hashes   = array();

		foreach ( $this->documents( $ids ) as $item ) {
			$doc = $item['doc'];
			$row = $before[ $doc->id ] ?? null;

			if ( null !== $row && $row['lex_hash'] === $item['hash'] && $row['lang'] === $doc->lang && $row['doc_len'] > 0 ) {
				++$skipped;
				continue;
			}

			$analyzer = Analyzer::for_language( $doc->lang );
			$result   = $analyzer->analyze( $doc );

			if ( null === $row || 0 === $row['doc_len'] ) {
				$fresh[ $doc->lang ][] = $result->terms();
			}

			$analyzed[]         = $result;
			$hashes[ $doc->id ] = $item['hash'];
			$rows[]             = $this->row( $doc->id, $doc->type, $doc->lang, $result->length );
		}

		foreach ( $fresh as $lang => $lists ) {
			foreach ( $lists as $terms ) {
				$this->repository->bump_df( (string) $lang, $terms );
			}
		}
		$this->repository->save_docs( $rows );

		$indexed = array();
		foreach ( $analyzed as $result ) {
			$indexed[] = $this->index_analyzed( $result );
		}
		$this->repository->put_many( $indexed );
		$this->repository->mark_lexical( $hashes );

		return array(
			'indexed'   => count( $indexed ),
			'unchanged' => $skipped,
		);
	}

	// ------------------------------------------------------------------ Común.

	/**
	 * Documentos de las entradas elegibles, con su huella. Las que no lo son (borradas, sin
	 * publicar, de un tipo que no se analiza) se ignoran: de su borrado se ocupa el grafo.
	 *
	 * @param array<int, int> $ids IDs.
	 *
	 * @return \Generator<int, array{doc: \MagicLinking\Engine\Document, hash: string}>
	 */
	private function documents( array $ids ): \Generator {
		$ids = array_map( 'intval', array_values( $ids ) );

		if ( count( $ids ) > 1 ) {
			_prime_post_caches( $ids, true, true );
		}

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || ! $this->source->is_eligible( $post ) ) {
				continue;
			}

			$html = PostSource::html_of( $post );

			yield array(
				'doc'  => $this->source->from_post( $post, $html ),
				'hash' => Fingerprint::of( $post, $html ),
			);
		}
	}

	/**
	 * Pesa una entrada con las estadísticas actuales de su idioma.
	 *
	 * @param \MagicLinking\Engine\Document $doc Documento.
	 */
	private function weigh_document( \MagicLinking\Engine\Document $doc ): IndexedDoc {
		return $this->index_analyzed( Analyzer::for_language( $doc->lang )->analyze( $doc ) );
	}

	/**
	 * Pesa una entrada ya analizada.
	 *
	 * @param \MagicLinking\Engine\AnalyzedDocument $analyzed Entrada analizada.
	 */
	private function index_analyzed( \MagicLinking\Engine\AnalyzedDocument $analyzed ): IndexedDoc {
		$lang       = $analyzed->document->lang;
		[ $stats, ] = $this->repository->stats_for( $lang, $analyzed->terms() );

		return $this->engine->index( $analyzed, $stats, Analyzer::for_language( $lang ) );
	}

	/**
	 * Fila de `magiclinking_docs`.
	 *
	 * @param int    $id   ID.
	 * @param string $type Tipo.
	 * @param string $lang Idioma.
	 * @param int    $len  Longitud.
	 *
	 * @return array{post_id: int, post_type: string, lang: string, doc_len: int}
	 */
	private function row( int $id, string $type, string $lang, int $len ): array {
		return array(
			'post_id'   => $id,
			'post_type' => $type,
			'lang'      => $lang,
			'doc_len'   => $len,
		);
	}
}
