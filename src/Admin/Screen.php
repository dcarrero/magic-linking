<?php
/**
 * Pantalla del plugin en el administrador.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Admin;

use MagicLinking\Core\Module;
use MagicLinking\Jobs\Jobs;
use MagicLinking\Report\ExportHandler;

/**
 * Menú propio «Magic Linking» con tres páginas (Informe, Enlaces rotos, Ajustes) que pintan la misma aplicación.
 *
 * Los scripts y estilos se cargan solo en esas pantallas. El plugin no muestra avisos en
 * ninguna otra pantalla del administrador.
 */
final class Screen implements Module {

	public const SLUG = 'magic-linking';

	public const SLUG_BROKEN   = 'magic-linking-broken';
	public const SLUG_SETTINGS = 'magic-linking-settings';

	/**
	 * Manejador del script.
	 */
	public const HANDLE = 'magiclinking-admin';

	/**
	 * Opción de usuario de «Opciones de pantalla»: elementos por página.
	 */
	public const PER_PAGE_OPTION = 'magiclinking_per_page';

	public const PER_PAGE_DEFAULT = 20;
	public const PER_PAGE_MAX     = 200;

	/**
	 * Procesos.
	 *
	 * @var Jobs
	 */
	private Jobs $jobs;

	/**
	 * Sufijos de las pantallas registradas, con la pestaña inicial de cada una.
	 *
	 * @var array<string, string>
	 */
	private array $screens = array();

	/**
	 * Constructor.
	 *
	 * @param Jobs $jobs Procesos.
	 */
	public function __construct( Jobs $jobs ) {
		$this->jobs = $jobs;
	}

