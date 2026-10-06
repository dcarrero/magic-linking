<?php
/**
 * Idioma por entrada.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\I18n;

/**
 * Detecta el idioma de una entrada con WPML, con Polylang o, en su defecto,
 * con el idioma del sitio. Devuelve siempre un código corto en minúsculas
 * («es», «en», «pt»).
 *
 * Solo lee lo que esos plugins ya tienen calculado; no consulta la base de
 * datos por su cuenta.
 */
final class Language {

	/**
	 * Función de Polylang que da el idioma de una entrada.
	 *
	 * @var callable|null
	 */
	private $polylang;

	/**
	 * Constructor.
	 *
	 * @param callable|null $polylang Sustituto de pll_get_post_language() (para pruebas); null para la función real si existe.
	 */
	public function __construct( ?callable $polylang = null ) {
		$this->polylang = $polylang;
	}

	/**
	 * Idioma de una entrada.
	 *
	 * Orden: filtro magiclinking_post_language, WPML, Polylang, idioma del sitio.
	 *
	 * @param int $post_id ID de la entrada.
	 */
	public function for_post( int $post_id ): string {
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

		return $this->site();
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
