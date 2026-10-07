<?php
/**
 * Instalador: tablas, migraciones, activación, desactivación y desinstalación.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Core;

use MagicLinking\Index\LexicalIndexer;
use MagicLinking\Jobs\Jobs;
use wpdb;

/**
 * Crea y actualiza las tablas propias.
 *
 * La versión instalada del esquema vive en la opción magiclinking_db_version,
 * sin autoload. Solo se consulta en el administrador (admin_init) y al
 * activar, nunca en el front-end. Si la versión instalada es menor que la del
 * código, se aplica el esquema actual con dbDelta() y después las migraciones
 * de datos de cada versión intermedia, en orden.
 */
final class Installer implements Module {

	/**
	 * Opción con la versión del esquema instalada (sin autoload).
	 */
	public const DB_VERSION_OPTION = 'magiclinking_db_version';

	/**
	 * Opción de ajustes (la única en autoload). Se lee aquí solo para saber si
	 * hay que borrar los datos al desinstalar.
	 */
	public const SETTINGS_OPTION = 'magiclinking_settings';

	/**
	 * Clave del ajuste «borrar datos al desinstalar» (por defecto, no).
	 */
	public const DELETE_DATA_SETTING = 'delete_data_on_uninstall';

	/**
	 * Grupo de Action Scheduler del plugin.
	 */
	public const ACTION_GROUP = 'magic-linking';

	/**
	 * Conexión a la base de datos.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Versión del esquema que espera el código.
	 *
	 * @var int
	 */
	private int $target_version;

	/**
	 * Migraciones de datos por versión de destino.
	 *
	 * @var array<int, callable(wpdb): void>
	 */
	private array $migrations;

	/**
	 * Constructor.
	 *
	 * @param wpdb                                  $wpdb           Conexión.
	 * @param int                                   $target_version Versión del esquema del código (MAGICLINKING_DB_VERSION).
	 * @param array<int, callable(wpdb): void>|null $migrations     Migraciones; null para las del plugin.
	 */
	public function __construct( wpdb $wpdb, int $target_version, ?array $migrations = null ) {
		$this->wpdb           = $wpdb;
		$this->target_version = $target_version;
		$this->migrations     = $migrations ?? self::migrations();
		ksort( $this->migrations );
	}

	/**
	 * Si ya se ha comprobado el esquema en esta petición.
	 *
	 * @var bool
	 */
	private static bool $verified = false;

	/**
	 * Se asegura de que el esquema es el del código antes de usar las tablas del índice.
	 *
	 * La actualización automática de un plugin no pasa por la activación ni por `admin_init`: el cron o
	 * Action Scheduler pueden ejecutar el código nuevo contra el esquema anterior. Quien escribe o lee el
	 * índice llama aquí (una vez por petición; el front-end nunca lo hace).
	 */
	public static function ensure_current(): void {
		if ( self::$verified || ! defined( 'MAGICLINKING_DB_VERSION' ) ) {
			return;
		}
		self::$verified = true;

		global $wpdb;
		( new self( $wpdb, (int) MAGICLINKING_DB_VERSION ) )->maybe_upgrade();
	}

	/**
	 * Olvida la comprobación de esquema (para las pruebas).
	 */
	public static function forget_check(): void {
		self::$verified = false;
	}

	/**
	 * Comprueba el esquema al entrar en el administrador: cubre las
	 * actualizaciones del plugin (que no disparan la activación) y los sitios
	 * de una red en los que se activó el plugin para toda la red.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'on_admin_init' ) );
	}

	/**
	 * Callback de admin_init.
	 */
	public function on_admin_init(): void {
		$this->maybe_upgrade();
	}

	/**
	 * Gancho de activación.
	 *
	 * En una activación para toda la red solo se instala el sitio actual; los
	 * demás se instalan al entrar en su administrador (register()), para no
	 * recorrer miles de sitios en una sola petición.
	 */
	public static function activate(): void {
		global $wpdb;

		( new self( $wpdb, MAGICLINKING_DB_VERSION ) )->maybe_upgrade();

		// La desactivación cancela lo programado: se vuelve a programar el recálculo nocturno.
		self::request_reconcile();
	}

	/**
	 * Pide en segundo plano (Action Scheduler) que se ponga al día lo programado: recálculo nocturno e índice
	 * léxico (véase Jobs::reconcile()). No bloquea la petición ni duplica la acción pendiente.
	 */
	public static function request_reconcile(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! did_action( 'init' ) ) {
			return;
		}

