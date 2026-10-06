<?php
/**
 * Construye el grafo de enlaces internos de las entradas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

use MagicLinking\Core\Settings;
use MagicLinking\I18n\Language;
use MagicLinking\Index\Fingerprint;
use WP_Post;

/**
 * Lee el contenido guardado de una entrada, extrae sus enlaces, los resuelve y
 * los guarda con los recuentos. No modifica nunca el contenido.
 *
 * Una entrada se analiza si está publicada y es de un tipo elegido en los
 * ajustes. Si deja de serlo, sale del índice.
 */
final class GraphIndexer {

	/**
	 * Repositorio.
	 *
	 * @var GraphRepository
	 */
	private GraphRepository $repository;

	/**
	 * Analizador.
	 *
	 * @var LinkParser|null
	 */
	private ?LinkParser $parser = null;

	/**
	 * Resolutor.
	 *
	 * @var LinkResolver
	 */
	private LinkResolver $resolver;

	/**
	 * Ajustes.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Idioma.
	 *
	 * @var Language
	 */
	private Language $language;

	/**
	 * Constructor.
	 *
	 * @param GraphRepository $repository Repositorio.
	 * @param LinkResolver    $resolver   Resolutor de URLs internas.
	 * @param Settings        $settings   Ajustes.
	 * @param Language        $language   Idioma por entrada.
	 */
	public function __construct( GraphRepository $repository, LinkResolver $resolver, Settings $settings, Language $language ) {
		$this->repository = $repository;
		$this->resolver   = $resolver;
		$this->settings   = $settings;
		$this->language   = $language;
	}

	/**
	 * Si una entrada se analiza: publicada y de un tipo elegido.
	 *
	 * @param WP_Post|null $post Entrada.
	 */
	public function is_eligible( ?WP_Post $post ): bool {
		return null !== $post
			&& 'publish' === $post->post_status
			&& in_array( $post->post_type, $this->settings->post_types(), true );
	}

	/**
	 * Indexa una entrada.
	 *
	 * @param int  $post_id ID.
	 * @param bool $force   Volver a analizar aunque el contenido no haya cambiado (los destinos pueden haber cambiado).
	 */
	public function index_post( int $post_id, bool $force = false ): IndexOutcome {
		$result = $this->process( array( $post_id ), $force, null );

		return $result['outcomes'][ $post_id ] ?? new IndexOutcome( IndexOutcome::SKIPPED );
	}

	/**
	 * Indexa varias entradas y recalcula los entrantes una sola vez.
	 *
	 * Carga las entradas y sus filas en bloque y escribe los enlaces y las filas con
	 * consultas múltiples, en vez de varias consultas por entrada.
	 *
	 * @param array<int, int> $post_ids IDs.
	 * @param bool            $force    Ver index_post().
	 * @param float|null      $deadline Instante (microtime) a partir del cual no se empieza otra entrada.
	 *
	 * @return array{indexed: int, unchanged: int, removed: int, skipped: int, processed: int} `processed`: cuántas de las primeras entradas de la lista se trataron (menos que las dadas solo si se agotó el plazo).
	 */
	public function index_many( array $post_ids, bool $force = false, ?float $deadline = null ): array {
		$result   = $this->process( array_values( $post_ids ), $force, $deadline );
		$counts   = array(
			IndexOutcome::INDEXED   => 0,
			IndexOutcome::UNCHANGED => 0,
			IndexOutcome::REMOVED   => 0,
			IndexOutcome::SKIPPED   => 0,
		);
		$affected = array();

		foreach ( $result['outcomes'] as $outcome ) {
			++$counts[ $outcome->status ];
			$affected = array_merge( $affected, $outcome->affected );
		}

		if ( array() !== $affected ) {
			$this->repository->refresh_inbound( $affected );
		}

		$counts['processed'] = $result['processed'];

		return $counts;
	}

