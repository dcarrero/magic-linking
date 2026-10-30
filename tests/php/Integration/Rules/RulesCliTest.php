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
use MagicLinking\Jobs\Jobs;
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
		$this->assertStringContainsString( '1 link added', implode( "\n", WP_CLI::$lines ) );
		$this->assertStringContainsString( '>bomba de calor</a>', $this->content_of( $post->ID ) );
	}

	public function test_scan_lists_the_entries_where_the_rule_fits(): void {
		$post = $this->entry( $this->body( array( 'Una bomba de calor ahorra energía.' ) ) );
		$this->entry( $this->body( array( 'Nada de nada.' ) ) );
		$rule = $this->rule();

		$this->command->scan( array( (string) $rule->id ), array() );

		$output = implode( "\n", WP_CLI::$lines );
		$this->assertStringContainsString( (string) $post->ID, $output );
		$this->assertStringContainsString( '1 entry where the rule fits', $output );
	}

	/**
	 * D-66 punto 8: el índice pierde coincidencias; sin --full avisa y con --full las encuentra.
	 */
	public function test_scan_warns_when_the_index_finds_nothing_and_full_checks_every_entry(): void {
		$this->entry( $this->body( array( 'Una entrada que ya estaba en el índice.' ) ) );
		$rule = $this->rule();

		$jobs = Plugin::container()->get( Jobs::class );
		$job  = $jobs->start_index( 0, false, true, true, true );
		$jobs->run_to_completion( $job['id'] );

		// Entrada posterior al índice: contiene la frase, pero el índice no la conoce.
		$late = $this->entry( $this->body( array( 'Una bomba de calor ahorra energía.' ) ) );

		$this->command->scan( array( (string) $rule->id ), array() );
		$output = implode( "\n", WP_CLI::$lines );
		$this->assertStringContainsString( '0 entries where the rule fits', $output );
		$this->assertStringContainsString( '--full', $output );
		$this->assertStringContainsString( 'Warning', $output );

		WP_CLI::reset();
		$this->command->scan( array( (string) $rule->id ), array( 'full' => true ) );
		$output = implode( "\n", WP_CLI::$lines );
		$this->assertStringContainsString( (string) $late->ID, $output );
		$this->assertStringContainsString( '1 entry where the rule fits', $output );
		$this->assertStringNotContainsString( 'Warning', $output );
	}

	public function test_run_applies_every_active_rule_like_saving_does(): void {
		$post = $this->entry( $this->body( array( 'Una bomba de calor ahorra energía.' ) ) );
		$this->rule();

		$this->command->run( array(), array( 'post' => (string) $post->ID ) );

		$this->assertStringContainsString( '>bomba de calor</a>', $this->content_of( $post->ID ) );
	}

	/**
	 * Hallazgo 7: sin enlaces insertados no hay éxito ni orden de deshacer con un lote vacío.
	 */
	public function test_apply_does_not_claim_success_when_nothing_was_inserted(): void {
		$post = $this->entry( $this->body( array( 'Una bomba de calor ahorra energía.' ) ) );
		$rule = $this->rule();
		update_post_meta( $post->ID, '_edit_lock', time() . ':' . $this->admin );

		$this->command->apply(
			array( (string) $rule->id ),
			array(
				'post' => (string) $post->ID,
				'yes'  => true,
			)
		);

		$output = implode( "\n", WP_CLI::$lines );
		$this->assertStringNotContainsString( 'Success', $output );
		$this->assertStringNotContainsString( 'history undo', $output );
		$this->assertStringContainsString( 'Warning', $output );
	}

	/**
	 * Hallazgo 9: --no-first-only y el usuario de WP-CLI como autor.
	 */
	public function test_add_accepts_no_first_only_and_records_the_cli_user(): void {
		wp_set_current_user( $this->admin );
		$this->command->add(
			array(),
			array(
				'phrases'    => 'bomba de calor',
				'target'     => (string) $this->target,
				'first-only' => false,
			)
		);

		$rule = $this->repo()->page( 1, 1 )['items'][0];
		$this->assertFalse( $rule->first_only );
		$this->assertSame( $this->admin, $rule->created_by );

		$this->command->add(
			array(),
			array(
				'phrases' => 'otra frase nueva',
				'target'  => (string) $this->target,
			)
		);
		$this->assertTrue( $this->repo()->page( 1, 1 )['items'][0]->first_only, 'Por defecto, solo la primera aparición.' );
	}
}
