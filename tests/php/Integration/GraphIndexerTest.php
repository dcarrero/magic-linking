<?php
/**
 * Grafo de enlaces: indexado, recuentos y enlaces rotos.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Plugin;
use MagicLinking\Core\Settings;
use MagicLinking\Graph\BrokenReason;
use MagicLinking\Graph\GraphIndexer;
use MagicLinking\Graph\IndexOutcome;

final class GraphIndexerTest extends GraphTestCase {

	private GraphIndexer $indexer;

	public function set_up(): void {
		parent::set_up();

		$this->indexer = Plugin::container()->get( GraphIndexer::class );

		// Estas pruebas indexan a mano; el indexado al guardar se prueba en JobsTest.
		remove_action( 'wp_after_insert_post', array( Plugin::container()->get( \MagicLinking\Jobs\Jobs::class ), 'on_post_saved' ), 20 );
		remove_action( 'deleted_post', array( Plugin::container()->get( \MagicLinking\Jobs\Jobs::class ), 'on_post_deleted' ), 10 );
	}

	private function post( string $slug, string $content = '', array $extra = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_name'    => $slug,
					'post_title'   => ucfirst( $slug ),
					'post_content' => $content,
					'post_status'  => 'publish',
				),
				$extra
			)
		);
	}

	private function link( string $slug, string $text = 'x' ): string {
		return '<a href="' . home_url( "/{$slug}/" ) . '">' . $text . '</a>';
	}

	public function test_counts_inbound_outbound_external_and_words(): void {
		$b = $this->post( 'beta', 'Una página sin enlaces.' );
		$a = $this->post( 'alfa', 'Hola mundo. ' . $this->link( 'beta', 'la beta' ) . ' y <a href="https://otro.com/">otro</a>.' );
		$c = $this->post( 'gamma', $this->link( 'beta' ) . $this->link( 'beta', 'otra vez' ) );

		$this->indexer->index_many( array( $a, $b, $c ) );

		$doc_b = $this->doc_row( $b );
		$this->assertSame( '2', $doc_b['inbound'], 'Cuenta entradas distintas que enlazan.' );
		$this->assertSame( '0', $doc_b['outbound'] );

		$doc_a = $this->doc_row( $a );
		$this->assertSame( '1', $doc_a['outbound'] );
		$this->assertSame( '1', $doc_a['external'] );
		$this->assertSame( '0', $doc_a['inbound'] );
		$this->assertSame( '6', $doc_a['word_count'] );
		$this->assertSame( 'post', $doc_a['post_type'] );
		$this->assertSame( 'en', $doc_a['lang'] );
		$this->assertSame( 40, strlen( $doc_a['content_hash'] ) );

		$this->assertSame( '2', $this->doc_row( $c )['outbound'], 'Cada aparición cuenta como saliente.' );

		$rows = $this->link_rows( $a );
		$this->assertSame( (string) $b, $rows[0]['target_id'] );
		$this->assertSame( 'la beta', $rows[0]['anchor'] );
		$this->assertSame( '1', $rows[0]['is_internal'] );
		$this->assertSame( '0', $rows[0]['is_broken'] );
	}

	public function test_self_links_do_not_count(): void {
		$a = $this->post( 'solo' );
		wp_update_post(
			array(
				'ID'           => $a,
				'post_content' => $this->link( 'solo' ),
			)
		);

		$this->indexer->index_and_refresh( $a );

		$doc = $this->doc_row( $a );
		$this->assertSame( '0', $doc['inbound'] );
		$this->assertSame( '0', $doc['outbound'] );
	}

	public function test_unchanged_content_is_skipped_unless_forced(): void {
		$a = $this->post( 'alfa', 'Texto.' );

		$this->assertSame( IndexOutcome::INDEXED, $this->indexer->index_post( $a )->status );
		$this->assertSame( IndexOutcome::UNCHANGED, $this->indexer->index_post( $a )->status );
		$this->assertSame( IndexOutcome::INDEXED, $this->indexer->index_post( $a, true )->status );
	}

	public function test_detects_broken_links_with_their_reason_and_ignores_valid_non_post_urls(): void {
		$trashed = $this->post( 'papelera' );
		$draft   = $this->post( 'borrador', '', array( 'post_status' => 'draft' ) );
		$private = $this->post( 'privada', '', array( 'post_status' => 'private' ) );
		wp_trash_post( $trashed );

		$html = $this->link( 'papelera' ) . $this->link( 'borrador' ) . $this->link( 'privada' ) . $this->link( 'no-existe' )
			. '<a href="' . home_url( '/category/uncategorized/' ) . '">cat</a>'
			. '<a href="' . home_url( '/' ) . '">home</a>'
			. '<a href="' . home_url( '/?s=algo' ) . '">busqueda</a>'
			. '<a href="' . home_url( '/feed/' ) . '">feed</a>';
		$a    = $this->post( 'origen', $html );

		$this->indexer->index_and_refresh( $a );

		$broken = array();
		foreach ( $this->link_rows( $a ) as $row ) {
			$broken[ basename( rtrim( $row['target_url'], '/' ) ) ] = (int) $row['is_broken'];
		}

		$this->assertSame( BrokenReason::TRASHED, $broken['papelera'] );
		$this->assertSame( BrokenReason::UNPUBLISHED, $broken['borrador'] );
		$this->assertSame( BrokenReason::PRIVATE_POST, $broken['privada'] );
		$this->assertSame( BrokenReason::NOT_FOUND, $broken['no-existe'] );
		$this->assertSame( 0, $broken['uncategorized'] );
		$this->assertSame( 0, $broken['feed'] );
		$this->assertSame( 4, (int) $this->doc_row( $a )['broken'] );
		$this->assertSame( '8', $this->doc_row( $a )['outbound'] );
		unset( $draft, $private );
	}

	public function test_old_slugs_still_resolve(): void {
		$target = $this->post( 'nuevo' );
		add_post_meta( $target, '_wp_old_slug', 'viejo' );
		$a = $this->post( 'origen', $this->link( 'viejo' ) );

		$this->indexer->index_and_refresh( $a );

		$this->assertSame( '0', $this->doc_row( $a )['broken'] );
		$this->assertSame( (string) $target, $this->link_rows( $a )[0]['target_id'] );
	}

	public function test_query_string_urls_resolve_by_id(): void {
		$target = $this->post( 'destino' );
		$draft  = $this->post( 'boceto', '', array( 'post_status' => 'draft' ) );
		$a      = $this->post( 'origen', '<a href="' . home_url( "/?p={$target}" ) . '">a</a><a href="' . home_url( "/?p={$draft}" ) . '">b</a><a href="' . home_url( '/?p=999999' ) . '">c</a>' );

		$this->indexer->index_and_refresh( $a );

		$rows = $this->link_rows( $a );
		$this->assertSame( '0', $rows[0]['is_broken'] );
		$this->assertSame( (string) BrokenReason::UNPUBLISHED, $rows[1]['is_broken'] );
		$this->assertSame( (string) BrokenReason::NOT_FOUND, $rows[2]['is_broken'] );
	}

	public function test_dependents_are_found_and_recovered_when_a_draft_is_published(): void {
		$draft = $this->post( 'pronto', 'Texto de la futura entrada.', array( 'post_status' => 'draft' ) );
		$a     = $this->post( 'origen', $this->link( 'pronto' ) );
		$this->indexer->index_and_refresh( $a );
		$this->assertSame( '1', $this->doc_row( $a )['broken'] );

		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'publish',
			)
		);
		$this->indexer->flush();

		$this->assertSame( array( $a ), $this->indexer->dependents_of( $draft ), 'La que tiene un enlace roto con su slug.' );

		$this->indexer->index_many( array( $draft, $a ), true );

		$this->assertSame( '0', $this->doc_row( $a )['broken'] );
		$this->assertSame( '1', $this->doc_row( $draft )['inbound'] );
	}

	public function test_a_trashed_target_breaks_its_sources_after_reindexing_them(): void {
		$b = $this->post( 'beta' );
		$a = $this->post( 'alfa', $this->link( 'beta' ) );
		$this->indexer->index_many( array( $a, $b ) );
		$this->assertSame( '1', $this->doc_row( $b )['inbound'] );

		wp_trash_post( $b );
		$this->indexer->flush();
		$dependents = $this->indexer->dependents_of( $b );
		$this->assertSame( array( $a ), $dependents );

		$this->assertSame( IndexOutcome::REMOVED, $this->indexer->index_and_refresh( $b )->status );
		$this->indexer->index_many( $dependents, true );

		$this->assertNull( $this->doc_row( $b ) );
		$this->assertSame( '1', $this->doc_row( $a )['broken'] );
		$this->assertSame( (string) BrokenReason::TRASHED, $this->link_rows( $a )[0]['is_broken'] );
	}

	public function test_only_published_posts_of_the_chosen_types_are_indexed(): void {
		$draft = $this->post( 'borrador', 'x', array( 'post_status' => 'draft' ) );
		$page  = $this->post( 'pagina', 'x', array( 'post_type' => 'page' ) );
		$this->assertSame( IndexOutcome::SKIPPED, $this->indexer->index_post( $draft )->status );
		$this->assertSame( IndexOutcome::INDEXED, $this->indexer->index_post( $page )->status );

		Plugin::container()->get( Settings::class )->update( array( 'post_types' => array( 'post' ) ) );
		$this->assertSame( IndexOutcome::REMOVED, $this->indexer->index_post( $page )->status );
		$this->assertNull( $this->doc_row( $page ) );
	}

	public function test_purge_out_of_scope_removes_deleted_and_unpublished(): void {
		$a = $this->post( 'alfa', 'x' );
		$b = $this->post( 'beta', $this->link( 'alfa' ) );
		$this->indexer->index_many( array( $a, $b ) );
		$this->assertSame( '1', $this->doc_row( $a )['inbound'] );

		wp_update_post(
			array(
				'ID'          => $b,
				'post_status' => 'draft',
			)
		);
		$purged = $this->indexer->repository()->purge_out_of_scope( array( 'post' ) );

		$this->assertSame( 1, $purged );
		$this->assertNull( $this->doc_row( $b ) );
		$this->assertSame( '0', $this->doc_row( $a )['inbound'] );
	}

	public function test_language_comes_from_the_language_service(): void {
		add_filter( 'magiclinking_post_language', static fn() => 'es' );
		$a = $this->post( 'alfa', 'x' );

		$this->indexer->index_post( $a );

		$this->assertSame( 'es', $this->doc_row( $a )['lang'] );
	}

	public function test_settings_defaults_and_sanitising(): void {
		$settings = new Settings();

		$this->assertSame( array( 'post', 'page' ), $settings->post_types() );
		$this->assertSame( 2, $settings->low_inbound_threshold() );
		$this->assertSame( 100, $settings->words_per_link() );
		$this->assertFalse( $settings->all()['delete_data_on_uninstall'] );

		$saved = $settings->update(
			array(
				'post_types'            => array( 'page', 'inventado', 'attachment' ),
				'low_inbound_threshold' => 999,
				'words_per_link'        => 1,
			)
		);

		$this->assertSame( array( 'page' ), $saved['post_types'] );
		$this->assertSame( 20, $saved['low_inbound_threshold'] );
		$this->assertSame( 20, $saved['words_per_link'] );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'magiclinking_settings' ) ); // phpcs:ignore WordPress.DB
		$this->assertContains( $autoload, array( 'yes', 'on', 'auto' ) );
	}
}
