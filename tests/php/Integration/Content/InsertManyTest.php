<?php
/**
 * Insertar varios enlaces de una misma entrada con una sola escritura (F1-08).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Content;

use MagicLinking\Content\InsertionException;
use MagicLinking\Content\Inserter;
use MagicLinking\Content\InsertRequest;
use MagicLinking\Content\InsertResult;
use MagicLinking\Core\Plugin;
use MagicLinking\History\BatchId;
use MagicLinking\History\Undo;
use MagicLinking\History\UndoResult;
use MagicLinking\Tests\Integration\History\HistoryTestCase;

final class InsertManyTest extends HistoryTestCase {

	private int $second = 0;

	public function set_up(): void {
		parent::set_up();

		$this->second = self::factory()->post->create(
			array(
				'post_title'   => 'Bomba de calor',
				'post_name'    => 'bomba-de-calor',
				'post_content' => $this->p( 'Guía de la bomba.' ),
			)
		);
	}

	private function inserter(): Inserter {
		return Plugin::container()->get( Inserter::class );
	}

	private function undo(): Undo {
		return Plugin::container()->get( Undo::class );
	}

	/**
	 * Dos peticiones sobre el mismo texto: «aire acondicionado» y «bomba de calor».
	 *
	 * @param int $post Entrada.
	 *
	 * @return list<InsertRequest>
	 */
	private function two( int $post ): array {
		return array(
			new InsertRequest( $post, (string) get_permalink( $this->target ), 'aire acondicionado', 'Compra ', ' y una bomba de calor hoy.' ),
			new InsertRequest( $post, (string) get_permalink( $this->second ), 'bomba de calor', 'aire acondicionado y una ', ' hoy.' ),
		);
	}

	private function links_in( int $post ): int {
		return substr_count( $this->stored( $post ), '<a href=' );
	}

	public function test_requests_for_different_posts_or_users_are_refused(): void {
		$a = $this->post();
		$b = $this->post();

		foreach ( array(
			'entrada' => array(
				new InsertRequest( $a, 'https://x.test/', 'aire', '', '' ),
				new InsertRequest( $b, 'https://x.test/', 'aire', '', '' ),
			),
			'usuario' => array(
				new InsertRequest( $a, 'https://x.test/', 'aire', '', '', null, array(), 1 ),
				new InsertRequest( $a, 'https://x.test/', 'aire', '', '', null, array(), 2 ),
			),
		) as $what => $requests ) {
			try {
				$this->inserter()->insert_many( $requests );
				$this->fail( "Debía negarse: {$what}." );
			} catch ( InsertionException $e ) {
				$this->assertSame( InsertionException::BAD_REQUEST, $e->reason(), $what );
			}
		}
		$this->assertSame( 0, $this->links_in( $a ) + $this->links_in( $b ) );
	}

	public function test_two_links_in_the_same_paragraph_are_one_write_and_undo_in_either_order(): void {
		$post     = $this->post( array( 'post_content' => $this->p( 'Compra aire acondicionado y una bomba de calor hoy.' ) ) );
		$original = $this->stored( $post );
		$writes   = 0;
		add_action(
			'post_updated',
			static function () use ( &$writes ): void {
				++$writes;
			}
		);
		$revisions = count( wp_get_post_revisions( $post ) );

		$batch   = BatchId::generate();
		$results = $this->inserter()->insert_many( $this->two( $post ), $batch );

		$this->assertContainsOnlyInstancesOf( InsertResult::class, $results );
		$this->assertSame( 1, $writes );
		$this->assertSame( $revisions + 1, count( wp_get_post_revisions( $post ) ) );
		$this->assertSame( 2, $this->links_in( $post ) );
		$this->assertSame( 2, $this->rows( $batch ) );

		// Deshacer solo el último: el contenido es el que dejó el primero (huella coincidente).
		$last = $this->undo()->revert( $results[1]->change_id );
		$this->assertSame( UndoResult::RESTORED, $last->status );
		$this->assertSame( 1, $this->links_in( $post ) );
		$first = $this->undo()->revert( $results[0]->change_id );
		$this->assertSame( UndoResult::RESTORED, $first->status );
		$this->assertSame( $original, $this->stored( $post ) );

		// Al revés (el primero con el último puesto): solo se quita su enlace.
		$again = $this->inserter()->insert_many( $this->two( $post ) );
		$first = $this->undo()->revert( $again[0]->change_id );
		$this->assertSame( UndoResult::LINK_REMOVED, $first->status );
		$this->assertSame( 1, $this->links_in( $post ) );
		$this->assertSame( UndoResult::LINK_REMOVED, $this->undo()->revert( $again[1]->change_id )->status, 'Ya no coincide la huella: se quita solo su enlace.' );
		$this->assertSame( $original, $this->stored( $post ) );
	}

	public function test_two_links_in_the_same_classic_span(): void {
		$post     = $this->post( array( 'post_content' => 'Compra aire acondicionado y una bomba de calor hoy.' ) );
		$original = $this->stored( $post );

		$results = $this->inserter()->insert_many( $this->two( $post ) );

		$this->assertContainsOnlyInstancesOf( InsertResult::class, $results );
		$this->assertSame( '@0', $results[0]->path );
		$this->assertSame( '@0', $results[1]->path );
		$this->assertSame( 2, $this->links_in( $post ) );

		$this->assertSame( UndoResult::RESTORED, $this->undo()->revert( $results[1]->change_id )->status );
		$this->assertSame( 1, $this->links_in( $post ) );
		$this->assertSame( UndoResult::RESTORED, $this->undo()->revert( $results[0]->change_id )->status );
		$this->assertSame( $original, $this->stored( $post ) );
	}

	public function test_a_third_party_failing_after_the_write_does_not_hide_the_links(): void {
		$post = $this->post( array( 'post_content' => $this->p( 'Compra aire acondicionado y una bomba de calor hoy.' ) ) );
		add_action(
			'wp_after_insert_post',
			static function (): void {
				throw new \RuntimeException( 'Complemento roto' );
			}
		);
		$fired = 0;
		add_action(
			'magiclinking_link_inserted',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$batch   = BatchId::generate();
		$results = $this->inserter()->insert_many( $this->two( $post ), $batch );
		remove_all_actions( 'wp_after_insert_post' );

		$this->assertContainsOnlyInstancesOf( InsertResult::class, $results, 'Los enlaces están escritos: se devuelven como insertados.' );
		$this->assertSame( $batch, $results[0]->batch_id );
		$this->assertSame( 2, $this->links_in( $post ) );
		$this->assertSame( 2, $this->rows( $batch ) );
		$this->assertSame( 2, $fired );
		$this->assertCount( 2, $this->link_rows( $post ), 'Se reindexó.' );

		$this->assertCount( 2, $this->undo()->revert_batch( $batch ) );
		$this->assertSame( 0, $this->links_in( $post ) );
	}

	public function test_a_failing_listener_does_not_hide_the_links_nor_stop_the_rest(): void {
		$post = $this->post( array( 'post_content' => $this->p( 'Compra aire acondicionado y una bomba de calor hoy.' ) ) );
		add_action(
			'magiclinking_link_inserted',
			static function (): void {
				throw new \RuntimeException( 'Oyente roto' );
			},
			10
		);
		$fired = 0;
		add_action(
			'magiclinking_link_inserted',
			static function () use ( &$fired ): void {
				++$fired;
			},
			5
		);

		$batch   = BatchId::generate();
		$results = $this->inserter()->insert_many( $this->two( $post ), $batch );

		$this->assertContainsOnlyInstancesOf( InsertResult::class, $results );
		$this->assertSame( 2, $fired, 'El aviso del segundo enlace se dispara aunque el del primero fallara.' );
		$this->assertSame( 2, $this->rows( $batch ) );
		$this->assertCount( 2, $this->link_rows( $post ) );
	}

	public function test_a_refused_write_leaves_no_rows_and_announces_nothing(): void {
		$post     = $this->post( array( 'post_content' => $this->p( 'Compra aire acondicionado y una bomba de calor hoy.' ) ) );
		$original = $this->stored( $post );
		// Un filtro de guardado que altera el contenido: se detecta antes de escribir.
		add_filter(
			'content_save_pre',
			static fn( $content ) => $content . ' '
		);
		$fired = 0;
		add_action(
			'magiclinking_link_inserted',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$batch   = BatchId::generate();
		$results = $this->inserter()->insert_many( $this->two( $post ), $batch );
		remove_all_filters( 'content_save_pre' );

		foreach ( $results as $result ) {
			$this->assertInstanceOf( InsertionException::class, $result );
			$this->assertSame( InsertionException::ALTERED, $result->reason() );
		}
		$this->assertSame( 0, $this->rows( $batch ), 'Sin filas huérfanas.' );
		$this->assertSame( 0, $fired );
		$this->assertSame( $original, $this->stored( $post ) );
	}
}
