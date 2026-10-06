<?php
/**
 * Stemmer Snowball para inglés (Porter2).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine\Stemmer;

use MagicLinking\Engine\Stemmer\English;
use MagicLinking\Engine\Stemmer\StemmerInterface;

final class EnglishTest extends SnowballVocabularyTestCase {

	protected function stemmer(): StemmerInterface {
		return new English();
	}

	protected function language(): string {
		return 'english';
	}

	/**
	 * @dataProvider examples
	 */
	public function test_examples( string $word, string $stem ): void {
		$this->assertSame( $stem, ( new English() )->stem( $word ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function examples(): array {
		return array(
			'gerundio'                   => array( 'linking', 'link' ),
			'plural'                     => array( 'links', 'link' ),
			'deshace la doble'           => array( 'hopping', 'hop' ),
			'añade e en palabra corta'   => array( 'hoping', 'hope' ),
			'excepción de R1 (gener)'    => array( 'generously', 'generous' ),
			'-ies tras una letra'        => array( 'ties', 'tie' ),
			'-ies tras varias letras'    => array( 'cries', 'cri' ),
			'-ing excepcional'           => array( 'herrings', 'herring' ),
			'y tras vocal es consonante' => array( 'happiness', 'happi' ),
			'forma invariable'           => array( 'news', 'news' ),
			'posesivo'                   => array( "link's", 'link' ),
			'dos letras'                 => array( 'is', 'is' ),
			'palabra vacía'              => array( '', '' ),
		);
	}
}
