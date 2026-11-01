<?php
/**
 * Idioma por entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\I18n;

use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use MagicLinking\Engine\LanguageDetector;
use MagicLinking\Graph\GraphRepository;
use MagicLinking\Index\PostSource;
use WP_Post;

/**
 * Detecta el idioma de una entrada con WPML, con Polylang o, en su defecto,
 * con el idioma del sitio. Devuelve siempre un código corto en minúsculas
 * («es», «en», «pt»).
 *
 * Con la opción «Detectar el idioma por el texto» (F1-37), y solo si no hay
 * WPML ni Polylang, el idioma de cada entrada sale de su texto: se calcula
 * al indexar y se guarda en el grafo (`magiclinking_docs.lang`), de donde lo
 * leen los demás. Sin la opción no consulta la base de datos por su cuenta.
 */
final class Language implements Module {

	/**
	 * Función de Polylang que da el idioma de una entrada.
	 *
	 * @var callable|null
	 */
	private $polylang;

	/**
	 * Ajustes (para saber si se detecta por el texto); null: nunca.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings;

	/**
	 * Grafo, donde queda guardado el idioma detectado; null: se detecta sobre la marcha.
	 *
	 * @var GraphRepository|null
	 */
	private ?GraphRepository $graph;

	/**
	 * Relanza el análisis completo (para cuando cambia el idioma del sitio); null: no hace nada.
	 *
	 * @var callable|null
	 */
	private $reindex;

	/**
	 * Idiomas ya guardados en el grafo que se han leído en esta petición, por entrada.
	 *
	 * @var array<int, string>
	 */
	private array $stored = array();

	/**
	 * Idiomas detectados por el texto en esta petición, por entrada: huella del texto e idioma.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $from_text = array();

	/**
	 * Constructor.
	 *
	 * @param callable|null        $polylang Sustituto de pll_get_post_language() (para pruebas); null para la función real si existe.
	 * @param Settings|null        $settings Ajustes; con ellos se puede detectar el idioma por el texto.
	 * @param GraphRepository|null $graph    Grafo, donde se lee el idioma ya detectado de una entrada indexada.
	 * @param callable|null        $reindex  Relanza el análisis completo cuando cambia el idioma del sitio.
	 */
	public function __construct( ?callable $polylang = null, ?Settings $settings = null, ?GraphRepository $graph = null, ?callable $reindex = null ) {
		$this->reindex  = $reindex;
		$this->polylang = $polylang;
		$this->settings = $settings;
		$this->graph    = $graph;
	}

	/**
	 * Cambiar el idioma del sitio cambia el de las entradas que no se han podido detectar (se guardan con el del
	 * sitio de cuando se indexaron, porque el índice léxico necesita un valor): con la detección activa se relee todo.
	 */
	public function register(): void {
		add_action( 'update_option_WPLANG', array( $this, 'site_language_changed' ) );
		add_action( 'add_option_WPLANG', array( $this, 'site_language_changed' ) );
	}

	/**
	 * Hooks de `update_option_WPLANG` y `add_option_WPLANG`.
	 */
	public function site_language_changed(): void {
		if ( null !== $this->reindex && $this->detects() ) {
			( $this->reindex )();
		}
	}

	/**
	 * Idioma de una entrada.
	 *
	 * Orden: filtro magiclinking_post_language, WPML, Polylang, idioma del sitio.
	 *
	 * @param int $post_id ID de la entrada.
	 */
	public function for_post( int $post_id ): string {
		$explicit = $this->explicit( $post_id );
		if ( null !== $explicit ) {
			return $explicit;
		}

		return $this->detects() ? $this->detected( $post_id ) : $this->site();
	}

	/**
	 * Idioma de una entrada que se va a indexar: igual que {@see self::for_post()}, pero con la opción de detectar
	 * por el texto activa lo calcula a partir del HTML que se indexa (el contenido con el filtro
	 * `magiclinking_post_html` o el del editor) y no lo lee del grafo, que aún tiene el anterior. Lo usan el indexado
	 * del grafo y el del índice léxico, y comparten el resultado.
	 *
	 * @param WP_Post $post Entrada.
	 * @param string  $html HTML que se indexa.
	 */
	public function for_indexing( WP_Post $post, string $html ): string {
		$explicit = $this->explicit( $post->ID );
		if ( null !== $explicit ) {
			return $explicit;
		}

		return $this->detects() ? $this->from_text( $post, $html ) : $this->site();
	}

	/**
	 * Olvida el idioma guardado que se había leído de unas entradas (el indexado acaba de reescribirlo).
	 *
	 * @param array<int, int> $post_ids IDs.
	 */
	public function forget( array $post_ids ): void {
		foreach ( $post_ids as $id ) {
			unset( $this->stored[ (int) $id ] );
		}
	}

	/**
	 * Lee de una vez, con una sola consulta, el idioma guardado de varias entradas, para que {@see self::for_post()}
	 * no haga una consulta por entrada al recorrer una lista. No hace nada si no se detecta por el texto.
	 *
	 * @param array<int, int> $post_ids IDs.
	 */
	public function prime( array $post_ids ): void {
		if ( null === $this->graph || ! $this->detects() ) {
			return;
		}

		$missing = array_values( array_filter( array_unique( array_map( 'intval', $post_ids ) ), fn( int $id ): bool => $id > 0 && ! isset( $this->stored[ $id ] ) ) );
		if ( count( $missing ) < 2 ) {
			return;
		}

		foreach ( $this->graph->docs_by_ids( $missing ) as $id => $doc ) {
			if ( '' !== $doc['lang'] ) {
				$this->stored[ $id ] = $doc['lang'];
			}
		}
	}

