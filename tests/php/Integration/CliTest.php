<?php
/**
 * Órdenes de WP-CLI (con un sustituto de WP_CLI que registra la salida).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Cli\CliModule;
use MagicLinking\Cli\Command;
use MagicLinking\Core\Plugin;
use MagicLinking\Jobs\JobRepository;
use MagicLinking\Jobs\Jobs;
use RuntimeException;
use WP_CLI;

final class CliTest extends GraphTestCase {

	private Command $command;

	public function set_up(): void {
		parent::set_up();

		WP_CLI::reset();
		$c             = Plugin::container();
		$this->command = new Command(
			$c->get( Jobs::class ),
			$c->get( \MagicLinking\Graph\ReportRepository::class ),
			$c->get( \MagicLinking\Graph\BrokenRepository::class ),
			$c->get( \MagicLinking\History\Reader::class ),
			$c->get( \MagicLinking\History\Undo::class ),
			$c->get( \MagicLinking\History\Retention::class ),
			$c->get( \MagicLinking\History\ChangeRepository::class ),
			$c->get( \MagicLinking\History\Redo::class ),
			$c->get( \MagicLinking\History\BatchJob::class )
		);
		$this->post( 'beta', 'Sin enlaces.' );
		$this->post( 'alfa', '<a href="' . home_url( '/beta/' ) . '">beta</a> <a href="' . home_url( '/roto/' ) . '">roto</a>' );
		$this->clear_index();
	}

	private function post( string $slug, string $content ): int {
		return self::factory()->post->create(
			array(
				'post_name'    => $slug,
				'post_title'   => ucfirst( $slug ),
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);
	}

	private function clear_index(): void {
		global $wpdb;
		foreach ( array( 'docs', 'links', 'jobs' ) as $table ) {
			$name = \MagicLinking\Core\Schema::table( $wpdb->prefix, $table );
			$wpdb->query( "DELETE FROM {$name}" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	public function test_index_runs_the_whole_site_and_reports_success(): void {
		$this->command->index( array(), array() );

		$this->assertSame( 'Success: 2 entries analyzed.', end( WP_CLI::$lines ) );
		$this->assertSame( 2, Plugin::container()->get( Jobs::class )->status()['indexed'] );
	}

	public function test_index_skips_unchanged_entries_unless_forced(): void {
		$this->command->index( array(), array() );
		WP_CLI::reset();

		$this->command->index( array(), array() );
		$this->assertContains( '0 new or modified, 2 unchanged and skipped.', WP_CLI::$lines );

		WP_CLI::reset();
		$this->command->index( array(), array( 'force' => true ) );
		$this->assertContains( '2 new or modified, 0 unchanged and skipped.', WP_CLI::$lines );
	}

	public function test_index_in_background_only_queues_the_job(): void {
		$this->command->index( array(), array( 'background' => true ) );

		$this->assertStringContainsString( 'queued', end( WP_CLI::$lines ) );
		$this->assertSame( 0, Plugin::container()->get( Jobs::class )->status()['indexed'] );
		$this->assertSame( JobRepository::QUEUED, Plugin::container()->get( Jobs::class )->status()['job']['status'] );
	}

	public function test_index_refuses_to_continue_a_paused_job_and_explains_how(): void {
		$jobs = Plugin::container()->get( Jobs::class );
		$job  = $jobs->start_index( 0, false );
		$jobs->pause( $job['id'] );

		try {
			$this->command->index( array(), array() );
			$this->fail( 'Debía terminar con un error.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'wp magic-linking job resume', $e->getMessage() );
		}
	}

	public function test_index_one_post(): void {
		$id = (int) get_page_by_path( 'alfa', OBJECT, 'post' )->ID;

		$this->command->index( array(), array( 'post' => (string) $id ) );
		$this->assertSame( 'Success: Entry analyzed.', end( WP_CLI::$lines ) );

		$this->command->index( array(), array( 'post' => (string) $id ) );
		$this->assertStringContainsString( 'Nothing changed', end( WP_CLI::$lines ) );

		$this->command->index(
			array(),
			array(
				'post'  => (string) $id,
				'force' => true,
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( 'Success: Entry analyzed.', end( WP_CLI::$lines ) );

		$this->expectException( RuntimeException::class );
		$this->command->index( array(), array( 'post' => '999999' ) );
	}

	public function test_status_lists_the_figures(): void {
		$this->command->index( array(), array() );
		WP_CLI::reset();

		$this->command->status( array(), array() );

		$this->assertContains( 'table:indexed|2', WP_CLI::$lines );
		$this->assertContains( 'table:orphans|1', WP_CLI::$lines );
		$this->assertContains( 'table:broken_links|1', WP_CLI::$lines );
		$this->assertMatchesRegularExpression( '/^table:process\|#\d+ done \(2\/2\)$/m', implode( "\n", WP_CLI::$lines ) );

		WP_CLI::reset();
		$this->command->status( array(), array( 'format' => 'json' ) );
		$this->assertMatchesRegularExpression( '/^json:2\|2\|2\|0\|#\d+ done \(2\/2\)\|1\|1\|1\|1\|2$/', WP_CLI::$lines[0] );
	}

	public function test_report_formats_and_filters(): void {
		$this->command->index( array(), array() );
		WP_CLI::reset();

		$this->command->report(
			array(),
			array(
				'filter' => 'broken',
				'format' => 'json',
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertCount( 1, WP_CLI::$lines );
		$this->assertStringContainsString( '|Alfa|post|en|0|2|0|1|orphan', WP_CLI::$lines[0] );

		WP_CLI::reset();
		$this->command->report( array(), array( 'format' => 'count' ) );
		$this->assertSame( array( '2' ), WP_CLI::$lines );

		ob_start();
		$this->command->report(
			array(),
			array(
				'format'  => 'csv',
				'orderby' => 'title',
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$csv = (string) ob_get_clean();
		$this->assertStringStartsWith( "id,title,type,inbound,outbound,external,broken,status,words,indexed_at,url\n", $csv );
		$this->assertStringNotContainsString( "\xEF\xBB\xBF", $csv, 'Sin BOM en la terminal.' );
		$this->assertSame( 3, substr_count( $csv, "\n" ) );

		foreach ( array( array( 'filter' => 'x' ), array( 'orderby' => 'x' ) ) as $bad ) {
			try {
				$this->command->report( array(), $bad );
				$this->fail( 'Debía fallar.' );
			} catch ( RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_broken_lists_the_links(): void {
		$this->command->index( array(), array() );
		WP_CLI::reset();

		$this->command->broken( array(), array() );
		$this->assertCount( 1, WP_CLI::$lines );
		$this->assertStringContainsString( '/roto/|roto|No entry exists with this URL.', WP_CLI::$lines[0] );

		WP_CLI::reset();
		$this->command->broken( array(), array( 'format' => 'count' ) );
		$this->assertSame( array( '1' ), WP_CLI::$lines );
	}

	public function test_job_pause_resume_cancel_and_list(): void {
		$jobs = Plugin::container()->get( Jobs::class );
		$job  = $jobs->start_index( 0, false );

		$this->command->job( array( 'pause' ), array() );
		$this->assertStringContainsString( 'paused', end( WP_CLI::$lines ) );
		$this->command->job( array( 'resume', (string) $job['id'] ), array() );
		$this->assertStringContainsString( 'queued', end( WP_CLI::$lines ) );

		WP_CLI::reset();
		$this->command->job( array( 'list' ), array() );
		$this->assertCount( 1, WP_CLI::$lines );

		$this->command->job( array( 'cancel' ), array() );
		$this->assertStringContainsString( 'cancelled', end( WP_CLI::$lines ) );

		$this->expectException( RuntimeException::class );
		$this->command->job( array( 'pause' ), array() );
	}

	public function test_the_command_is_only_registered_inside_wp_cli(): void {
		WP_CLI::$commands = array();
		$module           = Plugin::container()->get( CliModule::class );
		$module->register();

		$this->assertSame( array(), WP_CLI::$commands, 'Sin la constante WP_CLI no se registra nada.' );
	}
}
