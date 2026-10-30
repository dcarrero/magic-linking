<?php
/**
 * Reglas: aplicación al guardar, diferida, sin bucles y sin coste en el front-end.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Rules;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\History\Undo;
use MagicLinking\Rules\RuleRunner;
use MagicLinking\Rules\RuleValidator;

final class RuleRunnerTest extends RulesTestCase {

	private function scheduled( int $post_id ): bool {
		return as_has_scheduled_action( RuleRunner::HOOK, array( $post_id ), Installer::ACTION_GROUP );
	}

	private function run_job( int $post_id ): void {
		as_unschedule_all_actions( RuleRunner::HOOK, null, Installer::ACTION_GROUP );
		do_action( RuleRunner::HOOK, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Prefijo propio.
	}

	public function set_up(): void {
		parent::set_up();

		as_unschedule_all_actions( RuleRunner::HOOK, null, Installer::ACTION_GROUP );
	}

	private function links_in( int $post_id ): int {
		return substr_count( $this->content_of( $post_id ), '<a ' );
	}

	public function test_saving_a_published_entry_queues_the_rules_instead_of_writing(): void {
		$this->rule();
		$id = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ) ) );

		$this->assertTrue( $this->scheduled( $id ), 'Se encola en Action Scheduler.' );
		$this->assertSame( 0, $this->links_in( $id ), 'El guardado no escribe nada por su cuenta.' );
	}

	public function test_nothing_is_queued_without_active_rules_for_drafts_or_unchanged_content(): void {
		$id = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ) ) );
		$this->assertFalse( $this->scheduled( $id ), 'Sin reglas.' );

		$this->rule( array( 'active' => false ) );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Otro título',
			)
		);
		$this->assertFalse( $this->scheduled( $id ), 'Sin reglas activas.' );

		$this->rule( array( 'phrases' => array( 'bomba de calor' ) ) );
		$draft = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ),
			)
		);
		$this->assertFalse( $this->scheduled( $draft ), 'Un borrador no se enlaza.' );

		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Y otro título más',
			)
		);
		$this->assertFalse( $this->scheduled( $id ), 'Si el texto no cambia, no se reaplica.' );

		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'publish',
			)
		);
		$this->assertTrue( $this->scheduled( $draft ), 'Al publicar sí.' );
	}

	public function test_the_job_applies_the_rules_once_without_looping(): void {
		$rule = $this->rule();
		$id   = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ) ) );

		$this->run_job( $id );

		$this->assertSame( 1, $this->links_in( $id ) );
		$this->assertFalse( $this->scheduled( $id ), 'Lo que escribe la propia regla no vuelve a encolar nada.' );

		// Guardar otra vez la misma entrada tampoco duplica el enlace.
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $this->content_of( $id ) . $this->p( 'Más texto sobre la bomba de calor.' ),
			)
		);
		$this->run_job( $id );
		$this->assertSame( 1, $this->links_in( $id ) );
		$this->assertNotNull( $rule );
	}

	public function test_an_undone_rule_link_is_not_put_back_by_itself(): void {
		$this->rule();
		$id = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ) ) );
		$this->run_job( $id );
		$this->assertSame( 1, $this->links_in( $id ) );

		global $wpdb;
		$batch = (string) $wpdb->get_var( $wpdb->prepare( "SELECT batch_id FROM {$wpdb->prefix}magiclinking_changes WHERE post_id = %d", $id ) ); // phpcs:ignore WordPress.DB
		Plugin::container()->get( Undo::class )->revert_batch( $batch );
		$this->assertSame( 0, $this->links_in( $id ) );
		$this->assertFalse( $this->scheduled( $id ), 'Deshacer no dispara las reglas.' );

		// El usuario edita la entrada y vuelve a guardarla: la regla ya se aplicó (y se deshizo) aquí.
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $this->content_of( $id ) . $this->p( 'Una bomba de calor más.' ),
			)
		);
		$this->run_job( $id );
		$this->assertSame( 0, $this->links_in( $id ) );
	}

	public function test_editing_the_rule_makes_it_applicable_again(): void {
		$rule = $this->rule();
		$id   = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra y la aerotermia también.' ) ) ) );
		$this->run_job( $id );
		$this->assertSame( 1, $this->links_in( $id ) );

		$other = Plugin::container()->get( RuleValidator::class )->validate( array( 'phrases' => array( 'aerotermia' ) ), $rule, $this->admin );
		$this->repo()->update( $other );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $this->content_of( $id ) . $this->p( 'Algo nuevo.' ),
			)
		);
		$this->run_job( $id );

		// El destino ya está enlazado una vez (tope de un enlace por destino): no se añade otro.
		$this->assertSame( 1, $this->links_in( $id ) );
	}

	public function test_an_entry_open_in_the_editor_is_retried_later_and_never_written(): void {
		$this->rule();
		$id = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ) ) );
		update_post_meta( $id, '_edit_lock', time() . ':' . $this->admin );
		$before = $this->content_of( $id );

		$this->run_job( $id );

		$this->assertSame( $before, $this->content_of( $id ) );
		$this->assertTrue( $this->scheduled( $id ), 'Se reprograma unos minutos después.' );

		// Sin bloqueo, el siguiente intento escribe.
		delete_post_meta( $id, '_edit_lock' );
		$this->run_job( $id );
		$this->assertSame( 1, $this->links_in( $id ) );
		$this->assertFalse( $this->scheduled( $id ) );
	}

	public function test_it_keeps_retrying_for_a_day_with_growing_waits(): void {
		$this->rule();
		$id = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ) ) );
		update_post_meta( $id, '_edit_lock', time() . ':' . $this->admin );

		// Con el editor abierto desde hace 5 horas y 30 intentos, sigue esperando.
		update_post_meta(
			$id,
			RuleRunner::RETRY_META,
			array(
				'since'    => time() - 5 * HOUR_IN_SECONDS,
				'attempts' => 30,
			)
		);
		$this->run_job( $id );

		$this->assertTrue( $this->scheduled( $id ) );
		$this->assertSame( 0, $this->links_in( $id ) );
		$waits = array_map( static fn( int $n ): int => RuleRunner::wait_for( $n ), range( 0, 12 ) );
		$this->assertSame( RuleRunner::RETRY_SECONDS, $waits[0] );
		$this->assertGreaterThan( $waits[0], $waits[3] );
		$this->assertLessThanOrEqual( 2 * HOUR_IN_SECONDS, max( $waits ), 'La espera tiene techo.' );
	}

	public function test_after_a_day_it_gives_up_and_leaves_a_visible_record(): void {
		$this->rule();
		$id = self::factory()->post->create(
			array(
				'post_title'   => 'Entrada siempre abierta',
				'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ),
			)
		);
		update_post_meta( $id, '_edit_lock', time() . ':' . $this->admin );
		update_post_meta(
			$id,
			RuleRunner::RETRY_META,
			array(
				'since'    => time() - 25 * HOUR_IN_SECONDS,
				'attempts' => 14,
			)
		);

		$this->run_job( $id );

		$this->assertFalse( $this->scheduled( $id ) );
		$this->assertSame( array( $id ), array_column( \MagicLinking\Rules\RuleLog::all(), 'post_id' ), 'Queda constancia en la pantalla de reglas.' );

		// Cuando por fin se aplica, el registro se limpia.
		delete_post_meta( $id, '_edit_lock' );
		$this->run_job( $id );
		$this->assertSame( 1, $this->links_in( $id ) );
		$this->assertSame( array(), \MagicLinking\Rules\RuleLog::all() );
	}

	public function test_there_is_only_one_pending_action_per_entry_whatever_the_retry(): void {
		$this->rule();
		$id = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ) ) );
		update_post_meta( $id, '_edit_lock', time() . ':' . $this->admin );
		$count = static function ( int $post ): int {
			$n     = 0;
			$found = as_get_scheduled_actions(
				array(
					'hook'     => RuleRunner::HOOK,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 100,
				)
			);
			foreach ( $found as $action ) {
				$n += ( $action->get_args()[0] ?? 0 ) === $post ? 1 : 0;
			}
			return $n;
		};

		$this->run_job( $id );
		$this->assertSame( 1, $count( $id ) );

		// Un guardado con una espera ya pendiente, y otra ejecución a la fuerza: no se abre una segunda cadena.
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $this->content_of( $id ) . $this->p( 'Más texto.' ),
			)
		);
		do_action( RuleRunner::HOOK, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Prefijo propio.
		$this->assertSame( 1, $count( $id ) );
	}

	public function test_only_the_save_hook_is_added_and_nothing_touches_the_front_end_content(): void {
		global $wp_filter;
		$this->assertTrue( has_action( 'wp_after_insert_post' ) );
		foreach ( array( 'the_content', 'the_title', 'template_redirect', 'wp_head', 'wp_footer', 'wp_enqueue_scripts' ) as $hook ) {
			foreach ( $wp_filter[ $hook ]->callbacks ?? array() as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					$class    = is_array( $function ) && is_object( $function[0] ) ? get_class( $function[0] ) : '';
					$this->assertStringStartsNotWith( 'MagicLinking\\Rules', $class, "Las reglas no cuelgan de {$hook}." );
				}
			}
		}
	}

	public function test_a_rule_created_for_the_future_does_not_rewrite_existing_entries(): void {
		$id = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ) ) );
		$this->rule();

		$this->assertFalse( $this->scheduled( $id ) );
		$this->assertSame( 0, $this->links_in( $id ), 'Aplicar a las existentes es una acción del usuario, entrada a entrada.' );
	}
}
