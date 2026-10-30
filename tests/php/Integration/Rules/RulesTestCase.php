<?php
/**
 * Base de las pruebas de reglas automáticas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Rules;

use MagicLinking\Core\Plugin;
use MagicLinking\Graph\GraphIndexer;
use MagicLinking\Rules\Rule;
use MagicLinking\Rules\RuleRepository;
use MagicLinking\Rules\RuleService;
use MagicLinking\Rules\RuleValidator;
use MagicLinking\Tests\Integration\GraphTestCase;
use WP_Post;

abstract class RulesTestCase extends GraphTestCase {

	/**
	 * Texto de relleno sin ninguna frase de las reglas (unas 27 palabras).
	 */
	protected const FILLER = 'Este párrafo habla de otras cosas muy distintas y no contiene nada relevante para ninguna de las pruebas que se hacen aquí con el enlazado de entradas.';

	protected int $admin  = 0;
	protected int $target = 0;

	public function set_up(): void {
		parent::set_up();

		Plugin::container()->get( RuleRepository::class )->forget();
		// Todo en castellano, como los textos de las pruebas (el sitio de pruebas está en inglés).
		add_filter( 'magiclinking_post_language', static fn( $lang ) => $lang ?? 'es' );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$this->target = self::factory()->post->create(
			array(
				'post_title'   => 'Bomba de calor: guía',
				'post_name'    => 'bomba-de-calor',
				'post_content' => $this->p( 'Guía del destino.' ),
			)
		);
	}

	protected function p( string $html ): string {
		return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Contenido en bloques con relleno hasta tener unas $words palabras.
	 *
	 * @param list<string> $paragraphs Párrafos con la frase.
	 * @param int          $words      Palabras aproximadas.
	 */
	protected function body( array $paragraphs, int $words = 0 ): string {
		$blocks = array_map( fn( string $text ): string => $this->p( $text ), $paragraphs );
		for ( $i = 0; $i * 27 < $words; $i++ ) {
			$blocks[] = $this->p( self::FILLER . ' ' . $i );
		}

		return implode( "\n\n", $blocks );
	}

	protected function entry( string $content, array $extra = array() ): WP_Post {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_title'   => 'Origen ' . wp_generate_password( 4, false ),
					'post_content' => $content,
				),
				$extra
			)
		);

		return get_post( $id );
	}

	protected function repo(): RuleRepository {
		return Plugin::container()->get( RuleRepository::class );
	}

	protected function service(): RuleService {
		return Plugin::container()->get( RuleService::class );
	}

	/**
	 * Crea y guarda una regla hacia el destino de la prueba.
	 *
	 * @param array<string, mixed> $input Campos de la regla.
	 */
	protected function rule( array $input = array() ): Rule {
		$rule = Plugin::container()->get( RuleValidator::class )->validate(
			$input + array(
				'phrases'   => array( 'bomba de calor' ),
				'target_id' => $this->target,
			),
			null,
			$this->admin
		);
		$this->assertInstanceOf( Rule::class, $rule, is_wp_error( $rule ) ? $rule->get_error_message() : '' );
		$id    = $this->repo()->insert( $rule );
		$saved = $this->repo()->find( $id );
		$this->assertNotNull( $saved );

		return $saved;
	}

	/**
	 * Pone el grafo al día para las entradas indicadas (los topes del sitio leen el grafo guardado).
	 *
	 * @param list<int> $ids IDs.
	 */
	protected function index( array $ids ): void {
		$indexer = Plugin::container()->get( GraphIndexer::class );
		foreach ( $ids as $id ) {
			$indexer->index_and_refresh( $id, true );
		}
		$indexer->flush();
	}

	protected function content_of( int $post_id ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) ); // phpcs:ignore WordPress.DB
	}
}
