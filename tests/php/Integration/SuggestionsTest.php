<?php
/**
 * Sugerencias sobre el índice persistente (F1-07): mismos resultados que el motor en memoria.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\Engine\Analyzer;
use MagicLinking\Engine\Document;
use MagicLinking\Engine\DocumentSource;
use MagicLinking\Engine\Indexer;
use MagicLinking\Engine\IndexedDoc;
use MagicLinking\Engine\MemoryIndex;
use MagicLinking\Engine\PhraseFinder;
use MagicLinking\Engine\Scorer;
use MagicLinking\Engine\Suggester;
use MagicLinking\Engine\Suggestion;
use MagicLinking\Index\LexicalIndexer;
use MagicLinking\Index\Suggestions;
use MagicLinking\Index\TableDocuments;
use MagicLinking\Index\TableRepository;
use MagicLinking\Jobs\Jobs;

final class SuggestionsTest extends GraphTestCase {

	private const NOW = 1790000000;

	/**
	 * Frases (títulos) por tema; el tema «en» es inglés.
	 */
	private const TOPICS = array(
		'es-clima'  => array( 'aerotermia', 'bomba de calor', 'suelo radiante', 'placas solares', 'caldera de gas', 'aislamiento térmico', 'ventilación mecánica' ),
		'es-cocina' => array( 'aceite de oliva', 'gazpacho andaluz', 'tortilla de patatas', 'cocina mediterránea', 'pan de masa madre', 'queso manchego' ),
		'es-viajes' => array( 'ruta del Quijote', 'molinos de viento', 'parque nacional', 'turismo rural', 'casa rural', 'castillo medieval' ),
		'en-home'   => array( 'solar panels', 'heat pump', 'underfloor heating', 'wood stove', 'double glazing' ),
	);

	/**
	 * Plantillas de frase; {a} y {b} son frases del tema.
	 */
	private const TEMPLATES = array(
		'es' => array(
			'Muchos propietarios se preguntan por {a} antes de decidir si {b} compensa en una vivienda de tamaño medio.',
			'Cuando se compara {a} con {b} conviene mirar el coste total durante varios años y no solo el precio inicial.',
			'Los profesionales recomiendan estudiar {a} junto a {b} porque los dos cambian el resultado final.',
			'En esta guía repasamos {a}, sus ventajas más claras y los errores que se repiten con {b} en cada instalación.',
			'Nadie lo dice, pero {a} y {b} dependen del mismo cuidado diario durante los meses de más uso.',
		),
		'en' => array(
			'Many owners wonder about {a} before deciding whether {b} pays off in an average sized house.',
			'When comparing {a} with {b} it helps to look at the total cost over several years and not only the upfront price.',
			'Professionals suggest studying {a} together with {b} because both change the final result.',
		),
	);

	/**
	 * IDs de las entradas en inglés (para el filtro de idioma).
	 *
	 * @var list<int>
	 */
	private array $english = array();

	/**
	 * Entradas creadas: ID → tema.
	 *
	 * @var array<int, string>
	 */
	private array $created = array();

	/**
	 * Estado del generador pseudoaleatorio de la prueba (determinista: el corpus es siempre el mismo).
	 *
	 * @var int
	 */
	private int $seed = 7;

	private TableRepository $repo;

	private Suggestions $service;

	private Jobs $jobs;

	public function set_up(): void {
		parent::set_up();

		add_filter( 'locale', static fn(): string => 'es_ES' );
		add_filter(
			'magiclinking_post_language',
			fn( $lang, $id ) => in_array( (int) $id, $this->english, true ) ? 'en' : null,
			10,
			2
		);

		$this->jobs    = Plugin::container()->get( Jobs::class );
		$this->repo    = Plugin::container()->get( TableRepository::class );
		$this->service = Plugin::container()->get( Suggestions::class );
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );
	}

	/**
	 * Crea el corpus: entradas de varios temas, algunas con enlaces entre ellas, y lo indexa entero.
	 */
	private function corpus(): void {
		$this->seed = 7;

		$phrases = array();
		foreach ( self::TOPICS as $topic => $list ) {
			$lang = str_starts_with( $topic, 'en' ) ? 'en' : 'es';
			foreach ( $list as $phrase ) {
				foreach ( array( 'Guía de ' . $phrase, ucfirst( $phrase ) . ': preguntas frecuentes' ) as $n => $title ) {
					$title = 'en' === $lang ? ( 0 === $n ? 'Guide to ' . $phrase : ucfirst( $phrase ) . ': questions' ) : $title;
					$id    = self::factory()->post->create(
						array(
							'post_title'  => $title,
							'post_name'   => sanitize_title( $title ),
							'post_status' => 'publish',
						)
					);
					if ( 'en' === $lang ) {
						$this->english[] = $id;
					}
					$this->created[ $id ]     = $topic;
					$phrases[ $topic ][ $id ] = $phrase;
				}
			}
		}

		foreach ( $this->created as $id => $topic ) {
			$lang     = str_starts_with( $topic, 'en' ) ? 'en' : 'es';
			$siblings = array_values( $phrases[ $topic ] );
			$others   = array_keys( array_diff_key( $phrases[ $topic ], array( $id => true ) ) );
			$html     = array();

			for ( $p = 0; $p < 5; $p++ ) {
				$sentences = array();
				for ( $s = 0; $s < 2; $s++ ) {
					$template    = self::TEMPLATES[ $lang ][ $this->pick( count( self::TEMPLATES[ $lang ] ) ) ];
					$sentences[] = str_replace( array( '{a}', '{b}' ), array( $siblings[ $this->pick( count( $siblings ) ) ], $siblings[ $this->pick( count( $siblings ) ) ] ), $template );
				}
				$html[] = '<p>' . implode( ' ', $sentences ) . '</p>';
			}

			// Un enlace existente a otra entrada del mismo tema en una de cada tres entradas.
			if ( 0 === $id % 3 ) {
				$target = $others[ $this->pick( count( $others ) ) ];
				$html[] = '<p>Para saber más, lee sobre <a href="' . get_permalink( $target ) . '">' . $phrases[ $topic ][ $target ] . '</a> y compara.</p>';
			}

			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => implode( "\n", $html ),
				)
			);
		}

		// Las entradas de la prueba ya se han indexado al guardarlas; se construye de cero como un sitio real.
		$this->clear_index();
		$job = $this->jobs->start_index( 0, false, true, true, true );
		$this->jobs->run_to_completion( $job['id'] );
	}

	/**
	 * Entero de 0 a $count - 1 (congruencial lineal; no hace falta aleatoriedad, sí repetibilidad).
	 *
	 * @param int $count Cuántos valores.
	 */
	private function pick( int $count ): int {
		$this->seed = ( $this->seed * 1103515245 + 12345 ) % 2147483648;

		return intdiv( $this->seed, 65536 ) % $count;
	}

	private function clear_index(): void {
		global $wpdb;
		foreach ( array( 'docs', 'links', 'jobs', 'terms', 'postings' ) as $table ) {
			$name = Schema::table( $wpdb->prefix, $table );
			$wpdb->query( "DELETE FROM {$name}" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * El motor en memoria con lo que hay en las tablas: mismas entradas, mismos enlaces y los
	 * términos tal como se guardaron (el almacén guarda el peso en un FLOAT), de modo que la
	 * diferencia que pueda salir es la de la recuperación, no la del redondeo.
	 *
	 * @param array<string, mixed> $options Opciones del motor.
	 * @param bool                 $stored  Con los términos guardados en las tablas (true) o los que calcula el motor (false).
	 */
	private function memory_suggester( array $options = array(), bool $stored = true ): Suggester {
		/** @var TableDocuments $tables */
		$tables = Plugin::container()->get( TableDocuments::class );
		$docs   = array();
		foreach ( $tables->all() as $document ) {
			$docs[ $document->id ] = $document;
		}

		$source  = new class( $docs ) implements DocumentSource {
			/**
			 * @param array<int, Document> $docs Documentos.
			 */
			public function __construct( private array $docs ) {
			}

			public function get( int $id ): ?Document {
				return $this->docs[ $id ] ?? null;
			}

			public function all(): iterable {
				return array_values( $this->docs );
			}
		};
		$indexer = new Indexer();
		$memory  = new MemoryIndex();

		foreach ( $docs as $document ) {
			$analyzed = Analyzer::for_language( $document->lang )->analyze( $document );
			$memory->count_terms( $document->lang, $analyzed->terms(), $analyzed->length );
		}
		$memory->prune( $indexer->config()['min_df'] );

		foreach ( $docs as $document ) {
			$analyzer = Analyzer::for_language( $document->lang );
			$indexed  = $indexer->index( $analyzer->analyze( $document ), $memory->stats( $document->lang ), $analyzer );
			$memory->put( $stored ? new IndexedDoc( $indexed->meta, $this->repo->terms( $document->id ), $indexed->fields, $indexed->links ) : $indexed );
		}

		return new Suggester( $memory, $source, $indexer, new PhraseFinder(), new Scorer(), null, $options + array( 'now' => self::NOW ) );
	}

	/**
	 * @param list<Suggestion> $suggestions Sugerencias.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function shape( array $suggestions ): array {
		return array_map(
			static fn( Suggestion $s ): array => array(
				'source'  => $s->source,
				'target'  => $s->target,
				'anchor'  => $s->anchor,
				'offset'  => $s->offset,
				'passes'  => $s->score->passes,
				'reasons' => array_map( static fn( $r ) => $r->code, $s->reasons ),
			),
			$suggestions
		);
	}

	/**
	 * @param list<Suggestion> $expected Memoria.
	 * @param list<Suggestion> $actual   Tablas.
	 * @param string           $what     Qué se compara.
	 */
	private function assert_same_suggestions( array $expected, array $actual, string $what ): void {
		$this->assertSame( self::shape( $expected ), self::shape( $actual ), $what );
		foreach ( $expected as $i => $suggestion ) {
			$this->assertEqualsWithDelta( $suggestion->score->value, $actual[ $i ]->score->value, 2e-4, $what . ' (puntuación)' );
		}
	}

	private function options(): array {
		return array( 'now' => self::NOW );
	}

	public function test_outgoing_and_incoming_match_the_memory_engine(): void {
		$this->corpus();
		$memory = $this->memory_suggester();

		$outgoing = 0;
		$incoming = 0;
		foreach ( array_keys( $this->created ) as $id ) {
			$document = Plugin::container()->get( TableDocuments::class )->get( $id );
			$this->assertNotNull( $document );

			$expected = $memory->outgoing( $document );
			$actual   = $this->service->outgoing( $id, $this->options() );
			$this->assert_same_suggestions( $expected, $actual, "salientes de {$id}" );
			$outgoing += count( $actual );

			$expected = $memory->incoming( $id );
			$actual   = $this->service->incoming( $id, $this->options() );
			$this->assert_same_suggestions( $expected, $actual, "entrantes de {$id}" );
			$incoming += count( $actual );
		}

		// La comparación no vale si ninguna entrada produce sugerencias.
		$this->assertGreaterThan( 40, $outgoing );
		$this->assertGreaterThan( 40, $incoming );
	}

	public function test_matches_the_memory_engine_built_from_scratch(): void {
		$this->corpus();
		$memory = $this->memory_suggester( array(), false );

		$found = 0;
		foreach ( array_keys( $this->created ) as $id ) {
			$document = Plugin::container()->get( TableDocuments::class )->get( $id );
			$this->assertNotNull( $document );

			$actual = $this->service->outgoing( $id, $this->options() );
			$this->assert_same_suggestions( $memory->outgoing( $document ), $actual, "salientes de {$id}" );
			$found += count( $actual );

			$actual = $this->service->incoming( $id, $this->options() );
			$this->assert_same_suggestions( $memory->incoming( $id ), $actual, "entrantes de {$id}" );
			$found += count( $actual );
		}

		// Sin términos normalizados: el orden de los términos con el mismo peso (`pos`) es el del motor en memoria.
		$this->assertGreaterThan( 80, $found );
	}

	public function test_stored_terms_are_the_ones_the_engine_computes(): void {
		$this->corpus();

		$source = Plugin::container()->get( \MagicLinking\Index\PostSource::class );
		$memory = new MemoryIndex();
		( new Indexer() )->build( $source, $memory );

		foreach ( array_keys( $this->created ) as $id ) {
			$expected = $memory->terms( $id );
			$actual   = $this->repo->terms( $id );
			$this->assertEqualsCanonicalizing( array_keys( $expected ), array_keys( $actual ), "términos de {$id}" );
			foreach ( $expected as $term => $weight ) {
				$this->assertEqualsWithDelta( $weight, $actual[ $term ], 1e-4 * max( 1.0, abs( $weight ) ), "peso de «{$term}» en {$id}" );
			}
		}
	}

	public function test_similar_and_containing_sum_the_same_as_php(): void {
		$this->corpus();

		$ids    = array_keys( $this->created );
		$source = $ids[3];
		$lang   = 'es';
		$query  = $this->repo->terms( $source );

		$expected = array();
		foreach ( $ids as $id ) {
			$terms = $this->repo->terms( $id );
			$sum   = 0.0;
			foreach ( $query as $term => $weight ) {
				$sum += $weight * ( $terms[ $term ] ?? 0.0 );
			}
			if ( $sum > 0 && ! in_array( $id, $this->english, true ) ) {
				$expected[ $id ] = $sum;
			}
		}
		uksort( $expected, static fn( int $a, int $b ): int => array( $expected[ $b ], $a ) <=> array( $expected[ $a ], $b ) );
		$expected = array_slice( $expected, 0, 15, true );

		$actual = $this->repo->similar( $query, $lang, 15 );

		$this->assertSame( array_keys( $expected ), array_keys( $actual ) );
		foreach ( $expected as $id => $sum ) {
			$this->assertEqualsWithDelta( $sum, $actual[ $id ], 1e-5 * max( 1.0, $sum ), "suma de {$id}" );
		}

		$skipped = $this->repo->similar( $query, $lang, 15, array( array_key_first( $expected ) ) );
		$this->assertArrayNotHasKey( array_key_first( $expected ), $skipped );
		$this->assertCount( 15, $skipped, 'los excluidos no restan resultados' );

		$this->assertSame( array(), $this->repo->similar( array(), $lang, 15 ) );
		$this->assertSame( array(), $this->repo->similar( array( 'zzzz inexistente' => 1.0 ), $lang, 15 ) );
		$this->assertSame( array(), $this->repo->similar( $query, $lang, 0 ) );
	}

	public function test_a_query_with_many_terms_is_summed_in_chunks_with_the_same_result(): void {
		global $wpdb;
		$this->corpus();

		// Siete términos por sentencia: cada consulta de 40 términos se parte en seis.
		$chunked = new TableRepository( $wpdb, 7 );
		$checked = 0;
		foreach ( array_slice( array_keys( $this->created ), 0, 20 ) as $id ) {
			$query = $this->repo->terms( $id );
			$lang  = in_array( $id, $this->english, true ) ? 'en' : 'es';

			$expected = $this->repo->similar( $query, $lang, 15, array( $id ) );
			$actual   = $chunked->similar( $query, $lang, 15, array( $id ) );
			$this->assertSame( array_keys( $expected ), array_keys( $actual ), "candidatas de {$id}" );
			foreach ( $expected as $other => $score ) {
				$this->assertEqualsWithDelta( $score, $actual[ $other ], 1e-9 * max( 1.0, $score ) );
			}

			$terms = array_keys( $query );
			$this->assertSame( $this->repo->containing( $terms, $lang, 10, array( $id ) ), $chunked->containing( $terms, $lang, 10, array( $id ) ) );
			$checked += count( $actual );
		}
		$this->assertGreaterThan( 100, $checked );

		// Una entrada que ya no es válida no ocupa un hueco de los resultados.
		$id    = array_keys( $this->created )[0];
		$query = $this->repo->terms( $id );
		$best  = array_key_first( $chunked->similar( $query, 'es', 5, array( $id ) ) );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $best ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$after = $chunked->similar( $query, 'es', 5, array( $id ) );
		$this->assertArrayNotHasKey( $best, $after );
		$this->assertCount( 5, $after );
		$this->assertSame( array_keys( $this->repo->similar( $query, 'es', 5, array( $id ) ) ), array_keys( $after ) );
	}

	public function test_candidates_are_never_from_another_language(): void {
		$this->corpus();

		$spanish = 0;
		$english = 0;
		foreach ( array_keys( $this->created ) as $id ) {
			$is_english = in_array( $id, $this->english, true );
			foreach ( $this->service->outgoing( $id, $this->options() ) as $suggestion ) {
				$this->assertSame( $is_english, in_array( $suggestion->target, $this->english, true ), "salientes de {$id} cruzan idioma" );
			}
			foreach ( $this->service->incoming( $id, $this->options() ) as $suggestion ) {
				$this->assertSame( $is_english, in_array( $suggestion->source, $this->english, true ), "entrantes de {$id} cruzan idioma" );
			}
			$is_english ? ++$english : ++$spanish;
		}

		$this->assertGreaterThan( 0, $english );
		$this->assertGreaterThan( 0, $spanish );

		// Una entrada que cambia de idioma sin reindexar no se cuela: la fila de la entrada manda.
		global $wpdb;
		$docs   = Schema::table( $wpdb->prefix, 'docs' );
		$target = $this->english[0];
		$wpdb->update( $docs, array( 'lang' => 'fr' ), array( 'post_id' => $target ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $this->service->outgoing( $this->english[1], $this->options() ) as $suggestion ) {
			$this->assertNotSame( $target, $suggestion->target );
		}
	}

	public function test_excluded_and_unpublished_posts_are_not_suggested(): void {
		$this->corpus();

		$ids    = array_values( array_diff( array_keys( $this->created ), $this->english ) );
		$source = $ids[0];
		$base   = $this->service->outgoing( $source, $this->options() );
		$this->assertGreaterThan( 2, count( $base ), 'el corpus no da sugerencias de partida' );

		// Excluida por el usuario.
		$never = $this->service->outgoing( $source, array( 'never' => array( $base[0]->target ) ) + $this->options() );
		$this->assertNotContains( $base[0]->target, array_map( static fn( Suggestion $s ): int => $s->target, $never ) );

		// Pasada a borrador sin que el índice se entere (la fila sigue en las tablas).
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $base[1]->target ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $base[1]->target );
		$draft = $this->service->outgoing( $source, $this->options() );
		$this->assertNotContains( $base[1]->target, array_map( static fn( Suggestion $s ): int => $s->target, $draft ) );

		// Borrada de verdad.
		wp_delete_post( $base[2]->target, true );
		$deleted = $this->service->outgoing( $source, $this->options() );
		$this->assertNotContains( $base[2]->target, array_map( static fn( Suggestion $s ): int => $s->target, $deleted ) );

		// Un destino fuera de los tipos que se analizan no tiene entrantes.
		$settings = Plugin::container()->get( \MagicLinking\Core\Settings::class );
		$settings->update( array( 'post_types' => array( 'page' ) ) );
		$this->assertSame( array(), $this->service->incoming( $source, $this->options() ) );
		$this->assertSame( array(), $this->service->outgoing( $source, $this->options() ) );
	}

	/**
	 * Añade un enlace al final del contenido sin pasar por los hooks de guardado: el grafo no se entera.
	 *
	 * @param int $source Entrada que enlaza.
	 * @param int $target Entrada enlazada.
	 */
	private function link_behind_the_graphs_back( int $source, int $target ): void {
		global $wpdb;

		$content = (string) get_post_field( 'post_content', $source );
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content . '<p>Mira <a href="' . get_permalink( $target ) . '">este enlace nuevo</a>.</p>' ), array( 'ID' => $source ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $source );
		$this->assertNotContains( $target, array_map( static fn( array $row ): int => (int) $row['target_id'], $this->link_rows( $source ) ), 'el grafo ya conoce el enlace' );
	}

	public function test_a_link_added_since_the_graph_ran_is_not_suggested_again(): void {
		$this->corpus();
		$ids    = array_values( array_diff( array_keys( $this->created ), $this->english ) );
		$source = $ids[0];
		$base   = $this->service->outgoing( $source, $this->options() );
		$this->assertNotEmpty( $base );

		$this->link_behind_the_graphs_back( $source, $base[0]->target );

		$targets = array_map( static fn( Suggestion $s ): int => $s->target, $this->service->outgoing( $source, $this->options() ) );
		$this->assertNotContains( $base[0]->target, $targets );
	}

	public function test_an_origin_that_already_links_in_its_content_is_not_suggested_for_incoming(): void {
		$this->corpus();
		$ids    = array_values( array_diff( array_keys( $this->created ), $this->english ) );
		$target = $ids[1];
		$base   = $this->service->incoming( $target, $this->options() );
		$this->assertNotEmpty( $base );

		$this->link_behind_the_graphs_back( $base[0]->source, $target );

		$sources = array_map( static fn( Suggestion $s ): int => $s->source, $this->service->incoming( $target, $this->options() ) );
		$this->assertNotContains( $base[0]->source, $sources );
	}

	public function test_the_terms_of_the_open_post_are_looked_up_once(): void {
		$this->corpus();
		$id      = array_values( array_diff( array_keys( $this->created ), $this->english ) )[0];
		$lookups = 0;
		$count   = static function ( $query ) use ( &$lookups ) {
			if ( str_contains( (string) $query, 'SELECT term_id, stem, df FROM' ) ) {
				++$lookups;
			}

			return $query;
		};

		add_filter( 'query', $count );
		$found = $this->service->outgoing( $id, $this->options() );
		remove_filter( 'query', $count );

		$this->assertNotEmpty( $found );
		$this->assertSame( 1, $lookups, 'stats_for() y similar() buscan los mismos términos' );
	}

	public function test_a_half_built_index_gives_no_suggestions(): void {
		$this->corpus();
		$id = array_keys( $this->created )[1];
		$this->assertTrue( $this->service->ready() );
		$this->assertNotSame( array(), $this->service->incoming( $id, $this->options() ) );

		update_option( LexicalIndexer::BUILDING_OPTION, 99, false );
		$this->assertFalse( $this->service->ready() );
		$this->assertSame( array(), $this->service->outgoing( $id, $this->options() ) );
		$this->assertSame( array(), $this->service->incoming( $id, $this->options() ) );

		delete_option( LexicalIndexer::BUILDING_OPTION );
		$this->assertTrue( $this->service->ready() );
		$this->assertNotSame( array(), $this->service->incoming( $id, $this->options() ) );
	}

	public function test_an_empty_index_gives_no_suggestions(): void {
		$id = self::factory()->post->create( array( 'post_content' => '<p>Texto suelto sin nada indexado todavía.</p>' ) );
		$this->clear_index();

		$this->assertFalse( $this->service->ready() );
		$this->assertSame( array(), $this->service->outgoing( $id ) );
		$this->assertSame( array(), $this->service->incoming( $id ) );
	}

	public function test_a_draft_can_ask_for_outgoing_but_not_incoming(): void {
		$this->corpus();

		$draft = self::factory()->post->create(
			array(
				'post_title'   => 'Borrador sobre aerotermia y bomba de calor',
				'post_status'  => 'draft',
				'post_content' => '<p>Hoy hablamos de la aerotermia, de la bomba de calor y del suelo radiante para una casa nueva.</p><p>Tampoco olvidamos las placas solares ni el aislamiento térmico de la vivienda.</p>',
			)
		);

		$outgoing = $this->service->outgoing( $draft, $this->options() );
		$this->assertNotEmpty( $outgoing );
		foreach ( $outgoing as $suggestion ) {
			$this->assertSame( 'publish', get_post_status( $suggestion->target ) );
			$this->assertNotContains( $suggestion->target, $this->english );
		}
		$this->assertSame( array(), $this->service->incoming( $draft, $this->options() ) );
	}

	public function test_the_number_of_queries_does_not_grow_with_the_candidates(): void {
		$this->corpus();
		global $wpdb;

		$ids   = array_values( array_diff( array_keys( $this->created ), $this->english ) );
		$worst = 0;
		foreach ( array_slice( $ids, 0, 12 ) as $id ) {
			$before = $wpdb->num_queries;
			$this->service->outgoing( $id, $this->options() );
			$worst = max( $worst, $wpdb->num_queries - $before );

			$before = $wpdb->num_queries;
			$this->service->incoming( $id, $this->options() );
			$worst = max( $worst, $wpdb->num_queries - $before );
		}

		// Unas 30 consultas por petición con el corpus de la prueba; sin la carga por lotes serían centenares.
		$this->assertLessThan( 45, $worst );
	}

	public function test_no_frozen_value_changes(): void {
		// D-35: los pesos y constantes que usa el servicio son los congelados en F0-11.
		$this->assertSame( 0.55, Scorer::WEIGHTS['relevance'] );
		$this->assertSame( 0.05, Scorer::WEIGHTS['need'] );
		$this->assertSame( 0.25, Scorer::WEIGHTS['anchor'] );
		$this->assertSame( 0.10, Scorer::WEIGHTS['position'] );
		$this->assertSame( 0.05, Scorer::WEIGHTS['freshness'] );
		$this->assertSame( 0.35, Scorer::THRESHOLD );
		$this->assertSame( 0.15, Scorer::MIN_RELEVANCE );
		$this->assertSame( 200, \MagicLinking\Engine\Retriever::CANDIDATES );
		$this->assertSame( 100, \MagicLinking\Engine\Retriever::ORIGINS );
		$this->assertSame( 40, ( new Indexer() )->config()['k'] );
	}
}
