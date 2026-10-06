<?php
/**
 * Stemmer Snowball para castellano.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine\Stemmer;

use MagicLinking\Engine\Stemmer\Spanish;
use MagicLinking\Engine\Stemmer\StemmerInterface;

final class SpanishTest extends SnowballVocabularyTestCase {

	protected function stemmer(): StemmerInterface {
		return new Spanish();
	}

	protected function language(): string {
		return 'spanish';
	}

	/**
	 * @dataProvider examples
	 */
	public function test_examples( string $word, string $stem ): void {
		$this->assertSame( $stem, ( new Spanish() )->stem( $word ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function examples(): array {
		return array(
			'pronombre tras gerundio con tilde' => array( 'haciéndola', 'hac' ),
			'plural y singular se unen'         => array( 'niños', 'niñ' ),
			'la ñ se conserva'                  => array( 'niño', 'niñ' ),
			'-mente'                            => array( 'rápidamente', 'rapid' ),
			'-idad'                             => array( 'nacionalidad', 'nacional' ),
			'-logía'                            => array( 'biología', 'biolog' ),
			'-encia a -ente'                    => array( 'inteligencia', 'inteligent' ),
			'forma en y tras u'                 => array( 'construyendo', 'constru' ),
			'participio'                        => array( 'enlazado', 'enlaz' ),
			'plural con tilde'                  => array( 'artículos', 'articul' ),
			'palabra vacía'                     => array( '', '' ),
			'una letra'                         => array( 'a', 'a' ),
		);
	}
}
