<?php
/**
 * Extractor de texto con DOMDocument (docs/04 §2.1, D-21): el del plugin y el del banco de pruebas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Engine;

/**
 * Convierte el contenido guardado (bloques o editor clásico) en encabezados y
 * párrafos de texto plano, marcando los tramos que ya son enlace.
 */
final class DomExtractor implements ContentExtractor {

	/**
	 * Bloques cuyo contenido no cuenta (docs/04 §2.1).
	 */
	private const SKIP_BLOCKS = array( 'code', 'preformatted', 'table', 'quote', 'pullquote', 'buttons', 'button', 'navigation', 'block', 'html', 'shortcode', 'embed', 'verse', 'file', 'audio', 'video', 'gallery', 'image', 'social-links', 'search', 'latest-posts', 'rss', 'calendar', 'archives', 'categories', 'tag-cloud' );

	/**
	 * Elementos cuyo contenido no cuenta.
	 */
	private const SKIP_TAGS = array( 'script', 'style', 'pre', 'table', 'blockquote', 'figure', 'figcaption', 'nav', 'button', 'form', 'iframe', 'noscript', 'svg', 'select', 'textarea', 'object', 'video', 'audio', 'template' );

	/**
	 * Elementos en línea cuyo texto no cuenta pero que cortan los n-gramas.
	 */
	private const BREAK_TAGS = array( 'code', 'kbd', 'samp', 'img' );

	/**
	 * Elementos de bloque: cierran el párrafo en curso.
	 */
	private const BLOCK_TAGS = array( 'p', 'div', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'section', 'article', 'header', 'footer', 'aside', 'main', 'br', 'hr', 'details', 'summary', 'center', 'address' );

	/**
	 * Encabezados.
	 */
	private const HEADING_TAGS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/**
	 * Separador que corta los n-gramas sin cerrar frase.
	 */
	private const SEPARATOR = '·';

	/**
	 * Encabezados encontrados.
	 *
	 * @var list<string>
	 */
	private array $headings = array();

	/**
	 * Párrafos encontrados.
	 *
	 * @var list<Paragraph>
	 */
	private array $paragraphs = array();

	/**
	 * Texto del párrafo en curso.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Enlaces del párrafo en curso.
	 *
	 * @var list<array{0: int, 1: int}>
	 */
	private array $links = array();

	/**
	 * Si un salto de línea simple es un salto de línea del original (editor clásico).
	 *
	 * @var bool
	 */
	private bool $classic = false;

	/**
	 * {@inheritDoc}
	 *
	 * @param string $content Contenido guardado.
	 */
	public function extract( string $content ): ExtractedContent {
		$this->headings   = array();
		$this->paragraphs = array();
		$this->buffer     = '';
		$this->links      = array();
		$this->classic    = ! str_contains( $content, '<!-- wp:' );

		$html = $this->clean( $content );
		if ( '' === trim( $html ) ) {
			return new ExtractedContent( array(), array() );
		}

		if ( ! class_exists( '\DOMDocument' ) ) {
			return $this->without_dom( $html );
		}

		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( null !== $body ) {
			$this->walk( $body );
		}
		$this->flush();

		return new ExtractedContent( $this->headings, $this->paragraphs );
	}

