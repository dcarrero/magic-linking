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


	/**
	 * @return array<string, array{string, string}>
	 */
	public static function natural_texts(): array {
		return array(
			'pt: Lisboa e Porto' => array(
				'Lisboa e Porto são cidades bonitas e cheias de história, e quem as visita fica com vontade de voltar. Em Lisboa há os elétricos e os miradouros, e no Porto as caves de vinho e as pontes sobre o rio. Nos dois casos, a comida é boa e os preços são justos para quem vem de fora.',
				'pt',
			),
			'pt: longo'          => array(
				'Esta semana fomos ao mercado da aldeia para comprar legumes frescos e fruta da época. Os vendedores já nos conhecem e perguntam sempre como está a família, o que torna a visita ainda mais agradável. Depois das compras, sentámo-nos numa esplanada para beber um café e ler o jornal, enquanto as crianças brincavam na praça. Foi uma manhã tranquila, daquelas que dão vontade de repetir todos os fins de semana, sem pressa nenhuma e sem olhar para o relógio.',
				'pt',
			),
			'it: longo'          => array(
				'Questa settimana siamo andati al mercato del paese per comprare verdura fresca e frutta di stagione. I venditori ormai ci conoscono e chiedono sempre come sta la famiglia, e questo rende la visita ancora più piacevole. Dopo la spesa ci siamo seduti a un tavolino per bere un caffè e leggere il giornale, mentre i bambini giocavano in piazza. È stata una mattina tranquilla, di quelle che fanno venire voglia di ripeterle ogni fine settimana, senza fretta e senza guardare l\'orologio.',
				'it',
			),
			'it: elisioni'       => array(
				'L\'estate è la stagione migliore per viaggiare, e all\'inizio di giugno l\'aria è ancora fresca. Dell\'innovazione nel turismo si parla molto, ma all\'atto pratico quello che conta è l\'accoglienza, la qualità del cibo e l\'attenzione per l\'ambiente. Un\'idea semplice è partire con calma e scegliere un\'esperienza alla volta.',
				'it',
			),
		);
	}

	/**
	 * @dataProvider natural_texts
	 */
	public function test_detects_natural_long_texts( string $text, string $expected ): void {
		$this->assertSame( $expected, LanguageDetector::detect( $text, 'xx' ) );
	}

	public function test_elided_prefixes_do_not_count_as_words_of_other_languages(): void {
		$this->assertSame( 'xx', LanguageDetector::detect( str_repeat( 'all\'innovazione dell\'innovazione ', 20 ), 'xx' ) );
	}

	public function test_english_contractions_and_closing_quotes_keep_their_words(): void {
		$text = str_repeat( "It's what it's. That's why they're here, we're sure you're right and he's not. Don't say 'it' or 'the end' because she's gone, isn't it? ", 4 );

		$this->assertSame( 'en', LanguageDetector::detect( $text, 'xx' ) );
	}

	public function test_decomposed_accents_are_normalized(): void {
		$text = \Normalizer::normalize( self::natural_texts()['pt: Lisboa e Porto'][0], \Normalizer::FORM_D );

		$this->assertNotSame( self::natural_texts()['pt: Lisboa e Porto'][0], $text );
		$this->assertSame( 'pt', LanguageDetector::detect( $text, 'xx' ) );
	}

	public function test_accents_may_be_missing(): void {
		$text = 'Esta guia explica como se instala uma bomba de calor numa casa que ja tem piso radiante, e o que e preciso ter em conta para que o consumo seja o que se espera. Depois da instalacao convem rever os parametros com o instalador e comprovar que a temperatura esta correta para o edificio, ate porque nao ha duvida de que isso poupa dinheiro.';

		$this->assertSame( 'pt', LanguageDetector::detect( $text, 'xx' ) );
	}

	public function test_a_word_shared_by_several_languages_never_counts_for_only_one(): void {
		$this->assertSame( 'xx', LanguageDetector::detect( str_repeat( 'casa e jardim e piscina e quintal ', 6 ), 'xx' ) );
	}

	public function test_languages_come_from_the_lists(): void {
		$this->assertSame( array( 'es', 'en', 'fr', 'de', 'it', 'pt' ), LanguageDetector::languages() );
		foreach ( LanguageDetector::languages() as $language ) {
			$list = \MagicLinking\Engine\Stopwords::detection( $language );
			$this->assertNotEmpty( $list );
			$this->assertSame( $list, array_values( array_unique( $list ) ), $language . ' repite palabras.' );
		}
	}

	public function test_languages(): void {
		$this->assertSame( array( 'es', 'en', 'fr', 'de', 'it', 'pt' ), LanguageDetector::languages() );
	}
}
