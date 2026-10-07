<?php
/**
 * Panel del editor (F1-11): dónde se cargan los scripts y dónde se añade la caja del editor clásico.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Admin\EditorPanel;
use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use WP_Post;
use WP_UnitTestCase;

final class EditorPanelTest extends WP_UnitTestCase {

	/**
	 * Ficheros de dependencias creados por la prueba (en el CI de integración no se ha compilado nada).
	 *
	 * @var list<string>
	 */
	private array $created = array();

	private EditorPanel $panel;

	public function set_up(): void {
		parent::set_up();

		foreach ( array( 'editor', 'classic' ) as $entry ) {
			$file = MAGICLINKING_DIR . "assets/build/{$entry}.asset.php";
			if ( ! is_readable( $file ) ) {
				wp_mkdir_p( dirname( $file ) );
				file_put_contents( $file, "<?php return array( 'dependencies' => array(), 'version' => 'test' );\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$this->created[] = $file;
			}
		}

		$this->panel = Plugin::container()->get( EditorPanel::class );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	public function tear_down(): void {
		foreach ( $this->created as $file ) {
			wp_delete_file( $file );
		}
		wp_dequeue_script( EditorPanel::HANDLE_BLOCK );
		wp_dequeue_script( EditorPanel::HANDLE_CLASSIC );
		unset( $GLOBALS['post'], $GLOBALS['wp_meta_boxes'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Simula la pantalla de edición de una entrada.
	 *
	 * @param WP_Post $post         Entrada.
	 * @param bool    $block_editor Editor de bloques o clásico.
	 */
	private function edit_screen( WP_Post $post, bool $block_editor ): void {
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simula la pantalla de edición.
		set_current_screen( 'post' );
		get_current_screen()->is_block_editor( $block_editor );
	}

	public function test_loads_script_for_an_analyzed_type_with_boot_data(): void {
		$post = self::factory()->post->create_and_get();
		$this->edit_screen( $post, true );

		$this->panel->enqueue_block_editor();

		$this->assertTrue( wp_script_is( EditorPanel::HANDLE_BLOCK, 'enqueued' ) );
		$inline = (string) wp_scripts()->get_data( EditorPanel::HANDLE_BLOCK, 'before' )[1];
		$this->assertStringContainsString( '"postId":' . $post->ID, $inline );
		$this->assertStringContainsString( '"mode":"block"', $inline );
	}

	public function test_does_not_load_for_a_type_that_is_not_analyzed(): void {
		$settings               = (array) get_option( Installer::SETTINGS_OPTION, array() );
		$settings['post_types'] = array( 'page' );
		update_option( Installer::SETTINGS_OPTION, $settings );

		$post = self::factory()->post->create_and_get( array( 'post_type' => 'post' ) );
		$this->edit_screen( $post, true );
		$this->panel->enqueue_block_editor();

		$this->assertFalse( wp_script_is( EditorPanel::HANDLE_BLOCK, 'enqueued' ) );
	}

	public function test_does_not_load_for_someone_who_cannot_edit_that_entry(): void {
		$post = self::factory()->post->create_and_get( array( 'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->edit_screen( $post, true );

		$this->panel->enqueue_block_editor();

		$this->assertFalse( wp_script_is( EditorPanel::HANDLE_BLOCK, 'enqueued' ) );
	}

	public function test_does_not_load_outside_the_post_screen_or_on_the_front_end(): void {
		$post            = self::factory()->post->create_and_get();
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simula la pantalla de edición.

		set_current_screen( 'dashboard' );
		$this->panel->enqueue_block_editor();
		$this->assertFalse( wp_script_is( EditorPanel::HANDLE_BLOCK, 'enqueued' ) );

		set_current_screen( 'front' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_script_is( EditorPanel::HANDLE_BLOCK, 'enqueued' ) );
		$this->assertFalse( wp_script_is( EditorPanel::HANDLE_CLASSIC, 'enqueued' ) );
	}

	public function test_classic_editor_gets_the_box_and_its_own_script(): void {
		$post = self::factory()->post->create_and_get();
		$this->edit_screen( $post, false );

		do_action( 'add_meta_boxes', 'post', $post );
		$this->assertArrayHasKey( EditorPanel::META_BOX, $GLOBALS['wp_meta_boxes']['post']['normal']['low'] );

		$this->panel->enqueue_classic( 'post.php' );
		$this->assertTrue( wp_script_is( EditorPanel::HANDLE_CLASSIC, 'enqueued' ) );
		$this->assertFalse( wp_script_is( EditorPanel::HANDLE_BLOCK, 'enqueued' ) );

		ob_start();
		$this->panel->render_meta_box();
		$this->assertStringContainsString( 'id="magiclinking-editor-root"', (string) ob_get_clean() );
	}

	public function test_block_editor_does_not_get_the_classic_box(): void {
		$post = self::factory()->post->create_and_get();
		$this->edit_screen( $post, true );

		do_action( 'add_meta_boxes', 'post', $post );
		$this->panel->enqueue_classic( 'post.php' );

		$this->assertArrayNotHasKey( 'post', (array) ( $GLOBALS['wp_meta_boxes'] ?? array() ) );
		$this->assertFalse( wp_script_is( EditorPanel::HANDLE_CLASSIC, 'enqueued' ) );
	}

	public function test_applies_only_to_analyzed_types_the_user_can_edit(): void {
		$own = self::factory()->post->create_and_get( array( 'post_author' => get_current_user_id() ) );
		$this->assertTrue( $this->panel->applies( $own ) );

		$other = self::factory()->post->create_and_get( array( 'post_type' => 'nav_menu_item' ) );
		$this->assertFalse( $this->panel->applies( $other ) );
	}
}
