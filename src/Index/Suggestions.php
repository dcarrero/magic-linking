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
	 * Constructor.
	 *
	 * @param TableRepository $repository Índice.
	 * @param LexicalIndexer  $lexical    Indexador (para saber si el índice está construido).
	 * @param TableDocuments  $documents  Entradas con sus enlaces.
	 * @param Settings        $settings   Ajustes.
	 */
	public function __construct(
		private TableRepository $repository,
		private LexicalIndexer $lexical,
		private TableDocuments $documents,
		private Settings $settings
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
	 *
	 * @return list<Suggestion> Vacío si el índice no está listo o la entrada no se analiza.
	 *
	 * @phpstan-param array<string, mixed> $options
	 */
	public function outgoing( int $post_id, array $options = array(), ?string $content = null, ?string $title = null ): array {
		$post = get_post( $post_id );
		// Con el contenido del editor, una entrada nueva (borrador automático) también se analiza: aún no hay nada guardado.
		if ( ! $post instanceof WP_Post || ! $this->analyzes( $post, null !== $content ) || ! $this->ready() ) {
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
			return $this->without_ignored( $this->suggester( $options )->outgoing( $this->documents->from_post( $post ) ) );
		} finally {
			$this->documents->release();
		}
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

		try {
			return $this->without_ignored( $this->suggester( $options )->incoming( $post_id ) );
		} finally {
			$this->documents->release();
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
	 * Motor con la configuración congelada.
	 *
	 * @param array $options Opciones del motor.
	 *
	 * @phpstan-param array<string, mixed> $options
	 */
	private function suggester( array $options ): Suggester {
		$all = $this->settings->all();

		// Los ajustes de calidad del usuario (docs/07 §8); lo que pida quien llama manda sobre ellos.
		$defaults = array( 'max_outgoing' => $all['outbound_shown'] );
		$never    = $this->ignored_ids( $all['ignored_urls'] );
		if ( array() !== $never ) {
			$defaults['never'] = $never;
		}

		return new Suggester(
			$this->repository,
			$this->documents,
			new Indexer(),
			new PhraseFinder(),
			new Scorer( array(), Scorer::THRESHOLD, 100 / max( 1, (int) $all['words_per_link'] ), Scorer::MIN_RELEVANCE, array(), (int) $all['same_anchor_max'] ),
			new Retriever( $this->repository ),
			array_merge( $defaults, $options )
		);
	}

	/**
	 * Quita las sugerencias cuya ancla contiene una de las palabras o frases ignoradas (palabra completa, sin
	 * distinguir mayúsculas) o cuyo origen está en la lista de direcciones ignoradas.
	 *
	 * @param array $found Sugerencias del motor.
	 *
	 * @phpstan-param list<Suggestion> $found
	 *
	 * @return list<Suggestion>
	 */
	private function without_ignored( array $found ): array {
		$all     = $this->settings->all();
		$words   = $all['ignored_words'];
		$sources = $this->ignored_ids( $all['ignored_urls'] );
		if ( array() === $words && array() === $sources ) {
			return $found;
		}

		$patterns = array_map( static fn( string $word ): string => '/(?<![\p{L}\p{N}])' . preg_quote( $word, '/' ) . '(?![\p{L}\p{N}])/iu', $words );

		return array_values(
			array_filter(
				$found,
				static function ( Suggestion $suggestion ) use ( $patterns, $sources ): bool {
					if ( in_array( $suggestion->source, $sources, true ) ) {
						return false;
					}
					foreach ( $patterns as $pattern ) {
						if ( 1 === preg_match( $pattern, $suggestion->anchor ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	/**
	 * Entradas de la lista de direcciones ignoradas: nunca se sugieren como destino ni como origen.
	 *
	 * @param array $urls Direcciones guardadas en los ajustes.
	 *
	 * @phpstan-param list<string> $urls
	 *
	 * @return list<int>
	 */
	private function ignored_ids( array $urls ): array {
		$ids = array();
		foreach ( $urls as $url ) {
			$id = url_to_postid( $url );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