	/**
	 * Si el idioma se detecta por el texto: la opción está activa y no hay WPML ni Polylang (que mandan siempre).
	 */
	public function detects(): bool {
		return null !== $this->settings && $this->settings->detect_language() && ! $this->plugin_active();
	}

	/**
	 * Idioma fijado desde fuera: filtro, WPML o Polylang; null si ninguno lo da.
	 *
	 * @param int $post_id ID de la entrada.
	 */
	private function explicit( int $post_id ): ?string {
		/**
		 * Permite fijar el idioma de una entrada desde otro plugin o desde el tema.
		 *
		 * @param string|null $language Código de idioma, o null para dejar que lo decida el plugin.
		 * @param int         $post_id  ID de la entrada.
		 */
		$override = apply_filters( 'magiclinking_post_language', null, $post_id );
		if ( is_string( $override ) && '' !== self::normalize( $override ) ) {
			return self::normalize( $override );
		}

		// WPML.
		$details = apply_filters( 'wpml_post_language_details', null, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro de WPML.
		if ( is_array( $details ) && isset( $details['language_code'] ) && is_string( $details['language_code'] ) ) {
			$code = self::normalize( $details['language_code'] );
			if ( '' !== $code ) {
				return $code;
			}
		}

		// Polylang.
		$polylang = $this->polylang;
		if ( null === $polylang && function_exists( 'pll_get_post_language' ) ) {
			$polylang = 'pll_get_post_language';
		}
		if ( null !== $polylang ) {
			$slug = $polylang( $post_id, 'slug' );
			if ( is_string( $slug ) && '' !== self::normalize( $slug ) ) {
				return self::normalize( $slug );
			}
		}

		return null;
	}

	/**
	 * Idioma detectado de una entrada: el guardado en el grafo si está indexada; si no (borrador, entrada nueva),
	 * el que sale de su texto, sin guardarlo.
	 *
	 * @param int $post_id ID de la entrada.
	 */
	private function detected( int $post_id ): string {
		if ( isset( $this->stored[ $post_id ] ) ) {
			return $this->stored[ $post_id ];
		}

		$doc = null === $this->graph ? null : $this->graph->doc( $post_id );
		if ( null !== $doc && '' !== $doc['lang'] ) {
			$this->stored[ $post_id ] = $doc['lang'];

			return $doc['lang'];
		}

		$post = get_post( $post_id );

		return $post instanceof WP_Post ? $this->from_text( $post, PostSource::html_of( $post ) ) : $this->site();
	}

	/**
	 * Detecta el idioma por el título y el HTML que se indexa, sin etiquetas, shortcodes ni entidades. Se calcula una
	 * vez por entrada y texto en cada petición.
	 *
	 * @param WP_Post $post Entrada.
	 * @param string  $html HTML que se indexa.
	 */
	private function from_text( WP_Post $post, string $html ): string {
		// Tope antes de quitar etiquetas: un maquetador puede dejar cientos de KB de marcado.
		$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( $post->post_title . ' ' . mb_substr( $html, 0, 10 * LanguageDetector::MAX_CHARS, 'UTF-8' ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$seal = (string) crc32( $text );
		$memo = $this->from_text[ $post->ID ] ?? null;
		if ( null === $memo || $memo[0] !== $seal ) {
			$memo                         = array( $seal, LanguageDetector::detect( $text, '' ) );
			$this->from_text[ $post->ID ] = $memo;
		}

		// Sin confianza, el idioma del sitio de ahora (no se recuerda: puede cambiar).
		return '' !== $memo[1] ? $memo[1] : $this->site();
	}

	/**
	 * Si hay un plugin multilingüe activo (WPML o Polylang).
	 */
	public function plugin_active(): bool {
		$active = defined( 'ICL_SITEPRESS_VERSION' ) || defined( 'POLYLANG_VERSION' ) || function_exists( 'pll_get_post_language' ) || null !== $this->polylang;

		/**
		 * Permite marcar el sitio como multilingüe (o no) para mostrar u ocultar el idioma en el informe.
		 *
		 * @param bool $active Si hay WPML o Polylang activos.
		 */
		return (bool) apply_filters( 'magiclinking_is_multilingual', $active );
	}

	/**
	 * Idioma del sitio.
	 */
	public function site(): string {
		$code = self::normalize( get_locale() );

		return '' !== $code ? $code : 'en';
	}

	/**
	 * Pasa un código de idioma o de configuración regional a su forma corta:
	 * «es_ES» → «es», «pt-BR» → «pt», «ZH-hans» → «zh».
	 *
	 * @param string $code Código de idioma.
	 */
	public static function normalize( string $code ): string {
		$code = strtolower( trim( $code ) );
		$code = (string) preg_split( '/[-_]/', $code, 2 )[0];

		return preg_match( '/^[a-z]{2,3}$/', $code ) ? $code : '';
	}
}
