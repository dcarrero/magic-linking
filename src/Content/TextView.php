<?php
/**
 * Vista de texto plano de un tramo de HTML, con el mapa de cada carácter a su posición en bytes.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Content;

/**
 * Recorre un tramo de HTML (el interior de un bloque o el contenido clásico) y construye el texto que ve
 * el lector, normalizado como lo hace el extractor del motor (entidades decodificadas, espacios colapsados,
 * shortcodes como un espacio), junto con, para cada carácter, los bytes del HTML original que ocupa.
 *
 * Es lo que permite localizar la frase en el HTML con posiciones exactas aunque haya tildes, emojis,
 * entidades, `&nbsp;`, etiquetas en línea o shortcodes por medio. No modifica nada.
 */
final class TextView {

	/**
	 * Carácter que ocupa el lugar de algo que corta el texto (salto de línea de HTML, imagen, código en
	 * línea, línea en blanco del editor clásico). Nunca forma parte de un ancla ni de un contexto válido.
	 */
	public const BREAK = "\x1F";

	/**
	 * Elementos vacíos de HTML.
	 */
	private const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	/**
	 * Elementos vacíos que cortan el texto.
	 */
	private const VOID_BREAKS = array( 'br', 'hr', 'img', 'input', 'embed' );

	/**
	 * Elementos cuyo contenido no es texto del artículo: se trata como un corte, entero.
	 */
	private const OPAQUE = array( 'code', 'kbd', 'samp', 'pre', 'script', 'style', 'textarea', 'svg', 'math', 'template', 'select', 'noscript' );

	/**
	 * Elementos con contenido que no se puede recorrer con seguridad con este analizador.
	 */
	private const UNSAFE = array( 'script', 'style', 'textarea', 'svg', 'math', 'template', 'title', 'iframe', 'xmp', 'plaintext' );

	/**
	 * Texto normalizado.
	 *
	 * @var string
	 */
	private string $text = '';

	/**
	 * Carácter UTF-8 a carácter, para no depender de `mb_*` en cada acceso.
	 *
	 * @var list<string>
	 */
	private array $chars = array();

	/**
	 * Primer byte de cada carácter en el HTML.
	 *
	 * @var list<int>
	 */
	private array $from = array();

	/**
	 * Byte siguiente al último de cada carácter en el HTML.
	 *
	 * @var list<int>
	 */
	private array $to = array();

	/**
	 * Tipo de cada carácter: 0 texto, 1 shortcode, 2 corte.
	 *
	 * @var list<int>
	 */
	private array $kind = array();

	/**
	 * Tramo de texto (índice en $ancestors) de cada carácter.
	 *
	 * @var list<int>
	 */
	private array $run = array();

	/**
	 * Elementos abiertos en cada tramo de texto.
	 *
	 * @var list<list<string>>
	 */
	private array $ancestors = array();

	/**
	 * Etiquetas (no vacías) del tramo: inicio, fin, nombre y si cierran.
	 *
	 * @var list<array{0: int, 1: int, 2: string, 3: bool}>
	 */
	private array $tags = array();

	/**
	 * Etiquetas por byte de fin.
	 *
	 * @var array<int, int>
	 */
	private array $tag_by_end = array();

	/**
	 * Etiquetas por byte de inicio.
	 *
	 * @var array<int, int>
	 */
	private array $tag_by_start = array();

	/**
	 * Si el HTML tiene algo que este analizador no recorre con seguridad.
	 *
	 * @var string|null
	 */
	private ?string $unsafe = null;

	/**
	 * Construye la vista.
	 *
	 * @param string $html    HTML del tramo.
	 * @param bool   $classic Editor clásico: el salto de línea simple se conserva y una línea en blanco corta el texto.
	 */
	public function __construct( private string $html, private bool $classic ) {
		$this->build();
	}

	/**
	 * Texto normalizado.
	 */
	public function text(): string {
		return $this->text;
	}

	/**
	 * Número de caracteres.
	 */
	public function length(): int {
		return count( $this->chars );
	}

	/**
	 * Si el tramo tiene marcas que no se recorren con seguridad (por ejemplo `<script>`).
	 */
	public function unsafe(): ?string {
		return $this->unsafe;
	}

