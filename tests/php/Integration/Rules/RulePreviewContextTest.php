<?php
/**
 * Reglas: el contexto de la vista previa conserva el espacio junto al ancla.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Rules;

final class RulePreviewContextTest extends RulesTestCase {

	/**
	 * @param string $text Párrafo.
	 *
	 * @return array{anchor: string, before: string, after: string}
	 */
	private function link_in( string $text ): array {
		$post    = $this->entry( $this->body( array( $text ) ) );
		$preview = $this->service()->preview( $post, $this->rule() );

		$this->assertNotSame( array(), $preview['links'], 'La regla encaja en: ' . $text );

		return $preview['links'][0];
	}

	public function test_a_boundary_with_a_space_keeps_it(): void {
		$link = $this->link_in( 'Una bomba de calor ahorra energía.' );

		$this->assertSame( 'bomba de calor', $link['anchor'] );
		$this->assertStringEndsWith( 'Una ', $link['before'] );
		$this->assertStringStartsWith( ' ahorra', $link['after'] );
	}

	public function test_a_boundary_without_a_space_keeps_it_too(): void {
		$link = $this->link_in( 'Mira esto: una bomba de calor, ten cuidado.' );

		$this->assertStringStartsWith( ', ten', $link['after'] );
		$this->assertStringEndsWith( 'una ', $link['before'] );
	}

	public function test_an_opening_quote_is_not_separated_from_the_anchor(): void {
		$link = $this->link_in( 'Sobre «bomba de calor» y más cosas.' );

		$this->assertStringEndsWith( '«', $link['before'] );
		$this->assertStringStartsWith( '»', $link['after'] );
	}

	public function test_only_the_far_end_is_trimmed_when_the_context_is_long(): void {
		$link = $this->link_in( str_repeat( 'palabra ', 30 ) . 'y una bomba de calor ' . str_repeat( 'cosa ', 30 ) . 'fin' );

		$this->assertStringStartsWith( '…', $link['before'] );
		$this->assertStringEndsWith( 'y una ', $link['before'] );
		$this->assertStringStartsWith( ' cosa', $link['after'] );
		$this->assertStringEndsWith( '…', $link['after'] );
	}
}
