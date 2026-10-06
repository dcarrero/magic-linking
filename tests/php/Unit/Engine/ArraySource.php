<?php
/**
 * Fuente de documentos en memoria para las pruebas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Unit\Engine;

use MagicLinking\Engine\Document;
use MagicLinking\Engine\DocumentSource;
use MagicLinking\Engine\Link;
use MagicLinking\Engine\Paragraph;

/**
 * Documentos sintéticos. En los párrafos, «[[texto|ID]]» es un enlace existente.
 */
final class ArraySource implements DocumentSource {

	/** @var array<int, Document> */
	private array $documents = array();

	/**
	 * @param list<string> $paragraphs
	 * @param list<string> $headings
	 * @param array<string, list<string>> $taxonomies
	 */
	public function add( int $id, string $title, array $paragraphs, string $lang = 'es', string $type = 'post', string $slug = '', int $date = 1790000000, array $headings = array(), array $taxonomies = array() ): Document {
		$built = array();
		$links = array();
		foreach ( $paragraphs as $text ) {
			$spans = array();
			while ( preg_match( '/\[\[([^|\]]+)\|(\d+)\]\]/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
				$start   = $m[0][1];
				$text    = substr_replace( $text, $m[1][0], $start, strlen( $m[0][0] ) );
				$spans[] = array( $start, $start + strlen( $m[1][0] ) );
				$links[] = new Link( (int) $m[2][0], $m[1][0] );
			}
			$built[] = new Paragraph( $text, $spans );
		}
		$document               = new Document( $id, $type, $lang, $title, '' === $slug ? 'entrada-' . $id : $slug, $date, $headings, $built, $links, array(), $taxonomies );
		$this->documents[ $id ] = $document;
		return $document;
	}

	public function get( int $id ): ?Document {
		return $this->documents[ $id ] ?? null;
	}

	public function all(): iterable {
		return array_values( $this->documents );
	}
}
