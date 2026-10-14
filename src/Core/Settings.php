<?php
/**
 * Ajustes del plugin.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Core;

/**
 * Lee y guarda la opción magiclinking_settings, la única en autoload.
 *
 * Si la opción no existe se usan los valores por defecto sin escribir nada.
 */
final class Settings {

	/**
	 * Ajustes y valores por defecto. Todos viven en la única opción en autoload (`magiclinking_settings`).
	 *
	 * Los valores por defecto reproducen el comportamiento de la 0.10.0: quien actualiza no nota ningún cambio
	 * hasta que toca un ajuste (docs/07 §8).
	 */
	public const DEFAULTS = array(
		'post_types'                   => array( 'post', 'page' ),
		'low_inbound_threshold'        => 2,
		'words_per_link'               => 100,
		self::RETENTION_SETTING        => 90,
		Installer::DELETE_DATA_SETTING => false,
		'link_in_headings'             => false,
		'link_in_quotes'               => false,
		'link_in_tables'               => false,
		'open_in_new_tab'              => false,
		'extra_rel'                    => '',
		'outbound_shown'               => 15,
		'inbound_per_page'             => 10,
		'same_anchor_max'              => 5,
		'ignored_urls'                 => array(),
		'ignored_words'                => array(),
		'ignored_ids'                  => array(),
	);

	/**
	 * Días que se conserva el historial de cambios (docs/03 §4.6): 30, 90, 365 o 0 (sin caducidad).
	 */
	public const RETENTION_SETTING = 'history_retention_days';

	/**
	 * Valores admitidos de la conservación del historial, en días; 0 es «sin caducidad».
	 */
	public const RETENTION_CHOICES = array( 30, 90, 365, 0 );

	/**
	 * Ajustes de enteros: mínimo y máximo.
	 */
	public const INT_RANGES = array(
		'low_inbound_threshold' => array( 1, 20 ),
		'words_per_link'        => array( 20, 1000 ),
		'outbound_shown'        => array( 1, 50 ),
		'inbound_per_page'      => array( 5, 50 ),
		'same_anchor_max'       => array( 1, 50 ),
	);

	/**
	 * Ajustes de lista de texto (uno por línea en la pantalla): máximo de líneas.
	 */
	public const LIST_LIMITS = array(
		'ignored_urls'  => 200,
		'ignored_words' => 200,
	);

	/**
	 * Ajustes de sí o no.
	 */
	public const BOOLEANS = array( Installer::DELETE_DATA_SETTING, 'link_in_headings', 'link_in_quotes', 'link_in_tables', 'open_in_new_tab' );

	/**
	 * Todos los ajustes, con los valores por defecto donde falten.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( Installer::SETTINGS_OPTION, array() );

		return $this->sanitize( is_array( $stored ) ? $stored : array(), self::DEFAULTS );
	}

	/**
	 * Tipos de contenido que se analizan.
	 *
	 * @return list<string>
	 */
	public function post_types(): array {
		return $this->all()['post_types'];
	}

	/**
	 * Por debajo de cuántas entrantes una entrada cuenta como «poco enlazada».
	 */
	public function low_inbound_threshold(): int {
		return $this->all()['low_inbound_threshold'];
	}

	/**
	 * Cada cuántas palabras se admite un enlace saliente.
	 */
	public function words_per_link(): int {
		return $this->all()['words_per_link'];
	}

	/**
	 * Días que se conserva el historial de cambios; 0 = sin caducidad.
	 */
	public function history_retention_days(): int {
		return $this->all()['history_retention_days'];
	}

	/**
	 * Dónde más se puede enlazar además de los párrafos y las listas.
	 *
	 * @return array{headings: bool, quotes: bool, tables: bool}
	 */
	public function link_scope(): array {
		$all = $this->all();

		return array(
			'headings' => (bool) $all['link_in_headings'],
			'quotes'   => (bool) $all['link_in_quotes'],
			'tables'   => (bool) $all['link_in_tables'],
		);
	}

	/**
	 * Atributos `target` y `rel` de los enlaces que se insertan; vacío si no se ha configurado nada.
	 *
	 * @return array<string, string>
	 */
	public function link_attributes(): array {
		$all = $this->all();
		$rel = preg_split( '/\s+/', (string) $all['extra_rel'], -1, PREG_SPLIT_NO_EMPTY );
		$rel = false === $rel ? array() : $rel;
		$out = array();

		if ( $all['open_in_new_tab'] ) {
			$out['target'] = '_blank';
			$rel[]         = 'noopener';
		}
		$rel = array_values( array_unique( $rel ) );
		if ( array() !== $rel ) {
			$out['rel'] = implode( ' ', $rel );
		}

		return $out;
	}

