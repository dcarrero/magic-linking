<?php
/**
 * Panel de enlaces internos dentro del editor de entradas (docs/07 §3).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Admin;

use MagicLinking\Core\Module;
use MagicLinking\Core\Settings;
use WP_Post;
use WP_Screen;

/**
 * Barra lateral de Gutenberg y caja del editor clásico.
 *
 * Los scripts y estilos se cargan solo en la pantalla de edición de un tipo de contenido analizado, para
 * quien puede editar esa entrada. En el front-end no se carga nada (regla 8). El panel no muestra avisos fuera
 * de sí mismo (regla 7).
 */
final class EditorPanel implements Module {

	/**
	 * Manejador del script de Gutenberg.
	 */
	public const HANDLE_BLOCK = 'magiclinking-editor';

	/**
	 * Manejador del script del editor clásico.
	 */
	public const HANDLE_CLASSIC = 'magiclinking-classic';

	/**
	 * Identificador de la caja del editor clásico.
	 */
	public const META_BOX = 'magiclinking-editor-panel';

	/**
	 * Sugerencias entrantes por página en el panel (docs/07 §3); el ajuste llega con F1-16.
	 */
	public const PER_PAGE = 10;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Engancha el panel.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_classic' ) );
	}

	/**
	 * Gutenberg: carga la barra lateral en la pantalla de una entrada.
	 */
	public function enqueue_block_editor(): void {
		$post = $this->current_post();
		if ( null === $post ) {
			return;
		}

		$this->enqueue( self::HANDLE_BLOCK, 'editor', $post, 'block' );
	}

	/**
	 * Editor clásico: añade la caja.
	 *
	 * @param string        $post_type Tipo de contenido.
	 * @param WP_Post|mixed $post      Entrada.
	 */
	public function add_meta_box( string $post_type, $post ): void {
		if ( ! $post instanceof WP_Post || ! $this->applies( $post ) || $this->is_block_editor() ) {
			return;
		}

		add_meta_box(
			self::META_BOX,
			__( 'Internal links', 'magic-linking' ),
			array( $this, 'render_meta_box' ),
			$post_type,
			'normal',
			'low'
		);
	}

	/**
	 * Contenido de la caja del editor clásico: el contenedor de la aplicación.
	 */
	public function render_meta_box(): void {
		echo '<div id="magiclinking-editor-root" class="magiclinking-editor-root">';
		echo '<p>' . esc_html__( 'Loading…', 'magic-linking' ) . '</p>';
		echo '<noscript><p>' . esc_html__( 'This box needs JavaScript to show the link suggestions.', 'magic-linking' ) . '</p></noscript>';
		echo '</div>';
	}

	/**
	 * Editor clásico: carga el script de la caja.
	 *
	 * @param string $hook_suffix Pantalla actual.
	 */
	public function enqueue_classic( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || $this->is_block_editor() ) {
			return;
		}

		$post = $this->current_post();
		if ( null === $post ) {
			return;
		}

		$this->enqueue( self::HANDLE_CLASSIC, 'classic', $post, 'classic' );
	}

	/**
	 * Una entrada de un tipo analizado que el usuario actual puede editar.
	 *
	 * @param WP_Post $post Entrada.
	 */
	public function applies( WP_Post $post ): bool {
		return in_array( $post->post_type, $this->settings->post_types(), true )
			&& current_user_can( 'edit_posts' )
			&& current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * La entrada que se edita en esta pantalla, si el panel corresponde.
	 */
	private function current_post(): ?WP_Post {
		$screen = get_current_screen();
		if ( ! $screen instanceof WP_Screen || 'post' !== $screen->base ) {
			return null;
		}

		$post = get_post();

		return $post instanceof WP_Post && $this->applies( $post ) ? $post : null;
	}

	/**
	 * La pantalla actual usa el editor de bloques.
	 */
	private function is_block_editor(): bool {
		$screen = get_current_screen();

		return $screen instanceof WP_Screen && $screen->is_block_editor();
	}

	/**
	 * Registra el script y el estilo de un editor y le pasa su configuración.
	 *
	 * @param string  $handle Manejador.
	 * @param string  $entry  Nombre de la entrada de webpack (`editor` o `classic`).
	 * @param WP_Post $post   Entrada que se edita.
	 * @param string  $mode   `block` o `classic`.
	 */
	private function enqueue( string $handle, string $entry, WP_Post $post, string $mode ): void {
		$asset_file = MAGICLINKING_DIR . "assets/build/{$entry}.asset.php";
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = $this->read_asset( $asset_file );

		wp_enqueue_script( $handle, MAGICLINKING_URL . "assets/build/{$entry}.js", $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( $handle, 'magic-linking', MAGICLINKING_DIR . 'languages' );

		if ( is_readable( MAGICLINKING_DIR . "assets/build/{$entry}.css" ) ) {
			wp_enqueue_style( $handle, MAGICLINKING_URL . "assets/build/{$entry}.css", array( 'wp-components' ), $asset['version'] );
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}

		wp_add_inline_script(
			$handle,
			'window.magiclinkingEditor = ' . wp_json_encode(
				array(
					'namespace'  => 'magic-linking/v1',
					'mode'       => $mode,
					'postId'     => $post->ID,
					'perPage'    => self::PER_PAGE,
					'historyUrl' => Screen::page_url( 'history' ),
					'reportUrl'  => Screen::page_url( 'report' ),
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
}
