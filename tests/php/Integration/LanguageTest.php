<?php
/**
 * Idioma por entrada: WPML, Polylang y el idioma del sitio.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\I18n\Language;
use MagicLinking\Core\Plugin;
use WP_UnitTestCase;

final class LanguageTest extends WP_UnitTestCase {

	public function test_falls_back_to_the_site_language(): void {
		update_option( 'WPLANG', 'es_ES' );
		add_filter( 'locale', static fn(): string => 'es_ES' );

		$language = new Language();
		$this->assertSame( 'es', $language->for_post( 1 ) );
		$this->assertSame( 'es', $language->site() );
	}

	public function test_reads_wpml_details(): void {
		add_filter(
			'wpml_post_language_details',
			static fn( $value, $post_id ) => 7 === $post_id ? array( 'language_code' => 'FR' ) : $value,
			10,
			2
		);

		$language = new Language();
		$this->assertSame( 'fr', $language->for_post( 7 ) );
		$this->assertSame( 'en', $language->for_post( 8 ), 'Sin dato de WPML se usa el sitio (en_US).' );
	}

	public function test_detects_a_multilingual_plugin(): void {
		$this->assertFalse( ( new Language() )->plugin_active() );
		$this->assertTrue( ( new Language( static fn() => 'es' ) )->plugin_active(), 'Polylang.' );

		add_filter( 'magiclinking_is_multilingual', '__return_true' );
		$this->assertTrue( ( new Language() )->plugin_active() );
	}

	public function test_reads_polylang(): void {
		$seen     = array();
		$language = new Language(
			static function ( int $post_id, string $field ) use ( &$seen ) {
				$seen[] = array( $post_id, $field );
				return 9 === $post_id ? 'pt' : false;
			}
		);

		$this->assertSame( 'pt', $language->for_post( 9 ) );
		$this->assertSame( 'en', $language->for_post( 10 ) );
		$this->assertSame( array( 9, 'slug' ), $seen[0] );
	}

	public function test_wpml_wins_over_polylang_and_the_filter_wins_over_both(): void {
		add_filter( 'wpml_post_language_details', static fn() => array( 'language_code' => 'de' ) );
		$language = new Language( static fn() => 'it' );

		$this->assertSame( 'de', $language->for_post( 1 ) );

		add_filter( 'magiclinking_post_language', static fn() => 'ca' );
		$this->assertSame( 'ca', $language->for_post( 1 ) );
	}

	public function test_normalize_gives_short_lowercase_codes(): void {
		$this->assertSame( 'es', Language::normalize( 'es_ES' ) );
		$this->assertSame( 'pt', Language::normalize( ' pt-BR ' ) );
		$this->assertSame( 'zh', Language::normalize( 'ZH-hans' ) );
		$this->assertSame( '', Language::normalize( '' ) );
		$this->assertSame( '', Language::normalize( '123' ) );
	}

	public function test_is_available_as_a_service(): void {
		$this->assertInstanceOf( Language::class, Plugin::container()->get( Language::class ) );
	}
}