		as_enqueue_async_action( Jobs::HOOK_RECONCILE, array(), self::ACTION_GROUP, true );
	}

	/**
	 * Gancho de desactivación: cancela los procesos pendientes del plugin.
	 * No borra datos; la reactivación encuentra las tablas tal como estaban.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::ACTION_GROUP );
		}
	}

	/**
	 * Versión del esquema instalada; 0 si no hay ninguna.
	 */
	public function installed_version(): int {
		return (int) get_option( self::DB_VERSION_OPTION, 0 );
	}

	/**
	 * Instala o actualiza si la versión instalada es anterior a la del código.
	 * Si es posterior (se volvió a una versión antigua del plugin), no toca nada.
	 *
	 * @return bool Si el esquema queda en la versión del código o posterior.
	 */
	public function maybe_upgrade(): bool {
		$installed = $this->installed_version();

		if ( $installed >= $this->target_version ) {
			return true;
		}

		return $this->upgrade( $installed );
	}

	/**
	 * Aplica el esquema actual y las migraciones posteriores a $from.
	 *
	 * La versión solo se guarda si todas las tablas existen al terminar; si
	 * algo falla, se reintenta en la siguiente visita al administrador.
	 *
	 * @param int $from Versión instalada.
	 */
	public function upgrade( int $from ): bool {
		$this->create_tables();

		if ( array() !== $this->missing_tables() ) {
			return false;
		}

		foreach ( $this->migrations as $version => $migration ) {
			if ( $version > $from && $version <= $this->target_version ) {
				$migration( $this->wpdb );
			}
		}

		update_option( self::DB_VERSION_OPTION, $this->target_version, false );

		// Una actualización (no una instalación nueva) puede dejar el índice léxico sin construir.
		if ( $from > 0 ) {
			self::request_reconcile();
		}

		return true;
	}

	/**
	 * Crea las tablas que falten y añade columnas e índices nuevos.
	 *
	 * @return array<string, string> Cambios aplicados, según dbDelta(); vacío si no había nada que hacer.
	 */
	public function create_tables(): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$statements = Schema::create_statements( $this->wpdb->prefix, $this->wpdb->get_charset_collate() );

		return dbDelta( array_values( $statements ) );
	}

	/**
	 * Tablas del esquema que no existen.
	 *
	 * @return list<string> Nombres completos.
	 */
	public function missing_tables(): array {
		$wpdb    = $this->wpdb;
		$missing = array();

		foreach ( Schema::TABLES as $table ) {
			$name  = Schema::table( $wpdb->prefix, $table );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Comprobación del esquema, no hay nada que cachear.

			if ( $found !== $name ) {
				$missing[] = $name;
			}
		}

		return $missing;
	}

	/**
	 * Borra los datos del sitio actual si el usuario lo pidió en los ajustes.
	 * Los enlaces ya insertados se quedan en el contenido.
	 *
	 * @return bool Si se borraron los datos.
	 */
	public function uninstall(): bool {
		$settings = get_option( self::SETTINGS_OPTION );

		if ( ! is_array( $settings ) || empty( $settings[ self::DELETE_DATA_SETTING ] ) ) {
			return false;
		}

		$wpdb = $this->wpdb;

		foreach ( Schema::TABLES as $table ) {
			$name = esc_sql( Schema::table( $wpdb->prefix, $table ) );
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Nombre de tabla desde constantes; no admite marcadores.
		}

		$like = $wpdb->esc_like( Schema::PREFIX ) . '%';

		// Opciones y transitorios propios. Se borra también de la caché de objetos.
		$options = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Limpieza única al desinstalar.
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$like,
				$wpdb->esc_like( '_transient_' . Schema::PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . Schema::PREFIX ) . '%'
			)
		);
		foreach ( $options as $option ) {
			delete_option( $option );
		}

		// Metadatos de entradas (p. ej. sugerencias descartadas).
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Limpieza única al desinstalar.
			$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like )
		);
		wp_cache_flush_group( 'post_meta' );

		return true;
	}

	/**
	 * Desinstala en el sitio actual o, en una red, en cada sitio según sus
	 * propios ajustes. Lo llama uninstall.php.
	 */
	public static function uninstall_everywhere(): void {
		global $wpdb;

		self::deactivate();

		if ( ! is_multisite() ) {
			( new self( $wpdb, 0 ) )->uninstall();
			return;
		}

		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			( new self( $wpdb, 0 ) )->uninstall();
			restore_current_blog();
		}
	}

	/**
	 * Migraciones de datos del plugin, por versión de destino.
	 *
	 * La versión 1 es el esquema inicial: no necesita migración. La 2 añade a `docs` las columnas
	 * `lex_hash` y `lex_at` (huella y fecha del índice léxico); dbDelta() las crea y las filas
	 * existentes (huella vacía) se reindexan solas. La 3 añade a `postings` la columna `pos` (puesto de cada
	 * término en su entrada); las filas que ya hubiera no la tienen, así que el índice léxico se marca como
	 * construcción a medias y la siguiente pasada lo rehace entero (D-44). Una migración
	 * corre después de dbDelta(), así que ya ve las columnas nuevas; para
	 * renombrar una columna, dbDelta() añade la nueva y la migración copia los
	 * datos y borra la antigua.
	 *
	 * @return array<int, callable(wpdb): void>
	 */
	private static function migrations(): array {
		return array(
			3 => static function ( wpdb $wpdb ): void {
				$postings = Schema::table( $wpdb->prefix, 'postings' );
				$docs     = Schema::table( $wpdb->prefix, 'docs' );

				if ( null === $wpdb->get_var( $wpdb->prepare( 'SELECT term_id FROM %i LIMIT 1', $postings ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia; no hay nada que cachear.
					return;
				}

				$wpdb->query( $wpdb->prepare( "UPDATE %i SET lex_hash = '', lex_at = NULL", $docs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.

				// Si ya hay una construcción en curso se conserva su ID: solo ese proceso puede darla por terminada.
				if ( false === get_option( LexicalIndexer::BUILDING_OPTION, false ) ) {
					update_option( LexicalIndexer::BUILDING_OPTION, 0, false );
				}
			},
		);
	}
}
