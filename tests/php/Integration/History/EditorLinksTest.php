<?php
/**
 * Registro en el Historial de los enlaces puestos en el editor (F1-34, D-58).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\History;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\History\ChangeRepository;
use MagicLinking\History\EditorLinks;
use MagicLinking\History\Reader;
use MagicLinking\History\Redo;
use MagicLinking\History\Undo;
use MagicLinking\History\UndoResult;
use WP_REST_Request;
use WP_REST_Response;

final class EditorLinksTest extends HistoryTestCase {

	private const BASE = '/magic-linking/v1';

	private int $author = 0;

	private int $subscriber = 0;

	public function set_up(): void {
		parent::set_up();

		$this->author     = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Llama a la ruta como un usuario.
	 *
	 * @param array    $body Cuerpo.
	 * @param int|null $user Usuario; null = el administrador.
	 */
	private function post_editor( array $body, ?int $user = null ): WP_REST_Response {
		wp_set_current_user( $user ?? $this->admin );
		$request = new WP_REST_Request( 'POST', self::BASE . '/history/editor' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Un enlace del cliente.
	 *
	 * @param string $id     Identificador de la inserción.
	 * @param string $anchor Ancla.
	 * @param int    $target Destino; 0 = el de la prueba.
	 *
	 * @return array{insertion_id: string, suggestion: string, target_id: int, anchor: string}
	 */
	private function client_link( string $id = 'ins-00000001', string $anchor = 'aire acondicionado', int $target = 0 ): array {
		return array(
			'insertion_id' => $id,
			'suggestion'   => '1:2:10:' . $anchor,
			'target_id'    => 0 === $target ? $this->target : $target,
			'anchor'       => $anchor,
		);
	}

	/**
	 * Lo que queda guardado cuando el usuario ha enlazado en el editor: el enlace tal como lo escribe Gutenberg.
	 *
	 * @param string $inner  Contenido del enlace.
	 * @param array  $extra  Campos extra de la entrada.
	 * @param string $before Texto antes.
	 * @param string $after  Texto después.
	 */
	private function saved_with_link( string $inner = 'aire acondicionado', array $extra = array(), string $before = 'Compra ', string $after = ' ya.' ): int {
		$href = (string) get_permalink( $this->target );

		return $this->post( array_merge( array( 'post_content' => $this->p( $before . '<a href="' . $href . '">' . $inner . '</a>' . $after ) ), $extra ) );
	}

	private function rows_of( int $post ): array {
		global $wpdb;
		$table = \MagicLinking\Core\Schema::table( $wpdb->prefix, 'changes' );

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY id', $table, $post ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function reader(): Reader {
		return Plugin::container()->get( Reader::class );
	}

	// ------------------------------------------------------------------ Registro.

	public function test_a_saved_link_is_recorded_as_an_editor_insertion_without_writing_the_post(): void {
		$post     = $this->saved_with_link();
		$content  = $this->stored( $post );
		$modified = (string) get_post_field( 'post_modified_gmt', $post );
		$revs     = count( wp_get_post_revisions( $post ) );

		$response = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link() ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'recorded', $data['results'][0]['status'] );
		$this->assertNotNull( $data['batch_id'] );

		$this->assertSame( $content, $this->stored( $post ), 'Registrar no escribe en la entrada.' );
		$this->assertSame( $modified, (string) get_post_field( 'post_modified_gmt', $post ) );
		$this->assertCount( $revs, wp_get_post_revisions( $post ) );

		$rows = $this->rows_of( $post );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'insert', $rows[0]['action'] );
		$this->assertSame( 'editor', $rows[0]['origin'] );
		$this->assertSame( $data['batch_id'], $rows[0]['batch_id'] );
		$this->assertSame( $this->p( 'Compra aire acondicionado ya.' ), $rows[0]['before_html'] );
		$this->assertSame( $content, $rows[0]['after_html'] );
		$this->assertSame( $this->admin, (int) $rows[0]['user_id'] );

		$group = $this->reader()->group( (string) $data['batch_id'], $this->admin );
		$this->assertSame( 'editor', $group['origin'] );
		$this->assertSame( 1, $group['links'] );
		$this->assertSame( 1, $group['active'] );

		$page = $this->reader()->changes_of( (string) $data['batch_id'], 1, 50 );
		$this->assertSame( 'editor', $page['items'][0]['origin'] );
		$this->assertSame( 'aire acondicionado', $page['items'][0]['anchor'] );
		$this->assertSame( (string) get_permalink( $this->target ), $page['items'][0]['url'] );
	}

	public function test_server_inserted_links_stay_marked_as_plugin(): void {
		$linked = $this->link();

		$group = $this->reader()->group( $linked['batch'], $this->admin );
		$this->assertSame( 'plugin', $group['origin'] );
		$this->assertSame( 'plugin', $this->reader()->changes_of( $linked['batch'], 1, 50 )['items'][0]['origin'] );
	}

	public function test_several_links_in_one_request_go_into_one_batch(): void {
		$other = self::factory()->post->create(
			array(
				'post_title'  => 'Calefacción',
				'post_name'   => 'calefaccion',
				'post_status' => 'publish',
			)
		);
		$a     = (string) get_permalink( $this->target );
		$b     = (string) get_permalink( $other );
		$post  = $this->post(
			array(
				'post_content' => $this->p( 'Compra <a href="' . $a . '">aire acondicionado</a> ya.' ) . "\n\n" . $this->p( 'Mira la <a href="' . $b . '">calefacción</a> también.' ),
			)
		);

		$data = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link(), $this->client_link( 'ins-00000002', 'calefacción', $other ) ),
			)
		)->get_data();

		$this->assertSame( array( 'recorded', 'recorded' ), array_column( $data['results'], 'status' ) );
		$this->assertSame( 2, $this->reader()->group( (string) $data['batch_id'], $this->admin )['links'] );
		$this->assertCount( 2, $this->rows_of( $post ) );
	}

	public function test_saving_again_does_not_record_the_same_link_twice(): void {
		$post = $this->saved_with_link();
		$body = array(
			'post_id' => $post,
			'links'   => array( $this->client_link() ),
		);

		$first  = $this->post_editor( $body )->get_data();
		$second = $this->post_editor( $body )->get_data();

		$this->assertSame( 'duplicate', $second['results'][0]['status'] );
		$this->assertSame( $first['results'][0]['change_id'], $second['results'][0]['change_id'] );
		$this->assertNull( $second['batch_id'] );
		$this->assertCount( 1, $this->rows_of( $post ) );

		// Otro identificador de inserción del mismo enlace ya puesto: tampoco se duplica.
		$again = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link( 'ins-99999999' ) ),
			)
		)->get_data();
		$this->assertSame( 'duplicate', $again['results'][0]['status'] );
		$this->assertCount( 1, $this->rows_of( $post ) );
	}

	public function test_a_link_that_is_not_in_the_saved_content_is_not_recorded(): void {
		$post = $this->post();

		$data = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link() ),
			)
		)->get_data();

		$this->assertSame( 'not_found', $data['results'][0]['status'] );
		$this->assertNull( $data['batch_id'] );
		$this->assertSame( array(), $this->rows_of( $post ) );
	}

	public function test_other_anchor_other_target_or_a_link_inside_another_link_is_not_recorded(): void {
		$post = $this->saved_with_link();

		$wrong_anchor = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link( 'ins-00000003', 'aire' ) ),
			)
		)->get_data();
		$this->assertSame( 'not_found', $wrong_anchor['results'][0]['status'] );

		$elsewhere = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$wrong_url = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link( 'ins-00000004', 'aire acondicionado', $elsewhere ) ),
			)
		)->get_data();
		$this->assertSame( 'not_found', $wrong_url['results'][0]['status'] );
		$this->assertSame( array(), $this->rows_of( $post ) );
	}

	public function test_an_ambiguous_link_is_not_recorded(): void {
		$href = (string) get_permalink( $this->target );
		$post = $this->post(
			array(
				'post_content' => $this->p( 'Compra <a href="' . $href . '">aire acondicionado</a> ya.' ) . "\n\n" . $this->p( 'Y otra vez <a href="' . $href . '">aire acondicionado</a>.' ),
			)
		);

		$data = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link() ),
			)
		)->get_data();

		$this->assertSame( 'ambiguous', $data['results'][0]['status'] );
		$this->assertSame( array(), $this->rows_of( $post ) );
	}

	public function test_the_target_must_exist_be_another_entry_and_be_readable(): void {
		$post    = $this->saved_with_link();
		$private = self::factory()->post->create(
			array(
				'post_status' => 'private',
				'post_author' => $this->admin,
			)
		);

		$body = array(
			'post_id' => $post,
			'links'   => array(
				$this->client_link( 'ins-00000005', 'aire acondicionado', 999999 ),
				$this->client_link( 'ins-00000006', 'aire acondicionado', $post ),
			),
		);
		$this->assertSame( array( 'invalid', 'invalid' ), array_column( $this->post_editor( $body )->get_data()['results'], 'status' ) );

		// Un autor que edita su entrada no puede apuntar un destino privado ajeno.
		$mine = $this->post( array( 'post_author' => $this->author ) );
		wp_update_post(
			array(
				'ID'           => $mine,
				'post_content' => wp_slash( $this->p( 'Mira <a href="' . get_permalink( $private ) . '">esto</a>.' ) ),
			)
		);
		$data = $this->post_editor(
			array(
				'post_id' => $mine,
				'links'   => array( $this->client_link( 'ins-00000007', 'esto', $private ) ),
			),
			$this->author
		)->get_data();
		$this->assertSame( 'invalid', $data['results'][0]['status'] );
	}

	public function test_the_anchor_may_have_formatting_entities_and_non_breaking_spaces(): void {
		$post = $this->saved_with_link( '<strong>aire</strong>&nbsp;acondicionado' );

		$data = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link() ),
			)
		)->get_data();

		$this->assertSame( 'recorded', $data['results'][0]['status'] );
	}

	public function test_classic_content_is_recorded_and_undone_too(): void {
		$href = (string) get_permalink( $this->target );
		$text = "Primer párrafo.\n\nCompra <a href=\"{$href}\">aire acondicionado</a> ya.\n\nÚltimo.";
		$post = $this->post( array( 'post_content' => $text ) );

		$data = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link() ),
			)
		)->get_data();

		$this->assertSame( 'recorded', $data['results'][0]['status'] );
		$this->assertStringStartsWith( '@', (string) $this->rows_of( $post )[0]['block_path'] );

		$result = Plugin::container()->get( Undo::class )->revert( $data['results'][0]['change_id'] );
		$this->assertSame( UndoResult::RESTORED, $result->status );
		$this->assertSame( "Primer párrafo.\n\nCompra aire acondicionado ya.\n\nÚltimo.", $this->stored( $post ) );
	}

	// ------------------------------------------------------------------ Deshacer y rehacer.

	public function test_undo_from_the_history_removes_the_link_byte_for_byte_and_redo_puts_it_back(): void {
		$post   = $this->saved_with_link();
		$with   = $this->stored( $post );
		$change = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link() ),
			)
		)->get_data()['results'][0]['change_id'];

		$undo = Plugin::container()->get( Undo::class )->revert( $change );
		$this->assertSame( UndoResult::RESTORED, $undo->status );
		$this->assertSame( $this->p( 'Compra aire acondicionado ya.' ), $this->stored( $post ) );

		$redo = Plugin::container()->get( Redo::class )->redo( $change );
		$this->assertSame( UndoResult::REDONE, $redo->status );
		$this->assertSame( $with, $this->stored( $post ), 'Rehacer deja el contenido como lo guardó el editor.' );

		$batch = $this->rows_of( $post )[0]['batch_id'];
		$this->assertSame( 'editor', $this->reader()->group( $batch, $this->admin )['origin'], 'Tras rehacer sigue marcado como del editor.' );
	}

	public function test_undo_after_a_later_edit_removes_only_the_link(): void {
		$post   = $this->saved_with_link();
		$change = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link() ),
			)
		)->get_data()['results'][0]['change_id'];

		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => wp_slash( $this->stored( $post ) . "\n\n" . $this->p( 'Un párrafo nuevo.' ) ),
			)
		);

		$undo = Plugin::container()->get( Undo::class )->revert( $change );
		$this->assertSame( UndoResult::LINK_REMOVED, $undo->status );
		$this->assertSame( $this->p( 'Compra aire acondicionado ya.' ) . "\n\n" . $this->p( 'Un párrafo nuevo.' ), $this->stored( $post ) );

		// Rehacer con el párrafo editado después: el tramo del enlace está como estaba, se vuelve a poner.
		$redo = Plugin::container()->get( Redo::class )->redo( $change );
		$this->assertSame( UndoResult::REDONE, $redo->status );
		$this->assertStringContainsString( '<a href=', $this->stored( $post ) );
		$this->assertStringContainsString( 'Un párrafo nuevo.', $this->stored( $post ) );
	}

	public function test_undo_asks_for_a_manual_fix_if_the_link_was_duplicated_afterwards(): void {
		$post   = $this->saved_with_link();
		$change = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link() ),
			)
		)->get_data()['results'][0]['change_id'];

		$twice = $this->stored( $post ) . "\n\n" . $this->stored( $post );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => wp_slash( $twice ),
			)
		);

		$undo = Plugin::container()->get( Undo::class )->revert( $change );
		$this->assertSame( UndoResult::MANUAL, $undo->status );
		$this->assertSame( $twice, $this->stored( $post ), 'Lo que no se puede deshacer con seguridad no se toca.' );
	}

	public function test_undo_does_not_write_while_the_entry_is_open_in_an_editor(): void {
		$post   = $this->saved_with_link();
		$with   = $this->stored( $post );
		$change = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link() ),
			)
		)->get_data()['results'][0]['change_id'];

		// Quien acaba de salir del editor deja el bloqueo vigente (docs/06 §1.5).
		update_post_meta( $post, '_edit_lock', time() . ':' . $this->admin );
		$undo = Plugin::container()->get( Undo::class )->revert( $change );

		$this->assertSame( UndoResult::FAILED, $undo->status );
		$this->assertSame( $with, $this->stored( $post ) );

		delete_post_meta( $post, '_edit_lock' );
		$this->assertSame( UndoResult::RESTORED, Plugin::container()->get( Undo::class )->revert( $change )->status );
	}

	public function test_undo_through_the_rest_api_works_for_the_recorded_batch(): void {
		$post = $this->saved_with_link();
		$data = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link() ),
			)
		)->get_data();

		wp_set_current_user( $this->admin );
		$request = new WP_REST_Request( 'POST', self::BASE . '/undo' );
		$request->set_param( 'batch_id', $data['batch_id'] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'restored', $response->get_data()['results'][0]['status'] );
		$this->assertSame( $this->p( 'Compra aire acondicionado ya.' ), $this->stored( $post ) );
	}

	// ------------------------------------------------------------------ Permisos y validación.

	public function test_it_needs_a_login_and_permission_on_the_entry(): void {
		$mine   = $this->saved_with_link( 'aire acondicionado', array( 'post_author' => $this->author ) );
		$theirs = $this->saved_with_link( 'aire acondicionado', array( 'post_author' => $this->admin ) );
		$link   = array( $this->client_link() );

		$this->assertSame(
			401,
			$this->post_editor(
				array(
					'post_id' => $mine,
					'links'   => $link,
				),
				0
			)->get_status()
		);
		$this->assertSame(
			403,
			$this->post_editor(
				array(
					'post_id' => $mine,
					'links'   => $link,
				),
				$this->subscriber
			)->get_status()
		);
		$this->assertSame(
			403,
			$this->post_editor(
				array(
					'post_id' => $theirs,
					'links'   => $link,
				),
				$this->author
			)->get_status()
		);
		$this->assertSame( array(), $this->rows_of( $theirs ) );

		$own = $this->post_editor(
			array(
				'post_id' => $mine,
				'links'   => $link,
			),
			$this->author
		);
		$this->assertSame( 'recorded', $own->get_data()['results'][0]['status'] );
	}

	public function test_a_missing_entry_and_too_many_links_are_refused(): void {
		$post = $this->saved_with_link();
		$this->assertSame(
			400,
			$this->post_editor(
				array(
					'post_id' => $post,
					'links'   => array_fill( 0, 51, $this->client_link() ),
				)
			)->get_status()
		);
	}

	public function test_a_malformed_element_is_reported_on_its_own_and_does_not_sink_the_others(): void {
		$post = $this->saved_with_link();

		$response = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array(
					array_merge( $this->client_link( 'x' ), array( 'anchor' => 'aire acondicionado' ) ),
					$this->client_link( 'ins-0000000a' ),
					array_merge( $this->client_link( 'ins-0000000b' ), array( 'anchor' => str_repeat( 'a', 600 ) ) ),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'invalid', 'recorded', 'invalid' ), array_column( $response->get_data()['results'], 'status' ) );
	}

	public function test_an_empty_list_is_refused(): void {
		$post = $this->saved_with_link();

		$this->assertSame(
			400,
			$this->post_editor(
				array(
					'post_id' => $post,
					'links'   => array(),
				)
			)->get_status()
		);
	}

	/**
	 * Registra el enlace de la entrada y devuelve el id de la fila.
	 *
	 * @param int $post Entrada con el enlace guardado.
	 */
	private function record_row( int $post ): int {
		return (int) $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link() ),
			)
		)->get_data()['results'][0]['change_id'];
	}

	private function undone( int $change ): bool {
		return null !== Plugin::container()->get( ChangeRepository::class )->get( $change )['undone_at'];
	}

	private function save_content( int $post, string $content ): void {
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => wp_slash( $content ),
			)
		);
	}

	public function test_saving_the_entry_marks_as_undone_the_recorded_link_that_is_gone_without_writing(): void {
		$post   = $this->saved_with_link();
		$change = $this->record_row( $post );
		$batch  = (string) $this->rows_of( $post )[0]['batch_id'];

		// Un guardado con el enlace puesto no cambia nada.
		$this->save_content( $post, $this->stored( $post ) . "\n\n" . $this->p( 'Más texto.' ) );
		$this->assertFalse( $this->undone( $change ) );

		// El usuario lo quita (a mano, con el panel, desde cualquier editor) y guarda.
		$plain = $this->p( 'Compra aire acondicionado ya.' );
		$this->save_content( $post, $plain );

		$this->assertTrue( $this->undone( $change ) );
		$this->assertSame( 0, $this->reader()->group( $batch, $this->admin )['active'] );
		$this->assertSame( $plain, $this->stored( $post ), 'Reconciliar no escribe en la entrada.' );
	}

	public function test_a_link_with_attributes_added_later_stays_active(): void {
		$post   = $this->saved_with_link();
		$change = $this->record_row( $post );
		$href   = (string) get_permalink( $this->target );

		$this->save_content( $post, $this->p( 'Compra <a target="_blank" rel="noopener nofollow" href="' . $href . '/">aire acondicionado</a> ya.' ) );

		$this->assertFalse( $this->undone( $change ) );
	}

	public function test_another_link_to_the_same_target_with_other_text_does_not_keep_it_active(): void {
		$post   = $this->saved_with_link();
		$change = $this->record_row( $post );
		$href   = (string) get_permalink( $this->target );

		$this->save_content( $post, $this->p( 'Compra el <a href="' . $href . '">aparato</a> ya.' ) );

		$this->assertTrue( $this->undone( $change ) );
	}

	public function test_with_two_identical_links_and_one_removed_it_stays_active(): void {
		$post   = $this->saved_with_link();
		$change = $this->record_row( $post );
		$href   = (string) get_permalink( $this->target );
		$link   = '<a href="' . $href . '">aire acondicionado</a>';

		$this->save_content( $post, $this->p( "Compra {$link} ya." ) . "\n\n" . $this->p( "Otra vez {$link}." ) );
		$this->assertFalse( $this->undone( $change ) );

		$this->save_content( $post, $this->p( "Compra {$link} ya." ) );
		$this->assertFalse( $this->undone( $change ), 'No se puede distinguir cuál era: sigue activa.' );
	}

	public function test_a_link_in_classic_content_is_reconciled_too(): void {
		$href = (string) get_permalink( $this->target );
		$post = $this->post( array( 'post_content' => "Uno.\n\nCompra <a href=\"{$href}\">aire acondicionado</a> ya." ) );
		$id   = $this->record_row( $post );

		$this->save_content( $post, "Uno.\n\nCompra aire acondicionado ya." );

		$this->assertTrue( $this->undone( $id ) );
	}

	public function test_only_editor_rows_are_reconciled(): void {
		$linked = $this->link();
		$change = $linked['change'];
		$this->save_content( $linked['post'], $this->p( 'Compra aire acondicionado ya.' ) );

		$this->assertFalse( $this->undone( $change ), 'Las filas del servidor las trata Deshacer, no el guardado.' );
	}

	public function test_a_nonexistent_entry_is_404_and_an_entry_without_permission_is_403(): void {
		$gone = $this->post_editor(
			array(
				'post_id' => 987654,
				'links'   => array( $this->client_link() ),
			)
		);
		$this->assertSame( 404, $gone->get_status() );
		$this->assertSame( 'magiclinking_post_gone', $gone->get_data()['code'] );

		$theirs = $this->saved_with_link( 'aire acondicionado', array( 'post_author' => $this->admin ) );
		$this->assertSame(
			403,
			$this->post_editor(
				array(
					'post_id' => $theirs,
					'links'   => array( $this->client_link() ),
				),
				$this->author
			)->get_status()
		);
	}

	public function test_redo_keeps_the_editor_origin_on_the_new_row(): void {
		$post   = $this->saved_with_link();
		$change = $this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link() ),
			)
		)->get_data()['results'][0]['change_id'];

		Plugin::container()->get( Undo::class )->revert( $change );
		$this->assertSame( UndoResult::REDONE, Plugin::container()->get( Redo::class )->redo( $change )->status );

		$origins = array_column( $this->rows_of( $post ), 'origin', 'id' );
		$this->assertSame( array( 'editor', 'plugin', 'editor' ), array_values( $origins ), 'insert, remove (traza del deshacer) e insert rehecho' );
	}

	public function test_the_keys_of_all_the_links_are_looked_up_with_one_query(): void {
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$a     = (string) get_permalink( $this->target );
		$b     = (string) get_permalink( $other );
		$post  = $this->post(
			array(
				'post_content' => $this->p( 'Compra <a href="' . $a . '">aire acondicionado</a> ya.' ) . "\n\n" . $this->p( 'Y <a href="' . $b . '">otro</a>.' ),
			)
		);

		$queries = 0;
		$count   = static function ( $sql ) use ( &$queries ) {
			if ( str_contains( (string) $sql, 'origin_key' ) && str_starts_with( ltrim( (string) $sql ), 'SELECT' ) ) {
				++$queries;
			}
			return $sql;
		};
		add_filter( 'query', $count );
		$this->post_editor(
			array(
				'post_id' => $post,
				'links'   => array( $this->client_link( 'ins-0000000c' ), $this->client_link( 'ins-0000000d', 'otro', $other ) ),
			)
		);
		remove_filter( 'query', $count );

		$this->assertSame( 1, $queries );
	}

	public function test_the_idempotent_key_depends_on_every_part(): void {
		$base = EditorLinks::key( 1, 's', 2, 'a', 'i' );

		$this->assertSame( $base, EditorLinks::key( 1, 's', 2, 'a', 'i' ) );
		foreach ( array( EditorLinks::key( 2, 's', 2, 'a', 'i' ), EditorLinks::key( 1, 't', 2, 'a', 'i' ), EditorLinks::key( 1, 's', 3, 'a', 'i' ), EditorLinks::key( 1, 's', 2, 'b', 'i' ), EditorLinks::key( 1, 's', 2, 'a', 'j' ) ) as $other ) {
			$this->assertNotSame( $base, $other );
		}
		$this->assertSame( 40, strlen( $base ) );
		$this->assertInstanceOf( ChangeRepository::class, Plugin::container()->get( ChangeRepository::class ) );
	}
}
