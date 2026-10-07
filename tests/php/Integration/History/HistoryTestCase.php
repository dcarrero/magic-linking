<?php
/**
 * Base de las pruebas del historial: entradas con un enlace insertado de verdad y envejecimiento de grupos.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\History;

use MagicLinking\Content\InsertRequest;
use MagicLinking\Content\Inserter;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\History\BatchId;
use MagicLinking\History\ChangeRepository;
use MagicLinking\Tests\Integration\GraphTestCase;

abstract class HistoryTestCase extends GraphTestCase {

	/**
	 * Quien edita.
	 *
	 * @var int
	 */
	protected int $admin = 0;

	/**
	 * Destino de los enlaces.
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

	protected function p( string $html ): string {
		return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Entrada con una frase enlazable.
	 *
	 * @param array $extra Campos extra.
	 */
	protected function post( array $extra = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_title'   => 'Origen ' . wp_generate_password( 4, false ),
					'post_content' => $this->p( 'Compra aire acondicionado ya.' ),
				),
				$extra
			)
		);
	}

	/**
	 * Inserta el enlace en una entrada nueva (o en la dada) y devuelve el resultado.
	 *
	 * @param int|null    $post  Entrada; null = una nueva.
	 * @param string|null $batch Lote.
	 *
	 * @return array{post: int, change: int, batch: string}
	 */
	protected function link( ?int $post = null, ?string $batch = null ): array {
		$post   = $post ?? $this->post();
		$result = Plugin::container()->get( Inserter::class )->insert(
			new InsertRequest( $post, (string) get_permalink( $this->target ), 'aire acondicionado', 'Compra ', ' ya.' ),
			$batch
		);

		return array(
			'post'   => $post,
			'change' => $result->change_id,
			'batch'  => $result->batch_id,
		);
	}

	/**
	 * Un lote de N entradas enlazadas, cada una con su cambio.
	 *
	 * @param int         $count Cuántas.
	 * @param string|null $batch Identificador del lote; null = uno nuevo.
	 *
	 * @return array{batch: string, posts: list<int>, changes: list<int>}
	 */
	protected function batch( int $count, ?string $batch = null ): array {
		$batch ??= BatchId::generate();
		$out     = array(
			'batch'   => $batch,
			'posts'   => array(),
			'changes' => array(),
		);
		for ( $i = 0; $i < $count; $i++ ) {
			$linked           = $this->link( null, $batch );
			$out['posts'][]   = $linked['post'];
			$out['changes'][] = $linked['change'];
		}

		return $out;
	}

	/**
	 * Pone toda la actividad de un lote hace N días.
	 *
	 * @param string $batch Lote.
	 * @param int    $days  Días.
	 */
	protected function age( string $batch, int $days ): void {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'changes' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s WHERE batch_id = %s', $table, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ), $batch ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Cuántas filas tiene un lote.
	 *
	 * @param string $batch Lote.
	 */
	protected function rows( string $batch ): int {
		return count( Plugin::container()->get( ChangeRepository::class )->batch( $batch ) );
	}

	/**
	 * Contenido guardado ahora.
	 *
	 * @param int $post Entrada.
	 */
	protected function stored( int $post ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post ) ); // phpcs:ignore WordPress.DB
	}
}
