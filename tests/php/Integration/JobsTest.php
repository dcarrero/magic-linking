<?php
/**
 * Procesos en segundo plano: indexado inicial, incremental y nocturno.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Settings;
use MagicLinking\Jobs\JobRepository;
use MagicLinking\Jobs\Jobs;

final class JobsTest extends GraphTestCase {

	private Jobs $jobs;

	public function set_up(): void {
		parent::set_up();

		$this->jobs = Plugin::container()->get( Jobs::class );
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );
	}

	private function post( string $slug, string $content = '', string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_name'    => $slug,
				'post_title'   => ucfirst( $slug ),
				'post_content' => $content,
				'post_status'  => $status,
			)
		);
	}

	private function link( string $slug ): string {
		return '<a href="' . home_url( "/{$slug}/" ) . '">' . $slug . '</a>';
	}

	/**
	 * Quita los índices que el guardado incremental ha creado al fabricar las entradas.
	 */
	private function clear_index(): void {
		global $wpdb;
		foreach ( array( 'docs', 'links', 'jobs' ) as $table ) {
			$name = \MagicLinking\Core\Schema::table( $wpdb->prefix, $table );
			$wpdb->query( "DELETE FROM {$name}" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	public function test_save_indexes_the_post_and_keeps_counts_up_to_date(): void {
		$b = $this->post( 'beta' );
		$a = $this->post( 'alfa', $this->link( 'beta' ) );

		$this->assertSame( '1', $this->doc_row( $b )['inbound'] );
		$this->assertSame( '1', $this->doc_row( $a )['outbound'] );

		wp_update_post(
			array(
				'ID'           => $a,
				'post_content' => 'Sin enlaces.',
			)
		);
		$this->assertSame( '0', $this->doc_row( $b )['inbound'] );
	}

	public function test_saving_a_draft_removes_it_and_publishing_a_target_fixes_the_links_that_pointed_to_it(): void {
		$draft = $this->post( 'pronto', 'Texto.', 'draft' );
		$a     = $this->post( 'origen', $this->link( 'pronto' ) );
		$this->assertNull( $this->doc_row( $draft ) );
		$this->assertSame( '1', $this->doc_row( $a )['broken'] );

		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( '0', $this->doc_row( $a )['broken'] );
		$this->assertSame( '1', $this->doc_row( $draft )['inbound'] );

		wp_trash_post( $draft );

		$this->assertNull( $this->doc_row( $draft ) );
		$this->assertSame( '1', $this->doc_row( $a )['broken'], 'El origen se revisa al llevar el destino a la papelera.' );
	}

	public function test_deleting_a_post_updates_who_linked_to_it(): void {
		$b = $this->post( 'beta' );
		$a = $this->post( 'alfa', $this->link( 'beta' ) );

		wp_delete_post( $b, true );

		$this->assertNull( $this->doc_row( $b ) );
		$this->assertSame( '1', $this->doc_row( $a )['broken'] );
		$this->assertSame( '1', (string) $this->link_rows( $a )[0]['is_broken'] );
	}

	public function test_full_index_runs_in_batches_and_can_be_paused_resumed_and_cancelled(): void {
		$ids = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$ids[] = $this->post( "entrada-{$i}", 1 === $i ? '' : $this->link( 'entrada-1' ) );
		}
		$this->clear_index();
		add_filter( 'magiclinking_batch_max_entries', static fn(): int => 5 );

		$repo = $this->jobs->repository();
		$id   = $repo->create(
			Jobs::TYPE_INDEX,
			12,
			array(
				'last_id' => 0,
				'batch'   => 5,
				'force'   => true,
			),
			0
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		$this->assertTrue( $this->jobs->step( $id ), 'Lote 1 de 3.' );
		$job = $repo->get( $id );
		$this->assertSame( JobRepository::RUNNING, $job['status'] );
		$this->assertSame( 5, $job['done'] );
		$this->assertSame( $ids[4], $job['params']['last_id'] );

		$this->assertTrue( $this->jobs->pause( $id ) );
		$this->assertFalse( $this->jobs->step( $id ), 'Pausado no procesa nada.' );
		$this->assertSame( 5, $repo->get( $id )['done'] );

		$this->assertTrue( $this->jobs->resume( $id, false ) );
		$this->assertTrue( $this->jobs->step( $id ), 'Lote 2 de 3, desde el cursor.' );
		$this->assertSame( 10, $repo->get( $id )['done'] );

		$this->assertFalse( $this->jobs->step( $id ), 'El último lote termina el proceso.' );
		$job = $repo->get( $id );
		$this->assertSame( JobRepository::DONE, $job['status'] );
		$this->assertSame( 12, $job['done'] );
		$this->assertSame( '11', $this->doc_row( $ids[0] )['inbound'] );

		$again = $repo->create( Jobs::TYPE_INDEX, 12, array( 'last_id' => 0 ), 0 );
		$this->assertTrue( $this->jobs->cancel( $again ) );
		$this->assertFalse( $this->jobs->step( $again ) );
		$this->assertSame( JobRepository::CANCELLED, $repo->get( $again )['status'] );
		$this->assertFalse( $this->jobs->cancel( $again ), 'Un proceso cancelado no se cancela otra vez.' );
	}

	public function test_only_one_index_job_is_active_and_run_to_completion_finishes_it(): void {
		$b = $this->post( 'beta' );
		$a = $this->post( 'alfa', $this->link( 'beta' ) );
		$this->clear_index();

		$first  = $this->jobs->start_index( 0, false );
		$second = $this->jobs->start_index( 0, false );
		$this->assertSame( $first['id'], $second['id'] );
		$this->assertSame( 2, $first['total'] );

		$calls = array();
		$this->jobs->run_to_completion(
			$first['id'],
			static function ( int $done, int $total ) use ( &$calls ): void {
				$calls[] = array( $done, $total );
			}
		);

		// Dos pasos: el recorrido con conteo y el pesado del índice léxico.
		$this->assertSame( array( array( 2, 2 ), array( 2, 2 ) ), $calls );
		$status = $this->jobs->status();
		$this->assertSame( 2, $status['indexed'] );
		$this->assertSame( 0, $status['pending'] );
		$this->assertNull( $status['job'] );
		$this->assertSame( JobRepository::DONE, $status['last_job']['status'] );
		$this->assertSame( '1', $this->doc_row( $b )['inbound'] );
		$this->assertSame( '1', $this->doc_row( $a )['outbound'] );
	}

	public function test_a_full_index_forgets_posts_that_are_no_longer_published(): void {
		$a = $this->post( 'alfa', 'x' );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $a ) ); // Sin pasar por los hooks.
		clean_post_cache( $a );
		$this->assertNotNull( $this->doc_row( $a ) );

		$job = $this->jobs->start_index( 0, false );
		$this->jobs->run_to_completion( $job['id'] );

		$this->assertNull( $this->doc_row( $a ) );
	}

	public function test_starting_a_job_schedules_the_nightly_run_and_the_first_batch(): void {
		$this->post( 'alfa', 'x' );

		$job = $this->jobs->start_index( 1 );

		$this->assertTrue( as_has_scheduled_action( Jobs::HOOK_BATCH, array( $job['id'] ), Installer::ACTION_GROUP ) );
		$this->assertTrue( as_has_scheduled_action( Jobs::HOOK_NIGHTLY, null, Installer::ACTION_GROUP ) );

		$this->jobs->cancel( $job['id'] );
		$this->assertFalse( as_has_scheduled_action( Jobs::HOOK_BATCH, array( $job['id'] ), Installer::ACTION_GROUP ) );

		$this->jobs->ensure_schedules();
		$count = count(
			as_get_scheduled_actions(
				array(
					'hook'   => Jobs::HOOK_NIGHTLY,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( 1, $count, 'No se duplica el trabajo nocturno.' );
	}

	public function test_a_batch_running_in_action_scheduler_schedules_the_next_one(): void {
		for ( $i = 1; $i <= 7; $i++ ) {
			$this->post( "lote-{$i}", 'x' );
		}
		$this->clear_index();
		add_filter( 'magiclinking_batch_max_entries', static fn(): int => 5 );

		$repo = $this->jobs->repository();
		$id   = $repo->create(
			Jobs::TYPE_INDEX,
			7,
			array(
				'last_id' => 0,
				'batch'   => 5,
				'force'   => true,
			),
			0
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		// Action Scheduler considera «única» una acción mientras haya otra igual pendiente o EN CURSO,
		// y el lote que se está ejecutando sigue en curso: el siguiente no puede pedirse como único.
		$unique_flags = array();
		$spy          = static function ( $pre, $hook, $args, $group, $priority, $unique ) use ( &$unique_flags ) {
			if ( Jobs::HOOK_BATCH === $hook ) {
				$unique_flags[] = $unique;
			}
			return $pre;
		};
		add_filter( 'pre_as_enqueue_async_action', $spy, 10, 6 );
		$this->jobs->run_batch( $id );
		remove_filter( 'pre_as_enqueue_async_action', $spy, 10 );

		$this->assertSame( array( false ), $unique_flags, 'El siguiente lote se programa sin modo único.' );
		$this->assertSame( 5, $repo->get( $id )['done'] );
	}

	public function test_one_run_works_through_many_slices_until_done_when_time_allows(): void {
		$ids = array();
		for ( $i = 1; $i <= 23; $i++ ) {
			$ids[] = $this->post( "corte-{$i}", 1 === $i ? '' : $this->link( 'corte-1' ) );
		}
		$this->clear_index();

		$repo = $this->jobs->repository();
		$id   = $repo->create(
			Jobs::TYPE_INDEX,
			23,
			array(
				'last_id' => 0,
				'batch'   => 5,
				'force'   => true,
			),
			0
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		// Con tiempo de sobra, una sola ejecución recorre los cortes (5, 10, 8) y termina el proceso.
		$this->assertFalse( $this->jobs->step( $id, 60.0, 1000 ) );
		$job = $repo->get( $id );
		$this->assertSame( JobRepository::DONE, $job['status'] );
		$this->assertSame( 23, $job['done'] );
		$this->assertSame( '22', $this->doc_row( $ids[0] )['inbound'] );
		$this->assertSame( 23, $this->jobs->status()['indexed'] );
	}

	public function test_a_run_stops_when_its_time_budget_runs_out_and_the_next_one_resumes_from_the_cursor(): void {
		$ids = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$ids[] = $this->post( "plazo-{$i}", 'x' );
		}
		$this->clear_index();

		$repo = $this->jobs->repository();
		$id   = $repo->create(
			Jobs::TYPE_INDEX,
			12,
			array(
				'last_id' => 0,
				'batch'   => 5,
				'force'   => true,
			),
			0
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		// Presupuesto agotado desde el principio: aun así avanza (al menos una entrada) y guarda el cursor.
		$this->assertTrue( $this->jobs->step( $id, 0.0, 1000 ) );
		$job = $repo->get( $id );
		$this->assertGreaterThanOrEqual( 1, $job['done'] );
		$this->assertLessThan( 12, $job['done'] );
		$this->assertSame( $ids[ $job['done'] - 1 ], $job['params']['last_id'] );

		$safety = 0;
		while ( $this->jobs->step( $id, 0.0, 1000 ) && ++$safety < 50 ) {
			$this->assertLessThan( 12, $repo->get( $id )['done'] );
		}
		$this->assertSame( JobRepository::DONE, $repo->get( $id )['status'] );
		$this->assertSame( 12, $this->jobs->status()['indexed'] );
	}

	public function test_a_run_stops_at_the_maximum_number_of_entries(): void {
		for ( $i = 1; $i <= 9; $i++ ) {
			$this->post( "tope-{$i}", 'x' );
		}
		$this->clear_index();

		$repo = $this->jobs->repository();
		$id   = $repo->create(
			Jobs::TYPE_INDEX,
			9,
			array(
				'last_id' => 0,
				'batch'   => 5,
				'force'   => true,
			),
			0
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		$this->assertTrue( $this->jobs->step( $id, 60.0, 7 ) );
		$this->assertSame( 7, $repo->get( $id )['done'] );
		$this->assertFalse( $this->jobs->step( $id, 60.0, 7 ) );
		$this->assertSame( 9, $repo->get( $id )['done'] );
	}

	public function test_unforced_index_skips_unchanged_posts_and_forced_reanalyzes_them(): void {
		$b = $this->post( 'destino' );
		$a = $this->post( 'origen', $this->link( 'destino' ) );
		$this->clear_index();
		$indexer = $this->jobs->indexer();

		$first = $indexer->index_many( array( $a, $b ), true );
		$this->assertSame( 2, $first['indexed'] );
		$this->assertSame( 2, $first['processed'] );
		$this->assertSame( '1', $this->doc_row( $b )['inbound'] );

		$again = $indexer->index_many( array( $a, $b ), false );
		$this->assertSame( 0, $again['indexed'] );
		$this->assertSame( 2, $again['unchanged'] );

		$forced = $indexer->index_many( array( $a, $b ), true );
		$this->assertSame( 2, $forced['indexed'] );

		$job = $this->jobs->start_index( 0, false, false );
		$this->assertFalse( $job['params']['force'] );
	}

	public function test_analyze_changes_skips_unchanged_and_from_scratch_processes_everything(): void {
		global $wpdb;
		$b = $this->post( 'destino' );
		$a = $this->post( 'origen', $this->link( 'destino' ) );
		$this->clear_index();

		// Primer análisis: todo es nuevo.
		$job = $this->jobs->start_index( 0, false, false );
		$this->jobs->run_to_completion( $job['id'] );
		$params = $this->jobs->repository()->get( $job['id'] )['params'];
		$this->assertSame( 2, $params['changed'] );
		$this->assertSame( 0, $params['same'] );

		// Sin cambios: recorre todo pero no procesa nada.
		$job = $this->jobs->start_index( 0, false, false );
		$this->jobs->run_to_completion( $job['id'] );
		$done = $this->jobs->repository()->get( $job['id'] );
		$this->assertSame( 2, $done['done'] );
		$this->assertSame( 0, $done['params']['changed'] );
		$this->assertSame( 2, $done['params']['same'] );

		// Una entrada modificada (sin pasar por los ganchos de guardado): solo esa se procesa.
		$wpdb->update( $wpdb->posts, array( 'post_content' => 'Texto nuevo.' ), array( 'ID' => $a ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $a );
		$job = $this->jobs->start_index( 0, false, false );
		$this->jobs->run_to_completion( $job['id'] );
		$done = $this->jobs->repository()->get( $job['id'] );
		$this->assertSame( 1, $done['params']['changed'] );
		$this->assertSame( 1, $done['params']['same'] );
		$this->assertSame( '0', $this->doc_row( $b )['inbound'] );

		// Desde cero: procesa todo aunque no haya cambiado nada.
		$job = $this->jobs->start_index( 0, false, true );
		$this->jobs->run_to_completion( $job['id'] );
		$done = $this->jobs->repository()->get( $job['id'] );
		$this->assertTrue( $done['params']['force'] );
		$this->assertSame( 2, $done['params']['changed'] );
		$this->assertSame( 0, $done['params']['same'] );
	}

	public function test_bulk_indexing_replaces_links_and_recounts_old_and_new_targets(): void {
		$x = $this->post( 'uno' );
		$y = $this->post( 'dos' );
		$a = $this->post( 'fuente-a', $this->link( 'uno' ) . ' ' . $this->link( 'uno' ) );
		$b = $this->post( 'fuente-b', $this->link( 'uno' ) . ' <a href="' . home_url( '/no-existe/' ) . '">roto</a> <a href="https://example.org/x?a=1&b=%25">fuera</a>' );
		$this->clear_index();
		$indexer = $this->jobs->indexer();

		$indexer->index_many( array( $x, $y, $a, $b ), true );
		$this->assertSame( '2', $this->doc_row( $x )['inbound'] );
		$this->assertSame( '2', $this->doc_row( $a )['outbound'] );
		$this->assertSame( '2', $this->doc_row( $b )['outbound'] );
		$this->assertSame( '1', $this->doc_row( $b )['broken'] );
		$this->assertSame( '1', $this->doc_row( $b )['external'] );
		$this->assertCount( 2, $this->link_rows( $b ) );

		// La fuente A pasa a enlazar a «dos»: «uno» baja a 1 entrante y «dos» sube a 1, en la misma pasada.
		wp_update_post(
			array(
				'ID'           => $a,
				'post_content' => $this->link( 'dos' ),
			)
		);
		$indexer->index_many( array( $a ), false );
		$this->assertSame( '1', $this->doc_row( $x )['inbound'] );
		$this->assertSame( '1', $this->doc_row( $y )['inbound'] );
		$this->assertCount( 1, $this->link_rows( $a ) );
	}

	public function test_status_gives_an_estimate_only_for_a_running_job_with_measured_pace(): void {
		$id = $this->jobs->repository()->create(
			Jobs::TYPE_INDEX,
			1000,
			array(
				'last_id' => 0,
				'batch'   => 5,
				'force'   => true,
			),
			0
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertNull( $this->jobs->status()['eta'], 'Sin muestras no hay estimación.' );

		$now = time();
		$this->jobs->repository()->set_progress(
			$id,
			400,
			1000,
			array(
				'last_id' => 1,
				'batch'   => 5,
				'force'   => true,
				'samples' => array( array( $now - 100, 100 ), array( $now, 400 ) ),
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( 200, $this->jobs->status()['eta'], '600 pendientes a 3 entradas/s.' );

		$this->jobs->pause( $id );
		$this->assertNull( $this->jobs->status()['eta'], 'Pausado no se estima.' );

		$this->jobs->resume( $id, false );
		$params = $this->jobs->repository()->get( $id )['params'];
		$this->assertArrayNotHasKey( 'samples', $params, 'Al reanudar se vuelve a medir el ritmo.' );
	}

	public function test_nightly_rechecks_posts_with_broken_links(): void {
		$b = $this->post( 'beta' );
		$a = $this->post( 'alfa', $this->link( 'beta' ) . $this->link( 'no-existe' ) );
		$this->assertSame( '1', $this->doc_row( $a )['broken'] );

		$this->jobs->run_nightly();

		$this->assertTrue( as_has_scheduled_action( Jobs::HOOK_POSTS, array( array( $a ), true ), Installer::ACTION_GROUP ) );
		unset( $b );
	}

	public function test_large_sites_queue_the_incremental_index_instead_of_doing_it_in_the_request(): void {
		global $wpdb;
		$table = \MagicLinking\Core\Schema::table( $wpdb->prefix, 'docs' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$rows  = array();
		for ( $i = 1; $i <= Jobs::INLINE_LIMIT + 1; $i++ ) {
			$rows[] = $wpdb->prepare( '(%d, %s, %s)', 900000 + $i, 'post', $now );
		}
		$wpdb->query( "INSERT INTO {$table} (post_id, post_type, indexed_at) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$a = $this->post( 'alfa', 'x' );

		$this->assertNull( $this->doc_row( $a ), 'No se indexa en la petición.' );
		$this->assertTrue( as_has_scheduled_action( Jobs::HOOK_POSTS, array( array( $a ), false ), Installer::ACTION_GROUP ) );

		$this->jobs->run_posts( array( $a ), false );
		$this->assertNotNull( $this->doc_row( $a ) );
	}

	public function test_changing_the_chosen_post_types_takes_pages_out_of_the_index_on_the_next_full_run(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertNotNull( $this->doc_row( $page ) );

		Plugin::container()->get( Settings::class )->update( array( 'post_types' => array( 'post' ) ) );
		$job = $this->jobs->start_index( 0, false );
		$this->jobs->run_to_completion( $job['id'] );

		$this->assertNull( $this->doc_row( $page ) );
	}

	public function test_front_end_makes_no_queries_of_its_own_and_only_the_settings_option_autoloads(): void {
		$this->post( 'alfa', 'x' );
		Plugin::container()->get( Settings::class )->update( array( 'low_inbound_threshold' => 3 ) );
		$job = $this->jobs->start_index( 0, false );
		$this->jobs->run_to_completion( $job['id'] );

		$queries = array();
		$collect = static function ( $query ) use ( &$queries ) {
			$queries[] = (string) $query;
			return $query;
		};
		add_filter( 'query', $collect, 1 );
		ob_start();
		$this->go_to( home_url( '/' ) );
		do_action( 'template_redirect' );
		do_action( 'wp_head' );
		do_action( 'wp_footer' );
		ob_end_clean();
		remove_filter( 'query', $collect, 1 );

		$own = array_filter( $queries, static fn( string $q ): bool => false !== stripos( $q, 'magiclinking_' ) );
		$this->assertSame( array(), array_values( $own ) );

		global $wpdb;
		$autoloaded = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload IN ('yes', 'on', 'auto', 'auto-on')", 'magiclinking%' ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( 'magiclinking_settings' ), $autoloaded );
	}
}
