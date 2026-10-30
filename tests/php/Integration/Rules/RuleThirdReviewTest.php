<?php
/**
 * Tercera revisión de F1-13: coherencia de «enlaces por entrada», idioma al cambiar el destino, creador sin permisos,
 * análisis único por guardado y búsqueda con índice.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Rules;

use MagicLinking\Cli\RulesCommand;
use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Settings;
use MagicLinking\Jobs\Jobs;
use MagicLinking\Rules\Rule;
use MagicLinking\Rules\RuleLog;
use MagicLinking\Rules\RuleRunner;
use MagicLinking\Rules\RuleValidator;
use WP_CLI;

final class RuleThirdReviewTest extends RulesTestCase {

	private function validator(): RuleValidator {
		return Plugin::container()->get( RuleValidator::class );
	}

	private function run_job( int $post_id ): void {
		as_unschedule_all_actions( RuleRunner::HOOK, null, Installer::ACTION_GROUP );
		do_action( RuleRunner::HOOK, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Prefijo propio.
	}

	/**
	 * A: «enlaces por entrada» no puede pasar de lo que permiten los ajustes.
	 */
	public function test_links_per_entry_cannot_exceed_the_setting(): void {
		$input = array(
			'phrases'      => array( 'bomba de calor' ),
			'target_id'    => $this->target,
			'max_per_post' => 2,
		);

		$refused = $this->validator()->validate( $input, null, $this->admin );
		$this->assertWPError( $refused );
		$this->assertStringContainsString( 'Settings', $refused->get_error_message() );

		Plugin::container()->get( Settings::class )->update( array( 'links_per_target_max' => 2 ) );
		$this->assertInstanceOf( Rule::class, $this->validator()->validate( $input, null, $this->admin ) );
	}

	/**
	 * B: cambiar el destino revalida las frases guardadas con el idioma nuevo.
	 */
	public function test_changing_the_target_revalidates_the_saved_phrases_in_the_new_language(): void {
		$rule  = $this->rule( array( 'phrases' => array( 'of the' ) ) ); // En castellano «of the» tiene palabras con contenido.
		$other = self::factory()->post->create( array( 'post_title' => 'English guide' ) );
		add_filter( 'magiclinking_post_language', static fn( $lang, $id ) => $id === $other ? 'en' : $lang, 5, 2 );

		$this->assertWPError( $this->validator()->validate( array( 'target_id' => $other ), $rule, $this->admin ), 'En inglés «of the» solo tiene palabras vacías.' );
	}

	/**
	 * C: si quien creó la regla ya no puede, queda constancia una sola vez.
	 */
	public function test_a_rule_whose_creator_lost_the_permission_leaves_a_single_notice(): void {
		$rule = $this->rule();
		( new \WP_User( $this->admin ) )->set_role( 'subscriber' );
		$a = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ) ) );
		$this->run_job( $a );

		$rows = RuleLog::all();
		$this->assertSame( array( $rule->id ), array_column( $rows, 'rule_id' ) );
		$first = $rows[0]['time'];

		$b = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Otra bomba de calor ahorra.' ) ) ) );
		$this->run_job( $b );
		$this->assertCount( 1, RuleLog::all(), 'Sin repetirlo en cada guardado.' );
		$this->assertSame( $first, RuleLog::all()[0]['time'] );

		// Cuando el creador vuelve a poder, el aviso desaparece al aplicarse.
		( new \WP_User( $this->admin ) )->set_role( 'administrator' );
		$c = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Y otra bomba de calor.' ) ) ) );
		$this->run_job( $c );
		$this->assertSame( array(), RuleLog::all() );
	}

	/**
	 * D: `rules run` no dice Success si nada se insertó por bloqueo o permisos.
	 */
	public function test_cli_run_warns_instead_of_claiming_success(): void {
		WP_CLI::reset();
		$command = new RulesCommand( $this->repo(), $this->validator(), $this->service() );
		$post    = $this->entry( $this->body( array( 'Una bomba de calor ahorra.' ) ) );
		$this->rule();
		update_post_meta( $post->ID, '_edit_lock', time() . ':' . $this->admin );

		$command->run( array(), array( 'post' => (string) $post->ID ) );

		$output = implode( "\n", WP_CLI::$lines );
		$this->assertStringContainsString( 'Warning', $output );
		$this->assertStringNotContainsString( 'Success', $output );
	}

	/**
	 * E: con muchas reglas, el documento se analiza una sola vez por guardado.
	 */
	public function test_many_rules_analyze_the_document_once(): void {
		Plugin::container()->get( Settings::class )->update( array( 'links_per_target_max' => 1 ) );
		for ( $i = 0; $i < 300; $i++ ) {
			$this->rule( array( 'phrases' => array( 'xilófono cuántico ' . $i ) ) );
		}
		$id = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Un texto sin ninguna de las frases.' ) ) ) );

		$builds = 0;
		add_filter(
			'magiclinking_post_html',
			static function ( $html ) use ( &$builds ) {
				// Solo cuentan las lecturas de documentos para reglas (el reindexado tras escribir también pasa por este filtro).
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
					if ( isset( $frame['class'] ) && \MagicLinking\Index\TableDocuments::class === $frame['class'] ) {
						++$builds;
						break;
					}
				}
				return $html;
			}
		);
		$outcomes = $this->service()->apply_saved( $id );

		$this->assertSame( 1, $builds, 'Un solo documento analizado para 300 reglas.' );
		$this->assertSame( array(), $outcomes, 'Las reglas que no pueden encajar ni se procesan.' );
	}

	public function test_the_document_is_analyzed_again_only_after_a_rule_wrote(): void {
		$this->rule( array( 'phrases' => array( 'bomba de calor' ) ) );
		$this->rule(
			array(
				'phrases'   => array( 'aerotermia' ),
				'target_id' => self::factory()->post->create( array( 'post_title' => 'Aerotermia guía' ) ),
			)
		);
		$id = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.', 'Y la aerotermia también.' ), 300 ) ) );
		Plugin::container()->get( Settings::class )->update( array( 'links_per_target_max' => 1 ) );

		$builds = 0;
		add_filter(
			'magiclinking_post_html',
			static function ( $html ) use ( &$builds ) {
				// Solo cuentan las lecturas de documentos para reglas (el reindexado tras escribir también pasa por este filtro).
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
					if ( isset( $frame['class'] ) && \MagicLinking\Index\TableDocuments::class === $frame['class'] ) {
						++$builds;
						break;
					}
				}
				return $html;
			}
		);
		$outcomes = $this->service()->apply_saved( $id );

		$this->assertSame( 2, array_sum( array_map( static fn( $o ): int => $o->inserted, $outcomes ) ) );
		$this->assertSame( 2, $builds, 'Una vez al empezar y otra tras la escritura de la primera regla.' );
	}

	/**
	 * F: con el índice léxico, la búsqueda solo analiza las entradas candidatas.
	 */
	public function test_scan_with_the_index_only_analyzes_candidates(): void {
		$fit = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$fit[] = $this->entry( $this->body( array( 'La bomba de calor ahorra energía en casa ' . $i . ' y mucho más.' ) ) )->ID;
		}
		for ( $i = 0; $i < 12; $i++ ) {
			$this->entry( $this->body( array( 'Nada que ver con el tema ' . $i . ' ni parecido en absoluto.' ) ) );
		}
		$rule = $this->rule();

		$jobs = Plugin::container()->get( Jobs::class );
		$job  = $jobs->start_index( 0, false, true, true, true );
		$jobs->run_to_completion( $job['id'] );

		$found = $this->service()->scan( $rule, $this->admin, 0, 50, 5.0 );
		$this->assertEqualsCanonicalizing( $fit, array_column( $found['items'], 'post_id' ) );
		$this->assertTrue( $found['narrowed'] );
		$this->assertLessThanOrEqual( count( $fit ) + 1, $found['examined'], 'Solo se analizan las candidatas.' );

		$all = $this->service()->scan( $rule, $this->admin, 0, 50, 5.0, true );
		$this->assertFalse( $all['narrowed'] );
		$this->assertGreaterThan( 10, $all['examined'], 'La búsqueda completa recorre todas.' );
	}
}
