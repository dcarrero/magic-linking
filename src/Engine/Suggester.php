<?php
/**
 * Orquesta el motor: recuperación, anclas, puntuación y motivos.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Sugerencias salientes (desde una entrada) y entrantes (hacia una entrada).
 */
final class Suggester {

	/**
	 * Máximo de sugerencias salientes mostradas.
	 */
	public const MAX_OUTGOING = 15;

	/**
	 * Términos compartidos que se citan en el motivo.
	 */
	public const SHARED_TERMS = 3;

	/**
	 * Frases alternativas como máximo por destino.
	 */
	public const MAX_ALTERNATIVES = 2;

	/**
	 * Veces que se amplían las candidatas antes de aplicar un filtro Exigir (en las
	 * tablas del plugin el filtro va en la consulta y no hace falta).
	 */
	public const OVERFETCH = 5;

	/**
	 * Distancia máxima de una alternativa a la puntuación de la principal.
	 */
	public const ALTERNATIVE_MARGIN = 0.10;

	/**
	 * Modos de un filtro por tipo o taxonomía.
	 */
	public const IGNORE   = 'ignore';
	public const CONSIDER = 'consider';
	public const REQUIRE  = 'require';

	/**
	 * Filtros y la taxonomía de cada uno; `type` es el tipo de contenido.
	 */
	public const FILTERS = array(
		'type'     => '',
		'category' => 'category',
		'tag'      => 'post_tag',
	);

	/**
	 * Slugs de páginas que no son contenido (legal, contacto, tienda…): penalización
	 * «destino muy distinto en tipo» si el destino no es una entrada.
	 */
	public const UNLIKE_SLUGS = '/(?:^|-)(?:aviso-legal|legal|privacidad|privacy|cookies?|terminos|condiciones|terms|conditions|contacto|contact|carrito|cart|checkout|mi-cuenta|my-account|login|acceso|registro|register)(?:-|$)/';

	/**
	 * Máximo de sugerencias salientes.
	 *
	 * @var int
	 */
	private int $max_outgoing;

	/**
	 * Entradas que nunca se sugieren como destino.
	 *
	 * @var list<int>
	 */
	private array $never;

	/**
	 * Si se respetan los enlaces que ya existen (false en el modo de juicio del banco).
	 *
	 * @var bool
	 */
	private bool $existing;

	/**
	 * Momento de referencia para la frescura.
	 *
	 * @var int
	 */
	private int $now;

	/**
	 * Frases alternativas por destino (0 = solo la principal).
	 *
	 * @var int
	 */
	private int $alternatives;

	/**
	 * Distancia máxima de una alternativa a la principal.
	 *
	 * @var float
	 */
	private float $margin;

	/**
	 * Destinos pilar (ID → true).
	 *
	 * @var array<int, true>
	 */
	private array $pillars;

	/**
	 * Filtros activos: filtro → modo (solo Considerar y Exigir).
	 *
	 * @var array<string, string>
	 */
	private array $filters;

	/**
	 * Si el origen sin enlaces del modo de juicio cuenta los que tenía para la densidad (banco).
	 *
	 * @var bool
	 */
	private bool $count_removed;

	/**
	 * Recuperador.
	 *
	 * @var Retriever
	 */
	private Retriever $retriever;

	/**
	 * Peso del coseno en la relevancia cuando hay vectores.
	 *
	 * @var float
	 */
	private float $semantic_weight;

	/**
	 * Si las candidatas salientes salen del coseno en vez del índice léxico.
	 *
	 * @var bool
	 */
	private bool $semantic_retrieval;

