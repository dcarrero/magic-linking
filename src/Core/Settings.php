<?php
/**
 * Ajustes del plugin.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Core;

use MagicLinking\AI\Budget;
use MagicLinking\AI\Models;
use MagicLinking\Engine\Indexer;
use MagicLinking\Engine\RuleLimits;
use MagicLinking\Engine\Scorer;
use MagicLinking\Engine\Suggester;
use MagicLinking\Index\Taxonomies;

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
		'words_per_link'               => RuleLimits::WORDS_PER_LINK,
		'links_per_target_max'         => RuleLimits::LINKS_PER_TARGET,
		self::RETENTION_SETTING        => 90,
		Installer::DELETE_DATA_SETTING => false,
		'link_in_headings'             => false,
		'link_in_quotes'               => false,
		'link_in_tables'               => false,
		'open_in_new_tab'              => false,
		'extra_rel'                    => '',
		'outbound_shown'               => 15,
		'inbound_per_page'             => 10,
		'same_anchor_max'              => RuleLimits::SAME_ANCHOR,
		// Frases alternativas por sugerencia (docs/04 §5, D-30): 0 hasta que el banco las valide (D-33).
		'alternatives'                 => 0,
		'ignored_urls'                 => array(),
		'ignored_words'                => array(),
		'ignored_ids'                  => array(),
		'filters'                      => array(
			'type'     => Suggester::IGNORE,
			'category' => Suggester::IGNORE,
			'tag'      => Suggester::IGNORE,
		),
		// Categorías que quedan fuera del análisis (F1-16): `term_taxonomy_id` de la taxonomía jerárquica principal
		// de cada tipo analizado, igual que los filtros por categoría (D-67); una entrada excluida a mano (D-70) no entra aquí.
		'excluded_categories'          => array(),
		// Idioma de cada entrada detectado por su texto (F1-37, D-61): desactivado por defecto; sin efecto con WPML o Polylang.
		'detect_language'              => false,
		// Avanzado (docs/07 §8): términos que se guardan por entrada (docs/04 §3) y umbral de puntuación en tanto por
		// ciento (docs/04 §6); los valores por defecto son los congelados del motor (D-35).
		'k_terms'                      => Indexer::DEFAULTS['k'],
		'score_threshold'              => Scorer::THRESHOLD_PERCENT,
		'ai_daily_token_limit'         => Budget::DEFAULT_LIMIT,
		'ai_model'                     => '',
	);

	/**
	 * Máximo de categorías excluidas que se guardan.
	 */
	public const MAX_EXCLUDED_CATEGORIES = 500;

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
		'alternatives'          => array( 0, Suggester::MAX_ALTERNATIVES ),
		// Enlaces al mismo destino desde una entrada (docs/04 §6.1): tope duro de las reglas.
		'links_per_target_max'  => array( 1, 10 ),
		// Avanzado: K términos por entrada (docs/04 §3: 20 a 80) y umbral de puntuación en % (docs/04 §6: el barrido llegó a 0,30–0,45).
		'k_terms'               => array( 20, 80 ),
		'score_threshold'       => array( 20, 60 ),
		// Tope diario de tokens de IA (docs/05 §5.3): 0 = sin IA.
		'ai_daily_token_limit'  => array( 0, Budget::MAX_LIMIT ),
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
	public const BOOLEANS = array( Installer::DELETE_DATA_SETTING, 'link_in_headings', 'link_in_quotes', 'link_in_tables', 'open_in_new_tab', 'detect_language' );

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
	 * Tope diario de tokens de IA; 0 = sin IA (docs/05 §5.3).
	 */
	public function ai_daily_token_limit(): int {
		return $this->all()['ai_daily_token_limit'];
	}

	/**
	 * Modelo de texto que el usuario prefiere para la IA; vacío = automático (docs/05 §4).
	 */
	public function ai_model(): string {
		return $this->all()['ai_model'];
	}

	/**
	 * La entrada, si existe y es de un tipo que se analiza.
	 *
	 * @param int $post_id ID.
	 */
	public function analyzed_post( int $post_id ): ?\WP_Post {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post && in_array( $post->post_type, $this->post_types(), true ) ? $post : null;
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
	 * Umbral de puntuación del motor (docs/04 §6), de 0 a 1.
	 */
	public function score_threshold(): float {
		return $this->bounded( 'score_threshold' ) / 100;
	}

	/**
	 * Términos que se guardan por entrada en el índice léxico (K, docs/04 §3). Barato: lo llama el indexado por
	 * cada entrada, así que lee solo este ajuste y no sanea todos.
	 */
	public function k_terms(): int {
		return $this->bounded( 'k_terms' );
	}

	/**
	 * Si el idioma de cada entrada se detecta por su texto (F1-37). Barato: lee solo este ajuste, porque lo consulta
	 * {@see \MagicLinking\I18n\Language::for_post()} muchas veces por petición.
	 */
	public function detect_language(): bool {
		$stored = get_option( Installer::SETTINGS_OPTION, array() );

		return is_array( $stored ) && filter_var( $stored['detect_language'] ?? false, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Un ajuste de entero leído solo, con el mismo límite que le aplica {@see self::all()}.
	 *
	 * @param string $key Clave de {@see self::INT_RANGES}.
	 */
	private function bounded( string $key ): int {
		$stored        = get_option( Installer::SETTINGS_OPTION, array() );
		$value         = is_array( $stored ) ? ( $stored[ $key ] ?? self::DEFAULTS[ $key ] ) : self::DEFAULTS[ $key ];
		[ $min, $max ] = self::INT_RANGES[ $key ];

		return max( $min, min( $max, (int) $value ) );
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
	 * Topes de calidad duros de las reglas automáticas (docs/04 §6.1): son ajustes del usuario, con los mismos
	 * valores por defecto y rangos en la gratuita y en Pro.
	 */
	public function rule_limits(): RuleLimits {
		$all = $this->all();

		return new RuleLimits( $all['words_per_link'], $all['links_per_target_max'], $all['same_anchor_max'] );
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

		// Solo valen los términos de la taxonomía jerárquica principal de un tipo analizado: se comprueba una vez, al
		// guardar, y no en cada lectura.
		// También si cambian los tipos: las categorías de un tipo que ya no se analiza dejan de valer.
		if ( $settings['excluded_categories'] !== $before['excluded_categories'] || $settings['post_types'] !== $before['post_types'] ) {
			$settings['excluded_categories'] = Taxonomies::filter_category_terms( $settings['excluded_categories'], $settings['post_types'] );
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

		$out['excluded_categories'] = self::clean_ids( $input['excluded_categories'] ?? $base['excluded_categories'] ?? array(), self::MAX_EXCLUDED_CATEGORIES );

		$out['ai_model'] = self::clean_model( $input['ai_model'] ?? $base['ai_model'] ?? '' );

		$out['extra_rel'] = self::clean_rel( $input['extra_rel'] ?? $base['extra_rel'] ?? '' );

		foreach ( self::LIST_LIMITS as $key => $limit ) {
			$out[ $key ] = self::clean_list( $input[ $key ] ?? $base[ $key ] ?? array(), $limit, 'ignored_urls' === $key );
		}

		$filters = array();
		$given   = is_array( $input['filters'] ?? null ) ? $input['filters'] : array();
		$kept    = is_array( $base['filters'] ?? null ) ? $base['filters'] : array();
		foreach ( self::DEFAULTS['filters'] as $filter => $default ) {
			$mode               = $given[ $filter ] ?? $kept[ $filter ] ?? $default;
			$filters[ $filter ] = is_string( $mode ) && in_array( $mode, Suggester::MODES, true ) ? $mode : $default;
		}
		$out['filters'] = $filters;

		// Se calculan al guardar las direcciones (`update()`); lo que llegue de fuera se descarta allí.
		$out['ignored_ids'] = array_values( array_map( 'intval', (array) ( $input['ignored_ids'] ?? $base['ignored_ids'] ?? array() ) ) );

		return $out;
	}

	/**
	 * Lista de IDs enteros positivos, sin repetir.
	 *
	 * @param mixed $value Lista recibida.
	 * @param int   $limit Máximo de elementos.
	 *
	 * @return list<int>
	 */
	private static function clean_ids( $value, int $limit ): array {
		$ids = array();
		foreach ( is_array( $value ) ? $value : array() as $id ) {
			if ( ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) && (int) $id > 0 ) {
				$ids[ (int) $id ] = true;
			}
		}

		// Ordenados: el mismo conjunto marcado en otro orden no es un cambio (no relanza el análisis).
		$ids = array_keys( $ids );
		sort( $ids );

		return array_slice( $ids, 0, $limit );
	}

	/**
	 * Identificador de modelo: texto saneado de hasta 120 caracteres (los proveedores usan `@`, `+`, `:`, `/`…); vacío = automático.
	 *
	 * @param mixed $value Texto recibido.
	 */
	private static function clean_model( $value ): string {
		$model = is_string( $value ) ? sanitize_text_field( $value ) : '';

		// Un `:batch` (API por lotes, asíncrona) no sirve para el editor: la API REST lo rechaza con un mensaje; por
		// cualquier otra vía (WP-CLI, código) se vuelve a automático.
		return Models::allowed( $model ) ? mb_substr( $model, 0, 120, 'UTF-8' ) : '';
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
	 * Filtros por tipo, categoría y etiqueta con su modo (docs/04 §4.3): `ignore`, `consider` o `require`.
	 *
	 * @return array<string, string>
	 */
	public function filters(): array {
		return $this->all()['filters'];
	}

	/**
	 * Filtros en el modo indicado.
	 *
	 * @param string $mode `consider` o `require`.
	 *
	 * @return list<string>
	 */
	public function filters_in_mode( string $mode ): array {
		return array_keys( array_filter( $this->filters(), static fn( string $value ): bool => $value === $mode ) );
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
