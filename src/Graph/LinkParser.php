<?php
/**
 * Extrae los enlaces de un fragmento de HTML.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Graph;

use WP_HTML_Tag_Processor;

/**
 * Recorre el HTML con WP_HTML_Tag_Processor y devuelve cada <a href>.
 *
 * Clasifica como interno el que apunta al propio sitio (el host de home_url()
 * y los alias, con o sin «www»), lo lleva a URL absoluta y le quita el
 * fragmento y los parámetros de seguimiento. No cuenta los enlaces a
 * archivos (subidas, wp-content, wp-json…), los de página (#ancla), mailto:,
 * tel: ni ningún otro esquema que no sea http(s).
 */
final class LinkParser {

	/**
	 * Parámetros de seguimiento que no cambian el destino.
	 */
	private const TRACKING_PARAMS = '/^(utm_.*|gclid|fbclid|msclkid|mc_cid|mc_eid|_ga|_gl)$/i';

	/**
	 * Extensiones de fichero que no son páginas.
	 */
	private const PAGE_EXTENSIONS = array( '', 'html', 'htm', 'php', 'asp', 'aspx' );

	/**
	 * Rutas de WordPress que no son contenido.
	 */
	private const NON_CONTENT_PATHS = array( 'wp-content/', 'wp-includes/', 'wp-admin/', 'wp-json/', 'wp-login.php', 'xmlrpc.php' );

	/**
	 * URL base del sitio, con barra final.
	 *
	 * @var string
	 */
	private string $home;

	/**
	 * Esquema del sitio.
	 *
	 * @var string
	 */
	private string $scheme;

	/**
	 * Hosts propios, en minúsculas y sin «www.».
	 *
	 * @var list<string>
	 */
	private array $hosts;

	/**
	 * Constructor.
	 *
	 * @param string             $home  URL del sitio (home_url()).
	 * @param array<int, string> $alias Otros hosts que también son el sitio.
	 */
	public function __construct( string $home, array $alias = array() ) {
		$this->home   = trailingslashit( $home );
		$this->scheme = (string) ( wp_parse_url( $home, PHP_URL_SCHEME ) ?: 'https' ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Esquema vacío o null.
		$hosts        = array( (string) wp_parse_url( $home, PHP_URL_HOST ) );

		foreach ( $alias as $host ) {
			$hosts[] = $host;
		}

		$this->hosts = array_values( array_unique( array_filter( array_map( array( self::class, 'bare_host' ), $hosts ) ) ) );
	}

	/**
	 * Enlaces del HTML, en orden de aparición.
	 *
	 * @param string $html HTML (contenido de la entrada).
	 *
	 * @return list<ParsedLink>
	 */
	public function parse( string $html ): array {
		if ( false === stripos( $html, '<a' ) ) {
			return array();
		}

		$links = array();
		$state = array(
			'href'   => null,
			'anchor' => '',
			'alt'    => '',
		);

		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			if ( '#text' === $type ) {
				if ( null !== $state['href'] ) {
					$state['anchor'] .= $processor->get_modifiable_text();
				}
				continue;
			}

			if ( '#tag' !== $type ) {
				continue;
			}

			$tag = $processor->get_tag();

			if ( 'A' === $tag ) {
				$this->close( $state, $links );
				if ( ! $processor->is_tag_closer() ) {
					$href = $processor->get_attribute( 'href' );
					if ( is_string( $href ) ) {
						$state['href'] = $href;
					}
				}
				continue;
			}

			if ( 'IMG' === $tag && null !== $state['href'] && '' === $state['alt'] && ! $processor->is_tag_closer() ) {
				$value        = $processor->get_attribute( 'alt' );
				$state['alt'] = is_string( $value ) ? $value : '';
			}
		}//end while
		$this->close( $state, $links );

		return $links;
	}

	/**
	 * Cierra el enlace abierto, si lo hay, y lo añade a la lista.
	 *
	 * @param array{href: string|null, anchor: string, alt: string} $state Enlace abierto (se reinicia).
	 * @param array<int, ParsedLink>                                $links Lista de enlaces.
	 */
	private function close( array &$state, array &$links ): void {
		if ( null !== $state['href'] ) {
			$text = trim( $state['anchor'] );
			$link = $this->classify( $state['href'], '' === $text ? $state['alt'] : $state['anchor'] );
			if ( null !== $link ) {
				$links[] = $link;
			}
		}

		$state = array(
			'href'   => null,
			'anchor' => '',
			'alt'    => '',
		);
	}

