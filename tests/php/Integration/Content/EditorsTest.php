<?php
/**
 * Inserción pura sobre texto (docs/06 §8): los casos que no necesitan base de datos.
 *
 * Van en la suite de integración porque `parse_blocks()` y `WP_HTML_Tag_Processor` son de WordPress.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Content;

use MagicLinking\Content\BlockEditor;
use MagicLinking\Content\BlockMap;
use MagicLinking\Content\ClassicEditor;
use MagicLinking\Content\Edit;
use MagicLinking\Content\InsertionException;
use MagicLinking\Content\InsertRequest;
use MagicLinking\Content\Verifier;
use MagicLinking\Engine\DomExtractor;
use PHPUnit\Framework\TestCase;

final class EditorsTest extends TestCase {

	use Fixtures;

	/**
	 * URL de destino de las pruebas.
	 */
	private const URL = 'https://example.org/aire-acondicionado/';

	/**
	 * Inserta en bloques y comprueba, además, que el verificador da por bueno el resultado.
	 *
	 * @param string        $content Contenido.
	 * @param InsertRequest $request Petición.
	 */
	private function insert( string $content, InsertRequest $request ): Edit {
		$edit  = ( new BlockEditor() )->insert( $content, $request );
		$check = Verifier::check( $content, $edit->content, array( $edit->start, $edit->end ) );
		$this->assertTrue( $check->ok, 'El verificador rechaza un cambio correcto: ' . $check->reason );

		return $edit;
	}

	/**
	 * Lo que debe lanzar una petición.
	 *
	 * @param string        $reason  Código esperado.
	 * @param string        $content Contenido.
	 * @param InsertRequest $request Petición.
	 * @param bool          $classic Editor clásico.
	 */
	private function refused( string $reason, string $content, InsertRequest $request, bool $classic = false ): void {
		try {
			$classic ? ( new ClassicEditor() )->insert( $content, $request ) : ( new BlockEditor() )->insert( $content, $request );
		} catch ( InsertionException $e ) {
			$this->assertSame( $reason, $e->reason(), $e->getMessage() );
			return;
		}
		$this->fail( "Debía negarse con «{$reason}»." );
	}

	// -------------------------------------------------------------- docs/06 §8, los 15 casos.

	public function test_caso_01_parrafo_simple(): void {
		$content = $this->doc(
			$this->p( 'Primero.' ),
			$this->p( 'Instalar aire acondicionado en casa ahorra dinero.' ),
			$this->p( 'Último.' )
		);

		$edit = $this->insert( $content, $this->req( 'aire acondicionado', 'Instalar ', ' en casa ahorra' ) );

		$this->assertSame(
			$this->doc(
				$this->p( 'Primero.' ),
				$this->p( 'Instalar ' . $this->a( 'aire acondicionado' ) . ' en casa ahorra dinero.' ),
				$this->p( 'Último.' )
			),
			$edit->content
		);
		$this->assertSame( '2', $edit->path );
	}

	public function test_caso_02_ancla_repetida_en_el_mismo_parrafo_enlaza_la_del_contexto(): void {
		$text    = 'Un aire acondicionado fijo gasta menos que un aire acondicionado portátil en verano.';
		$content = $this->p( $text );

		$edit = $this->insert( $content, $this->req( 'aire acondicionado', 'gasta menos que un ', ' portátil en verano.' ) );

		$this->assertSame(
			$this->p( 'Un aire acondicionado fijo gasta menos que un ' . $this->a( 'aire acondicionado' ) . ' portátil en verano.' ),
			$edit->content
		);

		$first = $this->insert( $content, $this->req( 'aire acondicionado', 'Un ', ' fijo gasta' ) );
		$this->assertSame(
			$this->p( 'Un ' . $this->a( 'aire acondicionado' ) . ' fijo gasta menos que un aire acondicionado portátil en verano.' ),
			$first->content
		);

		// Con un contexto que vale para las dos, no se adivina.
		$this->refused( InsertionException::TEXT_CHANGED, $this->p( 'Sí, aire acondicionado y no. Sí, aire acondicionado y no.' ), $this->req( 'aire acondicionado', 'Sí, ', ' y no.' ) );
	}

	public function test_caso_03_ancla_dentro_de_strong_completo(): void {
		$content = $this->p( 'Instala <strong>aire acondicionado</strong> hoy.' );

		$inside = $this->insert( $content, $this->req( 'aire acondicionado', 'Instala ', ' hoy.' ) );
		$this->assertSame( $this->p( 'Instala <strong>' . $this->a( 'aire acondicionado' ) . '</strong> hoy.' ), $inside->content );

		// Un ancla que abarca el strong entero lo envuelve: el enlace queda alrededor.
		$around = $this->insert( $content, $this->req( 'Instala aire acondicionado', '', ' hoy.' ) );
		$this->assertSame( $this->p( $this->a( 'Instala <strong>aire acondicionado</strong>' ) . ' hoy.' ), $around->content );
	}

	public function test_caso_04_ancla_que_cruza_em_parcial_no_deja_html_mal_anidado(): void {
		// El ancla empieza a mitad del <em> y acaba fuera: no hay forma de enlazar sin mal anidar.
		$content = $this->p( 'Una <em>muy buena bomba de</em> calor ahorra.' );
		$this->refused( InsertionException::CROSSES_TAGS, $content, $this->req( 'bomba de calor', 'muy buena ', ' ahorra.' ) );

		// Empieza justo tras la apertura: el enlace envuelve el <em> entero y queda bien anidado.
		$edit = $this->insert( $this->p( 'Una <em>bomba de</em> calor ahorra.' ), $this->req( 'bomba de calor', 'Una ', ' ahorra.' ) );
		$this->assertSame( $this->p( 'Una ' . $this->a( '<em>bomba de</em> calor' ) . ' ahorra.' ), $edit->content );

		// Acaba a mitad de un elemento que empieza dentro del ancla.
		$this->refused( InsertionException::CROSSES_TAGS, $this->p( 'Una bomba <em>de calor muy</em> ahorra.' ), $this->req( 'bomba de calor', 'Una ', ' muy ahorra.' ) );
	}

	public function test_caso_05_ancla_ya_enlazada_aborta(): void {
		$content = $this->p( 'Mira el <a href="/otra/">aire acondicionado</a> de la sala.' );

		$this->refused( InsertionException::ALREADY_LINKED, $content, $this->req( 'aire acondicionado', 'Mira el ', ' de la sala.' ) );
		// Un ancla que solo toca parte de un enlace tampoco.
		$this->refused( InsertionException::ALREADY_LINKED, $content, $this->req( 'acondicionado de la', 'Mira el aire ', ' sala.' ) );
	}

	public function test_caso_06_bloques_anidados_se_localizan_por_block_path(): void {
		$inner   = fn( string $text ): string => "<!-- wp:column -->\n<div class=\"wp-block-column\">" . $this->p( $text ) . "</div>\n<!-- /wp:column -->";
		$content = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">"
			. "<!-- wp:columns -->\n<div class=\"wp-block-columns\">"
			. $inner( 'Compra aire acondicionado ya.' )
			. $inner( 'Compra aire acondicionado ya.' )
			. "</div>\n<!-- /wp:columns --></div>\n<!-- /wp:group -->";

		// Dos bloques con el mismo texto: sin ruta no se sabe cuál.
		$this->refused( InsertionException::TEXT_CHANGED, $content, $this->req( 'aire acondicionado', 'Compra ', ' ya.' ) );

		$edit = $this->insert( $content, $this->req( 'aire acondicionado', 'Compra ', ' ya.', '0.0.1.0' ) );

		$this->assertSame( '0.0.1.0', $edit->path );

		// Solo cambia el segundo bloque; el primero, byte a byte igual.
		$needle   = 'Compra aire acondicionado ya.</p>';
		$at       = (int) strpos( $content, $needle, (int) strpos( $content, $needle ) + 1 );
		$expected = substr( $content, 0, $at ) . 'Compra ' . $this->a( 'aire acondicionado' ) . ' ya.</p>' . substr( $content, $at + strlen( $needle ) );
		$this->assertSame( $expected, $edit->content );
	}

	public function test_caso_07_bloque_reutilizable_no_se_toca(): void {
		$content = $this->doc(
			'<!-- wp:block {"ref":123} /-->',
			$this->p( 'Otro párrafo.' )
		);

		$this->refused( InsertionException::REUSABLE_BLOCK, $content, $this->req( 'aire acondicionado', 'Compra ', ' ya.', '0' ) );
		// Sin ruta, el texto no está en ningún bloque que se pueda tocar.
		$this->refused( InsertionException::TEXT_CHANGED, $content, $this->req( 'aire acondicionado', 'Compra ', ' ya.' ) );
	}

	public function test_caso_08_emojis_tildes_enie_nbsp_y_entidades(): void {
		$html    = 'La niña 🍕 comió jamón&nbsp;ibérico &amp; queso &eacute;lite en Cádiz &#8211; ¡ñam!';
		$content = $this->doc( $this->p( 'Antes.' ), $this->p( $html ) );

		// Ancla con el espacio no separable (la frase del motor lo tiene como espacio normal).
		$edit = $this->insert( $content, $this->req( 'jamón ibérico', 'La niña 🍕 comió ', ' & queso élite en' ) );
		$this->assertSame(
			$this->doc( $this->p( 'Antes.' ), $this->p( 'La niña 🍕 comió ' . $this->a( 'jamón&nbsp;ibérico' ) . ' &amp; queso &eacute;lite en Cádiz &#8211; ¡ñam!' ) ),
			$edit->content
		);

		// Ancla que empieza en una entidad.
		$edit = $this->insert( $content, $this->req( 'élite en Cádiz', 'ibérico & queso ', ' – ¡ñam!' ) );
		$this->assertStringContainsString( 'queso ' . $this->a( '&eacute;lite en Cádiz' ) . ' &#8211;', $edit->content );

		// Ancla tras el emoji y con la eñe.
		$edit = $this->insert( $content, $this->req( 'niña', 'La ', ' 🍕 comió' ) );
		$this->assertStringContainsString( 'La ' . $this->a( 'niña' ) . ' 🍕 comió', $edit->content );

		// Texto plano idéntico antes y después, con las entidades decodificadas.
		$this->assertSame( wp_strip_all_tags( $content ), wp_strip_all_tags( $edit->content ) );
	}

	public function test_caso_09_clasico_sin_bloques_con_shortcodes_intactos(): void {
		$content = "Texto con [gallery ids=\"1,2\"] y aire acondicionado instalado.\n\n"
			. "[caption id=\"a\" align=\"alignleft\"]<img src=\"x.jpg\" /> Pie de foto[/caption]\n\n"
			. 'Otro párrafo con [boton url="/a?b=1&c=2"]aire acondicionado[/boton] y un final.';

		$edit  = ( new ClassicEditor() )->insert( $content, $this->req( 'aire acondicionado', 'Texto con  y ', ' instalado.' ) );
		$check = Verifier::check( $content, $edit->content, array( $edit->start, $edit->end ) );

		$this->assertTrue( $check->ok, $check->reason );
		$this->assertSame(
			str_replace( 'y aire acondicionado instalado.', 'y ' . $this->a( 'aire acondicionado' ) . ' instalado.', $content ),
			$edit->content
		);
		$this->assertSame( '@0', $edit->path );
		$this->assertStringContainsString( '[gallery ids="1,2"]', $edit->content );
		$this->assertStringContainsString( '[boton url="/a?b=1&c=2"]aire acondicionado[/boton]', $edit->content );

		// El otro párrafo: el ancla entre shortcodes se enlaza sin tocar los shortcodes.
		$second = ( new ClassicEditor() )->insert( $content, $this->req( 'aire acondicionado', 'Otro párrafo con  ', '  y un final.' ) );
		$this->assertStringContainsString( '[boton url="/a?b=1&c=2"]' . $this->a( 'aire acondicionado' ) . '[/boton] y un final.', $second->content );
		$this->assertTrue( Verifier::check( $content, $second->content, array( $second->start, $second->end ) )->ok );
		// Lo guardado en el historial es el párrafo, no el documento entero.
		$this->assertSame( 'Otro párrafo con [boton url="/a?b=1&c=2"]aire acondicionado[/boton] y un final.', $second->before_html );
	}

	public function test_caso_10_texto_cambiado_desde_la_sugerencia_aborta(): void {
		$content = $this->p( 'Ahora el texto dice otra cosa sobre el aire acondicionado de casa.' );

		$this->refused( InsertionException::TEXT_CHANGED, $content, $this->req( 'aire acondicionado', 'Compra el ', ' de casa.' ) );
		$this->refused( InsertionException::TEXT_CHANGED, $content, $this->req( 'aire acondicionado', 'Compra el ', ' de casa.', '5' ) );
	}

	public function test_caso_15_bloque_de_tercero_con_html_propio_no_se_toca(): void {
		$content = $this->doc(
			'<!-- wp:acme/callout {"tone":"info"} -->' . "\n" . '<div class="acme-callout"><p>Compra aire acondicionado ya.</p></div>' . "\n" . '<!-- /wp:acme/callout -->',
			$this->p( 'Otro.' )
		);

		$this->refused( InsertionException::BLOCK_NOT_ALLOWED, $content, $this->req( 'aire acondicionado', 'Compra ', ' ya.' ) );
		$this->refused( InsertionException::BLOCK_NOT_ALLOWED, $content, $this->req( 'aire acondicionado', 'Compra ', ' ya.', '0' ) );

		// Un complemento puede declarar el bloque como de texto.
		add_filter( 'magiclinking_insertable_blocks', static fn( array $names ): array => array_merge( $names, array( 'acme/callout' ) ) );
		$edit = ( new BlockEditor() )->insert( $content, $this->req( 'aire acondicionado', 'Compra ', ' ya.' ) );
		remove_all_filters( 'magiclinking_insertable_blocks' );
		$this->assertStringContainsString( $this->a( 'aire acondicionado' ), $edit->content );
	}

	// -------------------------------------------------------------- Casos límite.

	public function test_extra_elemento_de_lista_y_lista_anidada(): void {
		$content = "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Ventajas del aire acondicionado moderno<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Menos ruido</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list --></li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->";

		$edit = $this->insert( $content, $this->req( 'aire acondicionado', 'Ventajas del ', ' moderno' ) );

		$this->assertSame( str_replace( 'del aire acondicionado moderno', 'del ' . $this->a( 'aire acondicionado' ) . ' moderno', $content ), $edit->content );
		$this->assertSame( '0.0', $edit->path );

		// El elemento anidado.
		$nested = $this->insert( $content, $this->req( 'Menos ruido', '', '' ) );
		$this->assertSame( '0.0.0.0', $nested->path );
	}

	public function test_extra_encabezados_solo_si_el_usuario_los_permite(): void {
		$content = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Guía de aire acondicionado</h2>\n<!-- /wp:heading -->";

		$this->refused( InsertionException::BLOCK_NOT_ALLOWED, $content, $this->req( 'aire acondicionado', 'Guía de ', '' ) );

		$edit = $this->insert( $content, $this->req( 'aire acondicionado', 'Guía de ', '', null, 1, true ) );
		$this->assertStringContainsString( 'Guía de ' . $this->a( 'aire acondicionado' ) . '</h2>', $edit->content );

		// Un encabezado dentro de un párrafo tampoco (HTML a mano).
		$this->refused( InsertionException::BLOCK_NOT_ALLOWED, $this->p( '<h3>aire acondicionado</h3>' ), $this->req( 'aire acondicionado' ) );
	}

	public function test_extra_comentarios_de_bloque_con_atributos_json_quedan_intactos(): void {
		$attrs   = '{"align":"center","className":"a > b","metadata":{"name":"aire acondicionado"},"style":{"color":{"text":"#fff"}}}';
		$content = $this->doc(
			"<!-- wp:paragraph {$attrs} -->\n<p class=\"has-text-align-center\">Compra aire acondicionado ya.</p>\n<!-- /wp:paragraph -->",
			"<!-- wp:image {\"id\":4} -->\n<figure class=\"wp-block-image\"><img src=\"a.jpg\" alt=\"aire acondicionado\"/></figure>\n<!-- /wp:image -->"
		);

		$edit = $this->insert( $content, $this->req( 'aire acondicionado', 'Compra ', ' ya.' ) );

		$this->assertStringContainsString( "<!-- wp:paragraph {$attrs} -->", $edit->content );
		$this->assertStringContainsString( 'alt="aire acondicionado"/>', $edit->content );
		$this->assertSame( substr( $content, strpos( $content, '<!-- wp:image' ) ), substr( $edit->content, strpos( $edit->content, '<!-- wp:image' ) ) );
	}

	public function test_extra_bloque_con_enlace_de_datos_no_se_toca(): void {
		$content = "<!-- wp:paragraph {\"metadata\":{\"bindings\":{\"content\":{\"source\":\"core/post-meta\",\"args\":{\"key\":\"x\"}}}}} -->\n<p>Compra aire acondicionado ya.</p>\n<!-- /wp:paragraph -->";

		$this->refused( InsertionException::BOUND_BLOCK, $content, $this->req( 'aire acondicionado', 'Compra ', ' ya.' ) );
	}

	public function test_extra_ancla_con_salto_de_linea_o_codigo_no_se_enlaza(): void {
		$this->refused( InsertionException::TEXT_CHANGED, $this->p( 'Compra aire<br>acondicionado ya.' ), $this->req( 'aire acondicionado', 'Compra ', ' ya.' ) );
		// El código en línea es un corte: su texto no se recorre, ni siquiera por casualidad.
		$this->refused( InsertionException::TEXT_CHANGED, $this->p( 'Compra <code>aire acondicionado</code> ya.' ), $this->req( 'aire acondicionado', 'Compra ', ' ya.' ) );
		$this->refused( InsertionException::UNSAFE_ANCHOR, $this->p( 'Compra aire [shortcode] acondicionado ya.' ), $this->req( 'aire   acondicionado', 'Compra ', ' ya.' ) );
	}

	public function test_extra_el_codigo_en_linea_y_las_imagenes_casan_con_el_separador_del_motor(): void {
		// El extractor del motor escribe « · » donde hay <code>, <kbd>, <samp> o <img>.
		$content = $this->p( 'Usa <code>wp plugin list</code> para ver el aire acondicionado<img src="a.jpg" alt=""/> y <kbd>Ctrl</kbd>.' );

		$edit = $this->insert( $content, $this->req( 'aire acondicionado', 'Usa · para ver el ', ' · y ·.' ) );
		$this->assertStringContainsString( 'ver el ' . $this->a( 'aire acondicionado' ) . '<img', $edit->content );

		$edit = $this->insert( $content, $this->req( 'ver', 'Usa · para ', ' el aire' ) );
		$this->assertStringContainsString( 'para ' . $this->a( 'ver' ) . ' el', $edit->content );

		// El ancla no puede incluir el código.
		$this->refused( InsertionException::TEXT_CHANGED, $content, $this->req( 'wp plugin list', 'Usa ', ' para' ) );
	}

	public function test_extra_la_aparicion_ya_enlazada_no_impide_enlazar_la_libre(): void {
		$content = $this->doc(
			$this->p( 'Compra <a href="/x/">aire acondicionado</a> ya.' ),
			$this->p( 'Compra aire acondicionado ya.' )
		);

		$edit = $this->insert( $content, $this->req( 'aire acondicionado', 'Compra ', ' ya.' ) );

		$this->assertSame( '2', $edit->path );
		$this->assertStringContainsString( 'Compra <a href="/x/">aire acondicionado</a> ya.', $edit->content );
	}

	public function test_extra_contexto_cerca_del_principio_o_del_final_del_bloque(): void {
		$edit = $this->insert( $this->p( 'aire acondicionado' ), $this->req( 'aire acondicionado' ) );
		$this->assertSame( $this->p( $this->a( 'aire acondicionado' ) ), $edit->content );

		$edit = $this->insert( $this->p( 'Mucho aire acondicionado' ), $this->req( 'aire acondicionado', 'Mucho ' ) );
		$this->assertSame( $this->p( 'Mucho ' . $this->a( 'aire acondicionado' ) ), $edit->content );
	}

	public function test_extra_atributos_target_y_rel_solo_si_se_configuran(): void {
		$request = new InsertRequest(
			1,
			self::URL,
			'aire',
			'El ',
			' frío',
			null,
			array(
				'target' => '_blank',
				'rel'    => 'noopener',
			),
			0
		);

		$edit = $this->insert( $this->p( 'El aire frío' ), $request );

		$this->assertSame( $this->p( 'El <a href="' . self::URL . '" target="_blank" rel="noopener">aire</a> frío' ), $edit->content );
	}

	public function test_extra_direccion_no_valida_se_rechaza(): void {
		$this->refused( InsertionException::BAD_REQUEST, $this->p( 'El aire frío' ), new InsertRequest( 1, 'javascript:alert(1)', 'aire', 'El ', ' frío', null, array(), 0 ) );
	}

	public function test_extra_contexto_de_treinta_caracteres_con_multibyte(): void {
		$long    = str_repeat( 'ñandú ', 10 );
		$content = $this->p( $long . 'aire acondicionado ' . $long );

		$request                     = InsertRequest::from_sentence( 1, self::URL, $long . 'aire acondicionado ' . $long, strlen( $long ), 'aire acondicionado', array(), null, 0 );
		[ $before, $anchor, $after ] = $request->needle();

		$this->assertSame( 30, mb_strlen( $before ) );
		$this->assertSame( 30, mb_strlen( $after ) );
		$this->assertSame( 'aire acondicionado', $anchor );
		$this->assertStringContainsString( $this->a( 'aire acondicionado' ), $this->insert( $content, $request )->content );
	}

	public function test_extra_el_ancla_tiene_que_ser_un_tramo_literal_de_la_frase(): void {
		$this->expectException( InsertionException::class );
		InsertRequest::from_sentence( 1, self::URL, 'El aire frío', 3, 'viento', array(), null, 0 );
	}

	public function test_extra_el_mapa_de_bloques_coincide_con_parse_blocks(): void {
		$content = $this->doc(
			'<!-- wp:block {"ref":1} /-->',
			"<!-- wp:group -->\n<div>" . $this->p( 'a' ) . "\n\ntexto suelto\n" . $this->p( 'b' ) . "</div>\n<!-- /wp:group -->",
			'HTML suelto de editor clásico entre bloques',
			$this->p( 'c' )
		);

		$map = BlockMap::parse( $content );
		$this->assertNotNull( $map );
		$names = array_map( static fn( $n ) => (string) $n->name, $map->walk() );
		$this->assertSame( Verifier::block_names( $content ), $names );

		// Un comentario de cierre sin su apertura, o sin cerrar: no hay mapa, no se toca.
		$this->assertNull( BlockMap::parse( "<!-- wp:paragraph -->\n<p>x</p>" ) );
		$this->assertNull( BlockMap::parse( "<p>x</p>\n<!-- /wp:paragraph -->" ) );
	}

	public function test_extra_el_html_fuera_del_bloque_no_se_reserializa(): void {
		// Espacios, saltos y atributos con formato propio: nada de ello se reescribe.
		$content = "<!-- wp:paragraph   {\"dropCap\":true}   -->\n\n\n<p   class='x'  >Compra aire acondicionado ya.</p>\n\n\n<!--   /wp:paragraph   -->\t\n\n<!-- wp:more -->\n<!--more-->\n<!-- /wp:more -->";

		$edit = $this->insert( $content, $this->req( 'aire acondicionado', 'Compra ', ' ya.' ) );

		$this->assertSame( str_replace( 'Compra aire acondicionado ya.', 'Compra ' . $this->a( 'aire acondicionado' ) . ' ya.', $content ), $edit->content );
	}

	/**
	 * Contenidos con de todo: entidades, espacios no separables, shortcodes, formato en línea, enlaces,
	 * emojis, saltos de línea y listas.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function documents(): array {
		return array(
			'bloques' => array(
				"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Guía de climatización</h2>\n<!-- /wp:heading -->\n\n"
				. "<!-- wp:paragraph -->\n<p>La <strong>bomba de calor</strong> y el aire&nbsp;acondicionado son &quot;dos amigos&quot; en Cádiz 🍕 [gallery ids=\"1,2\"] según la <em>guía</em> de verano.</p>\n<!-- /wp:paragraph -->\n\n"
				. "<!-- wp:paragraph -->\n<p>Mira <a href=\"/x/\">este enlace previo</a> y sigue con el suelo radiante, que funciona   bien\ncon agua caliente.<br>Otra línea tras salto.</p>\n<!-- /wp:paragraph -->\n\n"
				. "<!-- wp:paragraph -->\n<p>Usa <code>wp plugin list</code> o <kbd>Ctrl</kbd>+<samp>C</samp> para ver el aire acondicionado<img src=\"a.jpg\" alt=\"\"/> y la bomba de calor.</p>\n<!-- /wp:paragraph -->\n\n"
				. "<!-- wp:list -->\n<ul><!-- wp:list-item -->\n<li>Ventilación mecánica &amp; aislamiento térmico</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
			),
			'clasico' => array(
				"Primer párrafo con <strong>aire acondicionado</strong> y&nbsp;calefacción, más [boton url=\"/a\"]un botón[/boton] y fin.\n\n"
				. "Segundo con <em>placas</em> solares &amp; caldera de gas\ny un salto simple dentro.\n\n<p>Un párrafo HTML con <a href=\"/y/\">enlace</a> previo y texto final.</p>",
			),
		);
	}

	/**
	 * @dataProvider documents
	 *
	 * @param string $content Contenido.
	 */
	public function test_extra_lo_que_ve_el_motor_se_localiza_en_el_html_sin_discrepancias( string $content ): void {
		$classic    = ! BlockEditor::handles( $content );
		$paragraphs = ( new DomExtractor() )->extract( $content )->paragraphs;
		$all        = implode( "\x1F", array_map( static fn( $p ): string => $p->text, $paragraphs ) );
		$accepted   = array( InsertionException::CROSSES_TAGS, InsertionException::UNSAFE_ANCHOR, InsertionException::ALREADY_LINKED );
		$linked     = 0;

		foreach ( $paragraphs as $paragraph ) {
			$words = (array) preg_split( '/ /', $paragraph->text, -1, PREG_SPLIT_OFFSET_CAPTURE );
			$total = count( $words );
			for ( $n = 1; $n <= 3; $n++ ) {
				for ( $i = 0; $i + $n <= $total; $i++ ) {
					$slice  = array_slice( $words, $i, $n );
					$anchor = implode( ' ', array_column( $slice, 0 ) );
					$offset = (int) $slice[0][1];
					if ( '' === trim( $anchor ) || 1 !== preg_match( '/^[\p{L}\p{N}]/u', $anchor ) || 1 !== preg_match( '/[\p{L}\p{N}]$/u', $anchor ) ) {
						continue;
					}
					$request                          = InsertRequest::from_sentence( 1, self::URL, $paragraph->text, $offset, $anchor, array(), null, 0 );
					[ $before, $anchor_text, $after ] = $request->needle();
					$duplicated                       = substr_count( $all, $before . $anchor_text . $after ) > 1;

					try {
						$edit = $classic ? ( new ClassicEditor() )->insert( $content, $request ) : ( new BlockEditor() )->insert( $content, $request );
					} catch ( InsertionException $e ) {
						$expected = $duplicated ? array_merge( $accepted, array( InsertionException::TEXT_CHANGED ) ) : $accepted;
						$this->assertContains( $e->reason(), $expected, "«{$anchor}» en «{$paragraph->text}»: " . $e->getMessage() );
						continue;
					}

					$check = Verifier::check( $content, $edit->content, array( $edit->start, $edit->end ) );
					$this->assertTrue( $check->ok, "«{$anchor}»: " . $check->reason );
					$this->assertStringContainsString( '<a href="' . self::URL . '">', $edit->content );
					++$linked;
				}
			}
		}

		$this->assertGreaterThan( 20, $linked );
	}

	// -------------------------------------------------------------- El verificador.

	public function test_verificador_rechaza_cambios_que_no_son_solo_el_enlace(): void {
		$before = $this->doc( $this->p( 'Uno aire dos.' ), $this->p( 'Tres.' ) );
		$ok     = str_replace( 'Uno aire dos.', 'Uno ' . $this->a( 'aire' ) . ' dos.', $before );
		$range  = array( 0, strlen( $this->p( 'Uno aire dos.' ) ) );

		$this->assertTrue( Verifier::check( $before, $ok, $range )->ok );

		$cases = array(
			'cambio fuera del rango' => array( str_replace( 'Tres.', 'Tres!', $ok ), 'outside_changed' ),
			'texto distinto'         => array( str_replace( 'dos.', 'dos!', $ok ), 'text' ),
			'dos enlaces'            => array( str_replace( 'Uno ', 'Uno ' . $this->a( 'x' ), $ok ), 'text' ),
			'enlace anidado'         => array( str_replace( '>aire<', '><a href="/y/">aire</a><', $ok ), 'link_count' ),
			'sin enlace'             => array( $before, 'link_count' ),
			'etiqueta de más'        => array( str_replace( 'Uno ', '<em>Uno </em>', $ok ), 'tags' ),
			'enlace mal cerrado'     => array( str_replace( '</a>', '', $ok ), 'tags' ),
		);
		foreach ( $cases as $label => [ $after, $reason ] ) {
			$result = Verifier::check( $before, $after, $range );
			$this->assertFalse( $result->ok, $label );
			$this->assertSame( $reason, $result->reason, $label );
		}

		// Un bloque que cambia de nombre o desaparece.
		$renamed = str_replace( 'wp:paragraph', 'wp:heading', substr( $ok, 0, strlen( $ok ) - strlen( $this->p( 'Tres.' ) ) ) ) . $this->p( 'Tres.' );
		$this->assertSame( 'blocks', Verifier::check( $before, $renamed, array( 0, strlen( $renamed ) - strlen( $this->p( 'Tres.' ) ) - 2 ) )->reason );
		$this->assertFalse( Verifier::check( $before, $ok, array( 0, 999999 ) )->ok );
	}

	public function test_verificador_quitar_enlace_es_el_camino_inverso(): void {
		$linked   = $this->p( 'Uno ' . $this->a( 'aire' ) . ' dos.' );
		$unlinked = $this->p( 'Uno aire dos.' );

		$this->assertTrue( Verifier::check( $linked, $unlinked, array( 0, strlen( $linked ) ), -1 )->ok );
		$this->assertFalse( Verifier::check( $linked, $unlinked, array( 0, strlen( $linked ) ), 1 )->ok );
	}
}