	/**
	 * Posiciones (en caracteres) de todas las apariciones de un texto, también las solapadas.
	 *
	 * @param string $needle Texto normalizado.
	 *
	 * @return list<int>
	 */
	public function find_all( string $needle ): array {
		$found  = array();
		$offset = 0;
		if ( '' === $needle ) {
			return $found;
		}

		$at = mb_strpos( $this->text, $needle, 0, 'UTF-8' );
		while ( false !== $at ) {
			$found[] = $at;
			$at      = mb_strpos( $this->text, $needle, $at + 1, 'UTF-8' );
		}

		return $found;
	}

	/**
	 * Bytes del HTML que ocupa el tramo de caracteres [from, to).
	 *
	 * @param int $from Primer carácter.
	 * @param int $to   Carácter siguiente al último.
	 *
	 * @return array{0: int, 1: int}
	 */
	public function bytes( int $from, int $to ): array {
		return array( $this->from[ $from ], $this->to[ $to - 1 ] );
	}

	/**
	 * Por qué no se puede enlazar el tramo de caracteres [from, to), o null si se puede.
	 *
	 * @param int   $from      Primer carácter.
	 * @param int   $to        Carácter siguiente al último.
	 * @param array $forbidden Elementos dentro de los cuales no se enlaza.
	 *
	 * @phpstan-param list<string> $forbidden
	 *
	 * @return string|null `link` (ya hay un enlace), `shortcode`, `break` o `element:<nombre>`.
	 */
	public function blocker( int $from, int $to, array $forbidden ): ?string {
		for ( $i = $from; $i < $to; $i++ ) {
			if ( 1 === $this->kind[ $i ] ) {
				return 'shortcode';
			}
			if ( 2 === $this->kind[ $i ] ) {
				return 'break';
			}
			foreach ( $this->ancestors[ $this->run[ $i ] ] as $name ) {
				if ( 'a' === $name ) {
					return 'link';
				}
				if ( in_array( $name, $forbidden, true ) ) {
					return 'element:' . $name;
				}
			}
		}

		return null;
	}

	/**
	 * Ajusta el tramo de bytes [start, end) para que sea una sucesión de etiquetas bien anidada: lo que se
	 * abre dentro se cierra dentro y viceversa. Si falta el otro extremo de una etiqueta y está justo al
	 * lado (el ancla empieza tras `<strong>` y acaba tras `</strong>`), el tramo lo incluye; si no, no hay
	 * forma de enlazarlo sin mal anidar y se devuelve null.
	 *
	 * @param int   $start   Primer byte.
	 * @param int   $end     Byte siguiente al último.
	 * @param array $inline  Elementos que pueden quedar dentro del enlace.
	 *
	 * @phpstan-param list<string> $inline
	 *
	 * @return array{0: int, 1: int}|null
	 */
	public function balanced( int $start, int $end, array $inline ): ?array {
		$stack   = array();
		$pending = array();

		foreach ( $this->tags as [ $t_start, $t_end, $name, $closer ] ) {
			if ( $t_start < $start || $t_end > $end ) {
				continue;
			}
			if ( ! in_array( $name, $inline, true ) ) {
				return null;
			}
			if ( ! $closer ) {
				$stack[] = $name;
				continue;
			}
			if ( array() !== $stack && end( $stack ) === $name ) {
				array_pop( $stack );
			} elseif ( in_array( $name, $stack, true ) ) {
				return null;
			} else {
				$pending[] = $name;
			}
		}

		foreach ( $pending as $name ) {
			$index = $this->tag_by_end[ $start ] ?? null;
			if ( null === $index || $this->tags[ $index ][3] || $this->tags[ $index ][2] !== $name ) {
				return null;
			}
			$start = $this->tags[ $index ][0];
		}

		while ( array() !== $stack ) {
			$name  = array_pop( $stack );
			$index = $this->tag_by_start[ $end ] ?? null;
			if ( null === $index || ! $this->tags[ $index ][3] || $this->tags[ $index ][2] !== $name ) {
				return null;
			}
			$end = $this->tags[ $index ][1];
		}

		return array( $start, $end );
	}

	/**
	 * Caracteres UTF-8 de un texto.
	 *
	 * @param string $text Texto.
	 *
	 * @return list<string>
	 */
	private static function characters( string $text ): array {
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return false === $chars ? str_split( $text ) : $chars;
	}

