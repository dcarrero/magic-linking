<?php
/**
 * Indexado léxico: pesos BM25 por campo y top-K.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Calcula el peso de cada término de una entrada y se queda con los K mejores.
 *
 * Peso: idf(t) · Σ_campo mult_campo · tf·(k1+1) / (tf + k1·(1 − b + b·len/avglen)) · bonus(n)
 *
 * len es la longitud de la entrada entera (doc_len), igual para todos los
 * campos. Solo entran en el top-K los términos con df ≥ min_df: un
 * término que solo tiene una entrada no puede unirla con otra.
 *
 * N-gramas casuales: la bonificación de bigramas y trigramas solo se
 * aplica si el n-grama está asentado (df ≥ solid_df) o aparece en un campo
 * fuerte (título, encabezado o frase objetivo) de la entrada. Un n-grama que
 * sale una sola vez, solo en el cuerpo y con df < solid_df no entra en el top-K.
 */
final class Indexer {

	/**
	 * Configuración por defecto.
	 */
	public const DEFAULTS = array(
		'k'           => 40,
		'k1'          => 1.2,
		'b'           => 0.75,
		'min_df'      => 2,
		'solid_df'    => 3,
		'ngram_bonus' => true,
	);

	/**
	 * Multiplicador por campo.
	 */
	public const FIELD_WEIGHTS = array(
		Analyzer::TITLE   => 3.0,
		Analyzer::HEADING => 2.0,
		Analyzer::BODY    => 1.0,
		Analyzer::FOCUS   => 4.0,
	);

	/**
	 * Bonificación por tamaño del n-grama.
	 */
	public const NGRAM_BONUS = array(
		1 => 1.0,
		2 => 1.5,
		3 => 2.0,
	);

	/**
	 * Configuración efectiva.
	 *
	 * @var array{k: int, k1: float, b: float, min_df: int, solid_df: int, ngram_bonus: bool}
	 */
	private array $config;

	/**
	 * Crea el indexador.
	 *
	 * @param array $config Claves de {@see self::DEFAULTS} que cambian.
	 *
	 * @phpstan-param array{k?: int, k1?: float, b?: float, min_df?: int, solid_df?: int, ngram_bonus?: bool} $config
	 */
	public function __construct( array $config = array() ) {
		$config       = array_merge( self::DEFAULTS, $config );
		$this->config = array(
			'k'           => (int) $config['k'],
			'k1'          => (float) $config['k1'],
			'b'           => (float) $config['b'],
			'min_df'      => (int) $config['min_df'],
			'solid_df'    => (int) $config['solid_df'],
			'ngram_bonus' => (bool) $config['ngram_bonus'],
		);
	}

	/**
	 * Configuración efectiva.
	 *
	 * @return array{k: int, k1: float, b: float, min_df: int, solid_df: int, ngram_bonus: bool}
	 */
	public function config(): array {
		return $this->config;
	}

	/**
	 * Indexa todas las entradas en dos pasadas: estadísticas (df, N, avglen) y
	 * después pesos y top-K.
	 *
	 * @param DocumentSource  $source Entradas.
	 * @param IndexRepository $index  Índice de destino.
	 * @return int Entradas indexadas.
	 */
	public function build( DocumentSource $source, IndexRepository $index ): int {
		foreach ( $source->all() as $document ) {
			$analyzed = Analyzer::for_language( $document->lang )->analyze( $document );
			$index->count_terms( $document->lang, $analyzed->terms(), $analyzed->length );
		}

		if ( $index instanceof MemoryIndex ) {
			$index->prune( $this->config['min_df'] );
		}

		$stats = array();
		$count = 0;
		foreach ( $source->all() as $document ) {
			$analyzer                   = Analyzer::for_language( $document->lang );
			$stats[ $document->lang ] ??= $index->stats( $document->lang );
			$index->put( $this->index( $analyzer->analyze( $document ), $stats[ $document->lang ], $analyzer ) );
			++$count;
		}
		return $count;
	}

