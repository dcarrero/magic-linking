<?php
/**
 * Los valores por defecto de los topes son los mismos con y sin Pro (F1-13, D-30).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Rules;

use MagicLinking\Core\Plugin;
use MagicLinking\Core\Settings;
use MagicLinking\Engine\RuleLimits;

final class RuleLimitsProTest extends RulesTestCase {

	/**
	 * @return array{0: int, 1: int, 2: int}
	 */
	private function defaults(): array {
		delete_option( 'magiclinking_settings' );
		$limits = Plugin::container()->get( Settings::class )->rule_limits();

		return array( $limits->words_per_link, $limits->links_per_target, $limits->same_anchor_max );
	}

	public function test_defaults_are_equal_with_and_without_pro(): void {
		$free = $this->defaults();
		$this->assertSame( array( 100, 1, 5 ), $free );

		// Pro es otro plugin que cuelga de los puntos de extensión de la gratuita: con todos esos ganchos puestos, los topes no cambian.
		foreach ( array( 'magiclinking_rule_actions', 'magiclinking_report_actions', 'magiclinking_score_signals', 'magiclinking_history_filters', 'magiclinking_content_adapters', 'magiclinking_candidate_sources', 'magiclinking_admin_screens' ) as $hook ) {
			add_filter( $hook, static fn( $value ) => $value );
		}
		add_filter( 'pre_option_magiclinking_license', static fn() => 'valid' );

		$this->assertSame( $free, $this->defaults() );
		$this->assertEquals( new RuleLimits(), Plugin::container()->get( Settings::class )->rule_limits() );
	}

	public function test_the_ranges_are_the_same_through_the_api_with_a_pro_user(): void {
		$settings = Plugin::container()->get( Settings::class );
		$saved    = $settings->update( array( 'words_per_link' => 5, 'links_per_target_max' => 99, 'same_anchor_max' => 0 ) );

		$this->assertSame( 20, $saved['words_per_link'] );
		$this->assertSame( 10, $saved['links_per_target_max'] );
		$this->assertSame( 1, $saved['same_anchor_max'] );
	}
}
