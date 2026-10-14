<?php
/**
 * Arreglar un enlace roto: cambiar el destino o quitarlo, por la vía segura (docs/06 §9).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Fix;

use MagicLinking\Content\FixRequest;
use MagicLinking\Content\InsertionException;
use MagicLinking\Core\Plugin;
use MagicLinking\History\ChangeRepository;
use MagicLinking\History\UndoResult;

final class FixTest extends FixTestCase {

	public function test_retarget_changes_only_the_href_and_keeps_text_and_attributes(): void {
		$before = $this->p( 'Antes. ' . $this->a( 'mira <strong>esto</strong> bien', 'no-existe', ' class="x" target="_blank" rel="noopener"' ) . ' Después.' ) . "\n\n" . $this->p( 'Otro párrafo intacto.' );
		$post   = $this->post( $before );
		$ids    = $this->broken_ids( $post );
		$this->assertCount( 1, $ids );

		$seen = array();
		add_action(
			'magiclinking_link_fixed',
			static function ( $change, $post_id, $batch, $action ) use ( &$seen ): void {
				$seen[] = array( $post_id, $action );
			},
			10,
			4
		);

		$result = $this->fix( $ids[0] );

		$expected = str_replace( home_url( '/no-existe/' ), get_permalink( $this->target ), $before );
		$this->assertSame( $expected, $this->stored( $post ), 'Solo cambia el href.' );
		$this->assertSame( array( array( $post, 'retarget' ) ), $seen );

		$row = Plugin::container()->get( ChangeRepository::class )->get( $result->change_id );
		$this->assertSame( ChangeRepository::RETARGET, $row['action'] );
		$this->assertStringContainsString( '/no-existe/', $row['before_html'] );
		$this->assertStringContainsString( 'aire-acondicionado', $row['after_html'] );

		$this->assertSame( array(), $this->broken_ids( $post ), 'El grafo ya no tiene el enlace roto.' );
		$rows = $this->link_rows( $post );
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $this->target, (string) $rows[0]['target_id'] );
	}

	public function test_unlink_keeps_the_text_and_inline_formatting(): void {
		$before = $this->p( 'Antes ' . $this->a( 'texto <em>con</em> formato' ) . ' después.' );
		$post   = $this->post( $before );

		$this->fix( $this->broken_ids( $post )[0], FixRequest::UNLINK );

		$this->assertSame( $this->p( 'Antes texto <em>con</em> formato después.' ), $this->stored( $post ) );
		$this->assertSame( array(), $this->link_rows( $post ) );
	}

	public function test_the_same_broken_url_repeated_only_touches_the_chosen_occurrence(): void {
		$list = "<!-- wp:list -->\n<ul><!-- wp:list-item -->\n<li>" . $this->a( 'tercera' ) . "</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->";
		$doc  = implode( "\n\n", array( $this->p( $this->a( 'primera' ) ), $this->p( $this->a( 'segunda' ) . ' y ' . $this->a( 'otra', 'otra-rota' ) ), $list ) );
		$post = $this->post( $doc );
		$ids  = $this->broken_ids( $post );
		$this->assertCount( 4, $ids );

		// La segunda aparición de /no-existe/ (ordinal 1): la fila que tiene el ancla «segunda».
		$second = null;
		foreach ( $this->link_rows( $post ) as $row ) {
			if ( 'segunda' === $row['anchor'] ) {
				$second = (int) $row['id'];
			}
		}
		$this->assertNotNull( $second );

		$this->fix( $second );

		$new = $this->stored( $post );
		$this->assertSame( 1, substr_count( $new, get_permalink( $this->target ) ), 'Solo una aparición cambia.' );
		$this->assertStringContainsString( '<a href="' . get_permalink( $this->target ) . '">segunda</a>', $new );
		$this->assertSame( 2, substr_count( $new, home_url( '/no-existe/' ) ), 'Las otras dos siguen rotas.' );
		$this->assertStringContainsString( '>primera</a>', $new );

		// Quitar la tercera (en un elemento de lista), ya con el grafo reindexado.
		$third = null;
		foreach ( $this->link_rows( $post ) as $row ) {
			if ( 'tercera' === $row['anchor'] ) {
				$third = (int) $row['id'];
			}
		}
		$this->assertNotNull( $third );
		$this->fix( (int) $third, FixRequest::UNLINK );

		$after = $this->stored( $post );
		$this->assertStringContainsString( '<li>tercera</li>', $after );
		$this->assertSame( 1, substr_count( $after, home_url( '/no-existe/' ) ), 'Solo queda «primera».' );
	}

	public function test_a_link_inside_nested_blocks_is_fixed(): void {
		$inner = $this->p( 'Dentro ' . $this->a( 'anidado' ) . ' del grupo.' );
		$doc   = "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:columns -->\n<div class=\"wp-block-columns\"><!-- wp:column -->\n<div class=\"wp-block-column\">{$inner}</div>\n<!-- /wp:column --></div>\n<!-- /wp:columns --></div>\n<!-- /wp:group -->";
		$post  = $this->post( $doc );

		$this->fix( $this->broken_ids( $post )[0] );

		$this->assertSame( str_replace( home_url( '/no-existe/' ), (string) get_permalink( $this->target ), $doc ), $this->stored( $post ) );
	}

	public function test_classic_content_is_fixed_and_the_rest_stays_byte_identical(): void {
		$doc  = "Primera línea  con espacios.\r\n\r\nUn párrafo con " . $this->a( 'enlace roto' ) . " y más.\n\n<img src=\"x.png\" alt=\"\">\n\nFin.";
		$post = $this->post( $doc );

		$result = $this->fix( $this->broken_ids( $post )[0] );
		$this->assertSame( str_replace( home_url( '/no-existe/' ), (string) get_permalink( $this->target ), $doc ), $this->stored( $post ) );

		$row = Plugin::container()->get( ChangeRepository::class )->get( $result->change_id );
		$this->assertStringStartsWith( '@', (string) $row['block_path'], 'En clásico se guarda el tramo entre líneas en blanco.' );
		$this->assertStringNotContainsString( 'Primera línea', $row['before_html'] );

		$classic = $this->post( 'Texto ' . $this->a( 'roto' ) . ' fin.' );
		$this->fix( $this->broken_ids( $classic )[0], FixRequest::UNLINK );
		$this->assertSame( 'Texto roto fin.', $this->stored( $classic ) );
	}

	public function test_a_button_can_change_destination_but_not_lose_its_link(): void {
		$button = "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"" . home_url( '/no-existe/' ) . "\">Comprar</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->";
		$post   = $this->post( $button );
		$id     = $this->broken_ids( $post )[0];

		$this->assertSame( InsertionException::BLOCK_NOT_ALLOWED, $this->refusal( fn() => $this->fix( $id, FixRequest::UNLINK ) ) );
		$this->assertSame( $button, $this->stored( $post ) );

		$this->fix( $id );
		$this->assertSame( str_replace( home_url( '/no-existe/' ), (string) get_permalink( $this->target ), $button ), $this->stored( $post ) );
	}

	public function test_a_block_that_repeats_the_address_in_its_settings_is_refused(): void {
		$file = '<!-- wp:file {"href":"' . home_url( '/no-existe/' ) . "\"} -->\n<div class=\"wp-block-file\"><a href=\"" . home_url( '/no-existe/' ) . "\">Guía</a></div>\n<!-- /wp:file -->";
		$post = $this->post( $file );

		$this->assertSame( InsertionException::UNSUPPORTED, $this->refusal( fn() => $this->fix( $this->broken_ids( $post )[0] ) ) );
		$this->assertSame( $file, $this->stored( $post ) );
	}

	public function test_a_bound_block_is_refused(): void {
		$bound = "<!-- wp:paragraph {\"metadata\":{\"bindings\":{\"content\":{\"source\":\"core/post-meta\",\"args\":{\"key\":\"k\"}}}}} -->\n<p>" . $this->a( 'enlazado' ) . "</p>\n<!-- /wp:paragraph -->";
		$post  = $this->post( $bound );

		$this->assertSame( InsertionException::BOUND_BLOCK, $this->refusal( fn() => $this->fix( $this->broken_ids( $post )[0], FixRequest::UNLINK ) ) );
		$this->assertSame( $bound, $this->stored( $post ) );
	}

	public function test_a_stale_list_is_refused_without_writing(): void {
		$doc  = $this->p( $this->a( 'primera' ) ) . "\n\n" . $this->p( $this->a( 'segunda' ) );
		$post = $this->post( $doc );
		$ids  = $this->broken_ids( $post );
		$this->assertCount( 2, $ids );

		// Alguien quita el primer enlace y el grafo todavía no lo sabe: la lista ya no cuadra.
		$edited = $this->p( 'primera' ) . "\n\n" . $this->p( $this->a( 'segunda' ) );
		$this->raw_update( $post, $edited );

		$changes = Plugin::container()->get( ChangeRepository::class );
		$this->assertSame( InsertionException::LINK_GONE, $this->refusal( fn() => $this->fix( $ids[1] ) ) );
		$this->assertSame( InsertionException::LINK_GONE, $this->refusal( fn() => $this->fix( $ids[0], FixRequest::UNLINK ) ) );
		$this->assertSame( $edited, $this->stored( $post ), 'No se ha escrito nada.' );
		$this->assertSame( 0, $changes->count(), 'Ni se ha anotado nada.' );

		// El ancla distinta también delata el cambio, aunque el número de apariciones sea el mismo.
		$this->raw_update( $post, $this->p( $this->a( 'cambiada' ) ) . "\n\n" . $this->p( $this->a( 'segunda' ) ) );
		$this->assertSame( InsertionException::LINK_GONE, $this->refusal( fn() => $this->fix( $ids[0] ) ) );
	}

	public function test_markup_that_cannot_be_scanned_is_refused(): void {
		$doc  = $this->p( $this->a( 'roto' ) ) . "\n\n<!-- wp:html -->\n<script>var a = '<a href=\"" . home_url( '/no-existe/' ) . "\">falso</a>';</script>\n<!-- /wp:html -->";
		$post = $this->post( $doc );
		$ids  = $this->broken_ids( $post );

		$this->assertNotEmpty( $ids );
		$this->assertSame( InsertionException::UNSUPPORTED, $this->refusal( fn() => $this->fix( $ids[0] ) ) );
		$this->assertSame( $doc, $this->stored( $post ) );
	}

	public function test_it_aborts_when_wordpress_would_alter_more_than_the_link(): void {
		$doc  = $this->p( 'Hola ' . $this->a( 'roto' ) . '.' );
		$post = $this->post( $doc );
		add_filter( 'content_save_pre', static fn( $content ) => $content . "\n<!-- extra -->" );

		$this->assertSame( InsertionException::ALTERED, $this->refusal( fn() => $this->fix( $this->broken_ids( $post )[0] ) ) );
		$this->assertSame( $doc, $this->stored( $post ) );
		$this->assertSame( 0, Plugin::container()->get( ChangeRepository::class )->count(), 'La fila del historial se retira.' );
	}

	public function test_an_entry_open_in_an_editor_is_not_touched(): void {
		$doc   = $this->p( 'Hola ' . $this->a( 'roto' ) . '.' );
		$post  = $this->post( $doc );
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_post_meta( $post, '_edit_lock', time() . ':' . $other );

		$this->assertSame( InsertionException::LOCKED, $this->refusal( fn() => $this->fix( $this->broken_ids( $post )[0] ) ) );
		$this->assertSame( $doc, $this->stored( $post ) );
	}

	public function test_a_user_who_cannot_edit_the_source_is_refused(): void {
		$post   = $this->post( $this->p( 'Hola ' . $this->a( 'roto' ) . '.' ), array( 'post_author' => $this->admin ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );

		$this->assertSame( InsertionException::NOT_ALLOWED, $this->refusal( fn() => $this->fix( $this->broken_ids( $post )[0], FixRequest::UNLINK ) ) );
		$this->assertStringContainsString( '<a href=', $this->stored( $post ) );
	}

	public function test_a_fix_is_one_group_in_the_history_and_can_be_undone_and_redone(): void {
		$doc  = $this->p( 'Antes ' . $this->a( 'roto', 'no-existe', ' class="c"' ) . ' después.' ) . "\n\n" . $this->p( 'Otro.' );
		$post = $this->post( $doc );
		$id   = $this->broken_ids( $post )[0];

		$result = $this->fix( $id );
		$fixed  = $this->stored( $post );
		$this->assertNotSame( $doc, $fixed );

		$reader = Plugin::container()->get( \MagicLinking\History\Reader::class );
		$group  = $reader->group( $result->batch_id, $this->admin );
		$this->assertNotNull( $group );
		$this->assertSame( 'retarget', $group['kind'] );
		$this->assertSame( 1, $group['active'] );

		$items = $reader->changes_of( $result->batch_id, 1, 10 )['items'];
		$this->assertSame( 'roto', $items[0]['anchor'] );
		$this->assertSame( (string) get_permalink( $this->target ), $items[0]['url'] );
		$this->assertSame( home_url( '/no-existe/' ), $items[0]['previous_url'] );

		$undone = $this->undo()->revert( $result->change_id );
		$this->assertSame( UndoResult::RESTORED, $undone->status );
		$this->assertSame( $doc, $this->stored( $post ), 'Idéntico byte a byte.' );
		$this->assertCount( 1, $this->broken_ids( $post ), 'Vuelve a estar roto en el grafo.' );

		$redone = $this->redo()->redo( $result->change_id );
		$this->assertSame( UndoResult::REDONE, $redone->status );
		$this->assertSame( $fixed, $this->stored( $post ) );
		$this->assertSame( array(), $this->broken_ids( $post ) );
	}

	public function test_unlink_can_be_undone_and_redone_even_after_other_edits(): void {
		$doc  = $this->p( 'Antes ' . $this->a( 'roto' ) . ' después.' ) . "\n\n" . $this->p( 'Otro.' );
		$post = $this->post( $doc );

		$result = $this->fix( $this->broken_ids( $post )[0], FixRequest::UNLINK );
		$this->assertSame( $this->p( 'Antes roto después.' ) . "\n\n" . $this->p( 'Otro.' ), $this->stored( $post ) );

		// Se edita otra parte de la entrada: la huella ya no coincide, pero el tramo sigue siendo único.
		$this->raw_update( $post, $this->p( 'Antes roto después.' ) . "\n\n" . $this->p( 'Otro editado.' ) );
		$undone = $this->undo()->revert( $result->change_id );
		$this->assertSame( UndoResult::FIX_REVERTED, $undone->status );
		$this->assertSame( $this->p( 'Antes ' . $this->a( 'roto' ) . ' después.' ) . "\n\n" . $this->p( 'Otro editado.' ), $this->stored( $post ) );

		$redone = $this->redo()->redo( $result->change_id );
		$this->assertSame( UndoResult::REDONE, $redone->status );
		$this->assertSame( $this->p( 'Antes roto después.' ) . "\n\n" . $this->p( 'Otro editado.' ), $this->stored( $post ) );
	}

	public function test_undoing_a_retarget_after_the_entry_was_edited_only_puts_that_link_back(): void {
		$doc  = $this->p( 'Antes ' . $this->a( 'roto' ) . ' después.' ) . "\n\n" . $this->p( 'Otro.' );
		$post = $this->post( $doc );

		$result = $this->fix( $this->broken_ids( $post )[0] );
		$this->raw_update( $post, str_replace( 'Otro.', 'Otro editado.', $this->stored( $post ) ) );

		$undone = $this->undo()->revert( $result->change_id );
		$this->assertSame( UndoResult::FIX_REVERTED, $undone->status );
		$this->assertSame( str_replace( 'Otro.', 'Otro editado.', $doc ), $this->stored( $post ) );
	}

	public function test_undoing_when_the_link_was_changed_by_hand_asks_for_attention_or_is_gone(): void {
		$doc  = $this->p( 'Antes ' . $this->a( 'roto' ) . ' después.' );
		$post = $this->post( $doc );

		$result = $this->fix( $this->broken_ids( $post )[0] );
		$this->raw_update( $post, $this->p( 'Antes roto, ya sin enlace, después.' ) );

		$undone = $this->undo()->revert( $result->change_id );
		$this->assertSame( UndoResult::GONE, $undone->status );
		$this->assertSame( $this->p( 'Antes roto, ya sin enlace, después.' ), $this->stored( $post ) );

		// Quitar y luego editar justo ese tramo: no hay forma segura de volver a poner el enlace.
		$other = $this->post( $this->p( 'Hola ' . $this->a( 'roto' ) . '.' ) );
		$fixed = $this->fix( $this->broken_ids( $other )[0], FixRequest::UNLINK );
		$this->raw_update( $other, $this->p( 'Hola roto editado.' ) );
		$manual = $this->undo()->revert( $fixed->change_id );
		$this->assertSame( UndoResult::MANUAL, $manual->status );
		$this->assertSame( $this->p( 'Hola roto editado.' ), $this->stored( $other ) );
	}

	public function test_undo_after_edits_does_not_rewrite_another_link_with_the_same_tag(): void {
		$other = '<a href="' . get_permalink( $this->target ) . '">otro enlace</a>';
		$post  = $this->post( $this->p( 'Uno ' . $this->a( 'roto' ) . ' y ' . $other . '.' ) . "\n\n" . $this->p( 'Fin.' ) );

		$result = $this->fix( $this->broken_ids( $post )[0] );
		// Alguien quita a mano el enlace arreglado (queda su texto); el otro enlace sigue igual.
		$edited = $this->p( 'Uno roto y ' . $other . '.' ) . "\n\n" . $this->p( 'Fin editado.' );
		$this->raw_update( $post, $edited );

		$undone = $this->undo()->revert( $result->change_id );

		$this->assertSame( UndoResult::GONE, $undone->status, 'El enlace arreglado ya no está: no hay nada que deshacer.' );
		$this->assertSame( $edited, $this->stored( $post ), 'No se reescribe otro enlace.' );
	}

	public function test_undo_after_attributes_were_added_puts_back_only_the_href(): void {
		$post   = $this->post( $this->p( 'Uno ' . $this->a( 'roto' ) . '.' ) . "\n\n" . $this->p( 'Fin.' ) );
		$result = $this->fix( $this->broken_ids( $post )[0] );
		$url    = (string) get_permalink( $this->target );
		$this->raw_update( $post, str_replace( '<a href="' . $url . '">', '<a href="' . $url . '" target="_blank" rel="noopener">', str_replace( 'Fin.', 'Fin editado.', $this->stored( $post ) ) ) );

		$undone = $this->undo()->revert( $result->change_id );

		$this->assertSame( UndoResult::FIX_REVERTED, $undone->status );
		$this->assertSame( $this->p( 'Uno <a href="' . home_url( '/no-existe/' ) . '" target="_blank" rel="noopener">roto</a>.' ) . "\n\n" . $this->p( 'Fin editado.' ), $this->stored( $post ) );
	}

	public function test_undoing_a_fix_after_edits_fires_the_batch_undone_action(): void {
		$post   = $this->post( $this->p( 'Uno ' . $this->a( 'roto' ) . '.' ) . "\n\n" . $this->p( 'Fin.' ) );
		$result = $this->fix( $this->broken_ids( $post )[0] );
		$this->raw_update( $post, str_replace( 'Fin.', 'Fin editado.', $this->stored( $post ) ) );
		$seen = 0;
		add_action(
			'magiclinking_batch_undone',
			static function () use ( &$seen ): void {
				++$seen;
			}
		);

		$results = $this->undo()->revert_batch( $result->batch_id );

		$this->assertSame( UndoResult::FIX_REVERTED, $results[0]->status );
		$this->assertSame( 1, $seen );
		remove_all_actions( 'magiclinking_batch_undone' );
	}

	public function test_a_failing_listener_does_not_break_redo_and_messages_talk_about_the_fix(): void {
		$post   = $this->post( $this->p( 'Uno ' . $this->a( 'roto' ) . '.' ) );
		$result = $this->fix( $this->broken_ids( $post )[0] );

		$undone = $this->undo()->revert( $result->change_id );
		$this->assertSame( 'Destination restored.', $undone->message );

		add_action(
			'magiclinking_link_fixed',
			static function (): void {
				throw new \RuntimeException( 'oyente roto' );
			}
		);
		$redone = $this->redo()->redo( $result->change_id );
		$this->assertSame( UndoResult::REDONE, $redone->status );
		$this->assertSame( 'Destination changed again.', $redone->message );

		$unlinked = $this->fix( $this->broken_ids( $this->post( $this->p( 'Dos ' . $this->a( 'otro' ) . '.' ) ) )[0], FixRequest::UNLINK );
		$this->assertSame( 'Link put back.', $this->undo()->revert( $unlinked->change_id )->message );
		$this->assertSame( 'Link removed again.', $this->redo()->redo( $unlinked->change_id )->message );
	}

	public function test_manual_messages_for_a_fix_do_not_talk_about_removing_a_link(): void {
		$post  = $this->post( $this->p( 'Hola ' . $this->a( 'roto' ) . '.' ) );
		$fixed = $this->fix( $this->broken_ids( $post )[0], FixRequest::UNLINK );
		$this->raw_update( $post, $this->p( 'Hola roto editado.' ) );

		$this->assertSame( 'This post was edited afterwards; put the link back by hand.', $this->undo()->revert( $fixed->change_id )->message );
	}
}
