<?php
/**
 * Traducciones incluidas: castellano en PHP y en el script de la pantalla.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\I18n\TextDomain;
use MagicLinking\Graph\BrokenReason;
use MagicLinking\Graph\LinkStatus;
use WP_UnitTestCase;

final class TextDomainTest extends WP_UnitTestCase {

	public function tear_down(): void {
		unload_textdomain( 'magic-linking' );
		parent::tear_down();
	}

	private function in_spanish(): void {
		add_filter( 'locale', static fn(): string => 'es_ES' );
		unload_textdomain( 'magic-linking' );
		( new TextDomain() )->load();
	}

	public function test_php_strings_are_translated_with_the_spanish_vocabulary(): void {
		$this->in_spanish();

		$this->assertSame( 'Huérfana', LinkStatus::label( LinkStatus::ORPHAN ) );
		$this->assertSame( 'Poco enlazada', LinkStatus::label( LinkStatus::LOW ) );
		$this->assertSame( 'Sobrecargada', LinkStatus::label( LinkStatus::OVER ) );
		$this->assertSame( 'La entrada de destino está en la papelera.', BrokenReason::label( BrokenReason::TRASHED ) );
	}

	public function test_plural_strings_are_translated(): void {
		$this->in_spanish();

		/* translators: %d: number of entries. */
		$one = _n( '%d entry analyzed.', '%d entries analyzed.', 1, 'magic-linking' );
		/* translators: %d: number of entries. */
		$five = _n( '%d entry analyzed.', '%d entries analyzed.', 5, 'magic-linking' );
		$this->assertSame( '1 entrada analizada.', sprintf( $one, 1 ) );
		$this->assertSame( '5 entradas analizadas.', sprintf( $five, 5 ) );
	}

	public function test_english_is_the_source_language(): void {
		$this->assertSame( 'Orphan', LinkStatus::label( LinkStatus::ORPHAN ) );
	}

	public function test_the_admin_script_finds_its_spanish_json(): void {
		$this->in_spanish();

		$json = load_script_textdomain( 'magiclinking-admin', 'magic-linking', MAGICLINKING_DIR . 'languages' );
		wp_register_script( 'magiclinking-admin', MAGICLINKING_URL . 'assets/build/index.js', array(), '1', true );
		$json = load_script_textdomain( 'magiclinking-admin', 'magic-linking', MAGICLINKING_DIR . 'languages' );

		$this->assertIsString( $json );
		$this->assertStringContainsString( 'Analizar mi sitio', (string) $json );
	}

	public function test_it_loads_on_init(): void {
		$this->assertNotFalse( has_action( 'init', array( \MagicLinking\Core\Plugin::container()->get( TextDomain::class ), 'load' ) ) );
	}
}