	/**
	 * Crea el orquestador.
	 *
	 * @param IndexRepository  $index     Índice.
	 * @param DocumentSource   $documents Documentos completos (para los orígenes de entrantes).
	 * @param Indexer          $indexer   Indexador (pesos en caliente del origen).
	 * @param PhraseFinder     $finder    Buscador de anclas.
	 * @param Scorer           $scorer    Puntuador.
	 * @param Retriever|null   $retriever Recuperador; por defecto, el estándar sobre $index.
	 * @param array            $options   max_outgoing, never (IDs), existing_links (bool), now (marca de tiempo),
	 *                                    semantic_weight (peso del coseno, 0–1), semantic_retrieval (candidatas por coseno),
	 *                                    alternatives (frases alternativas por destino, 0–2), alternative_margin,
	 *                                    pillars (IDs de destinos pilar), filters (type|category|tag → ignore|consider|require),
	 *                                    count_removed_links (banco: la densidad cuenta los enlaces retirados).
	 * @param VectorStore|null $vectors  Vectores de las entradas (capa semántica); sin ellos, solo léxico.
	 *
	 * @phpstan-param array{max_outgoing?: int, never?: list<int>, existing_links?: bool, now?: int, semantic_weight?: float, semantic_retrieval?: bool, alternatives?: int, alternative_margin?: float, pillars?: list<int>, filters?: array<string, string>, count_removed_links?: bool} $options
	 */
	public function __construct(
		private IndexRepository $index,
		private DocumentSource $documents,
		private Indexer $indexer,
		private PhraseFinder $finder,
		private Scorer $scorer,
		?Retriever $retriever = null,
		array $options = array(),
		private ?VectorStore $vectors = null
	) {
		$this->retriever    = $retriever ?? new Retriever( $index );
		$this->max_outgoing = $options['max_outgoing'] ?? self::MAX_OUTGOING;
		$this->never        = $options['never'] ?? array();
		$this->existing     = $options['existing_links'] ?? true;
		$this->now          = $options['now'] ?? time();

		$this->semantic_weight    = $options['semantic_weight'] ?? Semantic::WEIGHT;
		$this->semantic_retrieval = $options['semantic_retrieval'] ?? false;

		$this->alternatives  = max( 0, min( self::MAX_ALTERNATIVES, $options['alternatives'] ?? 0 ) );
		$this->margin        = $options['alternative_margin'] ?? self::ALTERNATIVE_MARGIN;
		$this->pillars       = array_fill_keys( $options['pillars'] ?? array(), true );
		$this->count_removed = $options['count_removed_links'] ?? false;
		$this->filters       = array();
		foreach ( $options['filters'] ?? array() as $filter => $mode ) {
			if ( isset( self::FILTERS[ $filter ] ) && in_array( $mode, array( self::CONSIDER, self::REQUIRE ), true ) ) {
				$this->filters[ $filter ] = $mode;
			}
		}
	}

	/**
	 * Sugerencias salientes de una entrada, tal como está ahora (no la versión
	 * indexada): las mejores por encima del umbral, una por destino, una por
	 * frase y sin repetir ancla.
	 *
	 * Con vectores, la relevancia mezcla la similitud léxica y el
	 * coseno ({@see Semantic::blend()}); con `semantic_retrieval`, además, las
	 * candidatas son las más cercanas por coseno. Las anclas salen siempre del
	 * léxico: sin frase anclable no hay sugerencia.
	 *
	 * @param Document     $source Entrada abierta.
	 * @param float[]|null $vector Vector de la entrada abierta; si falta, el guardado.
	 * @return list<Suggestion>
	 *
	 * @phpstan-param list<float>|null $vector
	 */
	public function outgoing( Document $source, ?array $vector = null ): array {
		try {
			return $this->compute_outgoing( $source, $vector );
		} finally {
			if ( $this->index instanceof Preloads ) {
				$this->index->release();
			}
		}
	}

	/**
	 * Avisa al almacén de las entradas que va a consultar (antes de filtrarlas y puntuarlas, para que ninguna consulta sea por entrada).
	 *
	 * @param int[] $ids IDs.
	 *
	 * @phpstan-param list<int> $ids
	 */
	private function preload( array $ids ): void {
		if ( $this->index instanceof Preloads ) {
			$this->index->preload( $ids );
		}
	}