	/**
	 * Guarda los ajustes que lleguen, saneados; los demás se conservan.
	 *
	 * @param array<string, mixed> $input Ajustes nuevos (parciales).
	 *
	 * @return array<string, mixed>
	 */
	public function update( array $input ): array {
		$before   = $this->all();
		$settings = $this->sanitize( $input, $before );

		// Las direcciones ignoradas se resuelven a entradas una sola vez, aquí, y no en cada petición (docs/09 §4).
		// Si una entrada cambia de dirección después, basta con volver a guardar los ajustes.
		$settings['ignored_ids'] = $before['ignored_ids'];
		if ( $settings['ignored_urls'] !== $before['ignored_urls'] ) {
			$ids = array();
			foreach ( $settings['ignored_urls'] as $url ) {
				$id = url_to_postid( $url );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			$settings['ignored_ids'] = array_values( array_unique( $ids ) );
		}

		update_option( Installer::SETTINGS_OPTION, $settings, true );

		return $settings;
	}

	/**
	 * Sanea los ajustes recibidos sobre una base ya válida.
	 *
	 * @param array<mixed, mixed>  $input Ajustes recibidos.
	 * @param array<string, mixed> $base  Valores de partida.
	 *
	 * @return array<string, mixed>
	 */
	private function sanitize( array $input, array $base ): array {
		$out = array();

		$post_types = $base['post_types'] ?? self::DEFAULTS['post_types'];
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$available  = array_keys( self::available_post_types() );
			$post_types = array_values( array_intersect( array_map( 'strval', $input['post_types'] ), $available ) );
		}
		$out['post_types'] = array_values( array_map( 'strval', (array) $post_types ) );

		foreach ( self::INT_RANGES as $key => [ $min, $max ] ) {
			$value       = $input[ $key ] ?? $base[ $key ] ?? self::DEFAULTS[ $key ];
			$out[ $key ] = max( $min, min( $max, (int) $value ) );
		}

		$keep = $input[ self::RETENTION_SETTING ] ?? $base[ self::RETENTION_SETTING ] ?? self::DEFAULTS[ self::RETENTION_SETTING ];

		$out[ self::RETENTION_SETTING ] = is_numeric( $keep ) && in_array( (int) $keep, self::RETENTION_CHOICES, true ) ? (int) $keep : (int) ( $base[ self::RETENTION_SETTING ] ?? self::DEFAULTS[ self::RETENTION_SETTING ] );

		foreach ( self::BOOLEANS as $key ) {
			$out[ $key ] = filter_var( $input[ $key ] ?? $base[ $key ] ?? false, FILTER_VALIDATE_BOOLEAN );
		}

		$out['extra_rel'] = self::clean_rel( $input['extra_rel'] ?? $base['extra_rel'] ?? '' );

		foreach ( self::LIST_LIMITS as $key => $limit ) {
			$out[ $key ] = self::clean_list( $input[ $key ] ?? $base[ $key ] ?? array(), $limit, 'ignored_urls' === $key );
		}

		// Se calculan al guardar las direcciones (`update()`); lo que llegue de fuera se descarta allí.
		$out['ignored_ids'] = array_values( array_map( 'intval', (array) ( $input['ignored_ids'] ?? $base['ignored_ids'] ?? array() ) ) );

		return $out;
	}

	/**
	 * Valores de `rel` válidos: palabras en minúsculas (letras y guiones), sin repetir.
	 *
	 * @param mixed $value Texto recibido.
	 */
	private static function clean_rel( $value ): string {
		$tokens = preg_split( '/[\s,]+/', strtolower( is_string( $value ) ? $value : '' ), -1, PREG_SPLIT_NO_EMPTY );
		$tokens = array_filter( false === $tokens ? array() : $tokens, static fn( string $t ): bool => 1 === preg_match( '/^[a-z][a-z-]{0,29}$/', $t ) );

		return implode( ' ', array_slice( array_values( array_unique( $tokens ) ), 0, 10 ) );
	}

	/**
	 * Lista de líneas de texto limpias: acepta una matriz o un texto con una por línea.
	 *
	 * @param mixed $value Lista recibida.
	 * @param int   $limit Máximo de elementos.
	 * @param bool  $url   Son direcciones: se sanean como tales.
	 *
	 * @return list<string>
	 */
	private static function clean_list( $value, int $limit, bool $url ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\R/u', $value );
		}
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}
			$line = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $line ) ) );
			if ( $url ) {
				$line = '' === $line ? '' : esc_url_raw( $line );
			}
			if ( '' !== $line && ! in_array( $line, $out, true ) ) {
				$out[] = mb_substr( $line, 0, 300, 'UTF-8' );
			}
		}

		return array_slice( $out, 0, $limit );
	}

	/**
	 * Tipos de contenido públicos que se pueden analizar (sin archivos adjuntos).
	 *
	 * @return array<string, string> Nombre → etiqueta.
	 */
	public static function available_post_types(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( 'attachment' === $name ) {
				continue;
			}
			$types[ $name ] = $object->labels->name ?? $name;
		}

		return $types;
	}
}
