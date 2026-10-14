<?php
/**
 * Los ajustes «dónde se puede enlazar» y «cómo se enlaza» se respetan al insertar (docs/06 §3, docs/07 §8).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Content;

use MagicLinking\Content\InsertionException;
use MagicLinking\Content\InsertRequest;
use MagicLinking\Content\Inserter;
use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Tests\Integration\GraphTestCase;

final class ScopeTest extends GraphTestCase {

	use Fixtures;

	private const URL = 'https://example.org/aire-acondicionado/';

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function inserter(): Inserter {
		return Plugin::container()->get( Inserter::class );
	}

	/**
	 * Guarda ajustes (sobre los de por defecto).
	 *
	 * @param array<string, mixed> $values Ajustes.
	 */
	private function settings( array $values ): void {
		update_option( Installer::SETTINGS_OPTION, $values );
	}

	private function post( string $content ): int {
		return self::factory()->post->create( array( 'post_content' => $content ) );
	}

	private function stored( int $id ): string {
		return (string) get_post_field( 'post_content', $id, 'raw' );
	}

	/**
	 * Intenta insertar y devuelve el código de la negativa, o null si se insertó.
	 *
	 * @param int    $post   Entrada.
	 * @param string $anchor Ancla.
	 * @param string $before Texto anterior.
	 */
	private function try_insert( int $post, string $anchor, string $before ): ?string {
		try {
			$this->inserter()->insert( $this->req( $anchor, $before, '', null, $post ) );
		} catch ( InsertionException $e ) {
			return $e->reason();
		}

		return null;
	}

	private function quote(): string {
		return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>Nos dijo que el aire acondicionado es lo primero.</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->";
	}

	private function table(): string {
		return "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody><tr><td>Revisar el aire acondicionado cada año</td></tr></tbody></table></figure>\n<!-- /wp:table -->";
	}

	private function heading(): string {
		return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Todo sobre el aire acondicionado</h2>\n<!-- /wp:heading -->";
	}

	public function test_by_default_nothing_changes_in_quotes_tables_and_headings(): void {
		foreach (
			array(
				'quote'   => array( $this->quote(), 'Nos dijo que el ' ),
				'table'   => array( $this->table(), 'Revisar el ' ),
				'heading' => array( $this->heading(), 'Todo sobre el ' ),
			) as $name => [ $content, $before ]
		) {
			$id = $this->post( $this->doc( $this->p( 'Intro.' ), $content ) );
			$this->assertSame( InsertionException::BLOCK_NOT_ALLOWED, $this->try_insert( $id, 'aire acondicionado', $before ), $name );
			$this->assertSame( $this->doc( $this->p( 'Intro.' ), $content ), $this->stored( $id ), "No se escribe nada en $name." );
		}
	}

	public function test_quotes_are_linked_only_when_the_setting_allows_it(): void {
		$this->settings( array( 'link_in_quotes' => true ) );
		$id = $this->post( $this->doc( $this->p( 'Intro.' ), $this->quote() ) );

		$this->assertNull( $this->try_insert( $id, 'aire acondicionado', 'Nos dijo que el ' ) );
		$this->assertSame(
			$this->doc( $this->p( 'Intro.' ), str_replace( 'aire acondicionado', $this->a( 'aire acondicionado' ), $this->quote() ) ),
			$this->stored( $id )
		);

		// Cada ajuste es independiente: citas permitidas no abre las tablas ni los encabezados.
		$other = $this->post( $this->doc( $this->table(), $this->heading() ) );
		$this->assertSame( InsertionException::BLOCK_NOT_ALLOWED, $this->try_insert( $other, 'aire acondicionado', 'Revisar el ' ) );
		$this->assertSame( InsertionException::BLOCK_NOT_ALLOWED, $this->try_insert( $other, 'aire acondicionado', 'Todo sobre el ' ) );
	}

	public function test_tables_are_linked_only_when_the_setting_allows_it(): void {
		$this->settings( array( 'link_in_tables' => true ) );
		$id = $this->post( $this->doc( $this->table() ) );

		$this->assertNull( $this->try_insert( $id, 'aire acondicionado', 'Revisar el ' ) );
		$this->assertSame( str_replace( 'aire acondicionado', $this->a( 'aire acondicionado' ), $this->table() ), $this->stored( $id ) );

		$quote = $this->post( $this->quote() );
		$this->assertSame( InsertionException::BLOCK_NOT_ALLOWED, $this->try_insert( $quote, 'aire acondicionado', 'Nos dijo que el ' ) );
	}

	public function test_headings_are_linked_only_when_the_setting_allows_it(): void {
		$this->settings( array( 'link_in_headings' => true ) );
		$id = $this->post( $this->heading() );

		$this->assertNull( $this->try_insert( $id, 'aire acondicionado', 'Todo sobre el ' ) );
		$this->assertSame( str_replace( 'aire acondicionado', $this->a( 'aire acondicionado' ), $this->heading() ), $this->stored( $id ) );
	}

	public function test_the_classic_editor_follows_the_same_settings(): void {
		$html    = "<p>Intro.</p>\n\n<blockquote>Nos dijo que el aire acondicionado es lo primero.</blockquote>\n\n<table><tr><td>Revisar el aire acondicionado cada año</td></tr></table>\n\n<h2>Todo sobre el aire acondicionado</h2>";
		$cases   = array(
			'link_in_quotes'   => 'Nos dijo que el ',
			'link_in_tables'   => 'Revisar el ',
			'link_in_headings' => 'Todo sobre el ',
		);
		$setting = array();

		foreach ( $cases as $key => $before ) {
			$id = $this->post( $html );
			$this->settings( $setting );
			$this->assertSame( InsertionException::BLOCK_NOT_ALLOWED, $this->try_insert( $id, 'aire acondicionado', $before ), "$key apagado" );
			$this->assertSame( $html, $this->stored( $id ) );

			$this->settings( array( $key => true ) );
			$this->assertNull( $this->try_insert( $id, 'aire acondicionado', $before ), "$key encendido" );
			$this->assertSame( 1, substr_count( $this->stored( $id ), '<a href=' ) );
		}
	}

	public function test_the_editor_is_only_offered_sentences_the_settings_allow(): void {
		$content = $this->doc( $this->p( 'Intro.' ), $this->quote() );
		$id      = $this->post( $content );
		$request = $this->req( 'aire acondicionado', 'Nos dijo que el ', ' es lo primero.', null, $id );

		$this->assertSame( array( false ), $this->inserter()->can_insert_many( $content, $id, array( $request ) ) );

		$this->settings( array( 'link_in_quotes' => true ) );
		$this->assertSame( array( true ), $this->inserter()->can_insert_many( $content, $id, array( $request ) ) );
	}

	public function test_new_tab_and_extra_rel_are_added_to_inserted_links_only(): void {
		$content = $this->p( 'Hablamos del aire acondicionado y de más cosas.' );

		$id = $this->post( $content );
		$this->assertNull( $this->try_insert( $id, 'aire acondicionado', 'Hablamos del ' ) );
		$this->assertStringContainsString( '<a href="' . self::URL . '">aire acondicionado</a>', $this->stored( $id ), 'Por defecto, sin atributos.' );

		$this->settings(
			array(
				'open_in_new_tab' => true,
				'extra_rel'       => 'NoFollow, sponsored <script>',
			)
		);
		$id = $this->post( $content );
		$this->assertNull( $this->try_insert( $id, 'aire acondicionado', 'Hablamos del ' ) );
		$this->assertStringContainsString( '<a href="' . self::URL . '" target="_blank" rel="nofollow sponsored noopener">aire acondicionado</a>', $this->stored( $id ) );

		$this->settings( array( 'extra_rel' => 'nofollow' ) );
		$id = $this->post( $content );
		$this->assertNull( $this->try_insert( $id, 'aire acondicionado', 'Hablamos del ' ) );
		$this->assertStringContainsString( '<a href="' . self::URL . '" rel="nofollow">aire acondicionado</a>', $this->stored( $id ) );
	}

	public function test_citations_inside_quotes_are_never_linked(): void {
		$this->settings( array( 'link_in_quotes' => true ) );

		$modern = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>Texto de la cita.</p>\n<!-- /wp:paragraph --><cite>Manual del aire acondicionado</cite></blockquote>\n<!-- /wp:quote -->";
		$id     = $this->post( $modern );
		$this->assertNotNull( $this->try_insert( $id, 'aire acondicionado', 'Manual del ' ) );
		$this->assertSame( $modern, $this->stored( $id ) );

		$classic = '<blockquote>Texto de la cita.<cite>Manual del aire acondicionado</cite></blockquote>';
		$id      = $this->post( $classic );
		$this->assertSame( InsertionException::BLOCK_NOT_ALLOWED, $this->try_insert( $id, 'aire acondicionado', 'Manual del ' ) );
		$this->assertSame( $classic, $this->stored( $id ) );
	}
}
