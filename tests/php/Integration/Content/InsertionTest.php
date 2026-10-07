<?php
/**
 * Inserción y deshacer sobre entradas reales (docs/06 §8, casos 10 a 14 y los de escritura).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Content;

use MagicLinking\Content\InsertionException;
use MagicLinking\Content\InsertRequest;
use MagicLinking\Content\Inserter;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\History\BatchId;
use MagicLinking\History\ChangeRepository;
use MagicLinking\History\Undo;
use MagicLinking\History\UndoResult;
use MagicLinking\Tests\Integration\GraphTestCase;

final class InsertionTest extends GraphTestCase {

	use Fixtures;

	/**
	 * Destino de los enlaces.
	 *
	 * @var int
	 */
	private int $target = 0;

	/**
	 * Quien edita.
	 *
	 * @var int
	 */
	private int $admin = 0;

	public function set_up(): void {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$this->target = self::factory()->post->create(
			array(
				'post_title'   => 'Aire acondicionado',
				'post_name'    => 'aire-acondicionado',
				'post_content' => $this->p( 'Guía del destino.' ),
			)
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'magiclinking_insertable_blocks' );
		parent::tear_down();
	}

	private function inserter(): Inserter {
		return Plugin::container()->get( Inserter::class );
	}

	private function undo(): Undo {
		return Plugin::container()->get( Undo::class );
	}

	/**
	 * Crea una entrada con ese contenido.
	 *
	 * @param string $content Contenido.
	 * @param array  $extra   Campos extra.
	 */
	private function post( string $content, array $extra = array() ): int {
		return self::factory()->post->create( array_merge( array( 'post_content' => $content ), $extra ) );
	}

	/**
	 * Petición hacia el destino.
	 *
	 * @param int         $post   Origen.
	 * @param string      $before Texto anterior.
	 * @param string      $after  Texto posterior.
	 * @param string|null $path   Ruta.
	 * @param string      $anchor Ancla.
	 */
	private function request( int $post, string $before = 'Compra ', string $after = ' ya.', ?string $path = null, string $anchor = 'aire acondicionado' ): InsertRequest {
		return new InsertRequest( $post, (string) get_permalink( $this->target ), $anchor, $before, $after, $path );
	}

	/**
	 * Contenido guardado ahora.
	 *
	 * @param int $post Entrada.
	 */
	private function stored( int $post ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Filas del historial.
	 *
	 * @return list<array<string, string>>
	 */
	private function changes(): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'changes' );

		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Lo que debe lanzar una inserción.
	 *
	 * @param string        $reason  Código esperado.
	 * @param InsertRequest $request Petición.
	 */
	private function refused( string $reason, InsertRequest $request ): void {
		try {
			$this->inserter()->insert( $request );
		} catch ( InsertionException $e ) {
			$this->assertSame( $reason, $e->reason(), $e->getMessage() );
			return;
		}
		$this->fail( "Debía negarse con «{$reason}»." );
	}

	// -------------------------------------------------------------- docs/06 §8.

	public function test_caso_10_texto_cambiado_desde_la_sugerencia_aborta_y_no_escribe(): void {
		$post = $this->post( $this->p( 'Compra aire acondicionado ya.' ) );
		$ask  = $this->request( $post );

		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => wp_slash( $this->p( 'Compra un climatizador ya.' ) ),
			)
		);
		$edited = $this->stored( $post );

		$this->refused( InsertionException::TEXT_CHANGED, $ask );

		$this->assertSame( $edited, $this->stored( $post ) );
		$this->assertSame( array(), $this->changes(), 'Sin cambio escrito no hay fila en el historial.' );
	}

	public function test_caso_11_entrada_bloqueada_por_otro_usuario_no_se_modifica(): void {
		$content = $this->p( 'Compra aire acondicionado ya.' );
		$post    = $this->post( $content );
		$ana     = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Ana',
			)
		);

		update_post_meta( $post, '_edit_lock', time() . ':' . $ana );

		try {
			$this->inserter()->insert( $this->request( $post ) );
			$this->fail( 'Debía negarse.' );
		} catch ( InsertionException $e ) {
			$this->assertSame( InsertionException::LOCKED, $e->reason() );
			$this->assertStringContainsString( 'Ana', $e->getMessage() );
		}

		$this->assertSame( $content, $this->stored( $post ) );
		$this->assertSame( array(), $this->changes() );

		// Un bloqueo caducado, o el propio, no impide nada.
		update_post_meta( $post, '_edit_lock', ( time() - 3600 ) . ':' . $ana );
		$this->inserter()->insert( $this->request( $post ) );
		$this->assertNotSame( $content, $this->stored( $post ) );
	}

	public function test_caso_11_el_deshacer_tampoco_toca_una_entrada_bloqueada(): void {
		$post   = $this->post( $this->p( 'Compra aire acondicionado ya.' ) );
		$result = $this->inserter()->insert( $this->request( $post ) );
		$linked = $this->stored( $post );
		$ana    = self::factory()->user->create( array( 'role' => 'editor' ) );

		update_post_meta( $post, '_edit_lock', time() . ':' . $ana );
		$undone = $this->undo()->revert( $result->change_id );

		$this->assertSame( UndoResult::FAILED, $undone->status );
		$this->assertSame( InsertionException::LOCKED, $undone->reason );
		$this->assertSame( $linked, $this->stored( $post ) );
	}

	public function test_caso_12_deshacer_inmediato_deja_el_contenido_identico_byte_a_byte(): void {
		$content = $this->doc( $this->p( 'Antes.' ), "<!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">Compra <strong>aire acondicionado</strong>&nbsp;ya &amp; siempre.</p>\n<!-- /wp:paragraph -->", $this->p( 'Después.' ) );
		$post    = $this->post( $content );

		$result = $this->inserter()->insert( $this->request( $post, 'Compra ', '  ya & siempre.' ) );
		$this->assertNotSame( $content, $this->stored( $post ) );

		$undone = $this->undo()->revert( $result->change_id );

		$this->assertSame( UndoResult::RESTORED, $undone->status );
		$this->assertSame( $content, $this->stored( $post ) );
	}

	public function test_caso_13_deshacer_tras_edicion_posterior_quita_solo_el_enlace(): void {
		$post   = $this->post( $this->doc( $this->p( 'Compra aire acondicionado ya.' ), $this->p( 'Final.' ) ) );
		$result = $this->inserter()->insert( $this->request( $post ) );

		// Alguien edita otra cosa después.
		$later = str_replace( 'Final.', 'Final y más cosas.', $this->stored( $post ) );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => wp_slash( $later ),
			)
		);

		$undone = $this->undo()->revert( $result->change_id );

		$this->assertSame( UndoResult::LINK_REMOVED, $undone->status );
		$this->assertSame( $this->doc( $this->p( 'Compra aire acondicionado ya.' ), $this->p( 'Final y más cosas.' ) ), $this->stored( $post ) );
	}

	public function test_caso_13_si_el_enlace_ya_no_esta_igual_se_informa_sin_tocar(): void {
		$post   = $this->post( $this->p( 'Compra aire acondicionado ya.' ) );
		$result = $this->inserter()->insert( $this->request( $post ) );

		// El usuario cambia el texto del propio enlace.
		$later = str_replace( '>aire acondicionado<', '>climatización<', $this->stored( $post ) );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => wp_slash( $later ),
			)
		);

		$undone = $this->undo()->revert( $result->change_id );

		$this->assertSame( UndoResult::MANUAL, $undone->status );
		$this->assertSame( InsertionException::EDITED_AFTER, $undone->reason );
		$this->assertStringContainsString( 'post=' . $post, (string) $undone->edit_url );
		$this->assertSame( $later, $this->stored( $post ) );
		$this->assertNull( $this->changes()[0]['undone_at'], 'Lo que no se ha deshecho no se marca como deshecho.' );
	}

	public function test_caso_13_un_enlace_igual_duplicado_a_mano_no_se_adivina(): void {
		$post   = $this->post( $this->p( 'Compra aire acondicionado ya.' ) );
		$result = $this->inserter()->insert( $this->request( $post ) );

		$link  = '<a href="' . get_permalink( $this->target ) . '">aire acondicionado</a>';
		$later = str_replace( 'ya.</p>', 'ya y ' . $link . ' otra vez.</p>', $this->stored( $post ) );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => wp_slash( $later ),
			)
		);

		$this->assertSame( UndoResult::MANUAL, $this->undo()->revert( $result->change_id )->status );
		$this->assertSame( $later, $this->stored( $post ) );
	}

	public function test_caso_14_lote_de_50_inserciones_y_deshacer_del_lote(): void {
		$originals = array();
		$batch     = BatchId::generate();

		for ( $i = 1; $i <= 50; $i++ ) {
			$content          = $this->doc( $this->p( "Entrada {$i}." ), $this->p( "Compra aire acondicionado ya, número {$i} de la serie." ) );
			$id               = $this->post( $content );
			$originals[ $id ] = $content;
			$this->inserter()->insert( $this->request( $id, 'Compra ', " ya, número {$i} de la serie." ), $batch );
		}

		foreach ( $originals as $id => $content ) {
			$this->assertNotSame( $content, $this->stored( $id ) );
		}
		$this->assertCount( 50, $this->changes() );

		$results = $this->undo()->revert_batch( $batch );

		$this->assertCount( 50, $results );
		foreach ( $results as $result ) {
			$this->assertSame( UndoResult::RESTORED, $result->status );
		}
		foreach ( $originals as $id => $content ) {
			$this->assertSame( $content, $this->stored( $id ), "La entrada {$id} no ha vuelto al original." );
		}
	}

	// -------------------------------------------------------------- Escritura.

	public function test_escribe_con_revision_sin_tocar_la_fecha_y_actualiza_el_grafo(): void {
		$post = $this->post( $this->p( 'Compra aire acondicionado ya.' ), array( 'post_date' => '2026-01-02 03:04:05' ) );
		$date = get_post_field( 'post_date', $post );
		$hits = 0;
		add_action(
			'magiclinking_link_inserted',
			static function () use ( &$hits ): void {
				++$hits;
			}
		);
		$before = count( wp_get_post_revisions( $post ) );

		$result = $this->inserter()->insert( $this->request( $post ) );

		$this->assertSame( 1, $hits );
		$this->assertSame( $date, get_post_field( 'post_date', $post ) );
		$this->assertGreaterThan( $before, count( wp_get_post_revisions( $post ) ), 'El historial nativo de WordPress también sirve.' );
		$this->assertSame( 1, count( $this->link_rows( $post ) ) );
		$this->assertSame( 1, (int) ( $this->doc_row( $this->target )['inbound'] ?? 0 ), 'Los entrantes del destino se han actualizado.' );

		$row = $this->changes()[0];
		$this->assertSame( ChangeRepository::INSERT, $row['action'] );
		$this->assertSame( (string) $result->change_id, $row['id'] );
		$this->assertSame( '0', $row['block_path'] );
		$this->assertSame( $this->p( 'Compra aire acondicionado ya.' ), $row['before_html'] );
		$this->assertStringContainsString( '<a href=', $row['after_html'] );
		$this->assertSame( sha1( $this->stored( $post ) ), $row['content_hash_after'] );
		$this->assertSame( (string) $this->admin, $row['user_id'] );
		$this->assertSame( 26, strlen( $row['batch_id'] ) );
	}

	public function test_deshacer_deja_su_propio_registro_y_no_se_repite(): void {
		$post   = $this->post( $this->p( 'Compra aire acondicionado ya.' ) );
		$result = $this->inserter()->insert( $this->request( $post ) );

		$this->assertSame( UndoResult::RESTORED, $this->undo()->revert( $result->change_id )->status );
		$this->assertSame( UndoResult::ALREADY, $this->undo()->revert( $result->change_id )->status );

		$rows = $this->changes();
		$this->assertCount( 2, $rows );
		$this->assertSame( ChangeRepository::REMOVE, $rows[1]['action'] );
		$this->assertSame( $rows[0]['after_html'], $rows[1]['before_html'] );
		$this->assertSame( $rows[0]['before_html'], $rows[1]['after_html'] );
		$this->assertNotNull( $rows[0]['undone_at'] );
		$this->assertSame( 0, (int) ( $this->doc_row( $this->target )['inbound'] ?? 0 ) );
	}

	public function test_clasico_se_inserta_y_se_deshace(): void {
		$content = "Texto con [gallery ids=\"1\"] y aire acondicionado instalado.\n\nOtro párrafo.";
		$post    = $this->post( $content );

		$result = $this->inserter()->insert( $this->request( $post, 'y ', ' instalado.' ) );

		$this->assertSame( '@0', $result->path );
		$this->assertSame( str_replace( 'y aire acondicionado instalado', 'y ' . $this->a_target( 'aire acondicionado' ) . ' instalado', $content ), $this->stored( $post ) );
		$this->assertSame( UndoResult::RESTORED, $this->undo()->revert( $result->change_id )->status );
		$this->assertSame( $content, $this->stored( $post ) );
	}

	public function test_clasico_con_edicion_posterior_quita_solo_el_enlace(): void {
		$content = "Primero.\n\nCompra aire acondicionado ya.\n\nFinal.";
		$post    = $this->post( $content );
		$result  = $this->inserter()->insert( $this->request( $post ) );

		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => wp_slash( str_replace( 'Primero.', "Primero.\n\nUn párrafo nuevo delante.", $this->stored( $post ) ) ),
			)
		);

		$this->assertSame( UndoResult::LINK_REMOVED, $this->undo()->revert( $result->change_id )->status );
		$this->assertSame( "Primero.\n\nUn párrafo nuevo delante.\n\nCompra aire acondicionado ya.\n\nFinal.", $this->stored( $post ) );
	}

	public function test_si_un_filtro_altera_el_contenido_al_guardar_se_restaura_y_se_avisa(): void {
		// Un autor no puede guardar un iframe: kses lo quitaría al escribir, y eso no es «solo el enlace».
		$content = $this->doc( $this->p( 'Compra aire acondicionado ya.' ), "<!-- wp:html -->\n<iframe src=\"https://example.org/v\"></iframe>\n<!-- /wp:html -->" );
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post    = $this->post( $content, array( 'post_author' => $author ) );

		wp_set_current_user( $author );
		kses_init();

		try {
			$this->refused( InsertionException::WRITE_FAILED, $this->request( $post ) );
			$this->assertSame( $content, $this->stored( $post ), 'El contenido anterior se restaura tal cual.' );
			$this->assertSame( array(), $this->changes(), 'Lo que no se escribió no queda en el historial.' );
		} finally {
			wp_set_current_user( $this->admin );
			kses_init();
		}
	}

	public function test_sin_permiso_o_en_la_papelera_o_de_otro_tipo_no_se_toca(): void {
		$content = $this->p( 'Compra aire acondicionado ya.' );
		$post    = $this->post( $content );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->refused( InsertionException::NOT_ALLOWED, $this->request( $post ) );
		wp_set_current_user( $this->admin );

		$trashed = $this->post( $content, array( 'post_status' => 'trash' ) );
		$this->refused( InsertionException::NO_POST, $this->request( $trashed ) );
		$this->refused( InsertionException::NO_POST, $this->request( 999999 ) );

		$other = $this->post(
			$content,
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			)
		);
		$this->refused( InsertionException::NO_POST, $this->request( $other ) );

		register_post_type( 'recipe', array( 'public' => true ) );
		$recipe = $this->post( $content, array( 'post_type' => 'recipe' ) );
		$this->refused( InsertionException::NOT_ALLOWED, $this->request( $recipe ) );

		$this->assertSame( $content, $this->stored( $post ) );
		$this->assertSame( array(), $this->changes() );
	}

	public function test_el_borrador_se_puede_enlazar_y_la_peticion_del_sistema_no_comprueba_capacidades(): void {
		$content = $this->p( 'Compra aire acondicionado ya.' );
		$draft   = $this->post( $content, array( 'post_status' => 'draft' ) );

		wp_set_current_user( 0 );
		$request = new InsertRequest( $draft, (string) get_permalink( $this->target ), 'aire acondicionado', 'Compra ', ' ya.', null, array(), 0 );
		$this->inserter()->insert( $request );
		wp_set_current_user( $this->admin );

		$this->assertStringContainsString( '<a href=', $this->stored( $draft ) );
		$this->assertSame( '0', $this->changes()[0]['user_id'] );
	}

	public function test_dos_enlaces_seguidos_en_la_misma_entrada(): void {
		$post = $this->post( $this->p( 'Compra aire acondicionado y también un calefactor eléctrico ya.' ) );

		$this->inserter()->insert( $this->request( $post, 'Compra ', ' y también' ) );
		$this->inserter()->insert( new InsertRequest( $post, 'https://example.org/calefactor/', 'calefactor eléctrico', 'y también un ', ' ya.' ) );

		$this->assertSame(
			$this->p( 'Compra ' . $this->a_target( 'aire acondicionado' ) . ' y también un <a href="https://example.org/calefactor/">calefactor eléctrico</a> ya.' ),
			$this->stored( $post )
		);
		$this->assertCount( 2, $this->changes() );
	}

	/**
	 * Enlace al destino.
	 *
	 * @param string $inner Contenido.
	 */
	private function a_target( string $inner ): string {
		return '<a href="' . get_permalink( $this->target ) . '">' . $inner . '</a>';
	}

	public function test_identificador_de_lote_es_un_ulid_ordenable(): void {
		$first  = BatchId::generate( 1_790_000_000_000 );
		$second = BatchId::generate( 1_790_000_000_001 );

		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $first );
		$this->assertLessThan( 0, strcmp( $first, $second ) );
		$this->assertNotSame( BatchId::generate( 1 ), BatchId::generate( 1 ) );
	}
}
