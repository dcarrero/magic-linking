<?php
/**
 * Los casos de docs/06 §8 con una frase alternativa o un ancla ajustada por el usuario (F1-22, D-30).
 *
 * La petición de insertar lleva la frase entera, el desplazamiento en bytes y el ancla (`from_sentence`): un ancla
 * ampliada o recortada, o una frase alternativa, entra por el mismo camino que la del motor y tiene que pasar los
 * mismos casos. Aquí cada uno con un ancla que no es la que propuso el motor.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Content;

use MagicLinking\Content\BlockEditor;
use MagicLinking\Content\ClassicEditor;
use MagicLinking\Content\Edit;
use MagicLinking\Content\InsertionException;
use MagicLinking\Content\InsertRequest;
use MagicLinking\Content\Verifier;
use PHPUnit\Framework\TestCase;

final class AdjustedAnchorTest extends TestCase {

	use Fixtures;

	/**
	 * URL de destino de las pruebas.
	 */
	private const URL = 'https://example.org/aire-acondicionado/';

	/**
	 * Petición desde la frase entera, como la que manda el panel.
	 *
	 * @param string      $sentence Frase (texto plano, espacios normales).
	 * @param string      $anchor   Tramo de la frase que se enlaza.
	 * @param string|null $path     Ruta del bloque.
	 * @param int         $nth      Aparición del ancla en la frase (desde 0) si se repite.
	 */
	private function from( string $sentence, string $anchor, ?string $path = null, int $nth = 0 ): InsertRequest {
		$offset = 0;
		for ( $i = 0; $i <= $nth; $i++ ) {
			$offset = (int) strpos( $sentence, $anchor, 0 === $i ? 0 : $offset + 1 );
		}

		return InsertRequest::from_sentence( 1, self::URL, $sentence, $offset, $anchor, array(), $path );
	}

	private function insert( string $content, InsertRequest $request ): Edit {
		$edit  = ( new BlockEditor() )->insert( $content, $request );
		$check = Verifier::check( $content, $edit->content, array( $edit->start, $edit->end ) );
		$this->assertTrue( $check->ok, $check->reason );

		return $edit;
	}

	private function refused( string $reason, string $content, InsertRequest $request, bool $classic = false ): void {
		try {
			$classic ? ( new ClassicEditor() )->insert( $content, $request ) : ( new BlockEditor() )->insert( $content, $request );
		} catch ( InsertionException $e ) {
			$this->assertSame( $reason, $e->reason(), $e->getMessage() );
			return;
		}
		$this->fail( "Debía negarse con «{$reason}»." );
	}

	public function test_caso_01_parrafo_simple_con_el_ancla_ampliada_y_recortada(): void {
		$content = $this->doc( $this->p( 'Primero.' ), $this->p( 'Instalar aire acondicionado fijo en casa ahorra dinero.' ), $this->p( 'Último.' ) );
		$phrase  = 'Instalar aire acondicionado fijo en casa ahorra dinero.';

		$wider = $this->insert( $content, $this->from( $phrase, 'aire acondicionado fijo' ) );
		$this->assertSame( $this->doc( $this->p( 'Primero.' ), $this->p( 'Instalar ' . $this->a( 'aire acondicionado fijo' ) . ' en casa ahorra dinero.' ), $this->p( 'Último.' ) ), $wider->content );

		$narrow = $this->insert( $content, $this->from( $phrase, 'aire' ) );
		$this->assertSame( $this->doc( $this->p( 'Primero.' ), $this->p( 'Instalar ' . $this->a( 'aire' ) . ' acondicionado fijo en casa ahorra dinero.' ), $this->p( 'Último.' ) ), $narrow->content );
	}

	public function test_caso_02_ancla_repetida_en_el_mismo_parrafo_enlaza_la_de_la_frase_elegida(): void {
		$content = $this->p( 'Un aire acondicionado fijo gasta menos. Un aire acondicionado portátil gasta más en verano.' );

		$edit = $this->insert( $content, $this->from( 'Un aire acondicionado portátil gasta más en verano.', 'aire acondicionado portátil' ) );

		$this->assertSame( $this->p( 'Un aire acondicionado fijo gasta menos. Un ' . $this->a( 'aire acondicionado portátil' ) . ' gasta más en verano.' ), $edit->content );
	}

	public function test_caso_03_ancla_ajustada_alrededor_de_un_strong_completo(): void {
		$content = $this->p( 'Instala <strong>aire acondicionado</strong> hoy.' );

		$edit = $this->insert( $content, $this->from( 'Instala aire acondicionado hoy.', 'Instala aire acondicionado' ) );

		$this->assertSame( $this->p( $this->a( 'Instala <strong>aire acondicionado</strong>' ) . ' hoy.' ), $edit->content );
	}

	public function test_caso_04_ancla_ajustada_que_cruza_em_parcial_no_deja_html_mal_anidado(): void {
		$content = $this->p( 'Una <em>muy buena bomba de</em> calor ahorra.' );

		$this->refused( InsertionException::CROSSES_TAGS, $content, $this->from( 'Una muy buena bomba de calor ahorra.', 'bomba de calor' ) );
	}

	public function test_caso_05_ancla_ajustada_que_atraviesa_otro_enlace_se_rechaza(): void {
		$content = $this->p( 'Mira el <a href="/otra/">aire acondicionado</a> de la sala.' );
		$phrase  = 'Mira el aire acondicionado de la sala.';

		// Entera, por el borde y solo tocando una palabra del enlace: ninguna.
		$this->refused( InsertionException::ALREADY_LINKED, $content, $this->from( $phrase, 'aire acondicionado' ) );
		$this->refused( InsertionException::ALREADY_LINKED, $content, $this->from( $phrase, 'Mira el aire' ) );
		$this->refused( InsertionException::ALREADY_LINKED, $content, $this->from( $phrase, 'acondicionado de la sala' ) );
	}

	public function test_caso_06_bloques_anidados_con_ancla_ajustada_por_block_path(): void {
		$inner   = fn( string $text ): string => "<!-- wp:column -->\n<div class=\"wp-block-column\">" . $this->p( $text ) . "</div>\n<!-- /wp:column -->";
		$content = "<!-- wp:columns -->\n<div class=\"wp-block-columns\">" . $inner( 'Compra aire acondicionado ya.' ) . $inner( 'Compra aire acondicionado ya.' ) . "</div>\n<!-- /wp:columns -->";
		$phrase  = 'Compra aire acondicionado ya.';

		$this->refused( InsertionException::TEXT_CHANGED, $content, $this->from( $phrase, 'aire acondicionado' ) );
		$edit = $this->insert( $content, $this->from( $phrase, 'Compra aire acondicionado', '0.1.0' ) );

		$this->assertSame( '0.1.0', $edit->path );
		$needle = 'Compra aire acondicionado ya.</p>';
		$at     = (int) strpos( $content, $needle, (int) strpos( $content, $needle ) + 1 );
		$this->assertSame( substr( $content, 0, $at ) . $this->a( 'Compra aire acondicionado' ) . ' ya.</p>' . substr( $content, $at + strlen( $needle ) ), $edit->content );
	}

	public function test_caso_07_bloque_reutilizable_no_se_toca_con_ancla_ajustada(): void {
		$content = $this->doc( '<!-- wp:block {"ref":123} /-->', $this->p( 'Otro párrafo.' ) );

		$this->refused( InsertionException::REUSABLE_BLOCK, $content, $this->from( 'Compra aire acondicionado ya.', 'Compra aire acondicionado', '0' ) );
	}

	public function test_caso_08_emojis_tildes_y_entidades_con_ancla_ajustada_en_bytes(): void {
		$content = $this->doc( $this->p( 'Antes.' ), $this->p( 'La niña 🍕 comió jamón&nbsp;ibérico &amp; queso &eacute;lite en Cádiz &#8211; ¡ñam!' ) );
		$phrase  = 'La niña 🍕 comió jamón ibérico & queso élite en Cádiz – ¡ñam!';

		// Ancla detrás de varios caracteres de varios bytes: el desplazamiento es de bytes, no de caracteres.
		$edit = $this->insert( $content, $this->from( $phrase, 'queso élite en Cádiz' ) );
		$this->assertStringContainsString( $this->a( 'queso &eacute;lite en Cádiz' ), $edit->content );
		$this->assertSame( wp_strip_all_tags( $content ), wp_strip_all_tags( $edit->content ), 'El texto plano es el mismo.' );

		$edit = $this->insert( $content, $this->from( $phrase, 'niña 🍕 comió jamón ibérico' ) );
		$this->assertSame( wp_strip_all_tags( $content ), wp_strip_all_tags( $edit->content ) );
		$this->assertStringContainsString( $this->a( 'niña 🍕 comió jamón&nbsp;ibérico' ), $edit->content );
	}

	public function test_caso_09_clasico_con_shortcodes_y_ancla_ajustada(): void {
		$content = "Texto con [gallery ids=\"1,2\"] y aire acondicionado instalado hoy.\n\n[caption id=\"a\"]<img src=\"x.jpg\" /> Pie[/caption]\n\nFin.";

		$edit  = ( new ClassicEditor() )->insert( $content, $this->from( 'Texto con  y aire acondicionado instalado hoy.', 'aire acondicionado instalado' ) );
		$check = Verifier::check( $content, $edit->content, array( $edit->start, $edit->end ) );

		$this->assertTrue( $check->ok, $check->reason );
		$this->assertStringContainsString( '[gallery ids="1,2"] y ' . $this->a( 'aire acondicionado instalado' ) . ' hoy.', $edit->content );
		$this->assertStringContainsString( '[caption id="a"]<img src="x.jpg" /> Pie[/caption]', $edit->content );
	}

	public function test_caso_10_texto_cambiado_desde_la_sugerencia_aborta_con_ancla_ajustada(): void {
		$content = $this->p( 'Ahora el texto dice otra cosa sobre el aire acondicionado de casa.' );

		$this->refused( InsertionException::TEXT_CHANGED, $content, $this->from( 'Compra el aire acondicionado de casa.', 'Compra el aire acondicionado' ) );
	}

	public function test_caso_15_bloque_de_tercero_no_se_toca_con_ancla_ajustada(): void {
		$content = $this->doc( '<!-- wp:acme/callout -->' . "\n" . '<div class="acme-callout"><p>Compra aire acondicionado ya.</p></div>' . "\n" . '<!-- /wp:acme/callout -->' );

		$this->refused( InsertionException::BLOCK_NOT_ALLOWED, $content, $this->from( 'Compra aire acondicionado ya.', 'Compra aire acondicionado' ) );
	}

	public function test_un_ancla_que_no_es_tramo_literal_de_la_frase_se_rechaza_antes_de_tocar_nada(): void {
		foreach ( array(
			array( 'Compra aire acondicionado ya.', 'aire frío', 7 ),
			array( 'Compra aire acondicionado ya.', 'aire acondicionado', 0 ),
			array( 'Compra aire acondicionado ya.', '', 0 ),
			array( 'Compra aire acondicionado ya.', 'ya.', -1 ),
		) as [ $sentence, $anchor, $offset ] ) {
			try {
				InsertRequest::from_sentence( 1, self::URL, $sentence, $offset, $anchor );
				$this->fail( "Debía rechazar «{$anchor}» en {$offset}." );
			} catch ( InsertionException $e ) {
				$this->assertSame( InsertionException::BAD_REQUEST, $e->reason() );
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	/**
	 * Enlace tal como lo escribe el plugin.
	 *
	 * @param string $inner Contenido del enlace.
	 */
	private function a( string $inner ): string {
		return '<a href="' . self::URL . '">' . $inner . '</a>';
	}
}