	/**
	 * Clasifica un href. Devuelve null si no es un enlace que se cuente.
	 *
	 * @param string $href   Valor de href.
	 * @param string $anchor Texto del enlace.
	 */
	private function classify( string $href, string $anchor ): ?ParsedLink {
		$href = trim( $href );

		if ( '' === $href || '#' === $href[0] || '?' === $href[0] ) {
			return null;
		}

		$anchor = $this->clean_anchor( $anchor );

		// Con esquema (o «//host»): solo http y https.
		if ( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $href, $m ) && ! in_array( strtolower( $m[1] ), array( 'http', 'https' ), true ) ) {
			return null;
		}

		$has_host = 0 === strpos( $href, '//' ) || preg_match( '#^https?://#i', $href );

		if ( $has_host ) {
			$absolute = 0 === strpos( $href, '//' ) ? $this->scheme . ':' . $href : $href;
			$parts    = wp_parse_url( $absolute );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return null;
			}
			if ( ! in_array( self::bare_host( (string) $parts['host'] ), $this->hosts, true ) ) {
				return new ParsedLink( $href, $anchor, false );
			}
		} else {
			// Ruta relativa al sitio.
			$absolute = 0 === strpos( $href, '/' )
				? rtrim( $this->origin(), '/' ) . $href
				: $this->home . $href;
			$parts    = wp_parse_url( $absolute );
			if ( ! is_array( $parts ) ) {
				return null;
			}
		}

		$path = (string) ( $parts['path'] ?? '' );

		if ( $this->is_not_a_page( $path ) ) {
			return null;
		}

		return new ParsedLink( $this->normalize( $parts ), $anchor, true );
	}

	/**
	 * Origen del sitio sin ruta («https://ejemplo.com»).
	 */
	private function origin(): string {
		$parts = wp_parse_url( $this->home );

		return $this->scheme . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * Si la ruta es un fichero o una ruta de WordPress que no es contenido.
	 *
	 * @param string $path Ruta de la URL.
	 */
	private function is_not_a_page( string $path ): bool {
		$relative = ltrim( $path, '/' );
		$home_dir = trim( (string) wp_parse_url( $this->home, PHP_URL_PATH ), '/' );

		if ( '' !== $home_dir && 0 === strpos( $relative, $home_dir . '/' ) ) {
			$relative = substr( $relative, strlen( $home_dir ) + 1 );
		}

		foreach ( self::NON_CONTENT_PATHS as $prefix ) {
			if ( 0 === strpos( $relative, $prefix ) ) {
				return true;
			}
		}

		$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );

		return ! in_array( $extension, self::PAGE_EXTENSIONS, true );
	}

	/**
	 * URL absoluta, sin fragmento ni parámetros de seguimiento.
	 *
	 * @param array<string, mixed> $parts Partes de wp_parse_url().
	 */
	private function normalize( array $parts ): string {
		$url = $this->origin() . ( $parts['path'] ?? '/' );

		if ( ! empty( $parts['query'] ) ) {
			$kept = array();
			foreach ( explode( '&', (string) $parts['query'] ) as $pair ) {
				$name = urldecode( (string) strtok( $pair, '=' ) );
				if ( '' !== $pair && ! preg_match( self::TRACKING_PARAMS, $name ) ) {
					$kept[] = $pair;
				}
			}
			if ( array() !== $kept ) {
				$url .= '?' . implode( '&', $kept );
			}
		}

		return $url;
	}

	/**
	 * Anchor sin espacios sobrantes y de como mucho 255 caracteres.
	 *
	 * @param string $anchor Texto en bruto.
	 */
	private function clean_anchor( string $anchor ): string {
		$anchor = trim( (string) preg_replace( '/\s+/u', ' ', $anchor ) );

		return mb_substr( $anchor, 0, 255 );
	}

	/**
	 * Host en minúsculas y sin «www.».
	 *
	 * @param string $host Host.
	 */
	private static function bare_host( string $host ): string {
		$host = strtolower( trim( $host ) );

		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
