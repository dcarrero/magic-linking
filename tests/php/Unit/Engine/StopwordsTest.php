<?php
/**
 * Palabras vacías iniciales.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\Stopwords;
use PHPUnit\Framework\TestCase;

final class StopwordsTest extends TestCase {

	public function test_languages(): void {
		$this->assertSame( array( 'es', 'en' ), Stopwords::languages() );
	}

	public function test_lists_are_lowercase_and_unique(): void {
		foreach ( Stopwords::languages() as $language ) {
			$list = Stopwords::for( $language );

			$this->assertNotEmpty( $list );
			$this->assertSame( $list, array_values( array_unique( $list ) ), $language );
			foreach ( $list as $word ) {
				$this->assertSame( mb_strtolower( $word ), $word );
			}
		}
	}

	public function test_snowball_lists_are_complete(): void {
		$this->assertCount( 308 + 18, Stopwords::for( 'es' ) );
		$this->assertCount( 174, Stopwords::for( 'en' ) );
	}

	/**
	 * @dataProvider stopwords
	 */
	public function test_is_stopword( string $word, string $language, bool $expected ): void {
		$this->assertSame( $expected, Stopwords::is( $word, $language ) );
	}

	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function stopwords(): array {
		return array(
			'artículo'                => array( 'el', 'es', true ),
			'preposición'             => array( 'de', 'es', true ),
			'forma verbal auxiliar'   => array( 'habían', 'es', true ),
			'con tilde'               => array( 'más', 'es', true ),
			'la misma sin tilde'      => array( 'mas', 'es', true ),
			'interrogativo sin tilde' => array( 'como', 'es', true ),
			'propia: hacia'           => array( 'hacia', 'es', true ),
			'propia sin tilde: segun' => array( 'segun', 'es', true ),
			'palabra con contenido'   => array( 'enlace', 'es', false ),
			'la ñ no se quita'        => array( 'año', 'es', false ),
			'locale completo'         => array( 'para', 'es_ES', true ),
			'inglés'                  => array( 'the', 'en', true ),
			'contracción inglesa'     => array( "don't", 'en-US', true ),
			'contenido en inglés'     => array( 'link', 'en', false ),
			'no cruza idiomas'        => array( 'the', 'es', false ),
			'idioma sin lista'        => array( 'der', 'de', false ),
		);
	}

	public function test_unknown_language_has_empty_list(): void {
		$this->assertSame( array(), Stopwords::for( 'fr' ) );
	}
}
