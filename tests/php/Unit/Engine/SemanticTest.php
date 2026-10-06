<?php
/**
 * Capa semántica pura: coseno, reescalado, mezcla y texto que se vectoriza.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\Document;
use MagicLinking\Engine\MemoryVectors;
use MagicLinking\Engine\Paragraph;
use MagicLinking\Engine\Semantic;
use PHPUnit\Framework\TestCase;

final class SemanticTest extends TestCase {

	public function test_cosine(): void {
		$this->assertEqualsWithDelta( 1.0, Semantic::cosine( array( 1.0, 2.0, 3.0 ), array( 2.0, 4.0, 6.0 ) ), 1e-9 );
		$this->assertEqualsWithDelta( 0.0, Semantic::cosine( array( 1.0, 0.0 ), array( 0.0, 5.0 ) ), 1e-9 );
		$this->assertEqualsWithDelta( -1.0, Semantic::cosine( array( 1.0, 1.0 ), array( -3.0, -3.0 ) ), 1e-9 );
		$this->assertEqualsWithDelta( sqrt( 0.5 ), Semantic::cosine( array( 1.0, 0.0 ), array( 1.0, 1.0 ) ), 1e-9 );
	}

	public function test_cosine_of_null_or_mismatched_vectors_is_zero(): void {
		$this->assertSame( 0.0, Semantic::cosine( array( 0.0, 0.0 ), array( 1.0, 1.0 ) ) );
		$this->assertSame( 0.0, Semantic::cosine( array( 1.0, 0.0 ), array( 1.0, 0.0, 0.0 ) ) );
		$this->assertSame( 0.0, Semantic::cosine( array(), array() ) );
	}

	public function test_normalize_gives_unit_norm_and_dot_equals_cosine(): void {
		$a = Semantic::normalize( array( 3.0, 4.0 ) );
		$this->assertEqualsWithDelta( array( 0.6, 0.8 ), $a, 1e-9 );
		$b = Semantic::normalize( array( 1.0, 2.0 ) );
		$this->assertEqualsWithDelta( Semantic::cosine( array( 3.0, 4.0 ), array( 1.0, 2.0 ) ), Semantic::dot( $a, $b ), 1e-9 );
		$this->assertSame( array( 0.0, 0.0 ), Semantic::normalize( array( 0.0, 0.0 ) ) );
	}

	public function test_rescale_maps_batch_to_zero_one(): void {
		$this->assertEqualsWithDelta(
			array(
				7 => 1.0,
				8 => 0.5,
				9 => 0.0,
			),
			Semantic::rescale(
				array(
					7 => 0.9,
					8 => 0.8,
					9 => 0.7,
				)
			),
			1e-9
		);
		$this->assertSame(
			array(
				1 => 1.0,
				2 => 1.0,
			),
			Semantic::rescale(
				array(
					1 => 0.8,
					2 => 0.8,
				)
			),
			'sin variación, todas 1'
		);
		$this->assertSame( array( 5 => 1.0 ), Semantic::rescale( array( 5 => 0.3 ) ) );
		$this->assertSame( array(), Semantic::rescale( array() ) );
	}

	public function test_blend(): void {
		$this->assertEqualsWithDelta( 0.6, Semantic::blend( 0.4, 0.8 ), 1e-9, 'por defecto, mitad y mitad' );
		$this->assertEqualsWithDelta( 0.52, Semantic::blend( 0.4, 0.8, 0.3 ), 1e-9 );
		$this->assertEqualsWithDelta( 0.4, Semantic::blend( 0.4, 0.8, 0.0 ), 1e-9, 'peso 0: solo léxico' );
		$this->assertEqualsWithDelta( 0.8, Semantic::blend( 0.4, 0.8, 1.0 ), 1e-9, 'peso 1: solo coseno' );
		$this->assertEqualsWithDelta( 0.8, Semantic::blend( 0.4, 0.8, 7.0 ), 1e-9, 'el peso se recorta a 0–1' );
		$this->assertEqualsWithDelta( 1.0, Semantic::blend( 1.5, 1.5 ), 1e-9, 'resultado en 0–1' );
	}

	public function test_text_has_title_headings_and_first_words(): void {
		$document = new Document(
			1,
			'post',
			'es',
			'Aerotermia: guía',
			'',
			0,
			array( 'Qué es', '', 'Cuánto cuesta' ),
			array( new Paragraph( 'uno dos tres' ), new Paragraph( '   ' ), new Paragraph( 'cuatro cinco seis siete' ) )
		);

		$this->assertSame( "Aerotermia: guía\n\nQué es · Cuánto cuesta\n\nuno dos tres\ncuatro cinco seis siete", Semantic::text( $document ) );
		$this->assertSame( "Aerotermia: guía\n\nQué es · Cuánto cuesta\n\nuno dos tres\ncuatro cinco", Semantic::text( $document, 5 ) );
		$this->assertSame( 'Aerotermia', Semantic::text( $document, 5, 10 ), 'tope de caracteres, sin partir UTF-8' );
		$this->assertSame( 'Solo título', Semantic::text( new Document( 2, 'post', 'es', 'Solo título' ) ) );
	}

	public function test_memory_vectors_nearest_by_language_with_exclusions(): void {
		$store = new MemoryVectors();
		$store->put( 1, 'es', array( 1.0, 0.0 ) );
		$store->put( 2, 'es', array( 1.0, 1.0 ) );
		$store->put( 3, 'es', array( 0.0, 2.0 ) );
		$store->put( 4, 'en', array( 1.0, 0.0 ) );

		$this->assertSame( array( 1, 2, 3 ), array_keys( $store->nearest( array( 5.0, 0.0 ), 'es', 10 ) ) );
		$this->assertSame( array( 2 ), array_keys( $store->nearest( array( 1.0, 0.0 ), 'es', 1, array( 1 ) ) ) );
		$this->assertSame( array( 4 ), array_keys( $store->nearest( array( 1.0, 0.0 ), 'en', 10 ) ), 'nunca entre idiomas' );
		$this->assertEqualsWithDelta( sqrt( 0.5 ), $store->nearest( array( 1.0, 0.0 ), 'es', 10 )[2], 1e-9 );
		$this->assertEqualsWithDelta( Semantic::normalize( array( 1.0, 1.0 ) ), $store->vector( 2 ), 1e-9, 'se guarda normalizado' );
		$this->assertNull( $store->vector( 99 ) );

		$store->put( 4, 'es', array( 0.0, 1.0 ) );
		$this->assertSame( 4, $store->count() );
		$this->assertSame( array(), $store->nearest( array( 1.0, 0.0 ), 'en', 10 ), 'cambiar de idioma lo saca del anterior' );
	}
}
