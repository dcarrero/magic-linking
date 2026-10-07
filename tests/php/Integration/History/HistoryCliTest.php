<?php
/**
 * `wp magic-linking history`: listar, deshacer, rehacer y purgar.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\History;

use MagicLinking\Cli\Command;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Settings;
use MagicLinking\Graph\BrokenRepository;
use MagicLinking\Graph\ReportRepository;
use MagicLinking\History\ChangeRepository;
use MagicLinking\History\Reader;
use MagicLinking\History\Redo;
use MagicLinking\History\Retention;
use MagicLinking\History\Undo;
use MagicLinking\Jobs\Jobs;
use RuntimeException;
use WP_CLI;

final class HistoryCliTest extends HistoryTestCase {

	private Command $command;

	public function set_up(): void {
		parent::set_up();

		WP_CLI::reset();
		$c             = Plugin::container();
		$this->command = new Command(
			$c->get( Jobs::class ),
			$c->get( ReportRepository::class ),
			$c->get( BrokenRepository::class ),
			$c->get( Reader::class ),
			$c->get( Undo::class ),
			$c->get( Retention::class ),
			$c->get( ChangeRepository::class ),
			$c->get( Redo::class )
		);
	}

	public function test_list_shows_the_groups(): void {
		$batch = $this->batch( 2 );

		$this->command->history( array( 'list' ), array() );

		$this->assertStringContainsString( $batch['batch'], WP_CLI::$lines[0] );
		$this->assertStringEndsWith( '|2|2|2', WP_CLI::$lines[0], 'Enlaces, puestos y entradas.' );
	}

	public function test_undo_and_redo_a_group_in_the_command_itself(): void {
		$batch = $this->batch( 2 );

		$this->command->history( array( 'undo', $batch['batch'] ), array() );
		$this->assertSame( 'Success: Group undone.', end( WP_CLI::$lines ) );
		foreach ( $batch['posts'] as $post ) {
			$this->assertStringNotContainsString( '<a href=', $this->stored( $post ) );
		}

		WP_CLI::reset();
		$this->command->history( array( 'redo', $batch['batch'] ), array() );
		$this->assertSame( 'Success: Group redone.', end( WP_CLI::$lines ) );
		foreach ( $batch['posts'] as $post ) {
			$this->assertStringContainsString( '<a href=', $this->stored( $post ) );
		}
	}

	public function test_without_a_user_it_acts_as_the_system(): void {
		$batch = $this->batch( 1 );
		wp_set_current_user( 0 );

		$this->command->history( array( 'undo', $batch['batch'] ), array() );

		$this->assertSame( 'Success: Group undone.', end( WP_CLI::$lines ) );
		$this->assertStringNotContainsString( '<a href=', $this->stored( $batch['posts'][0] ) );
	}

	public function test_what_cannot_be_undone_alone_is_a_warning(): void {
		$batch = $this->batch( 1 );
		preg_match( '#<a href="[^"]+">aire acondicionado</a>#', $this->stored( $batch['posts'][0] ), $link );
		wp_update_post(
			array(
				'ID'           => $batch['posts'][0],
				'post_content' => wp_slash( $this->stored( $batch['posts'][0] ) . "\n\n" . $this->p( $link[0] ) ),
			)
		);

		$this->command->history( array( 'undo', $batch['batch'] ), array() );

		$this->assertStringStartsWith( 'Warning: 1 change could not be applied by itself.', end( WP_CLI::$lines ) );
	}

	public function test_an_unknown_group_and_an_unknown_action_are_errors(): void {
		try {
			$this->command->history( array( 'undo', '01J9Z3K8Q2M4N5P6R7S8T9V0WX' ), array() );
			$this->fail( 'Debía fallar.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'no such group', $e->getMessage() );
		}

		$this->expectException( RuntimeException::class );
		$this->command->history( array( 'wipe' ), array() );
	}

	public function test_purge_counts_with_dry_run_and_then_deletes(): void {
		$old = $this->batch( 2 );
		$this->age( $old['batch'], 100 );
		$recent = $this->batch( 1 );

		$this->command->history( array( 'purge' ), array( 'dry-run' => true ) );
		$this->assertSame( 'Success: 2 history rows have expired.', end( WP_CLI::$lines ) );
		$this->assertSame( 2, $this->rows( $old['batch'] ) );

		$this->command->history( array( 'purge' ), array() );
		$this->assertSame( 'Success: 2 history rows deleted.', end( WP_CLI::$lines ) );
		$this->assertSame( 0, $this->rows( $old['batch'] ) );
		$this->assertSame( 1, $this->rows( $recent['batch'] ) );
	}

	public function test_purge_with_no_expiry_says_so(): void {
		Plugin::container()->get( Settings::class )->update( array( Settings::RETENTION_SETTING => 0 ) );

		$this->command->history( array( 'purge' ), array() );

		$this->assertStringContainsString( 'never expires', end( WP_CLI::$lines ) );
	}
}