	/**
	 * Recorre el HTML y rellena los mapas.
	 */
	private function build(): void {
		$html    = $this->html;
		$pattern = '~<!--.*?-->|<\?.*?>|<![^>]*>|</?[a-zA-Z][^\s/>]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>~s';
		$stack   = array();
		$cursor  = 0;
		$opaque  = 0;

		preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE );

		foreach ( $matches[0] as [ $token, $at ] ) {
			$at = (int) $at;
			if ( $at > $cursor ) {
				$this->gap( $cursor, $at, $stack, $opaque > 0 );
			}
			$cursor = $at + strlen( $token );

			if ( ! preg_match( '~^<(/?)([a-zA-Z][^\s/>]*)~', $token, $parts ) ) {
				continue;
			}

			$name   = strtolower( $parts[2] );
			$closer = '/' === $parts[1];

			if ( in_array( $name, self::UNSAFE, true ) ) {
				$this->unsafe = $name;
			}

			if ( in_array( $name, self::VOID, true ) ) {
				if ( ! $closer && in_array( $name, self::VOID_BREAKS, true ) ) {
					$this->emit( self::BREAK, $at, $cursor, 2, $stack );
				}
				continue;
			}

			$index                       = count( $this->tags );
			$this->tags[]                = array( $at, $cursor, $name, $closer );
			$this->tag_by_end[ $cursor ] = $index;
			$this->tag_by_start[ $at ]   = $index;

			if ( ! $closer ) {
				$stack[] = $name;
				if ( in_array( $name, self::OPAQUE, true ) ) {
					if ( 0 === $opaque ) {
						$this->emit( self::BREAK, $at, $cursor, 2, $stack );
					}
					++$opaque;
				}
				continue;
			}

			$depth = array_search( $name, array_reverse( $stack, true ), true );
			if ( false !== $depth ) {
				$stack = array_slice( $stack, 0, (int) $depth );
				if ( in_array( $name, self::OPAQUE, true ) && $opaque > 0 ) {
					--$opaque;
				}
			}
		}//end foreach

		if ( $cursor < strlen( $html ) ) {
			$this->gap( $cursor, strlen( $html ), $stack, $opaque > 0 );
		}

