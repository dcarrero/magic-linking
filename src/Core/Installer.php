<?php
/**
 * Instalador: tablas, migraciones, activación, desactivación y desinstalación.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Core;

use MagicLinking\Admin\Notices;
use MagicLinking\Index\LexicalIndexer;
use MagicLinking\Index\Exclusions;
use MagicLinking\Index\Pillars;
use MagicLinking\Index\TargetPhrases;
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
	 * Esquema que añadió las columnas `ai_*` de `changes` (F1-38).
	 */
	public const AI_COLUMNS_SCHEMA = 6;

	/**
	 * Acción de Action Scheduler que añade el índice por ancla a `links` en una actualización (esquema 7).
	 */
	public const HOOK_ANCHOR_INDEX = 'magiclinking_add_anchor_index';

	/**
	 * Transitorio (sin autoload) que frena los reintentos de una migración que falló.
	 */
	public const RETRY_TRANSIENT = 'magiclinking_schema_retry';

	/**
	 * Segundos que se espera antes de reintentar una migración fallida.
	 */
	public const RETRY_SECONDS = 300;

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
		add_action( self::HOOK_ANCHOR_INDEX, array( $this, 'add_anchor_index' ) );
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

		// Desde aquí cuentan los 30 días del aviso de reseña (docs/07 §5).
		Notices::remember_activation();

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

		// Una migración que acaba de fallar espera unos minutos antes de volver a intentarse.
		if ( $installed > 0 && false !== get_transient( self::RETRY_TRANSIENT ) ) {
			return false;
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
		// En un sitio con datos el índice por ancla de `links` no se crea en esta petición (en una tabla grande puede tardar
		// mucho): dbDelta no lo ve y se añade en segundo plano. Las consultas funcionan sin él mientras tanto.
		$with_index = 0 === $from || $this->has_anchor_index();
		$this->create_tables( $with_index );

		if ( array() !== $this->missing_tables() ) {
			return false;
		}

		// Paso a paso: cada versión que termina bien se guarda, así un reintento no repite migraciones ya hechas (la 3
		// vacía las huellas del índice léxico).
		for ( $version = $from + 1; $version <= $this->target_version; $version++ ) {
			if ( isset( $this->migrations[ $version ] ) ) {
				$this->migrations[ $version ]( $this->wpdb );
			}

			// El paso 6 solo vale si dbDelta consiguió las tres columnas: sin permiso ALTER o con el tiempo agotado se
			// queda en el 5 y se reintenta, pero con un respiro, no en cada carga del administrador.
			if ( self::AI_COLUMNS_SCHEMA === $version && ! Schema::has_columns( $this->wpdb, 'changes', Schema::AI_COLUMNS ) ) {
				set_transient( self::RETRY_TRANSIENT, 1, self::RETRY_SECONDS );

				return false;
			}

			update_option( self::DB_VERSION_OPTION, $version, false );
		}
		delete_transient( self::RETRY_TRANSIENT );

		// Una actualización (no una instalación nueva) puede dejar el índice léxico sin construir.
		if ( $from > 0 ) {
			self::request_reconcile();
			if ( ! $with_index ) {
				self::request_anchor_index();
			}
		}

		return true;
	}

	/**
	 * Crea las tablas que falten y añade columnas e índices nuevos.
	 *
	 * @param bool $anchor_index Incluir el índice por ancla de `links`.
	 *
	 * @return array<string, string> Cambios aplicados, según dbDelta(); vacío si no había nada que hacer.
	 */
	public function create_tables( bool $anchor_index = true ): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$statements = Schema::create_statements( $this->wpdb->prefix, $this->wpdb->get_charset_collate(), $anchor_index );

		return dbDelta( array_values( $statements ) );
	}

	/**
	 * Si la tabla de enlaces ya tiene el índice por ancla (esquema 7).
	 *
	 * @phpstan-impure
	 */
	public function has_anchor_index(): bool {
		$wpdb     = $this->wpdb;
		$table    = Schema::table( $wpdb->prefix, 'links' );
		$previous = $wpdb->suppress_errors( true );
		$found    = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'anchor' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Comprobación del esquema.
		$wpdb->suppress_errors( $previous );

		return null !== $found;
	}

	/**
	 * Pide en segundo plano (Action Scheduler) que se añada el índice por ancla. No bloquea la petición ni duplica la
	 * acción pendiente.
	 */
	public static function request_anchor_index(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! did_action( 'init' ) ) {
			return;
		}

		as_enqueue_async_action( self::HOOK_ANCHOR_INDEX, array(), self::ACTION_GROUP, true );
	}

	/**
	 * Acción de Action Scheduler: añade a `links` el índice por ancla. Idempotente (no hace nada si ya existe) y
	 * reanudable: si falla, se vuelve a intentar dentro de una hora.
	 */
	public function add_anchor_index(): void {
		if ( $this->has_anchor_index() ) {
			return;
		}

		$wpdb  = $this->wpdb;
		$table = esc_sql( Schema::table( $wpdb->prefix, 'links' ) );
		$done  = $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX anchor (anchor(40))" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Nombre de tabla desde constantes; no admite marcadores.

		if ( false === $done && ! $this->has_anchor_index() && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + HOUR_IN_SECONDS, self::HOOK_ANCHOR_INDEX, array(), self::ACTION_GROUP, true );
		}
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

		self::delete_scheduled_actions();

		// Metadatos de entradas (p. ej. sugerencias descartadas) las marcas propias de contenido pilar y de exclusión del análisis y las frases objetivo (la de Yoast y Rank Math no es nuestra).
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Limpieza única al desinstalar.
			$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_key IN (%s, %s, %s)", $like, Pillars::OWN_KEY, Exclusions::KEY, TargetPhrases::KEY )
		);
		wp_cache_flush_group( 'post_meta' );

		// Metadatos de usuario: solo las claves propias exactas de este sitio (el prefijo de sus tablas), con la
		// caché de WordPress al día. Nada de LIKE: las claves de Magic Linking Pro (`magiclinking_pro_*`) y las de
		// otros sitios de una red no se tocan. Con varios sitios, `uninstall_everywhere()` pasa por cada uno.
		foreach ( array( 'magiclinking_wizard_dismissed', 'magiclinking_ai_notice_dismissed', 'magiclinking_dismissed_notices', 'magiclinking_per_page' ) as $key ) {
			delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . $key, '', true );
		}

		return true;
	}

	/**
	 * Borra del todo las acciones de Action Scheduler del plugin (pendientes, hechas y fallidas) y su registro.
	 *
	 * Al desinstalar, el plugin ya no está cargado y Action Scheduler puede no estarlo (lo empaqueta el plugin),
	 * así que se borra por SQL en sus tablas, por el grupo; si no existen, no hace nada.
	 */
	private function delete_scheduled_actions(): void {
		$wpdb    = $this->wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Limpieza única al desinstalar, en tablas de Action Scheduler.
		if ( $actions !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions ) ) ) ) {
			return;
		}

		$group_id = $wpdb->get_var( $wpdb->prepare( 'SELECT group_id FROM %i WHERE slug = %s', $groups, self::ACTION_GROUP ) );
		if ( null === $group_id ) {
			return;
		}

		if ( $logs === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $logs ) ) ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE l FROM %i l INNER JOIN %i a ON a.action_id = l.action_id WHERE a.group_id = %d', $logs, $actions, (int) $group_id ) );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d', $actions, (int) $group_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d', $groups, (int) $group_id ) );
		// phpcs:enable
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
