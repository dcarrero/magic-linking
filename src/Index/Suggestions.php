<?php
/**
 * Sugerencias de enlaces calculadas sobre el índice persistente.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Settings;
use MagicLinking\Engine\Indexer;
use MagicLinking\Engine\PhraseFinder;
use MagicLinking\Engine\Retriever;
use MagicLinking\Engine\Scorer;
use MagicLinking\Engine\IgnoredWords;
use MagicLinking\Engine\Suggester;
use MagicLinking\Engine\Suggestion;
use WP_Post;

/**
 * El mismo motor que el banco de pruebas ({@see Suggester}, {@see Retriever}, {@see Scorer}) con el índice
 * en las tablas ({@see TableRepository}) y las entradas de WordPress ({@see TableDocuments}).
 *
 * Pesos y constantes: los congelados en D-35 (configuración `engine-k40` del banco: K = 40, 200 candidatas
 * salientes, 100 orígenes entrantes, umbral 0,35 y relevancia mínima 0,15), que son los valores por
 * defecto de las clases del motor; aquí no se cambia ninguno.
 *
 * Mientras el índice léxico no está construido (primera construcción en marcha, cancelada o fallida: D-44)
 * no hay sugerencias, porque los pesos y el `df` estarían a medias; {@see self::ready()} lo dice.
 */
final class Suggestions {

	/**
	 * Estados de una entrada que no se analiza nunca.
	 */
	private const NEVER_STATUS = array( 'trash', 'auto-draft', 'inherit' );

	/**
	 * Bonificaciones activas, medidas en el banco (F0-12, D-33): contenido pilar +0,05 y, por cada filtro en modo
	 * Considerar que comparten origen y destino, +0,05 con tope +0,10 (`Scorer::AFFINITY_CAP`). Reordenan, pero el
	 * umbral y la relevancia mínima se comprueban antes de sumarlas: nunca hacen pasar una sugerencia por sí solas.
	 */
	public const BONUSES = array(
		'pillar'   => 0.05,
		'affinity' => 0.05,
	);

	/**
	 * Constructor.
	 *
	 * @param TableRepository     $repository Índice.
	 * @param LexicalIndexer      $lexical    Indexador (para saber si el índice está construido).
	 * @param TableDocuments      $documents  Entradas con sus enlaces.
	 * @param Settings            $settings   Ajustes.
	 * @param DismissalRepository $dismissals Pares descartados.
	 * @param Pillars             $pillars    Contenido pilar.
	 */
	public function __construct(
		private TableRepository $repository,
		private LexicalIndexer $lexical,
		private TableDocuments $documents,
		private Settings $settings,
		private DismissalRepository $dismissals,
		private Pillars $pillars
	) {
	}

	/**
	 * Si hay un índice léxico completo sobre el que sugerir.
	 */
	public function ready(): bool {
		// Migra antes de decidir: una migración marca el índice como a medias y no debe servirse en esa misma petición.
		Installer::ensure_current();

		return $this->lexical->is_built();
	}

	/**
	 * Estado de una entrada de cara a las sugerencias: `ok`, o por qué no las hay.
	 *
	 * @param WP_Post $post    Entrada abierta.
	 * @param bool    $inbound Entrantes: el destino tiene que estar publicado.
	 * @param bool    $draft   Se analiza el contenido del editor (una entrada nueva también vale).
	 *
	 * @return string `ok`, `index_not_ready`, `not_analyzed` o `not_published`.
	 */
	public function state( WP_Post $post, bool $inbound = false, bool $draft = false ): string {
		if ( ! $this->ready() ) {
			return 'index_not_ready';
		}
		if ( ! $this->analyzes( $post, $draft ) ) {
			return 'not_analyzed';
		}
		if ( $inbound && 'publish' !== $post->post_status ) {
			return 'not_published';
		}

		return 'ok';
	}

