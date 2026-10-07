<?php
/**
 * Deshacer grupos conservados, con y sin ediciones posteriores, y rehacer (docs/06 §5).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\History;

use MagicLinking\Content\InsertionException;
use MagicLinking\Core\Plugin;
use MagicLinking\History\BatchId;
use MagicLinking\History\ChangeRepository;
use MagicLinking\History\Reader;
use MagicLinking\History\Redo;
use MagicLinking\History\Undo;
use MagicLinking\History\UndoResult;

final class UndoRedoTest extends HistoryTestCase {

	private function undo(): Undo {
		return Plugin::container()->get( Undo::class );
	}

	private function redo(): Redo {
		return Plugin::container()->get( Redo::class );
	}

	private function reader(): Reader {
		return Plugin::container()->get( Reader::class );
	}

	public function test_a_group_kept_for_weeks_can_still_be_undone_byte_for_byte(): void {
		$batch    = $this->batch( 3 );
		$original = array();
		foreach ( $batch['posts'] as $post ) {
			$original[ $post ] = $this->p( 'Compra aire acondicionado ya.' );
			$this->assertNotSame( $original[ $post ], $this->stored( $post ) );
		}
		$this->age( $batch['batch'], 80 );

		$group = $this->reader()->group( $batch['batch'], $this->admin );
		$this->assertNotNull( $group, 'Un grupo dentro de la conservación sigue en el historial.' );
		$this->assertSame( 3, $group['links'] );

		$results = $this->undo()->revert_batch( $batch['batch'] );

		$this->assertSame( array( UndoResult::RESTORED, UndoResult::RESTORED, UndoResult::RESTORED ), array_column( array_map( static fn( UndoResult $r ): array => array( 'status' => $r->status ), $results ), 'status' ) );
		foreach ( $original as $post => $content ) {
			$this->assertSame( $content, $this->stored( $post ) );
		}

		$group = $this->reader()->group( $batch['batch'], $this->admin );
		$this->assertSame( 0, $group['active'] );
		$this->assertSame( 3, $group['undone'] );
	}

	public function test_a_batch_with_later_edits_gives_a_mixed_result_and_touches_only_the_links_it_can(): void {
		$batch                              = $this->batch( 3 );
		[ $untouched, $edited, $ambiguous ] = $batch['posts'];

		// Editada después en otro párrafo: solo se quita el enlace.
		wp_update_post(
			array(
				'ID'           => $edited,
				'post_content' => wp_slash( $this->stored( $edited ) . "\n\n" . $this->p( 'Un párrafo nuevo.' ) ),
			)
		);

		// Editada después copiando el mismo enlace en otro sitio: dos coincidencias, no se toca.
		preg_match( '#<a href="[^"]+">aire acondicionado</a>#', $this->stored( $ambiguous ), $link );
		$twice = $this->stored( $ambiguous ) . "\n\n" . $this->p( 'Otra vez ' . $link[0] . '.' );
		wp_update_post(
			array(
				'ID'           => $ambiguous,
				'post_content' => wp_slash( $twice ),
			)
		);

		$results = $this->undo()->revert_batch( $batch['batch'] );
		$by_post = array();
		foreach ( $results as $result ) {
			$by_post[ $result->post_id ] = $result;
		}

		$this->assertSame( UndoResult::RESTORED, $by_post[ $untouched ]->status );
		$this->assertSame( $this->p( 'Compra aire acondicionado ya.' ), $this->stored( $untouched ) );

		$this->assertSame( UndoResult::LINK_REMOVED, $by_post[ $edited ]->status );
		$this->assertSame( $this->p( 'Compra aire acondicionado ya.' ) . "\n\n" . $this->p( 'Un párrafo nuevo.' ), $this->stored( $edited ) );

		$this->assertSame( UndoResult::MANUAL, $by_post[ $ambiguous ]->status );
		$this->assertSame( InsertionException::EDITED_AFTER, $by_post[ $ambiguous ]->reason );
		$this->assertStringContainsString( 'post=' . $ambiguous, (string) $by_post[ $ambiguous ]->edit_url );
		$this->assertSame( $twice, $this->stored( $ambiguous ), 'Lo que no se puede deshacer con seguridad no se toca.' );

		$group = $this->reader()->group( $batch['batch'], $this->admin );
		$this->assertSame( 2, $group['undone'] );
		$this->assertSame( 1, $group['active'], 'El enlace que quedó por quitar sigue contando como puesto.' );
	}

	public function test_redo_puts_the_same_link_back_and_it_can_be_undone_again(): void {
		$linked = $this->link();
		$with   = $this->stored( $linked['post'] );

		$this->assertSame( UndoResult::RESTORED, $this->undo()->revert( $linked['change'] )->status );
		$this->assertNotSame( $with, $this->stored( $linked['post'] ) );

		$redone = $this->redo()->redo( $linked['change'] );
		$this->assertSame( UndoResult::REDONE, $redone->status );
		$this->assertSame( $with, $this->stored( $linked['post'] ), 'Rehacer deja el contenido exactamente como estaba con el enlace.' );

		// Un enlace, aunque haya tres filas: insert (deshecha), remove e insert nueva.
		$this->assertSame( 3, $this->rows( $linked['batch'] ) );
		$group = $this->reader()->group( $linked['batch'], $this->admin );
		$this->assertSame( 1, $group['links'] );
		$this->assertSame( 1, $group['active'] );
		$this->assertSame( 0, $group['undone'] );

		$page = $this->reader()->changes_of( $linked['batch'], 1, 50 );
		$this->assertCount( 1, $page['items'] );
		$this->assertSame( 'active', $page['items'][0]['state'] );
		$this->assertSame( 'aire acondicionado', $page['items'][0]['anchor'] );
		$this->assertSame( (string) get_permalink( $this->target ), $page['items'][0]['url'] );

		// La fila nueva también se puede deshacer.
		$latest = $page['items'][0]['id'];
		$this->assertNotSame( $linked['change'], $latest );
		$this->assertSame( UndoResult::RESTORED, $this->undo()->revert( $latest )->status );
		$this->assertSame( $this->p( 'Compra aire acondicionado ya.' ), $this->stored( $linked['post'] ) );
	}

	public function test_redo_does_not_run_twice_nor_on_a_link_that_was_never_undone(): void {
		$linked = $this->link();

		$never = $this->redo()->redo( $linked['change'] );
		$this->assertSame( UndoResult::FAILED, $never->status );

		$this->undo()->revert( $linked['change'] );
		$this->assertSame( UndoResult::REDONE, $this->redo()->redo( $linked['change'] )->status );

		$again = $this->redo()->redo( $linked['change'] );
		$this->assertSame( UndoResult::FAILED, $again->status );
		$this->assertSame( 3, $this->rows( $linked['batch'] ), 'La segunda petición no escribe nada.' );
	}

	public function test_redo_is_refused_if_the_block_was_edited_after_undoing(): void {
		$linked = $this->link();
		$this->undo()->revert( $linked['change'] );

		$edited = $this->p( 'Compra un climatizador ya.' );
		wp_update_post(
			array(
				'ID'           => $linked['post'],
				'post_content' => wp_slash( $edited ),
			)
		);

		$result = $this->redo()->redo( $linked['change'] );

		$this->assertSame( UndoResult::MANUAL, $result->status );
		$this->assertStringContainsString( 'post=' . $linked['post'], (string) $result->edit_url );
		$this->assertSame( $edited, $this->stored( $linked['post'] ) );
	}

	public function test_redo_survives_edits_elsewhere_in_the_entry(): void {
		$linked = $this->link();
		$this->undo()->revert( $linked['change'] );

		wp_update_post(
			array(
				'ID'           => $linked['post'],
				'post_content' => wp_slash( $this->stored( $linked['post'] ) . "\n\n" . $this->p( 'Un párrafo nuevo.' ) ),
			)
		);

		$this->assertSame( UndoResult::REDONE, $this->redo()->redo( $linked['change'] )->status );
		$this->assertStringContainsString( 'Un párrafo nuevo.', $this->stored( $linked['post'] ) );
		$this->assertStringContainsString( '<a href=', $this->stored( $linked['post'] ) );
	}

	public function test_redo_respects_the_entry_being_open_in_the_editor(): void {
		$linked = $this->link();
		$this->undo()->revert( $linked['change'] );
		update_post_meta( $linked['post'], '_edit_lock', time() . ':' . $this->admin );

		$result = $this->redo()->redo( $linked['change'] );

		$this->assertSame( UndoResult::FAILED, $result->status );
		$this->assertSame( InsertionException::LOCKED, $result->reason );
		$this->assertSame( $this->p( 'Compra aire acondicionado ya.' ), $this->stored( $linked['post'] ) );
	}

	public function test_redo_batch_only_touches_the_undone_links(): void {
		$batch = $this->batch( 3 );
		$this->undo()->revert( $batch['changes'][0] );
		$this->undo()->revert( $batch['changes'][2] );

		$results = $this->redo()->redo_batch( $batch['batch'] );

		$this->assertCount( 2, $results );
		foreach ( $results as $result ) {
			$this->assertSame( UndoResult::REDONE, $result->status );
		}
		foreach ( $batch['posts'] as $post ) {
			$this->assertStringContainsString( '<a href=', $this->stored( $post ) );
		}
		$this->assertSame( 3, $this->reader()->group( $batch['batch'], $this->admin )['active'] );
	}

	public function test_the_hooks_fire_for_undo_and_redo(): void {
		$linked = $this->link();
		$events = array();
		add_action(
			'magiclinking_link_inserted',
			static function ( int $change, int $post, string $batch ) use ( &$events ): void {
				$events[] = array( 'inserted', $batch );
			},
			10,
			3
		);
		add_action(
			'magiclinking_batch_undone',
			static function ( string $batch, int $undone ) use ( &$events ): void {
				$events[] = array( 'undone', $batch, $undone );
			},
			10,
			2
		);

		$this->undo()->revert_batch( $linked['batch'] );
		$this->redo()->redo( $linked['change'] );

		$this->assertSame(
			array(
				array( 'undone', $linked['batch'], 1 ),
				array( 'inserted', $linked['batch'] ),
			),
			$events
		);
	}

	public function test_groups_are_listed_newest_first_with_a_cursor_and_no_cap(): void {
		$ids = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$id    = BatchId::generate( 1_790_000_000_000 + $i * 1000 );
			$ids[] = $id;
			$this->batch( 1, $id );
		}
		$expected = array_reverse( $ids );

		$seen   = array();
		$cursor = null;
		$pages  = 0;
		do {
			$page = $this->reader()->groups( $this->admin, $cursor, 3 );
			++$pages;
			foreach ( $page['items'] as $group ) {
				$seen[] = $group['batch_id'];
			}
			$cursor = $page['next'];
		} while ( null !== $cursor );

		$this->assertSame( $expected, $seen );
		$this->assertSame( 3, $pages );
	}

	public function test_a_user_only_sees_the_groups_whose_entries_they_can_edit(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$mine   = $this->post( array( 'post_author' => $author ) );
		$theirs = $this->post( array( 'post_author' => $this->admin ) );

		$own     = $this->link( $mine, BatchId::generate( 1_790_000_001_000 ) );
		$other   = $this->link( $theirs, BatchId::generate( 1_790_000_002_000 ) );
		$mixed   = $this->link( $this->post( array( 'post_author' => $author ) ), BatchId::generate( 1_790_000_003_000 ) );
		$strange = $this->post( array( 'post_author' => $this->admin ) );
		$this->link( $strange, $mixed['batch'] );

		$as_author = array_column( $this->reader()->groups( $author, null, 20 )['items'], 'batch_id' );
		$as_admin  = array_column( $this->reader()->groups( $this->admin, null, 20 )['items'], 'batch_id' );

		$this->assertSame( array( $own['batch'] ), $as_author, 'Un grupo con una entrada ajena no se muestra entero.' );
		$this->assertSame( array( $mixed['batch'], $other['batch'], $own['batch'] ), $as_admin );
		$this->assertSame( array( $strange ), $this->reader()->not_editable( $mixed['batch'], $author ) );
	}

	public function test_a_deleted_entry_is_decided_by_edit_others_posts(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$linked = $this->link( $this->post( array( 'post_author' => $author ) ) );
		wp_delete_post( $linked['post'], true );

		$this->assertNotNull( $this->reader()->group( $linked['batch'], $this->admin ) );
		$this->assertNull( $this->reader()->group( $linked['batch'], $author ) );

		$result = $this->undo()->revert( $linked['change'] );
		$this->assertSame( UndoResult::FAILED, $result->status, 'No hay nada que escribir en una entrada borrada.' );
		$this->assertSame( InsertionException::NO_POST, $result->reason );
	}

	public function test_the_changes_of_a_group_are_paged_without_html(): void {
		$batch = $this->batch( 5 );

		$first = $this->reader()->changes_of( $batch['batch'], 1, 2 );
		$last  = $this->reader()->changes_of( $batch['batch'], 3, 2 );

		$this->assertSame( 5, $first['total'] );
		$this->assertCount( 2, $first['items'] );
		$this->assertCount( 1, $last['items'] );
		$this->assertSame( $batch['changes'][0], $first['items'][0]['id'] );
		$this->assertSame( $batch['changes'][4], $last['items'][0]['id'] );
		$this->assertArrayNotHasKey( 'before_html', $first['items'][0] );
		$this->assertArrayNotHasKey( 'after_html', $first['items'][0] );
	}

	public function test_the_history_repository_returns_nothing_for_unknown_groups(): void {
		$changes = Plugin::container()->get( ChangeRepository::class );

		$this->assertSame( array(), $changes->inserts_of( array() ) );
		$this->assertSame( array(), $changes->inserts_of( array( BatchId::generate() ) ) );
		$this->assertNull( $this->reader()->group( BatchId::generate(), $this->admin ) );
	}
}
