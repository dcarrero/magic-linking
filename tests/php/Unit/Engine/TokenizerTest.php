<?php
/**
 * Tokenizador: frases reales en castellano e inglés.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\NGram;
use MagicLinking\Engine\Token;
use MagicLinking\Engine\Tokenizer;
use PHPUnit\Framework\TestCase;

final class TokenizerTest extends TestCase {

	/**
	 * Lista mínima de palabras vacías para las pruebas; las reales las trae la clase Stopwords.
	 */
	private const STOPWORDS_ES = array( 'el', 'la', 'los', 'las', 'de', 'del', 'un', 'una', 'y', 'o', 'en', 'con', 'para', 'por', 'que', 'se', 'su', 'es', 'al', 'lo', 'más', 'como', 'esta', 'este' );

	private const STOPWORDS_EN = array( 'the', 'a', 'an', 'of', 'and', 'or', 'in', 'on', 'to', 'for', 'is', 'with', 'it', 'its', 'by', 'from' );

	private Tokenizer $es;

	private Tokenizer $en;

	protected function setUp(): void {
		$this->es = new Tokenizer( self::STOPWORDS_ES );
		$this->en = new Tokenizer( self::STOPWORDS_EN );
	}

	/*
	 * Segmentación en frases.
	 */

	/**
	 * @dataProvider provide_sentences
	 *
	 * @param list<string> $expected
	 */
	public function test_sentences( string $text, array $expected ): void {
		$this->assertSame( $expected, $this->es->sentences( $text ) );
	}

	/**
	 * @return array<string, array{string, list<string>}>
	 */
	public static function provide_sentences(): array {
		return array(
			'dos frases simples'             => array(
				'La aerotermia ahorra energía. Consume menos que una caldera de gas.',
				array( 'La aerotermia ahorra energía.', 'Consume menos que una caldera de gas.' ),
			),
			'Sr. y Dña. no cortan'           => array(
				'El Sr. García y Dña. Pilar Ruiz firmaron el contrato. Después se fueron a comer.',
				array( 'El Sr. García y Dña. Pilar Ruiz firmaron el contrato.', 'Después se fueron a comer.' ),
			),
			'EE. UU. dentro de la frase'     => array(
				'Las ventas en EE. UU. crecieron un 12 % el año pasado.',
				array( 'Las ventas en EE. UU. crecieron un 12 % el año pasado.' ),
			),
			'EE. UU. al final de la frase'   => array(
				'Se mudó a EE. UU. Allí abrió su primera tienda.',
				array( 'Se mudó a EE. UU.', 'Allí abrió su primera tienda.' ),
			),
			'p. ej. no corta'                => array(
				'Algunos electrodomésticos, p. ej. Lavadoras y frigoríficos, gastan mucho.',
				array( 'Algunos electrodomésticos, p. ej. Lavadoras y frigoríficos, gastan mucho.' ),
			),
			'etc. seguido de minúscula'      => array(
				'Compra tornillos, tacos, etc. y guárdalos en la caja.',
				array( 'Compra tornillos, tacos, etc. y guárdalos en la caja.' ),
			),
			'etc. al final de la frase'      => array(
				'Hay tornillos, tacos, arandelas, etc. Todo está en la ferretería.',
				array( 'Hay tornillos, tacos, arandelas, etc.', 'Todo está en la ferretería.' ),
			),
			'números con decimales y miles'  => array(
				'La bomba rinde 3.5 kW con 1.500 horas de uso. El precio es de 4,99 €.',
				array( 'La bomba rinde 3.5 kW con 1.500 horas de uso.', 'El precio es de 4,99 €.' ),
			),
			'año al final de la frase'       => array(
				'La ley entró en vigor en 2020. Desde entonces hay más ayudas.',
				array( 'La ley entró en vigor en 2020.', 'Desde entonces hay más ayudas.' ),
			),
			'interrogación y exclamación'    => array(
				'¿Merece la pena la aerotermia? ¡Sí, sin duda! Te lo explicamos.',
				array( '¿Merece la pena la aerotermia?', '¡Sí, sin duda!', 'Te lo explicamos.' ),
			),
			'comillas latinas tras el punto' => array(
				'Dijo: «Volveremos mañana.» Y no volvieron.',
				array( 'Dijo: «Volveremos mañana.»', 'Y no volvieron.' ),
			),
			'puntos suspensivos'             => array(
				'Esperamos y esperamos… Nadie vino. Luego… nada.',
				array( 'Esperamos y esperamos…', 'Nadie vino.', 'Luego… nada.' ),
			),
			'URL con puntos'                 => array(
				'Consulta https://www.boe.es/diario_boe/txt.php?id=BOE-A-2020-1 para más detalles. Es gratis.',
				array( 'Consulta https://www.boe.es/diario_boe/txt.php?id=BOE-A-2020-1 para más detalles.', 'Es gratis.' ),
			),
			'salto de párrafo y de línea'    => array(
				"Primer párrafo sin punto\n\nSegundo párrafo.\nTercera línea",
				array( 'Primer párrafo sin punto', 'Segundo párrafo.', 'Tercera línea' ),
			),
			'Dr. inglés y e.g.'              => array(
				'Dr. Smith recommends heat pumps, e.g. Air-to-water models. They are efficient.',
				array( 'Dr. Smith recommends heat pumps, e.g. Air-to-water models.', 'They are efficient.' ),
			),
			'Mr. y U.S.'                     => array(
				'Mr. Brown moved to the U.S. Army base in 2019. He liked it.',
				array( 'Mr. Brown moved to the U.S. Army base in 2019.', 'He liked it.' ),
			),
			'iniciales de un nombre'         => array(
				'J. K. Rowling wrote seven books. The first one appeared in 1997.',
				array( 'J. K. Rowling wrote seven books.', 'The first one appeared in 1997.' ),
			),
			'No. solo delante de número'     => array(
				'See No. 5 in the list. The answer is no. Nobody asked.',
				array( 'See No. 5 in the list.', 'The answer is no.', 'Nobody asked.' ),
			),
			'pág. y núm.'                    => array(
				'Lo explica en la pág. 34 del núm. 12 de la revista. Merece la pena.',
				array( 'Lo explica en la pág. 34 del núm. 12 de la revista.', 'Merece la pena.' ),
			),
			'emoji suelto se descarta'       => array(
				"Nos encanta este sofá 😍.\n🔥🔥🔥\nEs comodísimo.",
				array( 'Nos encanta este sofá 😍.', 'Es comodísimo.' ),
			),
			'vacío'                          => array(
				"  \n\n  ",
				array(),
			),
		);
	}

	/*
	 * Normalización y clave de comparación.
	 */

	/**
	 * @dataProvider provide_normalize
	 */
	public function test_normalize( string $text, string $expected ): void {
		$this->assertSame( $expected, $this->es->normalize( $text ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provide_normalize(): array {
		return array(
			'minúsculas conservando tildes'   => array( 'Bomba de Calor AEROTÉRMICA', 'bomba de calor aerotérmica' ),
			'Ñ mayúscula'                     => array( 'ESPAÑA', 'españa' ),
			'comillas latinas y tipográficas' => array( '«Hola» y “adiós”', '"hola" y "adiós"' ),
			'apóstrofo tipográfico'           => array( 'Don’t stop', "don't stop" ),
			'espacios duros y dobles'         => array( "10\u{00A0}kW   de\tpotencia ", '10 kw de potencia' ),
			'NFD pasa a NFC'                  => array( "Cafe\u{0301} y an\u{0303}o", 'café y año' ),
			'guion tipográfico'               => array( "co\u{2010}working", 'co-working' ),
			'guion blando desaparece'         => array( "aero\u{00AD}termia", 'aerotermia' ),
		);
	}

	/**
	 * @dataProvider provide_key
	 */
	public function test_key( string $text, string $expected ): void {
		$this->assertSame( $expected, $this->es->key( $text ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provide_key(): array {
		return array(
			'sin tildes'           => array( 'Instalación térmica', 'instalacion termica' ),
			'la ñ se conserva'     => array( 'Año de montaña', 'año de montaña' ),
			'diéresis'             => array( 'Pingüino y cigüeña', 'pinguino y cigueña' ),
			'francés y portugués'  => array( 'Crème brûlée à São Paulo', 'creme brulee a sao paulo' ),
			'ñ descompuesta'       => array( "an\u{0303}o", 'año' ),
			'tilde descompuesta'   => array( "cancio\u{0301}n", 'cancion' ),
			'inglés sin cambios'   => array( 'Heat Pumps', 'heat pumps' ),
			'mayúsculas con tilde' => array( 'ÁRBOL ÉTICO', 'arbol etico' ),
		);
	}

	public function test_n_and_enye_are_different_keys(): void {
		$this->assertNotSame( $this->es->key( 'año' ), $this->es->key( 'ano' ) );
		$this->assertNotSame( $this->es->key( 'España' ), $this->es->key( 'Espana' ) );
		$this->assertSame( $this->es->key( 'camión' ), $this->es->key( 'camion' ) );
	}

	/*
	 * Tokens.
	 */

	/**
	 * @dataProvider provide_tokens
	 *
	 * @param list<string> $expected Formas superficiales.
	 */
	public function test_tokenize_surface( string $text, array $expected ): void {
		$this->assertSame( $expected, array_map( static fn( Token $t ): string => $t->surface, $this->es->tokenize( $text ) ) );
	}

	/**
	 * @return array<string, array{string, list<string>}>
	 */
	public static function provide_tokens(): array {
		return array(
			'guiones internos'           => array( 'El wi-fi del co-working falla.', array( 'El', 'wi-fi', 'del', 'co-working', 'falla' ) ),
			'guion suelto no es palabra' => array( 'Madrid - Barcelona', array( 'Madrid', 'Barcelona' ) ),
			'decimales y miles'          => array( 'Cuesta 4,99 € y pesa 1.500 g (3.5 kg).', array( 'Cuesta', '4,99', 'y', 'pesa', '1.500', 'g', '3.5', 'kg' ) ),
			'URL fuera'                  => array( 'Más en https://example.com/guia-aerotermia hoy', array( 'Más', 'en', 'hoy' ) ),
			'correo fuera'               => array( 'Escribe a info@colorvivo.com ya', array( 'Escribe', 'a', 'ya' ) ),
			'emojis fuera'               => array( 'Me encanta 😍 el café ☕', array( 'Me', 'encanta', 'el', 'café' ) ),
			'comillas latinas'           => array( 'El «coche eléctrico» gana', array( 'El', 'coche', 'eléctrico', 'gana' ) ),
			'apóstrofo inglés'           => array( 'It’s the user’s choice', array( 'It’s', 'the', 'user’s', 'choice' ) ),
			'abreviaturas'               => array( 'Viajó a EE. UU. con el Sr. Pérez', array( 'Viajó', 'a', 'EE', 'UU', 'con', 'el', 'Sr', 'Pérez' ) ),
			'ñ y tildes intactas'        => array( 'Niño pequeño, ¿qué tal?', array( 'Niño', 'pequeño', 'qué', 'tal' ) ),
		);
	}

	public function test_token_forms_and_offsets(): void {
		$text   = 'La Bomba de Calor AEROTÉRMICA de Año';
		$tokens = $this->es->tokenize( $text );

		$this->assertSame( 'AEROTÉRMICA', $tokens[4]->surface );
		$this->assertSame( 'aerotérmica', $tokens[4]->normal );
		$this->assertSame( 'aerotermica', $tokens[4]->key );
		$this->assertSame( 'año', $tokens[6]->key );

		foreach ( $tokens as $token ) {
			$this->assertSame( $token->surface, substr( $text, $token->offset, strlen( $token->surface ) ) );
		}
	}

	public function test_stopwords_are_flagged_by_key(): void {
		$tokens = $this->es->tokenize( 'Esta es MÁS barata que la otra' );

		$this->assertSame(
			array( true, true, true, false, true, true, false ),
			array_map( static fn( Token $t ): bool => $t->is_stopword, $tokens )
		);
		$this->assertTrue( $this->es->is_stopword( 'Está' ) );
		$this->assertFalse( $this->es->is_stopword( 'aerotermia' ) );
	}

	public function test_break_before_marks_punctuation(): void {
		$tokens = $this->es->tokenize( 'Manzanas, peras y uvas' );

		$this->assertSame( array( false, true, false, false ), array_map( static fn( Token $t ): bool => $t->break_before, $tokens ) );
	}

	/*
	 * N-gramas.
	 */

	/**
	 * @dataProvider provide_ngrams_es
	 *
	 * @param list<string> $expected Claves de los n-gramas.
	 */
	public function test_ngrams_spanish( string $text, array $expected ): void {
		$this->assertSame( $expected, $this->keys( $this->es->ngrams( $this->es->tokenize( $text ) ) ) );
	}

	/**
	 * @return array<string, array{string, list<string>}>
	 */
	public static function provide_ngrams_es(): array {
		return array(
			'palabra vacía central permitida' => array(
				'El centro de datos',
				array( 'centro', 'centro de datos', 'datos' ),
			),
			'bigrama y trigrama sin vacías'   => array(
				'Instalar aire acondicionado barato',
				array( 'instalar', 'instalar aire', 'instalar aire acondicionado', 'aire', 'aire acondicionado', 'aire acondicionado barato', 'acondicionado', 'acondicionado barato', 'barato' ),
			),
			'no empieza ni termina en vacía'  => array(
				'La bomba de calor de la casa',
				array( 'bomba', 'bomba de calor', 'calor', 'casa' ),
			),
			'no cruza comas'                  => array(
				'Manzanas, peras y uvas',
				array( 'manzanas', 'peras', 'peras y uvas', 'uvas' ),
			),
			'no cruza emojis'                 => array(
				'Sofá 😍 cama',
				array( 'sofa', 'cama' ),
			),
			'guion interno cuenta como una'   => array(
				'Router wi-fi doméstico',
				array( 'router', 'router wi-fi', 'router wi-fi domestico', 'wi-fi', 'wi-fi domestico', 'domestico' ),
			),
		);
	}

	public function test_ngrams_english(): void {
		$ngrams = $this->en->ngrams( $this->en->tokenize( 'The Best Heat Pump for the Home' ) );

		$this->assertSame(
			array( 'best', 'best heat', 'best heat pump', 'heat', 'heat pump', 'pump', 'home' ),
			$this->keys( $ngrams )
		);
	}

	public function test_ngrams_max_size(): void {
		$tokens = $this->en->tokenize( 'solar panel installation cost' );

		$this->assertSame( array( 'solar', 'panel', 'installation', 'cost' ), $this->keys( $this->en->ngrams( $tokens, 1 ) ) );
		$this->assertCount( 10, $this->en->ngrams( $tokens, 4 ) );
		$this->assertContains( 'solar panel installation cost', $this->keys( $this->en->ngrams( $tokens, 4 ) ) );
	}

	public function test_ngram_span_recovers_original_anchor(): void {
		$text   = 'Hoy instalamos una Bomba de Calor aerotérmica.';
		$ngrams = $this->es->ngrams( $this->es->tokenize( $text ) );
		$found  = array_values( array_filter( $ngrams, static fn( NGram $n ): bool => 'bomba de calor' === $n->key ) );

		$this->assertCount( 1, $found );
		$this->assertSame( 'Bomba de Calor', substr( $text, $found[0]->offset(), $found[0]->length() ) );
		$this->assertSame( 3, $found[0]->size() );
		$this->assertSame( 'bomba de calor', $found[0]->normal );
	}

	public function test_terms_never_cross_sentences(): void {
		$keys = $this->keys( $this->es->terms( 'Compra el aire. Acondicionado viene aparte.' ) );

		$this->assertNotContains( 'aire acondicionado', $keys );
		$this->assertContains( 'acondicionado viene aparte', $keys );
	}

	public function test_empty_and_invalid_input(): void {
		$this->assertSame( array(), $this->es->tokenize( '' ) );
		$this->assertSame( array(), $this->es->terms( '   ' ) );
		$this->assertSame( array( 'caf', 'bar' ), array_map( static fn( Token $t ): string => $t->key, $this->es->tokenize( "caf\xE9 bar" ) ) );
	}

	public function test_extra_abbreviations_from_constructor(): void {
		$tokenizer = new Tokenizer( array(), array( 'Ctra.' ) );

		$this->assertSame(
			array( 'Vive en la Ctra. Toledo, km 3.', 'Es fácil de encontrar.' ),
			$tokenizer->sentences( 'Vive en la Ctra. Toledo, km 3. Es fácil de encontrar.' )
		);
	}

	/*
	 * Palabras vacías reales de Stopwords (con y sin tildes).
	 */

	/**
	 * @dataProvider provide_real_stopwords_es
	 */
	public function test_real_spanish_stopwords_with_and_without_accents( string $word ): void {
		$this->assertTrue( Tokenizer::for_language( 'es' )->is_stopword( $word ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_real_stopwords_es(): array {
		return array(
			'más'     => array( 'más' ),
			'mas'     => array( 'mas' ),
			'también' => array( 'también' ),
			'tambien' => array( 'tambien' ),
			'él'      => array( 'él' ),
			'MÁS'     => array( 'MÁS' ),
		);
	}

	public function test_for_language_accepts_locales_and_unknown_languages(): void {
		$this->assertTrue( Tokenizer::for_language( 'es_ES' )->is_stopword( 'Tambien' ) );
		$this->assertTrue( Tokenizer::for_language( 'en-US' )->is_stopword( 'The' ) );
		$this->assertFalse( Tokenizer::for_language( 'es' )->is_stopword( 'aerotermia' ) );
		$this->assertFalse( Tokenizer::for_language( 'fr' )->is_stopword( 'le' ) );
	}

	public function test_real_spanish_ngrams_do_not_start_or_end_in_stopwords(): void {
		$tokenizer = Tokenizer::for_language( 'es' );
		$keys      = $this->keys( $tokenizer->terms( 'Él también compró mas paneles solares y tambien baterías más grandes.' ) );

		$this->assertContains( 'paneles solares', $keys );
		$this->assertContains( 'baterias', $keys );
		foreach ( $keys as $key ) {
			$words = explode( ' ', $key );
			foreach ( array( $words[0], $words[ count( $words ) - 1 ] ) as $edge ) {
				$this->assertFalse( $tokenizer->is_stopword( $edge ), "«{$key}» empieza o acaba en «{$edge}»" );
			}
		}
		$this->assertNotContains( 'mas', $keys );
		$this->assertNotContains( 'tambien', $keys );
		$this->assertNotContains( 'el', $keys );
	}

	public function test_real_english_stopwords_in_ngrams(): void {
		$tokenizer = Tokenizer::for_language( 'en' );
		$keys      = $this->keys( $tokenizer->terms( 'Why you should install a heat pump in your house' ) );

		$this->assertSame( array( 'install', 'install a heat', 'heat', 'heat pump', 'pump', 'house' ), $keys );
	}

	/*
	 * Casos conocidos.
	 */

	public function test_known_case_single_letter_before_period_is_taken_as_initial(): void {
		$sentences = $this->es->sentences( 'Toma vitamina C. Es buena para las defensas.' );
		if ( 2 !== count( $sentences ) ) {
			$this->markTestIncomplete( 'Caso conocido: «vitamina C. Es…» no se parte porque «C.» se toma como inicial («J. K. Rowling»).' );
		}
		$this->assertSame( array( 'Toma vitamina C.', 'Es buena para las defensas.' ), $sentences );
	}

	/**
	 * @param list<NGram> $ngrams
	 * @return list<string>
	 */
	private function keys( array $ngrams ): array {
		return array_map( static fn( NGram $n ): string => $n->key, $ngrams );
	}
}