	/**
	 * Sugerencias salientes de una entrada, con su contenido guardado ahora: la entrada puede ser un borrador
	 * (el destino siempre es una entrada publicada), pero tiene que ser de un tipo que se analiza.
	 *
	 * @param int         $post_id ID de la entrada abierta.
	 * @param array       $options Opciones del motor (`never`, `now`…; ver {@see Suggester::__construct()}).
	 * @param string|null $content Contenido (HTML o bloques) tal como está ahora en el editor, sin guardar; null = el guardado.
	 * @param string|null $title   Título tal como está en el editor; solo con `$content`.
	 * @param array|null  $terms   IDs de categorías y etiquetas elegidas en el editor, sin guardar (ver {@see Taxonomies::resolve()}); solo con `$content`.
	 *                             Mandan sobre las guardadas, que en una entrada nueva no existen todavía.
	 * @param array|null  $names   Nombres de etiquetas escritos en el editor clásico, sin guardar (nunca se toman por IDs).
	 *
	 * @return list<Suggestion> Vacío si el índice no está listo o la entrada no se analiza.
	 *
	 * @phpstan-param array<string, mixed> $options
	 * @phpstan-param array<string, mixed>|null $terms
	 * @phpstan-param array<string, mixed>|null $names
	 */
	public function outgoing( int $post_id, array $options = array(), ?string $content = null, ?string $title = null, ?array $terms = null, ?array $names = null ): array {
		$prepared = $this->prepare_outgoing( $post_id, $options, $content, $title, $terms, $names );
		if ( null === $prepared ) {
			return array();
		}

		[ $suggester, $document, $documents ] = $prepared;
		try {
			$found = $suggester->outgoing( $document );

			return array_slice( $this->without_ignored( $found ), 0, (int) ( $options['max_outgoing'] ?? $this->settings->all()['outbound_shown'] ) );
		} finally {
			$documents->release();
		}
	}

	/**
	 * Destinos a los que una entrada ya enlaza, con las anclas que usa, en el orden en que aparecen (docs/07 §3.3).
	 * Salen del contenido que se está viendo (el del editor o el guardado), no del grafo, que va por detrás de lo que
	 * el usuario tiene delante. Los enlaces rotos, los externos y los que van a la propia entrada no cuentan.
	 *
	 * @param int         $post_id ID de la entrada abierta.
	 * @param string|null $content Contenido del editor, sin guardar; null = el guardado.
	 * @param string|null $title   Título del editor, sin guardar (forma parte de la huella con la que se reconoce que el
	 *                             contenido es el guardado y se pueden leer los enlaces del grafo sin resolver cada URL).
	 *
	 * @return list<array{id: int, anchors: list<string>}>
	 */
	public function current_links( int $post_id, ?string $content = null, ?string $title = null ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}
		if ( null !== $content ) {
			// Una copia: el objeto de la caché de WordPress no se toca.
			$post               = clone $post;
			$post->post_content = $content;
			if ( null !== $title ) {
				$post->post_title = $title;
			}
		}

		try {
			$this->documents->prefetch( array( $post_id ) );
			$by_target = array();
			foreach ( $this->documents->links_in( $post ) as $link ) {
				if ( null !== $link->target && ! in_array( $link->anchor, $by_target[ $link->target ] ?? array(), true ) ) {
					$by_target[ $link->target ][] = $link->anchor;
				}
			}
		} finally {
			$this->documents->release();
		}

		$out = array();
		foreach ( $by_target as $id => $anchors ) {
			$out[] = array(
				'id'      => $id,
				'anchors' => $anchors,
			);
		}

