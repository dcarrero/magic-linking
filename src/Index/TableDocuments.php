<?php
/**
 * Documentos del motor con los enlaces internos de su contenido actual.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use MagicLinking\Engine\Document;
use MagicLinking\Engine\DocumentSource;
use MagicLinking\Engine\Link;
use MagicLinking\Engine\PrefetchesDocuments;
use MagicLinking\Graph\BrokenReason;
use MagicLinking\Graph\GraphRepository;
use MagicLinking\Graph\LinkParser;
use MagicLinking\Graph\LinkResolver;
use WP_Post;

/**
 * {@see PostSource} más los enlaces internos del contenido actual: el motor necesita saber a quién
 * enlaza ya una entrada (para no repetir destinos ni anclas y para la densidad), y no puede fiarse
 * sin más del grafo guardado, que va por detrás mientras hay procesos en cola o en un borrador.
 * Un enlace roto cuenta pero sin destino, como en el grafo.
 *
 * Los enlaces salen del mismo HTML que se analiza. Para los documentos pedidos por lotes (los orígenes
 * de las entrantes) se aprovecha el grafo solo si su huella es la del contenido de ahora (así
 * no hay que resolver cientos de URL); si no, o si el documento no se trajo por adelantado, se
 * resuelven del HTML.
 */
final class TableDocuments implements DocumentSource, PrefetchesDocuments {

	/**
	 * Analizador de enlaces, con el host y los alias actuales.
	 *
	 * @var LinkParser|null
	 */
	private ?LinkParser $parser = null;

	/**
	 * Enlaces del grafo y huella de los documentos traídos por adelantado.
	 *
	 * @var array<int, array{hash: string, links: list<array{0: int|null, 1: string}>}>
	 */
	private array $stored = array();

	/**
	 * Constructor.
	 *
	 * @param PostSource      $posts    Entradas como documentos.
	 * @param LinkResolver    $resolver Resolutor de URL internas a entradas (sin peticiones HTTP).
	 * @param GraphRepository $graph    Grafo.
	 */
	public function __construct( private PostSource $posts, private LinkResolver $resolver, private GraphRepository $graph ) {
	}

	/**
	 * Carga en las cachés de entradas y metadatos las que se van a pedir. Los términos de taxonomía no
	 * hacen falta: el motor no los usa aquí.
	 *
	 * @param int[] $ids IDs.
	 */
	public function prefetch( array $ids ): void {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return;
		}

		_prime_post_caches( $ids, false, true );

		$links        = $this->graph->links_from( $ids );
		$this->stored = array();
		foreach ( $this->graph->docs_by_ids( $ids ) as $id => $row ) {
			$this->stored[ $id ] = array(
				'hash'  => $row['content_hash'],
				'links' => $links[ $id ] ?? array(),
			);
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id ID de la entrada.
	 */
	public function get( int $id ): ?Document {
		$post = get_post( $id );

		return $post instanceof WP_Post && $this->posts->is_eligible( $post ) ? $this->from_post( $post ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return iterable<Document>
	 */
	public function all(): iterable {
		foreach ( $this->posts->all() as $document ) {
			$post = get_post( $document->id );
			if ( $post instanceof WP_Post ) {
				yield $this->from_post( $post );
			}
		}
	}

	/**
	 * Documento de una entrada, publicada o no (la abierta en el editor puede ser un borrador).
	 *
	 * @param WP_Post $post Entrada.
	 */
	public function from_post( WP_Post $post ): Document {
		$html     = PostSource::html_of( $post );
		$document = $this->posts->from_post( $post, $html );
		$links    = array();

		$stored = $this->stored[ $post->ID ] ?? null;
		if ( null !== $stored && '' !== $stored['hash'] && Fingerprint::of( $post, $html ) === $stored['hash'] ) {
			foreach ( $stored['links'] as list( $target, $anchor ) ) {
				$links[] = new Link( $target, $anchor );
			}

			return new Document( $document->id, $document->type, $document->lang, $document->title, $document->slug, $document->date, $document->headings, $document->paragraphs, $links, $document->focus, $document->taxonomies );
		}

		foreach ( $this->parser()->parse( $html ) as $link ) {
			if ( ! $link->internal ) {
				continue;
			}

			$resolution = $this->resolver->resolve( $link->url );

			// Un enlace a la propia entrada no cuenta.
			if ( $resolution->target_id === $post->ID ) {
				continue;
			}

			$links[] = new Link( BrokenReason::NONE === $resolution->broken ? $resolution->target_id : null, $link->anchor );
		}

		return new Document( $document->id, $document->type, $document->lang, $document->title, $document->slug, $document->date, $document->headings, $document->paragraphs, $links, $document->focus, $document->taxonomies );
	}

	/**
	 * Olvida lo resuelto (el estado de las entradas puede cambiar entre dos cálculos).
	 */
	public function release(): void {
		$this->stored = array();
		$this->resolver->flush();
	}

	/**
	 * Analizador de enlaces con el host y los alias actuales (los mismos que usa el grafo).
	 */
	private function parser(): LinkParser {
		if ( null === $this->parser ) {
			/** Este filtro está documentado en GraphIndexer. */
			$aliases      = apply_filters( 'magiclinking_internal_hosts', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Filtro propio.
			$this->parser = new LinkParser( home_url(), array_map( 'strval', (array) $aliases ) );
		}

		return $this->parser;
	}
}
