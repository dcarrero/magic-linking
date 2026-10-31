<?php
/**
 * Avisos (F1-17, docs/07 §5): cierre por usuario, aviso de reseña y que nada salga fuera de las pantallas del plugin.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Admin\Notices;
use MagicLinking\History\ChangeRepository;
use WP_REST_Request;
use WP_REST_Response;

final class NoticesTest extends GraphTestCase {

	private int $admin = 0;

	private int $author = 0;

	private int $subscriber = 0;

	public function set_up(): void {
		parent::set_up();

		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->author     = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		delete_option( Notices::ACTIVATED_OPTION );
		wp_cache_flush();
	}

	private function notices(): Notices {
		global $wpdb;

		return new Notices( $wpdb );
	}

	private function request( string $method, string $route, array $params = array(), ?int $user = null ): WP_REST_Response {
		wp_set_current_user( $user ?? $this->admin );
		$request = new WP_REST_Request( $method, '/magic-linking/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function insert_links( int $user, int $count, string $action = ChangeRepository::INSERT, bool $undone = false ): void {
		global $wpdb;

		for ( $i = 0; $i < $count; ++$i ) {
			$wpdb->insert(
				$wpdb->prefix . 'magiclinking_changes',
				array(
					'batch_id'    => 'B',
					'post_id'     => 1,
					'action'      => $action,
					'before_html' => '',
					'after_html'  => '',
					'user_id'     => $user,
					'created_at'  => gmdate( 'Y-m-d H:i:s' ),
					'undone_at'   => $undone ? gmdate( 'Y-m-d H:i:s' ) : null,
				)
			);
		}
		wp_cache_flush();
	}

	private function activated_days_ago( int $days ): void {
		update_option( Notices::ACTIVATED_OPTION, time() - $days * DAY_IN_SECONDS, false );
	}

	public function test_no_notice_when_nothing_applies(): void {
		$this->assertNull( $this->notices()->current( $this->admin ) );
	}

	public function test_the_activation_date_is_saved_once_without_autoload(): void {
		global $wpdb;

		$this->notices()->current( $this->admin );
		$first = (int) get_option( Notices::ACTIVATED_OPTION );
		$this->assertGreaterThan( 0, $first );
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Notices::ACTIVATED_OPTION ) ), array( 'no', 'off', 'auto-off' ) );

		$this->activated_days_ago( 40 );
		$stored = (int) get_option( Notices::ACTIVATED_OPTION );
		$this->notices()->current( $this->admin );
		$this->assertSame( $stored, (int) get_option( Notices::ACTIVATED_OPTION ), 'No se pisa una fecha ya guardada.' );
	}

	public function test_review_needs_twenty_links_and_thirty_days(): void {
		$this->activated_days_ago( 40 );
		$this->insert_links( $this->admin, 19 );
		$this->assertNull( $this->notices()->current( $this->admin ), '19 enlaces no bastan.' );

		$this->insert_links( $this->admin, 1 );
		$notice = $this->notices()->current( $this->admin );
		$this->assertSame( 'review', $notice['id'] ?? null );
		$this->assertSame( 'info', $notice['type'] );
		$this->assertStringContainsString( 'wordpress.org/support/plugin/magic-linking/reviews', $notice['action']['url'] );
		$this->assertNotSame( '', $notice['dismiss_label'] );
	}

	public function test_review_waits_thirty_days_from_activation(): void {
		$this->insert_links( $this->admin, 25 );
		$this->activated_days_ago( 29 );
		$this->assertNull( $this->notices()->current( $this->admin ) );

		$this->activated_days_ago( 30 );
		$this->assertSame( 'review', $this->notices()->current( $this->admin )['id'] ?? null );
	}

	public function test_only_active_links_of_this_user_count(): void {
		$this->activated_days_ago( 40 );
		$this->insert_links( $this->admin, 12 );
		$this->insert_links( $this->admin, 10, ChangeRepository::INSERT, true );
		$this->insert_links( $this->admin, 10, ChangeRepository::UNLINK );
		$this->insert_links( $this->author, 30 );
		$this->assertNull( $this->notices()->current( $this->admin ), 'Ni deshechos, ni otras acciones, ni los de otro usuario.' );
	}

	public function test_dismissing_stores_one_meta_per_user_with_all_ids(): void {
		global $wpdb;

		$this->assertSame( 200, $this->request( 'POST', '/notices/dismiss', array( 'id' => 'review' ) )->get_status() );
		$this->assertSame( 200, $this->request( 'POST', '/notices/dismiss', array( 'id' => 'review' ) )->get_status(), 'Es idempotente.' );
		$this->assertSame( array( 'review' ), get_user_meta( $this->admin, Notices::meta_key(), true ) );

		$other = array(
			array(
				'id'       => 'other',
				'priority' => 5,
				'type'     => 'info',
				'applies'  => true,
			),
		);
		$both  = new Notices(
			$wpdb,
			array_merge(
				$other,
				array(
					array(
						'id'       => 'review',
						'priority' => 9,
						'type'     => 'info',
						'applies'  => true,
					),
				)
			)
		);
		$this->assertTrue( $both->dismiss( $this->admin, 'other' ) );
		$this->assertSame( array( 'review', 'other' ), get_user_meta( $this->admin, Notices::meta_key(), true ) );
		$this->assertNull( $both->current( $this->admin ), 'Con los dos cerrados no queda ninguno.' );
		$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $this->admin, Notices::meta_key() ) );
		$this->assertSame( 1, $rows );
		$this->assertSame( '', get_user_meta( $this->author, Notices::meta_key(), true ), 'Cada usuario cierra los suyos.' );
	}

	public function test_a_dismissed_review_never_comes_back(): void {
		$this->activated_days_ago( 40 );
		$this->insert_links( $this->admin, 30 );
		$this->request( 'POST', '/notices/dismiss', array( 'id' => 'review' ) );
		$this->assertNull( $this->notices()->current( $this->admin ) );

		$this->activated_days_ago( 400 );
		$this->insert_links( $this->admin, 100 );
		$this->assertNull( $this->notices()->current( $this->admin ) );
	}

	public function test_dismiss_validates_permissions_and_ids(): void {
		$this->assertSame( 400, $this->request( 'POST', '/notices/dismiss', array( 'id' => 'does-not-exist' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/notices/dismiss', array() )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/notices/dismiss', array( 'id' => 'review' ), $this->subscriber )->get_status() );
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/magic-linking/v1/notices/dismiss' );
		$request->set_param( 'id', 'review' );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( '', get_user_meta( $this->subscriber, Notices::meta_key(), true ) );
	}

	public function test_an_author_can_dismiss_the_notices_of_the_screens_they_see(): void {
		$this->assertSame( 200, $this->request( 'POST', '/notices/dismiss', array( 'id' => 'review' ), $this->author )->get_status() );
	}

	public function test_the_meta_key_carries_the_blog_prefix(): void {
		global $wpdb;

		$this->assertSame( $wpdb->get_blog_prefix() . 'magiclinking_dismissed_notices', Notices::meta_key() );
	}

	public function test_nothing_is_hooked_outside_the_plugin_screens(): void {
		foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $hook ) {
			foreach ( ( isset( $GLOBALS['wp_filter'][ $hook ] ) ? $GLOBALS['wp_filter'][ $hook ]->callbacks : array() ) as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];
					$this->assertFalse( is_array( $fn ) && is_object( $fn[0] ) && str_starts_with( get_class( $fn[0] ), 'MagicLinking\\' ), "El plugin no engancha avisos en {$hook}." );
				}
			}
		}
	}
}