		$this->text = implode( '', $this->chars );
	}

	/**
	 * Texto entre dos etiquetas.
	 *
	 * @param int   $start  Primer byte.
	 * @param int   $end    Byte siguiente al último.
	 * @param array $stack  Elementos abiertos.
	 * @param bool  $opaque Dentro de un elemento cuyo contenido no cuenta.
	 *
	 * @phpstan-param list<string> $stack
	 */
	private function gap( int $start, int $end, array $stack, bool $opaque ): void {
		if ( $opaque ) {
			return;
		}

		$raw = substr( $this->html, $start, $end - $start );

		// Editor clásico: una línea en blanco es un párrafo nuevo, y el texto no cruza de uno a otro.
		if ( $this->classic && 1 === preg_match( '~\r?\n[ \t\r\f]*\r?\n~', $raw ) ) {
			$cursor = 0;
			preg_match_all( '~\r?\n[ \t\r\f]*\r?\n\s*~', $raw, $breaks, PREG_OFFSET_CAPTURE );
			foreach ( $breaks[0] as [ $blank, $at ] ) {
				$this->plain_gap( substr( $raw, $cursor, $at - $cursor ), $start + $cursor, $stack );
				$this->emit( self::BREAK, $start + $at, $start + $at + strlen( $blank ), 2, $stack );
				$cursor = $at + strlen( $blank );
			}
			$this->plain_gap( substr( $raw, $cursor ), $start + $cursor, $stack );
			return;
		}

		$this->plain_gap( $raw, $start, $stack );
	}

	/**
	 * Texto entre dos etiquetas, sin líneas en blanco: separa los shortcodes y decodifica el resto.
	 *
	 * @param string $raw   Texto en el HTML.
	 * @param int    $start Byte del HTML donde empieza.
	 * @param array  $stack Elementos abiertos.
	 *
	 * @phpstan-param list<string> $stack
	 */
	private function plain_gap( string $raw, int $start, array $stack ): void {
		if ( '' === $raw ) {
			return;
		}

		$parts = preg_split( '~(\[/?[a-z][\w-]*(?:\s[^\]]*)?\])~i', $raw, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE );
		foreach ( false === $parts ? array( array( $raw, 0 ) ) : $parts as $n => [ $piece, $at ] ) {
			if ( '' === $piece ) {
				continue;
			}
			if ( 1 === $n % 2 ) {
				$this->emit( ' ', $start + $at, $start + $at + strlen( $piece ), 1, $stack );
				continue;
			}
			$this->decode( $piece, $start + $at, $stack );
		}
	}

	/**
	 * Decodifica un tramo de texto sin shortcodes.
	 *
	 * @param string $raw   Texto en el HTML.
	 * @param int    $start Byte del HTML donde empieza.
	 * @param array  $stack Elementos abiertos.
	 *
	 * @phpstan-param list<string> $stack
	 */
	private function decode( string $raw, int $start, array $stack ): void {
		$pieces = preg_split( '~(&(?:#[0-9]+|#[xX][0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);)~', $raw, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE );
		foreach ( false === $pieces ? array( array( $raw, 0 ) ) : $pieces as $n => [ $piece, $at ] ) {
			if ( '' === $piece ) {
				continue;
			}
			$base = $start + $at;

			if ( 1 === $n % 2 ) {
				$decoded = html_entity_decode( $piece, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( $decoded !== $piece ) {
					$this->plain( $decoded, $base, $base + strlen( $piece ), $stack );
					continue;
				}
			}

			// Texto literal: cada carácter ocupa sus propios bytes.
			$offset = 0;
			foreach ( self::characters( $piece ) as $char ) {
				$this->plain( $char, $base + $offset, $base + $offset + strlen( $char ), $stack );
				$offset += strlen( $char );
			}
		}//end foreach
	}

	/**
	 * Añade el texto de una unidad del HTML (un carácter o una entidad), normalizando los espacios.
	 *
	 * @param string $text  Texto decodificado.
	 * @param int    $start Primer byte de la unidad.
	 * @param int    $end   Byte siguiente al último.
	 * @param array  $stack Elementos abiertos.
	 *
	 * @phpstan-param list<string> $stack
	 */
	private function plain( string $text, int $start, int $end, array $stack ): void {
		foreach ( self::characters( $text ) as $char ) {
			if ( 1 === preg_match( '/^[\s\x{00A0}]$/u', $char ) ) {
				$this->space( $start, $end, $stack );
				continue;
			}
			$this->emit( $char, $start, $end, 0, $stack );
		}
	}

	/**
	 * Añade un espacio en blanco: los seguidos se colapsan en uno, como hace el extractor del motor.
	 *
	 * @param int   $start Primer byte.
	 * @param int   $end   Byte siguiente al último.
	 * @param array $stack Elementos abiertos.
	 *
	 * @phpstan-param list<string> $stack
	 */
	private function space( int $start, int $end, array $stack ): void {
		$last = count( $this->chars ) - 1;

		if ( $last >= 0 && $this->kind[ $last ] < 2 && ' ' === $this->chars[ $last ] ) {
			$this->to[ $last ] = max( $this->to[ $last ], $end );
			return;
		}

		$this->emit( ' ', $start, $end, 0, $stack );
	}

	/**
	 * Añade un carácter normalizado.
	 *
	 * @param string $char  Carácter.
	 * @param int    $start Primer byte.
	 * @param int    $end   Byte siguiente al último.
	 * @param int    $kind  Tipo.
	 * @param array  $stack Elementos abiertos.
	 *
	 * @phpstan-param list<string> $stack
	 */
	private function emit( string $char, int $start, int $end, int $kind, array $stack ): void {
		// Un corte seguido de otro es un solo corte; un espacio de shortcode junto a otro espacio, uno.
		$last = count( $this->chars ) - 1;
		if ( $last >= 0 && 2 === $kind && 2 === $this->kind[ $last ] ) {
			$this->to[ $last ] = $end;
			return;
		}
		if ( $last >= 0 && 1 === $kind && ' ' === $this->chars[ $last ] && 0 === $this->kind[ $last ] ) {
			$this->kind[ $last ] = 1;
			$this->to[ $last ]   = $end;
			return;
		}

		if ( array() === $this->ancestors || end( $this->ancestors ) !== $stack ) {
			$this->ancestors[] = $stack;
		}

		$this->chars[] = $char;
		$this->from[]  = $start;
		$this->to[]    = $end;
		$this->kind[]  = $kind;
		$this->run[]   = count( $this->ancestors ) - 1;
	}
}
