<?php
/**
 * Detección del idioma por palabras vacías.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\LanguageDetector;
use PHPUnit\Framework\TestCase;

final class LanguageDetectorTest extends TestCase {

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function texts(): array {
		return array(
			'es' => array(
				'La bomba de calor aerotérmica extrae la energía del aire exterior y la transforma en calefacción para toda la casa. Con un buen aislamiento, el consumo baja mucho y las facturas de la luz son más pequeñas durante el invierno, que es cuando más se nota.',
				'es',
			),
			'en' => array(
				'A heat pump extracts energy from the outside air and turns it into heating for the whole house. With good insulation, the consumption drops a lot and the electricity bills are smaller during the winter, which is when you notice it the most.',
				'en',
			),
			'fr' => array(
				'La pompe à chaleur extrait l\'énergie de l\'air extérieur et la transforme en chauffage pour toute la maison. Avec une bonne isolation, la consommation baisse beaucoup et les factures d\'électricité sont plus petites pendant l\'hiver, quand on le remarque le plus.',
				'fr',
			),
			'de' => array(
				'Die Wärmepumpe entzieht der Außenluft Energie und wandelt sie in Heizwärme für das ganze Haus um. Mit einer guten Dämmung sinkt der Verbrauch deutlich und die Stromrechnung ist im Winter, wenn man es am meisten merkt, auch viel kleiner als bei einer alten Heizung.',
				'de',
			),
			'it' => array(
				'La pompa di calore estrae l\'energia dall\'aria esterna e la trasforma in riscaldamento per tutta la casa. Con un buon isolamento, il consumo scende molto e le bollette della luce sono più piccole durante l\'inverno, quando non si nota che è così.',
				'it',
			),
			'pt' => array(
				'A bomba de calor extrai a energia do ar exterior e transforma-a em aquecimento para toda a casa. Com um bom isolamento, o consumo desce muito e as faturas da luz são mais pequenas durante o inverno, que é quando se nota mais e não há dúvida.',
				'pt',
			),
		);
	}

	/**
	 * @dataProvider texts
	 */
	public function test_detects_each_language( string $text, string $expected ): void {
		// Con el idioma de reserva distinto del esperado, así no puede acertar por casualidad.
		$fallback = 'en' === $expected ? 'es' : 'en';

		$this->assertSame( $expected, LanguageDetector::detect( $text, $fallback ) );
	}

	public function test_uses_the_fallback_with_empty_or_short_text(): void {
		$this->assertSame( 'xx', LanguageDetector::detect( '', 'xx' ) );
		$this->assertSame( 'xx', LanguageDetector::detect( '   ... 123 ...', 'xx' ) );
		$this->assertSame( 'xx', LanguageDetector::detect( 'La bomba de calor ahorra mucho en calefacción.', 'xx' ), 'Menos de ' . LanguageDetector::MIN_WORDS . ' palabras.' );
	}

	public function test_uses_the_fallback_without_a_clear_winner(): void {
		$mixed = str_repeat( 'Pan el the pan la of casa y and casa los with jardín con the el of y and ', 3 );

		$this->assertSame( 'xx', LanguageDetector::detect( $mixed, 'xx' ) );
	}

	public function test_uses_the_fallback_for_text_without_function_words(): void {
		$list = str_repeat( 'Zanahoria Berenjena Calabacín Pimiento Tomate Cebolla Lechuga Espinaca Pepino Rábano ', 5 );

		$this->assertSame( 'xx', LanguageDetector::detect( $list, 'xx' ) );
	}

	public function test_a_long_text_in_one_language_with_a_quote_in_another_keeps_its_language(): void {
		$text = 'Esta guía explica cómo se instala una bomba de calor en una casa que ya tiene suelo radiante, y qué hay que tener en cuenta para que el consumo sea el que se espera. '
			. 'Como dice el fabricante: "The heat pump must be installed by a qualified technician". '
			. 'Después de la instalación conviene revisar los parámetros con el instalador y comprobar que la temperatura de impulsión es la correcta para el edificio.';

		$this->assertSame( 'es', LanguageDetector::detect( $text, 'en' ) );
	}

	public function test_only_reads_the_beginning_of_a_long_text(): void {
		$text = str_repeat( 'The heat pump takes the energy from the outside air and turns it into heating for the house. ', 400 ) . str_repeat( 'La bomba de calor toma la energía del aire. ', 2000 );

		$this->assertSame( 'en', LanguageDetector::detect( $text, 'es' ) );
	}

	public function test_languages(): void {
		$this->assertSame( array( 'es', 'en', 'fr', 'de', 'it', 'pt' ), LanguageDetector::languages() );
	}
}