	/**
	 * Engancha el menú.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy' ) );
		add_filter( 'set_screen_option_' . self::PER_PAGE_OPTION, array( $this, 'save_per_page' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( MAGICLINKING_FILE ), array( $this, 'add_action_link' ) );
	}

	/**
	 * Enlace «Abrir informe» en la fila del plugin.
	 *
	 * @param array<string|int, string> $links Enlaces de la fila.
	 * @return array<string|int, string>
	 */
	public function add_action_link( array $links ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::page_url( 'report' ) ),
				esc_html__( 'Open report', 'magic-linking' )
			)
		);

		return $links;
	}

	/**
	 * Dirección de la página de una pestaña.
	 *
	 * @param string $tab report, broken o settings.
	 */
	public static function page_url( string $tab ): string {
		$slugs = array(
			'report'   => self::SLUG,
			'broken'   => self::SLUG_BROKEN,
			'settings' => self::SLUG_SETTINGS,
		);

		return admin_url( 'admin.php?page=' . ( $slugs[ $tab ] ?? self::SLUG ) );
	}

	/**
	 * Las direcciones antiguas (Herramientas → Enlaces internos) redirigen al menú propio.
	 */
	public function redirect_legacy(): void {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Solo lectura de la dirección para redirigir.
		if ( 'tools.php' !== $pagenow || ! isset( $_GET['page'] ) || self::SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'report';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_safe_redirect( self::page_url( $tab ) );
		exit;
	}

	/**
	 * Añade el menú «Magic Linking» y sus tres páginas.
	 */
	public function add_menu(): void {
		$title = __( 'Magic Linking', 'magic-linking' );

		$report = add_menu_page( $title, $title, 'edit_posts', self::SLUG, array( $this, 'render' ), 'dashicons-admin-links', 58 );

		// «Informe» va primero y con el slug del menú: así WordPress no añade un submenú duplicado «Magic Linking».
		add_submenu_page( self::SLUG, __( 'Report', 'magic-linking' ), __( 'Report', 'magic-linking' ), 'edit_posts', self::SLUG, array( $this, 'render' ) );

		$items = array(
			array( 'report', $report ),
			array(
				'broken',
				add_submenu_page( self::SLUG, __( 'Broken links', 'magic-linking' ), __( 'Broken links', 'magic-linking' ), 'edit_posts', self::SLUG_BROKEN, array( $this, 'render' ) ),
			),
			array(
				'settings',
				add_submenu_page( self::SLUG, __( 'Settings', 'magic-linking' ), __( 'Settings', 'magic-linking' ), 'manage_options', self::SLUG_SETTINGS, array( $this, 'render' ) ),
			),
		);

		foreach ( $items as $item ) {
			if ( false === $item[1] ) {
				continue;
			}
			$this->screens[ $item[1] ] = $item[0];
			add_action( 'load-' . $item[1], array( $this, 'on_load' ) );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Valida el valor guardado desde «Opciones de pantalla».
	 *
	 * @param mixed  $keep   Valor previo del filtro (false para descartar).
	 * @param string $option Nombre de la opción.
	 * @param mixed  $value  Valor enviado.
	 * @return int|mixed
	 */
	public function save_per_page( $keep, string $option, $value ) {
		unset( $keep, $option );

		return max( 1, min( self::PER_PAGE_MAX, (int) $value ) );
	}

	/**
	 * Elementos por página del usuario actual.
	 */
	public static function per_page(): int {
		$value = (int) get_user_option( self::PER_PAGE_OPTION );

		return $value >= 1 ? min( self::PER_PAGE_MAX, $value ) : self::PER_PAGE_DEFAULT;
	}

	/**
	 * Al abrir la pantalla: se asegura de que el trabajo nocturno está programado.
	 */
	public function on_load(): void {
		$screen = get_current_screen();
		if ( $screen && 'settings' !== ( $this->screens[ $screen->id ] ?? '' ) ) {
			add_screen_option(
				'per_page',
				array(
					'label'   => __( 'Items per page', 'magic-linking' ),
					'default' => self::PER_PAGE_DEFAULT,
					'max'     => self::PER_PAGE_MAX,
					'option'  => self::PER_PAGE_OPTION,
				)
			);
		}

		if ( current_user_can( 'manage_options' ) && $this->jobs->status()['indexed'] > 0 ) {
			$this->jobs->ensure_schedules();
		}
	}

	/**
	 * Carga el script y el estilo solo en las pantallas del plugin.
	 *
	 * @param string $hook_suffix Pantalla actual.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! isset( $this->screens[ $hook_suffix ] ) ) {
			return;
		}

		$asset_file = MAGICLINKING_DIR . 'assets/build/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = $this->read_asset( $asset_file );

		wp_enqueue_script( self::HANDLE, MAGICLINKING_URL . 'assets/build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'magic-linking', MAGICLINKING_DIR . 'languages' );

		if ( is_readable( MAGICLINKING_DIR . 'assets/build/style-index.css' ) ) {
			wp_enqueue_style( self::HANDLE, MAGICLINKING_URL . 'assets/build/style-index.css', array( 'wp-components' ), $asset['version'] );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}

		wp_add_inline_script(
			self::HANDLE,
			'window.magiclinking = ' . wp_json_encode(
				array(
					'namespace'   => 'magic-linking/v1',
					'exportUrl'   => ExportHandler::url(),
					'canManage'   => current_user_can( 'manage_options' ),
					'perPage'     => self::per_page(),
					'settingsUrl' => self::page_url( 'settings' ),
					'initialTab'  => $this->screens[ $hook_suffix ],
					'tabUrls'     => array(
						'report'   => self::page_url( 'report' ),
						'broken'   => self::page_url( 'broken' ),
						'settings' => self::page_url( 'settings' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Lee el fichero de dependencias y versión que genera @wordpress/scripts.
	 *
	 * @param string $file Ruta del fichero .asset.php.
	 *
	 * @return array{dependencies: array<int, string>, version: string}
	 */
	private function read_asset( string $file ): array {
		return require $file;
	}

	/**
	 * Pinta el contenedor de la aplicación.
	 */
	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to see this page.', 'magic-linking' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap magiclinking-wrap">';
		echo '<div id="magiclinking-root">';
		echo '<h1>' . esc_html__( 'Internal links', 'magic-linking' ) . '</h1>';
		echo '<p>' . esc_html__( 'Loading…', 'magic-linking' ) . '</p>';
		echo '<noscript><p>' . esc_html__( 'This screen needs JavaScript. You can get the same report with WP-CLI: wp magic-linking report', 'magic-linking' ) . '</p></noscript>';
		echo '</div></div>';
	}
}