	/**
	 * Cálculo de {@see self::outgoing()}.
	 *
	 * @param Document     $source Entrada abierta.
	 * @param float[]|null $vector Vector de la entrada abierta.
	 * @return list<Suggestion>
	 *
	 * @phpstan-param list<float>|null $vector
	 */
	private function compute_outgoing( Document $source, ?array $vector ): array {
		$links = null;
		if ( ! $this->existing ) {
			$links  = $this->count_removed ? count( $source->links ) : null;
			$source = $source->without_links();
		}
		$analyzer = Analyzer::for_language( $source->lang );
		$analyzed = $analyzer->analyze( $source, true );
		$stats    = $this->index instanceof TermStats ? $this->index->stats_for( $source->lang, $analyzed->terms(), true )[0] : $this->index->stats( $source->lang );
		$weights  = $this->indexer->weigh( $analyzed, $stats );

		$exclude = array_values( array_unique( array_merge( array( $source->id ), $source->linked(), $this->never ) ) );
		$vectors = $this->vectors;
		$query   = null === $vectors ? null : ( $vector ?? $vectors->vector( $source->id ) );
		$cosines = array();

		$required = in_array( self::REQUIRE, $this->filters, true );
		if ( null !== $vectors && null !== $query && $this->semantic_retrieval ) {
			$cosines = $vectors->nearest( $query, $source->lang, Retriever::CANDIDATES, $exclude );
			$this->preload( array_keys( $cosines ) );
			$cosines    = $required ? $this->restrict( $source, $cosines ) : $cosines;
			$candidates = array();
			foreach ( array_keys( $cosines ) as $target ) {
				$candidates[ $target ] = self::lexical( $weights, $this->index->terms( $target ) );
			}
		} else {
			$candidates = $this->retriever->outgoing( $weights, $source->lang, $exclude, $required ? Retriever::CANDIDATES * self::OVERFETCH : null );
			$this->preload( array_keys( $candidates ) );
			if ( $required ) {
				$candidates = array_slice( $this->restrict( $source, $candidates ), 0, Retriever::CANDIDATES, true );
			}
			if ( null !== $vectors && null !== $query ) {
				$query = Semantic::normalize( $query );
				foreach ( array_keys( $candidates ) as $target ) {
					$other = $vectors->vector( $target );
					if ( null !== $other ) {
						$cosines[ $target ] = Semantic::dot( $query, $other );
					}
				}
			}
		}//end if
		$max     = array() === $candidates ? 0.0 : max( $candidates );
		$scaled  = Semantic::rescale( $cosines );
		$blocked = $this->blocked( $source, $analyzer );

		$found = array();
		foreach ( $candidates as $target => $similarity ) {
			$relevance = $max > 0 ? $similarity / $max : 0.0;
			$semantic  = $scaled[ $target ] ?? null;
			if ( null !== $semantic ) {
				$relevance = Semantic::blend( $relevance, $semantic, $this->semantic_weight );
			}
			$best = $this->best( $analyzed, $target, $relevance, $weights, $analyzer, $blocked, $semantic, $links );
			if ( null !== $best && $best[0]->score->passes ) {
				$found[] = $best;
			}
		}

		usort( $found, static fn( array $a, array $b ): int => array( $b[0]->score->value, $a[0]->target ) <=> array( $a[0]->score->value, $b[0]->target ) );

		$result    = array();
		$sentences = array();
		$anchors   = array();
		foreach ( $found as [ $suggestion, $sentence, $key ] ) {
			if ( isset( $sentences[ $sentence ] ) || isset( $anchors[ $key ] ) ) {
				continue;
			}
			$sentences[ $sentence ] = true;
			$anchors[ $key ]        = true;
			$result[]               = $suggestion;
			if ( count( $result ) >= $this->max_outgoing ) {
				break;
			}
		}
		return $result;
	}

	/**
	 * Sugerencias entrantes hacia una entrada: la mejor de cada origen, todas las
	 * que superan el umbral, de mayor a menor puntuación.
	 *
	 * @param int $target ID del destino.
	 * @return list<Suggestion>
	 */
	public function incoming( int $target ): array {
		try {
			return $this->compute_incoming( $target );
		} finally {
			if ( $this->index instanceof Preloads ) {
				$this->index->release();
			}
		}
	}

