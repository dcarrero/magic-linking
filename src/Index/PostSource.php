<?php
/**
 * Entradas de WordPress como documentos del motor.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

use MagicLinking\Core\PostTitle;
use MagicLinking\Core\Settings;
use MagicLinking\Engine\ContentExtractor;
use MagicLinking\Engine\Document;
use MagicLinking\Engine\DocumentSource;
use MagicLinking\Graph\GraphRepository;
use MagicLinking\I18n\Language;
use WP_Post;

/**
 * Convierte el contenido guardado de una entrada en un {@see Document}: título, encabezados,
 * párrafos y frases objetivo, con el idioma de la entrada. No modifica nunca el contenido.
 */
final class PostSource implements DocumentSource {

	/**
	 * Extractor de texto.
	 *
	 * @var ContentExtractor
	 */
	private ContentExtractor $extractor;

	/**
	 * Idioma por entrada.
	 *
	 * @var Language
	 */
	private Language $language;

	/**
	 * Ajustes.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Grafo (para recorrer las entradas).
	 *
	 * @var GraphRepository
	 */
	private GraphRepository $graph;

	/**
	 * Los documentos llevan sus categorías y etiquetas (solo en la vista de {@see self::with_taxonomies()}).
	 *
	 * @var bool
	 */
	private bool $with_taxonomies = false;

	/**
	 * Constructor.
	 *
	 * @param ContentExtractor $extractor Extractor de texto.
	 * @param Language         $language  Idioma por entrada.
	 * @param Settings         $settings  Ajustes.
	 * @param GraphRepository  $graph     Repositorio del grafo.
	 */
	public function __construct( ContentExtractor $extractor, Language $language, Settings $settings, GraphRepository $graph ) {
		$this->extractor = $extractor;
		$this->language  = $language;
		$this->settings  = $settings;
		$this->graph     = $graph;
	}

	/**
	 * Si una entrada se indexa: publicada, de un tipo elegido en los ajustes y no excluida.
	 *
	 * @param WP_Post|null $post Entrada.
	 */
	public function is_eligible( ?WP_Post $post ): bool {
		return null !== $post
			&& 'publish' === $post->post_status
			&& in_array( $post->post_type, $this->settings->post_types(), true )
			&& ! Exclusions::is_excluded( $post->ID );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id ID de la entrada.
	 */
	public function get( int $id ): ?Document {
		$post = get_post( $id );

		return $post instanceof WP_Post && $this->is_eligible( $post ) ? $this->from_post( $post ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return iterable<Document>
	 */
	public function all(): iterable {
		$types = $this->settings->post_types();
		$after = 0;
		$more  = true;

		while ( $more ) {
			$ids  = $this->graph->eligible_ids( $types, $after, 200 );
			$more = 200 === count( $ids );
			if ( count( $ids ) > 1 ) {
				_prime_post_caches( $ids, true, true );
			}
			foreach ( $ids as $id ) {
				$document = $this->get( $id );
				if ( null !== $document ) {
					yield $document;
				}
				$after = $id;
			}
		}
	}

	/**
	 * Documento de una entrada.
	 *
	 * @param WP_Post $post Entrada.
	 * @param string  $html Contenido que se analiza; por defecto, el de la entrada con el filtro aplicado.
	 */
	public function from_post( WP_Post $post, ?string $html = null ): Document {
		$content = $this->extractor->extract( $html ?? self::html_of( $post ) );
		$title   = PostTitle::of( $post );
		$date    = strtotime( $post->post_modified_gmt . ' UTC' );

		return new Document(
			$post->ID,
			$post->post_type,
			$this->language->for_post( $post->ID ),
			$title,
			$post->post_name,
			false === $date ? 0 : $date,
			$content->headings,
			$content->paragraphs,
			array(),
			Fingerprint::focus( $post->ID ),
			$this->with_taxonomies ? Taxonomies::of( $post->ID, $post->post_type ) : array()
		);
	}

	/**
	 * Una vista de esta fuente cuyos documentos llevan (o no) sus categorías y etiquetas. Solo la usa el cálculo de
	 * sugerencias, y solo si hay filtros por taxonomía en modo Considerar o Exigir: el indexado y las reglas, que usan
	 * el servicio compartido, no hacen ninguna consulta de términos.
	 *
	 * @param bool $enabled Documentos con términos.
	 */
	public function with_taxonomies( bool $enabled ): self {
		$view                  = clone $this;
		$view->with_taxonomies = $enabled;

		return $view;
	}

	/**
	 * Si los documentos de esta fuente llevan sus categorías y etiquetas.
	 */
	public function taxonomies_enabled(): bool {
		return $this->with_taxonomies;
	}

	/**
	 * HTML que se analiza: el contenido guardado de la entrada, ampliable con el filtro
	 * `magiclinking_post_html` (documentado en GraphIndexer).
	 *
	 * @param WP_Post $post Entrada.
	 */
	public static function html_of( WP_Post $post ): string {
		return (string) apply_filters( 'magiclinking_post_html', $post->post_content, $post );
	}
}
