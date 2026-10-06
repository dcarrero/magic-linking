<?php
/**
 * Corpus sintético pequeño en castellano para las pruebas del motor.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\Indexer;
use MagicLinking\Engine\MemoryIndex;
use MagicLinking\Engine\PhraseFinder;
use MagicLinking\Engine\Scorer;
use MagicLinking\Engine\Suggester;
use MagicLinking\Engine\VectorStore;

/**
 * Seis entradas sobre climatización, una de cocina y una página legal.
 */
final class Corpus {

	public const NOW = 1790000000;

	public ArraySource $source;

	public MemoryIndex $index;

	public Indexer $indexer;

	public function __construct( ?callable $extra = null ) {
		$this->source = new ArraySource();
		$s            = $this->source;

		$s->add(
			1,
			'Aerotermia: qué es y cómo funciona',
			array(
				'La aerotermia extrae energía del aire exterior para producir calefacción y agua caliente.',
				'Una bomba de calor aerotérmica consume menos que una caldera de gas.',
				'La aerotermia combina bien con el suelo radiante y con las placas solares.',
			)
		);
		$s->add(
			2,
			'Bomba de calor para calefacción',
			array(
				'La bomba de calor mueve calor del aire al interior de la vivienda.',
				'Una bomba de calor bien dimensionada reduce el consumo de calefacción.',
				'Con aerotermia, la bomba de calor también produce agua caliente.',
			)
		);
		$s->add(
			3,
			'Placas solares para autoconsumo',
			array(
				'Las placas solares permiten el autoconsumo y bajan la factura de la luz.',
				'Si ya tienes aerotermia, las placas solares alimentan la bomba de calor.',
				'El autoconsumo con placas solares se amortiza en pocos años.',
			)
		);
		$s->add(
			4,
			'Suelo radiante: ventajas',
			array(
				'El suelo radiante reparte el calor de forma uniforme por la vivienda.',
				'El suelo radiante funciona a baja temperatura y encaja con la aerotermia.',
				'La [[bomba de calor|2]] alimenta el suelo radiante con agua templada.',
			)
		);
		$s->add(
			5,
			'Recetas de cocina mediterránea',
			array(
				'El aceite de oliva y el tomate son la base de la cocina mediterránea.',
				'Una receta mediterránea sencilla lleva tomate, ajo y aceite de oliva.',
			)
		);
		$s->add(
			6,
			'Gazpacho andaluz',
			array(
				'El gazpacho lleva tomate maduro, pepino y aceite de oliva.',
				'Es una receta de cocina mediterránea para el verano.',
			)
		);
		$s->add(
			7,
			'Aviso legal y calefacción',
			array( 'Condiciones de uso del sitio sobre calefacción y bomba de calor.' ),
			'es',
			'page',
			'aviso-legal'
		);
		if ( null !== $extra ) {
			$extra( $s );
		}

		$this->indexer = new Indexer();
		$this->index   = new MemoryIndex();
		$this->indexer->build( $this->source, $this->index );
	}

	/**
	 * @param array{max_outgoing?: int, never?: list<int>, existing_links?: bool, now?: int, semantic_weight?: float, semantic_retrieval?: bool} $options
	 */
	public function suggester( array $options = array(), ?Scorer $scorer = null, ?VectorStore $vectors = null ): Suggester {
		return new Suggester( $this->index, $this->source, $this->indexer, new PhraseFinder(), $scorer ?? new Scorer(), null, $options + array( 'now' => self::NOW ), $vectors );
	}
}