	/**
	 * Cálculo de {@see self::incoming()}.
	 *
	 * @param int $target ID del destino.
	 * @return list<Suggestion>
	 */
	private function compute_incoming( int $target ): array {
		$meta = $this->index->meta( $target );
		if ( null === $meta || in_array( $target, $this->never, true ) ) {
			return array();
		}
		$analyzer = Analyzer::for_language( $meta->lang );
		$phrases  = $this->finder->target_phrases( $meta, $this->index->terms( $target ), $analyzer );
		$exclude  = array_merge( array( $target ), $this->existing ? $this->index->linking_to( $target ) : array() );
		$origins  = $this->retriever->incoming( $meta, $phrases, $exclude );
		if ( $this->index instanceof Preloads ) {
			$this->index->preload( array_merge( array( $target ), $origins ) );
		}
		if ( $this->documents instanceof PrefetchesDocuments ) {
			$this->documents->prefetch( $origins );
		}

		$similarity = array();
		foreach ( $origins as $origin ) {
			$similarity[ $origin ] = $this->index->similarity( $origin, $target );
		}
		$max = array() === $similarity ? 0.0 : max( $similarity );

		$result = array();
		foreach ( $origins as $origin ) {
			$document = $this->documents->get( $origin );
			if ( null === $document ) {
				continue;
			}
			if ( ! $this->passes_required( $document, $meta ) ) {
				continue;
			}
			// El texto del origen manda sobre el índice: si ya enlaza al destino, no se vuelve a sugerir aunque el grafo no lo sepa todavía.
			if ( $this->existing && in_array( $target, $document->linked(), true ) ) {
				continue;
			}
			$links = null;
			if ( ! $this->existing ) {
				$links    = $this->count_removed ? count( $document->links ) : null;
				$document = $document->without_links();
			}
			$analyzed = $analyzer->analyze( $document, true );
			$best     = $this->best( $analyzed, $target, $max > 0 ? $similarity[ $origin ] / $max : 0.0, $this->index->terms( $origin ), $analyzer, $this->blocked( $document, $analyzer ), null, $links );
			if ( null !== $best && $best[0]->score->passes ) {
				$result[] = $best[0];
			}
		}//end foreach

		usort( $result, static fn( Suggestion $a, Suggestion $b ): int => array( $b->score->value, $a->source ) <=> array( $a->score->value, $b->source ) );
		return $result;
	}

