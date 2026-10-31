<?php
/**
 * Los resultados del motor sobre un corpus fijo no cambian (F1-32): quien optimice el cálculo de las
 * sugerencias tiene que dejar exactamente las mismas, con las mismas puntuaciones.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\Indexer;
use MagicLinking\Engine\MemoryIndex;
use MagicLinking\Engine\PhraseFinder;
use MagicLinking\Engine\Reason;
use MagicLinking\Engine\Scorer;
use MagicLinking\Engine\Suggester;
use MagicLinking\Engine\Suggestion;
use PHPUnit\Framework\TestCase;

/**
 * 24 entradas de tres temas en castellano y una en inglés, con párrafos largos, enlaces ya puestos, páginas
 * y entradas pilar; se calculan todas las salientes y todas las entrantes con varias combinaciones de opciones
 * y se comparan, línea a línea, con las guardadas en `golden/suggestions.json`.
 *
 * Para regenerar la huella a propósito (cuando el motor cambia de verdad y el banco lo avala):
 * `MAGICLINKING_UPDATE_GOLDEN=1 composer test -- --filter SuggestionsGoldenTest`.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions -- Prueba unitaria sin WordPress: no hay wp_json_encode() ni WP_Filesystem.

final class SuggestionsGoldenTest extends TestCase {

	private const NOW = 1790000000;

	private const COUNT = 24;

	private const TOPICS = array(
		array( 'aerotermia', 'bomba de calor', 'suelo radiante', 'placas solares', 'caldera de gas', 'aislamiento térmico', 'ventilación mecánica', 'radiadores de baja temperatura' ),
		array( 'aceite de oliva', 'gazpacho andaluz', 'tortilla de patatas', 'cocina mediterránea', 'pan de masa madre', 'queso manchego', 'vino tinto', 'jamón ibérico' ),
		array( 'ruta del Quijote', 'molinos de viento', 'parque nacional', 'turismo rural', 'casa rural', 'castillo medieval', 'camino de Santiago', 'ciudad histórica' ),
		array( 'solar panels', 'heat pump', 'underfloor heating', 'wood stove' ),
	);

	private const FILLER = array( 'casa', 'proyecto', 'tiempo', 'historia', 'familia', 'precio', 'calidad', 'servicio', 'verano', 'invierno', 'ciudad', 'campo', 'camino', 'mercado', 'cliente', 'energía', 'consumo', 'ahorro', 'factura', 'año', 'mes', 'obra', 'plan', 'idea', 'lugar', 'persona', 'trabajo', 'forma', 'caso', 'ejemplo' );

	private const GLUE = array( 'es', 'una', 'de', 'con', 'para', 'muy', 'cuando', 'siempre', 'también', 'sobre', 'entre', 'desde', 'hasta', 'según', 'donde', 'porque', 'aunque', 'además', 'cada', 'otro' );

	private int $state = 12345;

	private function next( int $max ): int {
		$this->state = ( $this->state * 1103515245 + 12345 ) & 0x7fffffff;

		return ( $this->state >> 8 ) % $max;
	}

	private function pick( array $items ): string {
		return $items[ $this->next( count( $items ) ) ];
	}

	/**
	 * @return array{0: ArraySource, 1: MemoryIndex, 2: Indexer}
	 */
	private function corpus(): array {
		$this->state = 12345;
		$source      = new ArraySource();

		for ( $id = 1; $id <= self::COUNT; $id++ ) {
			$topic   = 0 === $id % 9 ? 3 : $id % 3;
			$lang    = 3 === $topic ? 'en' : 'es';
			$phrases = self::TOPICS[ $topic ];
			$title   = ucfirst( $this->pick( $phrases ) ) . ' ' . $this->pick( self::FILLER ) . ' ' . $id;
			$paras   = array();
			$count   = 6 + $this->next( 7 );
			for ( $p = 0; $p < $count; $p++ ) {
				$words = array();
				$len   = 25 + $this->next( 25 );
				for ( $w = 0; $w < $len; $w++ ) {
					$roll = $this->next( 10 );
					if ( $roll < 2 ) {
						$words[] = $this->pick( $phrases );
					} elseif ( $roll < 3 ) {
						$words[] = $this->pick( self::TOPICS[ $this->next( 3 ) ] );
					} elseif ( $roll < 6 ) {
						$words[] = $this->pick( self::FILLER );
					} else {
						$words[] = $this->pick( self::GLUE );
					}
				}
				// Algunos enlaces ya puestos a otras entradas del mismo tema.
				if ( 0 === $this->next( 5 ) ) {
					$other                        = 1 + $this->next( self::COUNT );
					$words[ $this->next( $len ) ] = '[[' . $this->pick( $phrases ) . '|' . $other . ']]';
				}
				$paras[] = ucfirst( implode( ' ', $words ) ) . '.';
			}
			$type = 0 === $id % 7 ? 'page' : 'post';
			$source->add( $id, $title, $paras, $lang, $type, '', self::NOW - 86400 * ( 1 + $this->next( 900 ) ) );
		}

		$indexer = new Indexer();
		$index   = new MemoryIndex();
		$indexer->build( $source, $index );

		return array( $source, $index, $indexer );
	}

	/**
	 * Una sugerencia en una línea de texto, con las puntuaciones a 6 decimales: así no depende de los últimos bits de un
	 * flotante (cambian entre procesadores y versiones de PHP) y, si algo cambia, el fallo dice qué sugerencia fue.
	 */
	private static function line( Suggestion $s ): string {
		$map     = static function ( array $values ): string {
			$out = array();
			foreach ( $values as $name => $value ) {
				$out[] = $name . '=' . sprintf( '%.6f', $value );
			}

			return implode( ',', $out );
		};
		$reasons = array_map( static fn( Reason $r ): string => $r->code . ':' . json_encode( $r->args, JSON_UNESCAPED_UNICODE ), $s->reasons );

		return sprintf(
			'%d>%d "%s" @%d/%d score=%.6f %s penalties[%s] bonuses[%s] reasons[%s] alt[%s]',
			$s->source,
			$s->target,
			$s->anchor,
			$s->paragraph,
			$s->offset,
			$s->score->value,
			$s->score->passes ? 'pasa' : 'no-pasa',
			$map( $s->score->penalties ),
			$map( $s->score->bonuses ),
			implode( ';', $reasons ),
			implode( ';', array_map( array( self::class, 'line' ), $s->alternatives ) )
		);
	}

	public function test_results_on_a_fixed_corpus_do_not_change(): void {
		[ $source, $index, $indexer ] = $this->corpus();

		$variants = array(
			'defecto'     => array(),
			'sin-enlaces' => array( 'existing_links' => false ),
			'pilares'     => array(
				'pillars'      => array( 3, 10, 21, 23 ),
				'alternatives' => 2,
				'penalized'    => array( 5, 6 ),
			),
			'filtros'     => array(
				'filters'          => array( 'type' => Suggester::REQUIRE ),
				'never'            => array( 4, 7 ),
				'excluded_origins' => array( 8, 9, 11 ),
				'max_outgoing'     => 5,
			),
		);

		$out = array();
		foreach ( $variants as $name => $options ) {
			$suggester = new Suggester( $index, $source, $indexer, new PhraseFinder(), new Scorer(), null, $options + array( 'now' => self::NOW ) );
			$lines     = array();
			for ( $id = 1; $id <= self::COUNT; $id++ ) {
				$document = $source->get( $id );
				$this->assertNotNull( $document );
				foreach ( $suggester->incoming( $id ) as $s ) {
					$lines[] = 'entrante ' . $id . ' ' . self::line( $s );
				}
				foreach ( $suggester->outgoing( $document ) as $s ) {
					$lines[] = 'saliente ' . $id . ' ' . self::line( $s );
				}
			}
			$this->assertGreaterThan( 0, count( $lines ), $name );
			$out[ $name ] = $lines;
		}

		$file = __DIR__ . '/golden/suggestions.json';
		if ( '1' === getenv( 'MAGICLINKING_UPDATE_GOLDEN' ) ) {
			file_put_contents( $file, json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
		}

		$golden = json_decode( (string) file_get_contents( $file ), true );
		$this->assertIsArray( $golden );
		foreach ( $out as $name => $lines ) {
			$this->assertSame( $golden[ $name ] ?? array(), $lines, $name );
		}
	}
}
