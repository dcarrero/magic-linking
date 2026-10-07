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
	 * Ajustes y valores por defecto.
	 */
	public const DEFAULTS = array(
		'post_types'                   => array( 'post', 'page' ),
		'low_inbound_threshold'        => 2,
		'words_per_link'               => 100,
		self::RETENTION_SETTING        => 90,
		Installer::DELETE_DATA_SETTING => false,
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
	 * Todos los ajustes, con los valores por defecto donde falten.
	 *
	 * @return array{post_types: list<string>, low_inbound_threshold: int, words_per_link: int, history_retention_days: int, delete_data_on_uninstall: bool}
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
	 * Guarda los ajustes que lleguen, saneados; los demás se conservan.
	 *
	 * @param array<string, mixed> $input Ajustes nuevos (parciales).
	 *
	 * @return array{post_types: list<string>, low_inbound_threshold: int, words_per_link: int, history_retention_days: int, delete_data_on_uninstall: bool}
	 */
	public function update( array $input ): array {
		$settings = $this->sanitize( $input, $this->all() );

		update_option( Installer::SETTINGS_OPTION, $settings, true );

		return $settings;
	}

	/**
	 * Sanea los ajustes recibidos sobre una base ya válida.
	 *
	 * @param array<mixed, mixed>  $input Ajustes recibidos.
	 * @param array<string, mixed> $base  Valores de partida.
	 *
	 * @return array{post_types: list<string>, low_inbound_threshold: int, words_per_link: int, history_retention_days: int, delete_data_on_uninstall: bool}
	 */
	private function sanitize( array $input, array $base ): array {
		$post_types = $base['post_types'] ?? self::DEFAULTS['post_types'];
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$available  = array_keys( self::available_post_types() );
			$post_types = array_values( array_intersect( array_map( 'strval', $input['post_types'] ), $available ) );
		}

		$threshold = (int) ( $input['low_inbound_threshold'] ?? $base['low_inbound_threshold'] ?? self::DEFAULTS['low_inbound_threshold'] );
		$words     = (int) ( $input['words_per_link'] ?? $base['words_per_link'] ?? self::DEFAULTS['words_per_link'] );
		$keep      = $input[ self::RETENTION_SETTING ] ?? $base[ self::RETENTION_SETTING ] ?? self::DEFAULTS[ self::RETENTION_SETTING ];
		$keep      = is_numeric( $keep ) && in_array( (int) $keep, self::RETENTION_CHOICES, true ) ? (int) $keep : (int) ( $base[ self::RETENTION_SETTING ] ?? self::DEFAULTS[ self::RETENTION_SETTING ] );
		$delete    = $input[ Installer::DELETE_DATA_SETTING ] ?? $base[ Installer::DELETE_DATA_SETTING ] ?? false;

		return array(
			'post_types'                   => array_values( array_map( 'strval', (array) $post_types ) ),
			'low_inbound_threshold'        => max( 1, min( 20, $threshold ) ),
			'words_per_link'               => max( 20, min( 1000, $words ) ),
			self::RETENTION_SETTING        => $keep,
			Installer::DELETE_DATA_SETTING => filter_var( $delete, FILTER_VALIDATE_BOOLEAN ),
		);
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