		return $out;
	}

	/**
	 * Si el filtro Exigir es lo que deja sin sugerencias salientes a una entrada (con él no hay candidatas y sin él sí).
	 * Solo para el estado vacío: se llama cuando el resultado ya salió vacío. Mismos argumentos que {@see self::outgoing()}.
	 *
	 * @param int         $post_id ID de la entrada abierta.
	 * @param string|null $content Contenido del editor.
	 * @param string|null $title   Título del editor.
	 * @param array|null  $terms   IDs de categorías y etiquetas del editor.
	 * @param array|null  $names   Nombres de etiquetas del editor clásico.
	 *
	 * @phpstan-param array<string, mixed>|null $terms
	 * @phpstan-param array<string, mixed>|null $names
	 */
	public function outgoing_limited( int $post_id, ?string $content = null, ?string $title = null, ?array $terms = null, ?array $names = null ): bool {
		if ( array() === $this->settings->filters_in_mode( Suggester::REQUIRE ) ) {
			return false;
		}
		$prepared = $this->prepare_outgoing( $post_id, array(), $content, $title, $terms, $names );
		if ( null === $prepared ) {
			return false;
		}

		[ $suggester, $document, $documents ] = $prepared;
		try {
			return $suggester->outgoing_limited( $document );
		} finally {
			$documents->release();
		}
	}

	/**
	 * Lo mismo para las entrantes hacia una entrada.
	 *
	 * @param int $post_id ID del destino.
	 */
	public function incoming_limited( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( array() === $this->settings->filters_in_mode( Suggester::REQUIRE ) || ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! $this->analyzes( $post ) || ! $this->ready() ) {
			return false;
		}

		[ $repository, $documents ] = $this->views();
		try {
			return $this->suggester( array(), $repository, $documents )->incoming_limited( $post_id );
		} finally {
			$documents->release();
		}
	}

	/**
	 * Motor, documento y vista de documentos para calcular las salientes de una entrada, o null si no se analiza.
	 *
	 * @param int         $post_id ID.
	 * @param array       $options Opciones del motor.
	 * @param string|null $content Contenido del editor.
	 * @param string|null $title   Título del editor.
	 * @param array|null  $terms   IDs de términos del editor.
	 * @param array|null  $names   Nombres de etiquetas del editor.
	 *
	 * @phpstan-param array<string, mixed> $options
	 * @phpstan-param array<string, mixed>|null $terms
	 * @phpstan-param array<string, mixed>|null $names
	 *
	 * @return array{0: Suggester, 1: \MagicLinking\Engine\Document, 2: TableDocuments}|null
	 */
	private function prepare_outgoing( int $post_id, array $options, ?string $content, ?string $title, ?array $terms, ?array $names ): ?array {
		$post = get_post( $post_id );
		// Con el contenido del editor, una entrada nueva (borrador automático) también se analiza: aún no hay nada guardado.
		if ( ! $post instanceof WP_Post || ! $this->analyzes( $post, null !== $content ) || ! $this->ready() ) {
			return null;
		}

		if ( null !== $content ) {
			// Una copia: el objeto de la caché de WordPress no se toca.
			$post               = clone $post;
			$post->post_content = $content;
			if ( null !== $title ) {
				$post->post_title = $title;
			}
		}

		// Lo descartado desde esta entrada no se vuelve a proponer; los destinos muy descartados bajan su prior.
		$options['never']     = array_values( array_unique( array_merge( $options['never'] ?? array(), $this->dismissals->targets_for_source( $post_id ) ) ) );
		$options['penalized'] = $this->dismissals->penalized_targets( $this->settings->post_types() );

		// Vistas de este cálculo: con términos solo si hay filtros por taxonomía; el servicio compartido no cambia.
		[ $repository, $documents ] = $this->views();
		$suggester                  = $this->suggester( $options, $repository, $documents );
		$document                   = $documents->from_post( $post );
		if ( null !== $content && ( null !== $terms || null !== $names ) && $documents->taxonomies_enabled() ) {
			$document = $document->with_taxonomies( Taxonomies::resolve( $post->post_type, $terms ?? array(), $names ?? array() ) + $document->taxonomies );
		}

		return array( $suggester, $document, $documents );
	}

	/**
	 * Sugerencias entrantes hacia una entrada: la mejor de cada origen que supera el umbral, de mayor a
	 * menor puntuación. El destino tiene que estar publicado e indexado.
	 *
	 * @param int   $post_id ID del destino.
	 * @param array $options Opciones del motor.
	 *
	 * @return list<Suggestion> Vacío si el índice no está listo o el destino no está indexado.
	 *
	 * @phpstan-param array<string, mixed> $options
	 */
	public function incoming( int $post_id, array $options = array() ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! $this->analyzes( $post ) || ! $this->ready() ) {
			return array();
		}

		// Los orígenes que ya se descartaron para este destino no se vuelven a proponer.
		$options['excluded_origins'] = array_values( array_unique( array_merge( $options['excluded_origins'] ?? array(), $this->dismissals->sources_for_target( $post_id ) ) ) );
		$options['penalized']        = $this->dismissals->penalized_targets( $this->settings->post_types() );

		[ $repository, $documents ] = $this->views();
		try {
			return $this->without_ignored( $this->suggester( $options, $repository, $documents )->incoming( $post_id ) );
		} finally {
			$documents->release();
		}
	}

	/**
	 * Si una entrada es de un tipo que se analiza y no está en un estado que se descarta.
	 *
	 * @param WP_Post $post  Entrada.
	 * @param bool    $draft Se analiza lo que hay en el editor: un borrador automático también vale.
	 */
	private function analyzes( WP_Post $post, bool $draft = false ): bool {
		$never = $draft ? array_diff( self::NEVER_STATUS, array( 'auto-draft' ) ) : self::NEVER_STATUS;

		return in_array( $post->post_type, $this->settings->post_types(), true ) && ! in_array( $post->post_status, $never, true );
	}

	/**
	 * Cuántas sugerencias salientes se piden al motor: las que se muestran y, si hay palabras ignoradas que se
	 * quitarán después, otras tantas de reserva para que no se vean menos de las configuradas.
	 *
	 * @param array $all Ajustes.
	 *
	 * @phpstan-param array<string, mixed> $all
	 */
	private function candidates( array $all ): int {
		$shown = (int) $all['outbound_shown'];

		return array() === $all['ignored_words'] ? $shown : min( 4 * $shown, 200 );
	}

	/**
	 * Filtros en modo Considerar o Exigir.
	 *
	 * @param array $all Ajustes.
	 *
	 * @phpstan-param array<string, mixed> $all
	 *
	 * @return array<string, string>
	 */
	private function active_filters( array $all ): array {
		return array_filter( $all['filters'], static fn( string $mode ): bool => Suggester::IGNORE !== $mode );
	}

	/**
	 * Índice y documentos para un cálculo: vistas que leen categorías y etiquetas solo si hay filtros por esas
	 * taxonomías en modo Considerar o Exigir. Sin estado compartido: el resto del plugin no ve nunca esos términos.
	 *
	 * @return array{0: TableRepository, 1: TableDocuments}
	 */
	private function views(): array {
		$filters    = $this->active_filters( $this->settings->all() );
		$taxonomies = isset( $filters['category'] ) || isset( $filters['tag'] );

		// Sin filtros de taxonomía se usan los servicios compartidos tal cual (conservan sus cachés por petición).
		return $taxonomies ? array( $this->repository->with_taxonomies( true ), $this->documents->with_taxonomies( true ) ) : array( $this->repository, $this->documents );
	}

	/**
	 * Motor con la configuración congelada y los ajustes de calidad del usuario (docs/07 §8); lo que pida
	 * quien llama manda sobre ellos.
	 *
	 * @param array           $options    Opciones del motor.
	 * @param TableRepository $repository Índice de este cálculo (vista).
	 * @param TableDocuments  $documents  Documentos de este cálculo (vista).
	 *
	 * @phpstan-param array<string, mixed> $options
	 */
	private function suggester( array $options, TableRepository $repository, TableDocuments $documents ): Suggester {
		$all = $this->settings->all();

		$defaults = array(
			'max_outgoing' => $this->candidates( $all ),
			// Frases alternativas por sugerencia (docs/04 §5): 0 por defecto, igual que el motor.
			'alternatives' => (int) $all['alternatives'],
		);
		// Las direcciones ignoradas se suman a los destinos descartados; ninguna lista pisa a la otra.
		if ( array() !== $all['ignored_ids'] ) {
			$options['never'] = array_values( array_unique( array_merge( $options['never'] ?? array(), $all['ignored_ids'] ) ) );
		}

		// Los filtros en modo Considerar o Exigir (docs/04 §4.3) y el contenido pilar (§6).
		$defaults['filters'] = $this->active_filters( $all );
		$defaults['pillars'] = $this->pillars->ids();

		return new Suggester(
			$repository,
			$documents,
			new Indexer(),
			new PhraseFinder(),
			new Scorer( array(), Scorer::THRESHOLD, Scorer::DENSITY, Scorer::MIN_RELEVANCE, self::BONUSES, (int) $all['same_anchor_max'] ),
			new Retriever( $repository ),
			array_merge( $defaults, $options )
		);
	}

	/**
	 * Quita las sugerencias cuya ancla contiene una de las palabras o frases ignoradas (palabra completa, sin
	 * distinguir mayúsculas) o cuyo origen está en la lista de direcciones ignoradas.
	 *
	 * @param array $found Sugerencias del motor, ya ordenadas.
	 *
	 * @phpstan-param list<Suggestion> $found
	 *
	 * @return list<Suggestion>
	 */
	private function without_ignored( array $found ): array {
		$all = $this->settings->all();
		if ( array() === $all['ignored_words'] && array() === $all['ignored_ids'] ) {
			return $found;
		}

		$patterns = IgnoredWords::patterns( $all['ignored_words'] );

		$kept = array();
		foreach ( $found as $suggestion ) {
			if ( in_array( $suggestion->source, $all['ignored_ids'], true ) || IgnoredWords::matches( $patterns, $suggestion->anchor ) ) {
				continue;
			}

			// Las frases alternativas siguen las mismas reglas que la principal.
			$alternatives = array_values(
				array_filter(
					$suggestion->alternatives,
					static fn( Suggestion $alternative ): bool => ! IgnoredWords::matches( $patterns, $alternative->anchor )
				)
			);
			$kept[]       = count( $alternatives ) === count( $suggestion->alternatives ) ? $suggestion : $suggestion->with_alternatives( $alternatives );
		}

		return $kept;
	}
}
