<?php
/**
 * Base de las pruebas de arreglar enlaces rotos (F1-33): entradas con enlaces rotos reales y el grafo al día.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Fix;

use MagicLinking\Content\FixRequest;
use MagicLinking\Content\InsertionException;
use MagicLinking\Content\InsertResult;
use MagicLinking\Content\Repairer;
use MagicLinking\Core\Plugin;
use MagicLinking\Graph\BrokenRepository;
use MagicLinking\Graph\GraphIndexer;
use MagicLinking\History\Redo;
use MagicLinking\History\Undo;
use MagicLinking\Tests\Integration\GraphTestCase;

abstract class FixTestCase extends GraphTestCase {

	/**
	 * Administrador que arregla.
	 *
	 * @var int
	 */
	protected int $admin = 0;

	/**
	 * Destino válido.
	 *
	 * @var int
	 */
	protected int $target = 0;

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
		remove_all_filters( 'content_save_pre' );
		remove_all_actions( 'magiclinking_link_fixed' );
		parent::tear_down();
	}

	protected function p( string $html ): string {
		return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Enlace roto con ese texto.
	 *
	 * @param string $text Texto del ancla.
	 * @param string $slug Ruta rota.
	 * @param string $attr Atributos extra.
	 */
	protected function a( string $text, string $slug = 'no-existe', string $attr = '' ): string {
		return '<a href="' . home_url( "/{$slug}/" ) . '"' . $attr . '>' . $text . '</a>';
	}

	/**
	 * Crea una entrada publicada con ese contenido y deja el grafo al día.
	 *
	 * @param string $content Contenido.
	 * @param array  $extra   Campos extra.
	 */
	protected function post( string $content, array $extra = array() ): int {
		$id = self::factory()->post->create( array_merge( array( 'post_content' => $content ), $extra ) );
		$this->index( $id );

		return $id;
	}

	protected function index( int $id ): void {
		$indexer = Plugin::container()->get( GraphIndexer::class );
		$indexer->flush();
		$indexer->index_and_refresh( $id, true );
	}

	/**
	 * Contenido guardado.
	 *
	 * @param int $id Entrada.
	 */
	protected function stored( int $id ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Cambia el contenido sin pasar por ningún gancho (como una edición que el grafo todavía no ha visto).
	 *
	 * @param int    $id      Entrada.
	 * @param string $content Contenido.
	 */
	protected function raw_update( int $id, string $content ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );
	}

	/**
	 * Filas rotas de una entrada, en orden.
	 *
	 * @param int $id Entrada.
	 *
	 * @return list<int> IDs de fila del grafo.
	 */
	protected function broken_ids( int $id ): array {
		$ids = array();
		foreach ( $this->link_rows( $id ) as $row ) {
			if ( (int) $row['is_broken'] > 0 ) {
				$ids[] = (int) $row['id'];
			}
		}

		return $ids;
	}

	/**
	 * Petición como la que arma el controlador a partir de la fila del grafo.
	 *
	 * @param int      $link_id Fila del grafo.
	 * @param string   $mode    Modo.
	 * @param int|null $target  Destino.
	 */
	protected function request( int $link_id, string $mode, ?int $target = null ): FixRequest {
		$row = Plugin::container()->get( BrokenRepository::class )->occurrence( $link_id );
		$this->assertNotNull( $row, 'La fila del grafo existe.' );

		return new FixRequest(
			$row['source_id'],
			$mode,
			$row['url'],
			$row['ordinal'],
			$row['count'],
			$row['anchor'],
			FixRequest::RETARGET === $mode ? (string) get_permalink( $target ?? $this->target ) : null
		);
	}

	protected function repairer(): Repairer {
		return Plugin::container()->get( Repairer::class );
	}

	protected function undo(): Undo {
		return Plugin::container()->get( Undo::class );
	}

	protected function redo(): Redo {
		return Plugin::container()->get( Redo::class );
	}

	/**
	 * Arregla y devuelve el resultado.
	 *
	 * @param int      $link_id Fila del grafo.
	 * @param string   $mode    Modo.
	 * @param int|null $target  Destino.
	 */
	protected function fix( int $link_id, string $mode = FixRequest::RETARGET, ?int $target = null ): InsertResult {
		return $this->repairer()->fix( $this->request( $link_id, $mode, $target ) );
	}

	/**
	 * Motivo por el que se niega un arreglo.
	 *
	 * @param callable $work Lo que se intenta.
	 */
	protected function refusal( callable $work ): string {
		try {
			$work();
		} catch ( InsertionException $e ) {
			return $e->reason();
		}

		$this->fail( 'Debía negarse.' );
	}
}