	/**
	 * La mejor ancla de un origen hacia un destino, puntuada y con sus motivos.
	 *
	 * @param AnalyzedDocument     $source    Origen analizado con frases.
	 * @param int                  $target    ID del destino.
	 * @param float                $relevance Relevancia normalizada.
	 * @param array<string, float> $weights   Pesos del origen (para los términos compartidos).
	 * @param Analyzer             $analyzer  Analizador del idioma.
	 * @param array<string, int>   $blocked   Anclas que el origen ya usa → destino.
	 * @param float|null           $semantic  Coseno reescalado del lote, si hay vectores.
	 * @param int|null             $links     Enlaces que tenía el origen antes de retirarlos (banco), si cuentan.
	 * @return array{0: Suggestion, 1: int, 2: string}|null Sugerencia, índice de la frase y clave del ancla.
	 */
	private function best( AnalyzedDocument $source, int $target, float $relevance, array $weights, Analyzer $analyzer, array $blocked, ?float $semantic = null, ?int $links = null ): ?array {
		$meta = $this->index->meta( $target );
		if ( null === $meta ) {
			return null;
		}
		$terms   = $this->index->terms( $target );
		$phrases = $this->finder->target_phrases( $meta, $terms, $analyzer );
		$matches = $this->finder->find( $source, $phrases, $analyzer, $this->expansions( $meta, $terms, $analyzer ), $blocked, $target, $terms );
		if ( array() === $matches ) {
			return null;
		}

		$document = $source->document;
		$inbound  = count( $this->index->linking_to( $target ) );
		$age      = intdiv( max( 0, $this->now - $meta->date ), 86400 );
		$unlike   = 'post' !== $meta->type && 1 === preg_match( self::UNLIKE_SLUGS, $meta->slug );

		$pillar   = isset( $this->pillars[ $target ] );
		$affinity = $this->affinity( $document, $meta );
		$linked   = $links ?? count( $document->links );

		$scored = array();
		foreach ( $matches as $n => $match ) {
			$candidate = new Candidate( $relevance, $match->kind, $match->words, $inbound, $match->position, $match->last, $age, $linked, $source->words, $this->index->anchor_uses( $match->key, $target ), $unlike, $pillar, $affinity );
			$scored[]  = array( $match, $this->scorer->score( $candidate ), $n );
		}
		// Mayor puntuación primero; con empate, la primera aparición en el texto.
		usort( $scored, static fn( array $a, array $b ): int => array( $b[1]->value, $a[2] ) <=> array( $a[1]->value, $b[2] ) );
		[ $best, $score ] = $scored[0];

		$reasons = array();
		$shared  = $this->shared( $weights, $terms, $source->surfaces, $analyzer, $analyzer->foreign_document( $source ) );
		if ( array() !== $shared ) {
			$reasons[] = new Reason( Reason::SHARED_TERMS, array( 'terms' => $shared ) );
		}
		if ( Phrase::TITLE === $best->kind ) {
			$reasons[] = new Reason( Reason::ANCHOR_TITLE );
		} elseif ( Phrase::FOCUS === $best->kind ) {
			$reasons[] = new Reason( Reason::ANCHOR_FOCUS );
		}
		if ( 0 === $inbound ) {
			$reasons[] = new Reason( Reason::ORPHAN, array( 'inbound' => 0 ) );
		}
		if ( null !== $semantic && $semantic >= Semantic::REASON_MIN ) {
			$reasons[] = new Reason( Reason::SEMANTIC );
		}

		$sentence = $source->sentences[ $best->sentence ];

		// Frases alternativas: otras frases del origen, a no más de `margin` de la principal.
		$alternatives = array();
		$used         = array( $best->sentence => true );
		foreach ( array_slice( $scored, 1 ) as [ $match, $alt_score ] ) {
			if ( count( $alternatives ) >= $this->alternatives ) {
				break;
			}
			if ( isset( $used[ $match->sentence ] ) || $alt_score->value < $score->value - $this->margin ) {
				continue;
			}
			$used[ $match->sentence ] = true;
			$alt_sentence             = $source->sentences[ $match->sentence ];
			$alternatives[]           = new Suggestion( $document->id, $target, $match->anchor, $alt_sentence->text, $match->offset, $alt_sentence->paragraph, $alt_score, array() );
		}

		$suggestion = new Suggestion( $document->id, $target, $best->anchor, $sentence->text, $best->offset, $sentence->paragraph, $score, $reasons, $alternatives );

		return array( $suggestion, $best->sentence, $best->key );
	}

	/**
	 * Similitud léxica entre los pesos del origen y los términos principales de
	 * un destino (la misma suma que {@see IndexRepository::similar()}).
	 *
	 * @param array<string, float> $weights Pesos del origen.
	 * @param array<string, float> $terms   Términos principales del destino.
	 */
	private static function lexical( array $weights, array $terms ): float {
		$sum = 0.0;
		foreach ( $terms as $term => $weight ) {
			if ( isset( $weights[ $term ] ) ) {
				$sum += $weight * $weights[ $term ];
			}
		}
		return $sum;
	}

	/**
	 * Términos que más aportan a la similitud entre origen y destino, en su forma
	 * para mostrar (la superficie más frecuente en el origen, nunca la raíz y sin
	 * palabras vacías en los extremos, {@see Analyzer::display_term()}); los que
	 * están contenidos en otro ya citado no se repiten.
	 *
	 * @param array<string, float>  $weights  Pesos del origen.
	 * @param array<string, float>  $terms    Términos principales del destino.
	 * @param array<string, string> $surfaces Formas para mostrar del origen.
	 * @param Analyzer              $analyzer Analizador del idioma del origen.
	 * @param string|null           $foreign  Idioma que domina el texto del origen si no es el de la entrada.
	 * @return list<string>
	 */
	private function shared( array $weights, array $terms, array $surfaces, Analyzer $analyzer, ?string $foreign = null ): array {
		$products = array();
		foreach ( $terms as $term => $weight ) {
			if ( isset( $weights[ $term ] ) ) {
				$products[ (string) $term ] = $weight * $weights[ $term ];
			}
		}
		arsort( $products );

		$shown = array();
		$seen  = array();
		foreach ( array_keys( $products ) as $term ) {
			$term = (string) $term;
			// Sin forma de superficie solo habría la raíz («aerotermi»): no se enseña.
			$form = isset( $surfaces[ $term ] ) ? $analyzer->display_term( $surfaces[ $term ], $foreign ) : null;
			if ( null === $form ) {
				continue;
			}
			// Un término contenido en otro ya citado (o que lo contiene) no se repite, por raíz y por forma.
			$padded = array( ' ' . $term . ' ', ' ' . $form . ' ' );
			foreach ( $seen as $other ) {
				if ( str_contains( $other[0], $padded[0] ) || str_contains( $padded[0], $other[0] ) || str_contains( $other[1], $padded[1] ) || str_contains( $padded[1], $other[1] ) ) {
					continue 2;
				}
			}
			$seen[]  = $padded;
			$shown[] = $form;
			if ( count( $shown ) >= self::SHARED_TERMS ) {
				break;
			}
		}
		return $shown;
	}