	/**
	 * Resultado de indexar una entrada ya analizada.
	 *
	 * @param AnalyzedDocument $doc      Entrada analizada.
	 * @param IndexStats       $stats    Estadísticas del idioma.
	 * @param Analyzer         $analyzer Analizador del idioma (para las claves de las anclas).
	 */
	public function index( AnalyzedDocument $doc, IndexStats $stats, Analyzer $analyzer ): IndexedDoc {
		$document = $doc->document;
		$terms    = $this->top( $this->weigh( $doc, $stats ), $stats, $doc );

		$fields = array();
		foreach ( $doc->counts as $field => $counts ) {
			foreach ( $counts as $term => $tf ) {
				if ( isset( $terms[ $term ] ) ) {
					$fields[ (string) $term ] = ( $fields[ (string) $term ] ?? 0 ) | $field;
				}
			}
		}

		$links = array();
		foreach ( $document->links as $link ) {
			if ( null !== $link->target && $link->target !== $document->id ) {
				$links[] = array( $link->target, $analyzer->phrase_key( $link->anchor ) );
			}
		}

		$meta = new DocMeta( $document->id, $document->type, $document->lang, $document->title, $document->slug, $document->date, $doc->words, $doc->length, count( $links ), $document->focus, $document->taxonomies );

		return new IndexedDoc( $meta, $terms, $fields, $links );
	}

	/**
	 * Peso de todos los términos de una entrada, de mayor a menor.
	 *
	 * @param AnalyzedDocument $doc   Entrada analizada.
	 * @param IndexStats       $stats Estadísticas del idioma.
	 * @return array<string, float>
	 */
	public function weigh( AnalyzedDocument $doc, IndexStats $stats ): array {
		$k1      = $this->config['k1'];
		$norm    = $k1 * ( 1 - $this->config['b'] + $this->config['b'] * ( $stats->average > 0 ? $doc->length / $stats->average : 1.0 ) );
		$weights = array();

		foreach ( $doc->counts as $field => $counts ) {
			$multiplier = self::FIELD_WEIGHTS[ $field ] ?? 1.0;
			foreach ( $counts as $term => $tf ) {
				$weights[ (string) $term ] = ( $weights[ (string) $term ] ?? 0.0 ) + $multiplier * $tf * ( $k1 + 1 ) / ( $tf + $norm );
			}
		}

		foreach ( $weights as $term => $weight ) {
			// Un término numérico («2024») llega como clave entera.
			$term             = (string) $term;
			$size             = min( 3, substr_count( $term, ' ' ) + 1 );
			$bonus            = $this->config['ngram_bonus'] && ( $stats->df( $term ) >= $this->config['solid_df'] || self::strong( $doc, $term ) ) ? self::NGRAM_BONUS[ $size ] : 1.0;
			$weights[ $term ] = $weight * $stats->idf( $term ) * $bonus;
		}

		arsort( $weights );
		return $weights;
	}

	/**
	 * Los K términos de más peso con df ≥ min_df, sin n-gramas casuales.
	 *
	 * @param array<string, float>  $weights Pesos de mayor a menor.
	 * @param IndexStats            $stats   Estadísticas del idioma.
	 * @param AnalyzedDocument|null $doc     Entrada (para reconocer los n-gramas casuales).
	 * @return array<string, float>
	 */
	public function top( array $weights, IndexStats $stats, ?AnalyzedDocument $doc = null ): array {
		$top = array();
		foreach ( $weights as $term => $weight ) {
			$term = (string) $term;
			$df   = $stats->df( $term );
			if ( $df < $this->config['min_df'] || ( null !== $doc && $this->casual( $doc, $term, $df ) ) ) {
				continue;
			}
			$top[ $term ] = $weight;
			if ( count( $top ) >= $this->config['k'] ) {
				break;
			}
		}
		return $top;
	}

	/**
	 * Si un n-grama es casual en una entrada: de dos palabras o más, con df por
	 * debajo de solid_df, fuera de los campos fuertes y una sola vez en el cuerpo.
	 *
	 * @param AnalyzedDocument $doc  Entrada.
	 * @param string           $term Término.
	 * @param int              $df   Su df.
	 */
	public function casual( AnalyzedDocument $doc, string $term, int $df ): bool {
		return str_contains( $term, ' ' )
			&& $df < $this->config['solid_df']
			&& ! self::strong( $doc, $term )
			&& ( $doc->counts[ Analyzer::BODY ][ $term ] ?? 0 ) < 2;
	}

	/**
	 * Si un término aparece en un campo fuerte (título, encabezado o frase objetivo).
	 *
	 * @param AnalyzedDocument $doc  Entrada.
	 * @param string           $term Término.
	 */
	private static function strong( AnalyzedDocument $doc, string $term ): bool {
		return isset( $doc->counts[ Analyzer::TITLE ][ $term ] )
			|| isset( $doc->counts[ Analyzer::HEADING ][ $term ] )
			|| isset( $doc->counts[ Analyzer::FOCUS ][ $term ] );
	}
}