	/**
	 * Analiza y guarda un grupo de entradas.
	 *
	 * @param array<int, int> $post_ids IDs.
	 * @param bool            $force    Ver index_post().
	 * @param float|null      $deadline Ver index_many().
	 *
	 * @return array{outcomes: array<int, IndexOutcome>, processed: int}
	 */
	private function process( array $post_ids, bool $force, ?float $deadline ): array {
		$post_ids = array_map( 'intval', $post_ids );

		if ( count( $post_ids ) > 1 ) {
			_prime_post_caches( $post_ids, false, true );
		}

		$docs      = $this->repository->docs_by_ids( $post_ids );
		$outcomes  = array();
		$new_docs  = array();
		$new_links = array();
		$affected  = array();
		$processed = 0;

		foreach ( $post_ids as $post_id ) {
			if ( null !== $deadline && $processed > 0 && microtime( true ) > $deadline ) {
				break;
			}
			++$processed;

			$post = get_post( $post_id );
			$post = $post instanceof WP_Post ? $post : null;
			$doc  = $docs[ $post_id ] ?? null;

			if ( ! $this->is_eligible( $post ) || null === $post ) {
				$outcomes[ $post_id ] = null === $doc
					? new IndexOutcome( IndexOutcome::SKIPPED )
					: new IndexOutcome( IndexOutcome::REMOVED, $this->repository->delete_doc( $post_id ) );
				continue;
			}

			$html = $this->html_of( $post );
			$hash = Fingerprint::of( $post, $html );

			if ( ! $force && null !== $doc && $doc['content_hash'] === $hash && $doc['post_type'] === $post->post_type ) {
				$outcomes[ $post_id ] = new IndexOutcome( IndexOutcome::UNCHANGED );
				continue;
			}

			$internal = array();
			$external = 0;
			$broken   = 0;

			foreach ( $this->parser()->parse( $html ) as $link ) {
				if ( ! $link->internal ) {
					++$external;
					continue;
				}

				$resolution = $this->resolver->resolve( $link->url );

				// Un enlace a la propia entrada no cuenta (ni entrante ni saliente).
				if ( $resolution->target_id === $post_id ) {
					continue;
				}

				$internal[] = array(
					'target_id' => $resolution->target_id,
					'url'       => $link->url,
					'anchor'    => $link->anchor,
					'broken'    => $resolution->broken,
				);

				if ( BrokenReason::NONE !== $resolution->broken ) {
					++$broken;
				}
			}//end foreach

			$new_links[ $post_id ] = $internal;
			$new_docs[ $post_id ]  = array(
				'post_id'      => $post_id,
				'post_type'    => $post->post_type,
				'lang'         => $this->language->for_post( $post_id ),
				'content_hash' => $hash,
				'word_count'   => $this->word_count( $html ),
				'outbound'     => count( $internal ),
				'external'     => $external,
				'broken'       => $broken,
			);
		}//end foreach

		if ( array() !== $new_docs ) {
			$affected = $this->repository->write_batch( $new_docs, $new_links );
		}

		foreach ( array_keys( $new_docs ) as $post_id ) {
			$outcomes[ $post_id ] = new IndexOutcome( IndexOutcome::INDEXED, array( $post_id ) );
		}

		// Los destinos afectados (antiguos y nuevos) se recalculan una vez, junto con la primera entrada indexada.
		$first = array_key_first( $new_docs );
		if ( null !== $first && array() !== $affected ) {
			$outcomes[ $first ] = new IndexOutcome( IndexOutcome::INDEXED, array_values( array_unique( array_merge( $affected, array( $first ) ) ) ) );
		}

		return array(
			'outcomes'  => $outcomes,
			'processed' => $processed,
		);
	}

	/**
	 * Indexa una entrada y recalcula sus entrantes y los de sus destinos.
	 *
	 * @param int  $post_id ID.
	 * @param bool $force   Ver index_post().
	 */
	public function index_and_refresh( int $post_id, bool $force = false ): IndexOutcome {
		$outcome = $this->index_post( $post_id, $force );

		if ( array() !== $outcome->affected ) {
			$this->repository->refresh_inbound( $outcome->affected );
		}

		return $outcome;
	}

	/**
	 * Entradas que hay que volver a analizar porque otra ha cambiado de estado o de dirección:
	 * las que ya la enlazan y las que tienen un enlace roto con su slug.
	 *
	 * @param int $post_id ID de la entrada que ha cambiado.
	 *
	 * @return list<int>
	 */
	public function dependents_of( int $post_id ): array {
		$ids  = $this->repository->sources_linking_to( $post_id );
		$post = get_post( $post_id );

		if ( $post instanceof WP_Post && '' !== $post->post_name ) {
			$ids = array_merge( $ids, $this->repository->sources_with_broken_url_like( '/' . rawurlencode( $post->post_name ) ) );
		}

		return array_values( array_diff( array_unique( $ids ), array( $post_id ) ) );
	}

	/**
	 * Repositorio del grafo.
	 */
	public function repository(): GraphRepository {
		return $this->repository;
	}

	/**
	 * Olvida lo resuelto hasta ahora.
	 */
	public function flush(): void {
		$this->resolver->flush();
	}

	/**
	 * HTML que se analiza: el contenido guardado de la entrada.
	 *
	 * @param WP_Post $post Entrada.
	 */
	private function html_of( WP_Post $post ): string {
		/**
		 * Permite añadir el HTML que un maquetador guarda fuera de post_content.
		 *
		 * @param string  $html Contenido guardado.
		 * @param WP_Post $post Entrada.
		 */
		return (string) apply_filters( 'magiclinking_post_html', $post->post_content, $post );
	}

	/**
	 * Palabras del texto visible.
	 *
	 * @param string $html HTML.
	 */
	private function word_count( string $html ): int {
		$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return (int) preg_match_all( "/[\\p{L}\\p{N}]+(?:['’\\-][\\p{L}\\p{N}]+)*/u", $text );
	}

	/**
	 * Analizador con el host y los alias actuales.
	 */
	private function parser(): LinkParser {
		if ( null === $this->parser ) {
			/**
			 * Otros hosts que cuentan como enlaces internos (dominios alternativos del mismo sitio).
			 *
			 * @param array<int, string> $hosts Hosts, sin esquema.
			 */
			$aliases      = apply_filters( 'magiclinking_internal_hosts', array() );
			$this->parser = new LinkParser( home_url(), array_map( 'strval', (array) $aliases ) );
		}

		return $this->parser;
	}
}
