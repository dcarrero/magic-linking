<?php
/**
 * Entradas de WordPress como documentos del motor.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Index;

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
	 * Si una entrada se indexa: publicada y de un tipo elegido en los ajustes.
	 *
	 * @param WP_Post|null $post Entrada.
	 */
	public function is_eligible( ?WP_Post $post ): bool {
		return null !== $post
			&& 'publish' === $post->post_status
			&& in_array( $post->post_type, $this->settings->post_types(), true );
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
		$title   = html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
			Fingerprint::focus( $post->ID )
		);
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
