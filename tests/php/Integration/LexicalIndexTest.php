<?php
/**
 * Indexado léxico persistente (F1-04): tablas docs, terms y postings.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\Engine\Analyzer;
use MagicLinking\Engine\Indexer;
use MagicLinking\Engine\MemoryIndex;
use MagicLinking\Index\LexicalIndexer;
use MagicLinking\Index\PostSource;
use MagicLinking\Index\TableRepository;
use MagicLinking\Jobs\JobRepository;
use MagicLinking\Jobs\Jobs;

final class LexicalIndexTest extends GraphTestCase {

	private Jobs $jobs;

	private LexicalIndexer $lexical;

	private TableRepository $repo;

	public function set_up(): void {
		parent::set_up();

		// El idioma del sitio es el de las entradas sin WPML ni Polylang.
		add_filter( 'locale', static fn(): string => 'es_ES' );

		$this->jobs    = Plugin::container()->get( Jobs::class );
		$this->lexical = Plugin::container()->get( LexicalIndexer::class );
		$this->repo    = Plugin::container()->get( TableRepository::class );
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );
	}

	private function post( string $slug, string $content, string $title = '' ): int {
		return self::factory()->post->create(
			array(
				'post_name'    => $slug,
				'post_title'   => '' === $title ? ucfirst( $slug ) : $title,
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);
	}

	/**
	 * Vacía lo que el guardado de las entradas de la prueba haya creado.
	 */
	private function clear_index(): void {
		global $wpdb;
		foreach ( array( 'docs', 'links', 'jobs', 'terms', 'postings' ) as $table ) {
			$name = Schema::table( $wpdb->prefix, $table );
			$wpdb->query( "DELETE FROM {$name}" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Lanza un proceso y lo ejecuta entero.
	 *
	 * @return array<string, mixed>
	 */
	private function run_job( bool $force = false, bool $graph = true, bool $rebuild = false ): array {
		$job = $this->jobs->start_index( 0, false, $force, $graph, $rebuild );
		$this->jobs->run_to_completion( $job['id'] );

		return (array) $this->jobs->repository()->get( $job['id'] );
	}

	/**
	 * Raíz con la que el motor guarda una palabra.
	 */
	private function stem( string $word, string $lang = 'es' ): string {
		return (string) array_key_first( Analyzer::for_language( $lang )->terms( $word ) );
	}

	private function df( string $word, string $lang = 'es' ): ?int {
		global $wpdb;
		$name = Schema::table( $wpdb->prefix, 'terms' );
		$df   = $wpdb->get_var( $wpdb->prepare( "SELECT df FROM {$name} WHERE lang = %s AND stem = %s", $lang, $this->stem( $word, $lang ) ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $df ? null : (int) $df;
	}

	/**
	 * Tres entradas que comparten «zorrillo» y una con «ardilla».
	 *
	 * @return array<string, int>
	 */
	private function corpus(): array {
		$ids = array(
			'a' => $this->post( 'alfa', 'El zorrillo duerme en el bosque profundo junto al río helado.', 'Alfa zorrillo' ),
			'b' => $this->post( 'beta', 'Un zorrillo cruza el camino cuando llueve sobre el bosque.', 'Beta' ),
			'c' => $this->post( 'gamma', 'Vimos otro zorrillo cerca del río, pero ningún pájaro cantaba.', 'Gamma' ),
			'd' => $this->post( 'delta', 'Recetas de cocina con aceite, pan y queso del pueblo vecino.', 'Delta' ),
		);
		$this->clear_index();

		return $ids;
	}

	public function test_build_populates_the_three_tables(): void {
		$ids = $this->corpus();

		$job = $this->run_job();

		$this->assertSame( JobRepository::DONE, $job['status'] );
		$this->assertSame( 4, $this->repo->count_lexical() );
		$this->assertGreaterThan( 0, (int) $this->doc_row( $ids['a'] )['doc_len'] );
		$this->assertSame( 40, strlen( $this->doc_row( $ids['a'] )['content_hash'] ) );
		$this->assertGreaterThan( 0, $this->repo->count_rows( 'postings' ) );
		$this->assertArrayHasKey( $this->stem( 'zorrillo' ), $this->repo->terms( $ids['a'] ) );
	}

	public function test_build_has_two_phases_and_counts_both(): void {
		$this->corpus();

		$job = $this->run_job();

		$this->assertSame( 4, $job['total'] );
		$this->assertSame( 4, $job['done'] );
		$this->assertTrue( $job['params']['build'] );
	}

	public function test_df_is_exact_and_singletons_are_pruned(): void {
		$this->corpus();
		$this->run_job();

		$this->assertSame( 3, $this->df( 'zorrillo' ) );
		$this->assertNull( $this->df( 'aceite' ), 'un término de una sola entrada no se guarda' );
	}

	public function test_matches_the_memory_index_of_the_engine(): void {
		$ids = $this->corpus();
		$this->run_job();

		$source = Plugin::container()->get( PostSource::class );
		$memory = new MemoryIndex();
		( new Indexer() )->build( $source, $memory );

		foreach ( $ids as $id ) {
			$expected = $memory->terms( $id );
			$actual   = $this->repo->terms( $id );

			$this->assertSame( array_keys( $expected ), array_keys( $actual ), "términos de la entrada {$id}" );
			foreach ( $expected as $term => $weight ) {
				$this->assertEqualsWithDelta( $weight, $actual[ $term ], 1e-4 * max( 1.0, abs( $weight ) ), "peso de «{$term}» en {$id}" );
			}
		}
	}

	public function test_a_failed_postings_write_does_not_mark_the_entry_as_up_to_date(): void {
		global $wpdb;
		$ids = $this->corpus();
		$this->run_job();
		$before = $this->doc_row( $ids['a'] )['lex_hash'];
		$this->assertNotSame( '', $before );

		// Las postings no se pueden escribir (la sentencia falla) y el contenido cambia sin pasar por los hooks.
		// Sin DDL: un ALTER TABLE confirmaría la transacción de la prueba.
		$postings = Schema::table( $wpdb->prefix, 'postings' );
		$break    = static fn( $query ) => str_starts_with( (string) $query, "INSERT INTO `{$postings}`" ) ? 'INSERT INTO magiclinking_no_such_table VALUES (1)' : $query;
		$wpdb->update( $wpdb->posts, array( 'post_content' => 'El zorrillo se esconde junto al río helado y al bosque profundo.' ), array( 'ID' => $ids['a'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $ids['a'] );

		$wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$this->lexical->index_changed( array( $ids['a'] ) );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( false );
		}

		$this->assertSame( $before, $this->doc_row( $ids['a'] )['lex_hash'], 'la entrada debe seguir pareciendo desfasada para reintentarse' );
	}

	public function test_unchanged_posts_are_skipped_by_content_hash(): void {
		$ids = $this->corpus();
		$this->run_job();

		global $wpdb;
		$name   = Schema::table( $wpdb->prefix, 'postings' );
		$before = (int) $wpdb->get_var( "SELECT SUM(weight) FROM {$name}" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$result = $this->lexical->index_changed( array_values( $ids ) );

		$this->assertSame( 0, $result['indexed'] );
		$this->assertSame( 4, $result['unchanged'] );

		$second = $this->run_job();
		$this->assertFalse( $second['params']['build'], 'con el índice construido no hay segunda construcción' );
		$this->assertSame( 4, $second['params']['same'] );
		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT SUM(weight) FROM {$name}" ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_a_changed_post_is_reindexed_on_save(): void {
		$ids = $this->corpus();
		$this->run_job();
		$this->assertArrayHasKey( $this->stem( 'zorrillo' ), $this->repo->terms( $ids['a'] ) );

		wp_update_post(
			array(
				'ID'           => $ids['a'],
				'post_title'   => 'Alfa',
				'post_content' => 'Ahora habla del río helado y de las recetas de cocina del pueblo vecino.',
			)
		);

		$terms = $this->repo->terms( $ids['a'] );
		$this->assertArrayNotHasKey( $this->stem( 'zorrillo' ), $terms );
		// «río» ya estaba en el vocabulario (df 2 al construir), así que cuenta aunque la entrada sea la modificada.
		$this->assertArrayHasKey( $this->stem( 'río' ), $terms );
	}

	public function test_a_post_that_only_changes_its_title_is_reindexed(): void {
		$ids = $this->corpus();
		$this->run_job();
		$hash = $this->doc_row( $ids['b'] )['content_hash'];

		wp_update_post(
			array(
				'ID'         => $ids['b'],
				'post_title' => 'Beta con otro título',
			)
		);

		$this->assertNotSame( $hash, $this->doc_row( $ids['b'] )['content_hash'] );
	}

	public function test_a_new_post_adds_to_df_and_gets_postings(): void {
		$this->corpus();
		$this->run_job();

		$new = $this->post( 'epsilon', 'El zorrillo vuelve a aparecer junto al bosque cuando amanece.' );

		$this->assertSame( 4, $this->df( 'zorrillo' ) );
		$this->assertArrayHasKey( $this->stem( 'zorrillo' ), $this->repo->terms( $new ) );
		$this->assertSame( 5, $this->repo->count_lexical() );
	}

	public function test_deleting_a_post_removes_its_postings_and_row(): void {
		global $wpdb;
		$ids = $this->corpus();
		$this->run_job();
		$this->assertNotSame( array(), $this->repo->terms( $ids['c'] ) );

		wp_delete_post( $ids['c'], true );

		$this->assertSame( array(), $this->repo->terms( $ids['c'] ) );
		$this->assertNull( $this->doc_row( $ids['c'] ) );
		$name = Schema::table( $wpdb->prefix, 'postings' );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$name} WHERE post_id = %d", $ids['c'] ) ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_languages_are_indexed_apart(): void {
		$es = array(
			$this->post( 'uno', 'La banana madura está en la cocina de la casa grande.' ),
			$this->post( 'dos', 'Compramos una banana en el mercado de la ciudad vieja.' ),
		);
		$en = array(
			$this->post( 'one', 'The banana is ripe and waits in the kitchen of the big house.' ),
			$this->post( 'two', 'We bought a banana at the market of the old city yesterday.' ),
			$this->post( 'three', 'Another banana fell from the table in the big kitchen.' ),
		);
		$this->clear_index();

		$filter = static fn( $lang, $id ) => in_array( $id, $en, true ) ? 'en' : 'es';
		add_filter( 'magiclinking_post_language', $filter, 10, 2 );
		$this->run_job();
		remove_filter( 'magiclinking_post_language', $filter, 10 );

		$this->assertSame( 'es', $this->doc_row( $es[0] )['lang'] );
		$this->assertSame( 'en', $this->doc_row( $en[0] )['lang'] );
		$this->assertSame( 2, $this->df( 'banana', 'es' ) );
		$this->assertSame( 3, $this->df( 'banana', 'en' ) );

		$spanish = array_keys( $this->repo->terms( $es[0] ) );
		$english = array_keys( $this->repo->terms( $en[0] ) );
		$this->assertContains( $this->stem( 'banana', 'es' ), $spanish );
		$this->assertContains( $this->stem( 'banana', 'en' ), $english );

		// Las candidatas se filtran por idioma.
		$this->assertSame( array( $es[1] ), array_keys( $this->repo->containing( array( $this->stem( 'banana', 'es' ) ), 'es', 10, array( $es[0] ) ) ) );
		$this->assertSame( 3, count( $this->repo->containing( array( $this->stem( 'banana', 'en' ) ), 'en', 10 ) ) );
	}

	public function test_repository_reads_are_consistent(): void {
		$ids = $this->corpus();
		$this->run_job();
		$zorrillo = $this->stem( 'zorrillo' );

		$this->assertSame( array( $ids['b'], $ids['c'] ), array_keys( $this->repo->similar( $this->repo->terms( $ids['a'] ), 'es', 5, array( $ids['a'] ) ) ) );
		$this->assertGreaterThan( 0.0, $this->repo->similarity( $ids['a'], $ids['b'] ) );
		$this->assertSame( 0.0, $this->repo->similarity( $ids['a'], $ids['d'] ) );
		$this->assertEqualsWithDelta( $this->repo->similarity( $ids['a'], $ids['b'] ), $this->repo->similarity( $ids['b'], $ids['a'] ), 1e-6 );
		$this->assertCount( 3, $this->repo->containing( array( $zorrillo ), 'es', 10 ) );

		$meta = $this->repo->meta( $ids['a'] );
		$this->assertNotNull( $meta );
		$this->assertSame( 'es', $meta->lang );
		$this->assertSame( 'Alfa zorrillo', $meta->title );
		$this->assertNull( $this->repo->meta( 999999 ) );
	}

	public function test_stale_index_triggers_a_lexical_rebuild_at_night(): void {
		$this->corpus();
		$this->run_job();
		$this->assertFalse( $this->lexical->is_stale() );

		// Más de un 5 % de entradas nuevas.
		$this->post( 'zeta', 'Texto nuevo sobre el bosque y el río con un zorrillo.' );
		$this->assertTrue( $this->lexical->is_stale() );

		$this->jobs->run_nightly();

		$active = $this->jobs->repository()->active( Jobs::TYPE_INDEX );
		$this->assertNotNull( $active );
		$this->assertTrue( $active['params']['build'] );
		$this->assertFalse( $active['params']['graph'] );

		$this->jobs->run_to_completion( $active['id'] );
		$this->assertFalse( $this->lexical->is_stale() );
		$this->assertSame( 4, $this->df( 'zorrillo' ) );
	}

	public function test_rebuild_resets_df_of_deleted_posts(): void {
		$ids = $this->corpus();
		$this->run_job();

		wp_delete_post( $ids['c'], true );
		$this->assertSame( 3, $this->df( 'zorrillo' ), 'las borradas no restan hasta el recálculo' );

		$this->run_job( false, false, true );

		$this->assertSame( 2, $this->df( 'zorrillo' ) );
	}

	public function test_a_cancelled_rebuild_is_not_taken_for_a_built_index_and_the_next_run_rebuilds(): void {
		$this->corpus();

		$job = $this->jobs->start_index( 0, false );
		$this->jobs->step( $job['id'], 20.0, 2 );
		$this->jobs->cancel( $job['id'] );

		$this->assertFalse( $this->lexical->is_built(), 'una reconstrucción a medias no es un índice construido' );
		$this->assertTrue( $this->lexical->is_stale(), 'el recálculo nocturno la detecta' );

		$next = $this->jobs->start_index( 0, false );
		$this->assertTrue( $next['params']['build'] );
		$this->jobs->run_to_completion( $next['id'] );

		$this->assertTrue( $this->lexical->is_built() );
		$this->assertFalse( $this->lexical->is_stale() );
		$this->assertSame( 3, $this->df( 'zorrillo' ) );
	}

	public function test_a_failed_rebuild_is_rebuilt_by_the_next_run(): void {
		$this->corpus();
		$this->run_job();

		$boom = static function (): never {
			throw new \RuntimeException( 'fallo simulado' );
		};
		$job  = $this->jobs->start_index( 0, false, false, false, true );
		add_filter( 'magiclinking_post_html', $boom );
		$this->jobs->step( $job['id'] );
		remove_filter( 'magiclinking_post_html', $boom );

		$this->assertSame( JobRepository::FAILED, $this->jobs->repository()->get( $job['id'] )['status'] );
		$this->assertFalse( $this->lexical->is_built() );

		$next = $this->jobs->start_index( 0, false );
		$this->assertTrue( $next['params']['build'] );
	}

	public function test_a_user_request_is_not_swallowed_by_the_nightly_recount(): void {
		$this->corpus();
		$this->run_job();
		$this->post( 'zeta', 'Texto nuevo sobre el bosque y el río con un zorrillo.' );
		$this->jobs->run_nightly();

		$nightly = $this->jobs->repository()->active( Jobs::TYPE_INDEX );
		$this->assertNotNull( $nightly );
		$this->assertFalse( $nightly['params']['graph'] );

		$mine = $this->jobs->start_index( 1, false, true );
		$this->assertNotSame( $nightly['id'], $mine['id'] );
		$this->assertTrue( $mine['params']['graph'] );
		$this->assertTrue( $mine['params']['force'] );
		$this->assertSame( JobRepository::CANCELLED, $this->jobs->repository()->get( $nightly['id'] )['status'] );

		$this->jobs->run_to_completion( $mine['id'] );
		$status = $this->jobs->status();
		$this->assertSame( $mine['id'], $status['last_done']['id'] );
		$this->assertSame( 0, $status['last_done']['params']['same'] );
	}

	public function test_a_finished_nightly_recount_is_not_shown_as_the_users_last_job(): void {
		$this->corpus();
		$mine = $this->run_job();
		$this->post( 'zeta', 'Texto nuevo sobre el bosque y el río con un zorrillo.' );
		$this->jobs->run_nightly();
		$nightly = $this->jobs->repository()->active( Jobs::TYPE_INDEX );
		$this->jobs->run_to_completion( $nightly['id'] );

		$status = $this->jobs->status();
		$this->assertSame( $mine['id'], $status['last_job']['id'] );
		$this->assertSame( $mine['id'], $status['last_done']['id'] );
	}

	/**
	 * Cambia el contenido de una entrada y deja que solo el grafo lo vea: el léxico «falló» o no llegó a ejecutarse.
	 */
	private function change_for_the_graph_only( int $id ): void {
		remove_action( 'wp_after_insert_post', array( $this->jobs, 'on_post_saved' ), 20 );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => 'Alfa',
				'post_content' => 'Ahora habla de recetas de cocina con aceite y queso del pueblo vecino.',
			)
		);
		add_action( 'wp_after_insert_post', array( $this->jobs, 'on_post_saved' ), 20, 4 );

		$this->jobs->indexer()->index_many( array( $id ) );
	}

	public function test_a_lexical_failure_after_the_graph_write_is_retried(): void {
		$ids = $this->corpus();
		$this->run_job();
		$this->change_for_the_graph_only( $ids['a'] );
		$this->assertArrayHasKey( $this->stem( 'zorrillo' ), $this->repo->terms( $ids['a'] ), 'estado de partida: postings viejas' );

		$this->jobs->run_posts( array( $ids['a'] ) );

		$this->assertArrayNotHasKey( $this->stem( 'zorrillo' ), $this->repo->terms( $ids['a'] ) );
	}

	public function test_the_nightly_run_retries_posts_whose_lexical_index_is_behind_the_graph(): void {
		$ids = $this->corpus();
		$this->run_job();
		$this->change_for_the_graph_only( $ids['b'] );

		$this->jobs->run_nightly();

		$this->assertNotFalse( as_has_scheduled_action( Jobs::HOOK_POSTS, null, Installer::ACTION_GROUP ) );
	}

	public function test_forced_graph_reanalysis_does_not_make_the_index_look_stale(): void {
		$ids = $this->corpus();
		$this->run_job();
		$this->assertFalse( $this->lexical->is_stale() );

		// Todo ocurrió hace una hora salvo el reanálisis forzado del grafo (enlaces rotos, cambio de slug).
		global $wpdb;
		$docs = Schema::table( $wpdb->prefix, 'docs' );
		$jobs = Schema::table( $wpdb->prefix, 'jobs' );
		$old  = gmdate( 'Y-m-d H:i:s', time() - 7200 );
		$mid  = gmdate( 'Y-m-d H:i:s', time() - 3600 );
		$wpdb->query( $wpdb->prepare( "UPDATE {$docs} SET indexed_at = %s", $old ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null !== $wpdb->get_var( "SHOW COLUMNS FROM {$docs} LIKE 'lex_at'" ) ) { // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$docs} SET lex_at = %s", $old ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$jobs} SET updated_at = %s WHERE type = 'recount'", $mid ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->jobs->indexer()->index_many( array_values( $ids ), true );

		$this->assertFalse( $this->lexical->is_stale() );
	}

	public function test_a_cancelled_nightly_step_that_keeps_running_does_not_close_the_new_build(): void {
		$this->corpus();
		$this->run_job();
		$this->post( 'zeta', 'Texto nuevo sobre el bosque y el río con un zorrillo.' );

		$old = $this->jobs->start_index( 0, false, false, false, true, true );
		$new = null;

		// Mitad del lote del nocturno: una petición manual lo cancela y lanza su propia construcción.
		$race = function ( $html ) use ( &$new ) {
			if ( null === $new ) {
				$new = $this->jobs->start_index( 1, false );
			}
			return $html;
		};
		add_filter( 'magiclinking_post_html', $race );
		$this->jobs->step( $old['id'], 20.0, 100 );
		remove_filter( 'magiclinking_post_html', $race );

		$this->assertNotNull( $new );
		$this->assertSame( JobRepository::CANCELLED, $this->jobs->repository()->get( $old['id'] )['status'] );
		$this->assertTrue( $this->lexical->is_building(), 'el nocturno cancelado no cierra la construcción del nuevo' );

		$this->jobs->run_to_completion( $new['id'] );
		$this->assertFalse( $this->lexical->is_building() );
		$this->assertSame( 4, $this->df( 'zorrillo' ), 'df no se cuenta dos veces' );
	}
}
