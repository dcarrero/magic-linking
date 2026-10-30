<?php
/**
 * Los topes de calidad de las reglas tienen un único valor por defecto (docs/04 §6.1, D-30).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Core;

use MagicLinking\Core\Settings;
use MagicLinking\Engine\RuleLimits;
use MagicLinking\Engine\Scorer;
use PHPUnit\Framework\TestCase;

final class RuleLimitsDefaultsTest extends TestCase {

	public function test_the_defaults_are_the_documented_ones_and_come_from_one_place(): void {
		$this->assertSame( 100, RuleLimits::WORDS_PER_LINK );
		$this->assertSame( 1, RuleLimits::LINKS_PER_TARGET );
		$this->assertSame( 5, RuleLimits::SAME_ANCHOR );

		$this->assertSame( RuleLimits::WORDS_PER_LINK, Settings::DEFAULTS['words_per_link'] );
		$this->assertSame( RuleLimits::LINKS_PER_TARGET, Settings::DEFAULTS['links_per_target_max'] );
		$this->assertSame( RuleLimits::SAME_ANCHOR, Settings::DEFAULTS['same_anchor_max'] );
		$this->assertSame( Scorer::OVERUSED, RuleLimits::SAME_ANCHOR, 'Las sugerencias y las reglas usan el mismo tope de ancla repetida.' );
	}

	public function test_the_defaults_are_inside_the_allowed_ranges(): void {
		foreach ( array( 'words_per_link', 'links_per_target_max', 'same_anchor_max' ) as $key ) {
			[ $min, $max ] = Settings::INT_RANGES[ $key ];
			$this->assertGreaterThanOrEqual( $min, Settings::DEFAULTS[ $key ], $key );
			$this->assertLessThanOrEqual( $max, Settings::DEFAULTS[ $key ], $key );
		}
	}

	public function test_no_rule_code_knows_about_pro(): void {
		$files = array_merge( (array) glob( dirname( __DIR__, 4 ) . '/src/Rules/*.php' ), array( dirname( __DIR__, 4 ) . '/src/Engine/RuleLimits.php', dirname( __DIR__, 4 ) . '/src/Engine/RuleMatcher.php' ) );
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$this->assertDoesNotMatchRegularExpression( '/magiclinking_pro|MagicLinking\\\\Pro|is_pro|license/i', (string) file_get_contents( $file ), basename( $file ) . ': los topes no dependen de ninguna licencia.' );
		}
	}
}
