<?php
/**
 * Reglas: órdenes de WP-CLI (con un sustituto de WP_CLI que registra la salida).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Rules;

use MagicLinking\Cli\RulesCommand;
use MagicLinking\Core\Plugin;
use MagicLinking\Rules\RuleValidator;
use RuntimeException;
use WP_CLI;

final class RulesCliTest extends RulesTestCase {

	private RulesCommand $command;

	public function set_up(): void {
		parent::set_up();

		WP_CLI::reset();
		$c             = Plugin::container();
		$this->command = new RulesCommand( $this->repo(), $c->get( RuleValidator::class ), $this->service() );
	}

	public function test_add_list_and_delete(): void {
		$this->command->add(
			array(),
			array(
				'phrases'  => 'bomba de calor|aerotermia',
				'target'   => (string) $this->target,
				'word-gap' => true,
			)
		);
		$this->assertStringContainsString( 'Success: Rule', implode( "\n", WP_CLI::$lines ) );

		WP_CLI::reset();
		$this->command->list_rules( array(), array() );
		$this->assertStringContainsString( 'bomba de calor | aerotermia', implode( "\n", WP_CLI::$lines ) );
		$this->assertStringContainsString( 'yes', implode( "\n", WP_CLI::$lines ) );

		$id = $this->repo()->page( 1, 1 )['items'][0]->id;
		WP_CLI::reset();
		$this->command->delete( array( (string) $id ), array() );
		$this->assertNull( $this->repo()->find( $id ) );

		$this->expectException( RuntimeException::class );
		$this->command->add(
			array(),
			array(
				'phrases' => 'de la',
				'target'  => (string) $this->target,
			)
		);
	}

	public function test_apply_previews_without_yes_and_writes_with_it_one_entry_at_a_time(): void {
		$post = $this->entry( $this->body( array( 'Una bomba de calor ahorra energía.' ) ) );
		$rule = $this->rule();

		$this->command->apply( array( (string) $rule->id ), array( 'post' => (string) $post->ID ) );
		$this->assertStringContainsString( '[bomba de calor]', implode( "\n", WP_CLI::$lines ) );
		$this->assertStringContainsString( 'Nothing was written', implode( "\n", WP_CLI::$lines ) );
		$this->assertSame( $post->post_content, $this->content_of( $post->ID ) );

		WP_CLI::reset();
		$this->command->apply(
			array( (string) $rule->id ),
			array(
				'post' => (string) $post->ID,
				'yes'  => true,
			)
		);
		$this->assertStringContainsString( '1 link(s) added', implode( "\n", WP_CLI::$lines ) );
		$this->assertStringContainsString( '>bomba de calor</a>', $this->content_of( $post->ID ) );
	}

	public function test_scan_lists_the_entries_where_the_rule_fits(): void {
		$post = $this->entry( $this->body( array( 'Una bomba de calor ahorra energía.' ) ) );
		$this->entry( $this->body( array( 'Nada de nada.' ) ) );
		$rule = $this->rule();

		$this->command->scan( array( (string) $rule->id ), array() );

		$output = implode( "\n", WP_CLI::$lines );
		$this->assertStringContainsString( (string) $post->ID, $output );
		$this->assertStringContainsString( '1 entries where the rule fits', $output );
	}

	public function test_run_applies_every_active_rule_like_saving_does(): void {
		$post = $this->entry( $this->body( array( 'Una bomba de calor ahorra energía.' ) ) );
		$this->rule();

		$this->command->run( array(), array( 'post' => (string) $post->ID ) );

		$this->assertStringContainsString( '>bomba de calor</a>', $this->content_of( $post->ID ) );
	}
}