	/**
	 * Alternativa sin la extensión DOM: párrafos separados por líneas en blanco o por etiquetas de bloque, sin encabezados.
	 *
	 * @param string $html HTML ya limpio.
	 */
	private function without_dom( string $html ): ExtractedContent {
		$html       = (string) preg_replace( '#</?(?:p|div|li|ul|ol|br|h[1-6]|section|article|blockquote)\b[^>]*>#i', "\n\n", $html );
		$paragraphs = array();
		foreach ( (array) preg_split( '/\n\s*\n/', $html ) as $chunk ) {
			$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( (string) $chunk ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- El motor no depende de WordPress.
			if ( 1 === preg_match( '/[\p{L}\p{N}]/u', $text ) ) {
				$paragraphs[] = new Paragraph( $text );
			}
		}

		return new ExtractedContent( array(), $paragraphs );
	}

	/**
	 * Quita bloques excluidos, comentarios y shortcodes.
	 *
	 * @param string $content Contenido.
	 */
	private function clean( string $content ): string {
		$names   = implode( '|', array_map( static fn( string $n ): string => preg_quote( $n, '/' ), self::SKIP_BLOCKS ) );
		$content = (string) preg_replace( '/<!--\s+wp:(?:core\/)?(' . $names . ')(?:\s[^>]*?)?(?<!\/)-->.*?<!--\s+\/wp:(?:core\/)?\1\s+-->/s', "\n\n", $content );
		$content = (string) preg_replace( '/<!--.*?-->/s', '', $content );
		// Shortcodes con contenido que no es texto del artículo.
		$content = (string) preg_replace( '/\[(caption|embed|video|audio|gallery|playlist)\b[^\]]*\].*?\[\/\1\]/is', ' ', $content );
		return (string) preg_replace( '/\[\/?[a-z][\w-]*(?:\s[^\]]*)?\]/i', ' ', $content );
	}

	/**
	 * Recorre un nodo.
	 *
	 * @param \DOMNode $node Nodo.
	 */
	private function walk( \DOMNode $node ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- API de DOM.
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				$this->text( $child->data );
				continue;
			}
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- API de DOM.
			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, self::SKIP_TAGS, true ) ) {
				$this->flush();
				continue;
			}
			if ( in_array( $tag, self::BREAK_TAGS, true ) ) {
				$this->buffer .= ( str_ends_with( $this->buffer, ' ' ) ? '' : ' ' ) . self::SEPARATOR . ' ';
				$this->buffer  = rtrim( $this->buffer );
				continue;
			}
			if ( in_array( $tag, self::HEADING_TAGS, true ) ) {
				$this->flush();
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- API de DOM.
				$heading = trim( (string) preg_replace( '/\s+/u', ' ', $child->textContent ) );
				if ( '' !== $heading ) {
					$this->headings[] = $heading;
				}
				continue;
			}
			if ( in_array( $tag, self::BLOCK_TAGS, true ) ) {
				$this->flush();
				$this->walk( $child );
				$this->flush();
				continue;
			}
			if ( 'a' === $tag && '' !== trim( $child->getAttribute( 'href' ) ) ) {
				$start = strlen( $this->buffer );
				$this->walk( $child );
				$this->links[] = array( $start, strlen( $this->buffer ) );
				continue;
			}
			$this->walk( $child );
		}//end foreach
	}

	/**
	 * Añade texto al párrafo en curso. Una línea en blanco cierra el párrafo; en
	 * el editor clásico un salto simple se conserva (es un salto de línea).
	 *
	 * @param string $text Texto de un nodo.
	 */
	private function text( string $text ): void {
		$parts = preg_split( '/\n[ \t\x{00A0}]*\n\s*/u', $text );
		foreach ( false === $parts ? array( $text ) : $parts as $n => $part ) {
			if ( $n > 0 ) {
				$this->flush();
			}
			$part          = (string) preg_replace( $this->classic ? '/[ \t\r\f\x{00A0}]+/u' : '/\s+/u', ' ', $part );
			$this->buffer .= $part;
		}
	}

	/**
	 * Cierra el párrafo en curso.
	 */
	private function flush(): void {
		$text  = rtrim( $this->buffer );
		$start = strlen( $text ) - strlen( ltrim( $text ) );
		$text  = ltrim( $text );
		if ( 1 === preg_match( '/[\p{L}\p{N}]/u', $text ) ) {
			$links = array();
			foreach ( $this->links as [ $from, $to ] ) {
				$from = max( 0, $from - $start );
				$to   = min( strlen( $text ), $to - $start );
				if ( $to > $from ) {
					$links[] = array( $from, $to );
				}
			}
			$this->paragraphs[] = new Paragraph( $text, $links );
		}
		$this->buffer = '';
		$this->links  = array();
	}
}