	/**
	 * Palabras con las que se puede ampliar un ancla: las del título del destino
	 * que además son términos principales suyos (así no entran verbos ni
	 * palabras de relleno del título).
	 *
	 * @param DocMeta              $meta     Destino.
	 * @param array<string, float> $terms    Términos principales del destino.
	 * @param Analyzer             $analyzer Analizador.
	 * @return array<string, true>
	 */
	private function expansions( DocMeta $meta, array $terms, Analyzer $analyzer ): array {
		$keys = array();
		foreach ( $analyzer->tokenizer()->tokenize( $meta->title ) as $token ) {
			$key = $analyzer->key( $token );
			if ( ! $token->is_stopword && isset( $terms[ $key ] ) ) {
				$keys[ $key ] = true;
			}
		}
		return $keys;
	}

	/**
	 * Valores de una entrada para un filtro (tipo de contenido o taxonomía).
	 *
	 * @param string                      $filter     Filtro de {@see FILTERS}.
	 * @param string                      $type       Tipo de contenido.
	 * @param array<string, list<string>> $taxonomies Taxonomías de la entrada.
	 * @return list<string>
	 */
	private static function values( string $filter, string $type, array $taxonomies ): array {
		return '' === self::FILTERS[ $filter ] ? array( $type ) : ( $taxonomies[ self::FILTERS[ $filter ] ] ?? array() );
	}

	/**
	 * Filtros en modo Considerar que comparten origen y destino.
	 *
	 * @param Document $source Origen.
	 * @param DocMeta  $target Destino.
	 */
	private function affinity( Document $source, DocMeta $target ): int {
		$count = 0;
		foreach ( $this->filters as $filter => $mode ) {
			if ( self::CONSIDER === $mode && array() !== array_intersect( self::values( $filter, $source->type, $source->taxonomies ), self::values( $filter, $target->type, $target->taxonomies ) ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Si origen y destino cumplen los filtros en modo Exigir: comparten al menos un valor de cada uno.
	 *
	 * @param Document $source Origen.
	 * @param DocMeta  $target Destino.
	 */
	private function passes_required( Document $source, DocMeta $target ): bool {
		foreach ( $this->filters as $filter => $mode ) {
			if ( self::REQUIRE === $mode && array() === array_intersect( self::values( $filter, $source->type, $source->taxonomies ), self::values( $filter, $target->type, $target->taxonomies ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Candidatas que cumplen los filtros Exigir.
	 *
	 * @param Document          $source     Origen.
	 * @param array<int, float> $candidates ID → similitud.
	 * @return array<int, float>
	 */
	private function restrict( Document $source, array $candidates ): array {
		return array_filter(
			$candidates,
			function ( int $id ) use ( $source ): bool {
				$meta = $this->index->meta( $id );
				return null !== $meta && $this->passes_required( $source, $meta );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Anclas que un documento ya usa, por clave, con su destino.
	 *
	 * @param Document $document Documento.
	 * @param Analyzer $analyzer Analizador.
	 * @return array<string, int>
	 */
	private function blocked( Document $document, Analyzer $analyzer ): array {
		$blocked = array();
		foreach ( $document->links as $link ) {
			if ( null !== $link->target ) {
				$blocked[ $analyzer->phrase_key( $link->anchor ) ] = $link->target;
			}
		}
		return $blocked;
	}
}
