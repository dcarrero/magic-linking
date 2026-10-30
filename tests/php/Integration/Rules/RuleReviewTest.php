<?php
/**
 * Hallazgos de la revisión de código de F1-13 (cada prueba falló antes de su arreglo).
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Rules;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Settings;
use MagicLinking\History\ChangeRepository;
use MagicLinking\History\Undo;
use MagicLinking\Rules\Rule;
use MagicLinking\Rules\RuleRunner;
use MagicLinking\Rules\RuleService;
use MagicLinking\Rules\RuleValidator;

final class RuleReviewTest extends RulesTestCase {

	private function links_in( int $post_id ): int {
		return substr_count( $this->content_of( $post_id ), '<a ' );
	}

	private function run_job( int $post_id ): void {
		as_unschedule_all_actions( RuleRunner::HOOK, null, Installer::ACTION_GROUP );
		do_action( RuleRunner::HOOK, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Prefijo propio.
	}

	/**
	 * Hallazgo 1: pausar no exige que el destino siga publicado.
	 */
	public function test_pausing_a_rule_does_not_revalidate_a_target_that_is_gone(): void {
		$rule = $this->rule();
		wp_update_post(
			array(
				'ID'          => $this->target,
				'post_status' => 'draft',
			)
		);
		$validator = Plugin::container()->get( RuleValidator::class );

		$paused = $validator->validate( array( 'active' => false ), $rule, $this->admin );
		$this->assertInstanceOf( Rule::class, $paused );
		$this->assertFalse( $paused->active );
		$this->assertSame( $rule->lang, $paused->lang );
	}

	/**
	 * Hallazgo 2: las URL ignoradas valen también para las reglas.
	 */
	public function test_ignored_urls_and_words_are_respected_by_rules(): void {
		$rule     = $this->rule();
		$ignored  = $this->entry( $this->body( array( 'Una bomba de calor ahorra.' ) ) );
		$fine     = $this->entry( $this->body( array( 'Una bomba de calor ahorra.' ) ) );
		$settings = Plugin::container()->get( Settings::class );
		$settings->update( array( 'ignored_urls' => array( (string) get_permalink( $ignored ) ) ) );

		$this->assertSame( RuleService::IGNORED, $this->service()->apply( $ignored, $rule, $this->admin )->skipped );
		$this->assertSame( $ignored->post_content, $this->content_of( $ignored->ID ) );
		$this->assertSame( 1, $this->service()->apply( $fine, $rule, $this->admin )->inserted );

		$ids = array_column( $this->service()->scan( $rule, $this->admin, 0, 50, 5.0 )['items'], 'post_id' );
		$this->assertNotContains( $ignored->ID, $ids, 'La búsqueda no ofrece entradas ignoradas.' );

		$other = $this->entry( $this->body( array( 'Otra bomba de calor ahorra.' ) ) );
		$settings->update( array( 'ignored_words' => array( 'bomba de calor' ) ) );
		$this->assertSame( 0, $this->service()->apply( $other, $rule, $this->admin )->inserted, 'Un ancla con palabras ignoradas no se enlaza.' );

		$settings->update(
			array(
				'ignored_urls'  => array( (string) get_permalink( $this->target ) ),
				'ignored_words' => array(),
			)
		);
		$validator = Plugin::container()->get( RuleValidator::class );
		$this->assertWPError(
			$validator->validate(
				array(
					'phrases'   => array( 'bomba de calor' ),
					'target_id' => $this->target,
				),
				null,
				$this->admin
			),
			'Un destino ignorado no sirve.'
		);
		$this->assertSame( RuleService::IGNORED, $this->service()->apply( $this->entry( $this->body( array( 'Una bomba de calor ahorra.' ) ) ), $rule, $this->admin )->skipped );
	}

	/**
	 * Hallazgo 3: editar la regla no devuelve los enlaces que el usuario deshizo.
	 */
	public function test_editing_a_rule_keeps_the_undone_protection(): void {
		$rule = $this->rule();
		$id   = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra y también la aerotermia.' ) ) ) );
		$this->run_job( $id );
		$this->assertSame( 1, $this->links_in( $id ) );

		global $wpdb;
		$batch = (string) $wpdb->get_var( $wpdb->prepare( "SELECT batch_id FROM {$wpdb->prefix}magiclinking_changes WHERE post_id = %d", $id ) ); // phpcs:ignore WordPress.DB
		Plugin::container()->get( Undo::class )->revert_batch( $batch );

		$edited = Plugin::container()->get( RuleValidator::class )->validate(
			array(
				'phrases'  => array( 'bomba de calor', 'aerotermia' ),
				'word_gap' => true,
			),
			$rule,
			$this->admin
		);
		$this->repo()->update( $edited );
		$this->run_job( $id );

		$this->assertSame( 0, $this->links_in( $id ) );
	}

	/**
	 * Hallazgo 4: la protección no depende de la retención del Historial.
	 */
	public function test_the_protection_survives_the_history_purge(): void {
		$this->rule();
		$id = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ) ) );
		$this->run_job( $id );

		global $wpdb;
		$batch = (string) $wpdb->get_var( $wpdb->prepare( "SELECT batch_id FROM {$wpdb->prefix}magiclinking_changes WHERE post_id = %d", $id ) ); // phpcs:ignore WordPress.DB
		Plugin::container()->get( Undo::class )->revert_batch( $batch );
		$this->assertSame( 0, $this->links_in( $id ) );

		Plugin::container()->get( ChangeRepository::class )->purge( gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 100 );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}magiclinking_changes" ) ); // phpcs:ignore WordPress.DB

		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $this->content_of( $id ) . $this->p( 'Más sobre la bomba de calor.' ),
			)
		);
		$this->run_job( $id );
		$this->assertSame( 0, $this->links_in( $id ) );
	}

	/**
	 * Hallazgo 5 (falso con Action Scheduler 4.2: al reclamarse, la acción en ejecución libera su `unique_key`, así que
	 * un guardado durante la ejecución encola otra). Se deja como prueba de regresión.
	 */
	public function test_a_save_while_the_action_is_running_is_queued_again(): void {
		$this->rule();
		as_unschedule_all_actions( RuleRunner::HOOK, null, Installer::ACTION_GROUP );
		$id      = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ) ) );
		$pending = as_get_scheduled_actions(
			array(
				'hook'   => RuleRunner::HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 1, $pending );
		\ActionScheduler::store()->log_execution( (int) array_values( $pending )[0] ); // En ejecución.
		$this->assertCount(
			0,
			as_get_scheduled_actions(
				array(
					'hook'   => RuleRunner::HOOK,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		);

		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $this->content_of( $id ) . $this->p( 'Texto nuevo.' ),
			)
		);

		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => RuleRunner::HOOK,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		);
	}

	/**
	 * Propuesta: la regla escribe como quien la creó, si todavía puede.
	 */
	public function test_rules_run_as_their_creator_while_they_can_manage_and_edit(): void {
		$rule = $this->rule();
		$id   = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ) ) );
		$this->run_job( $id );

		global $wpdb;
		$this->assertSame( $this->admin, (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}magiclinking_changes WHERE post_id = %d", $id ) ) ); // phpcs:ignore WordPress.DB
		$this->assertTrue( is_protected_meta( RuleService::APPLIED_META ) );

		// Si el creador ya no gestiona el sitio, la regla se salta.
		( new \WP_User( $this->admin ) )->set_role( 'subscriber' );
		$other = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Otra bomba de calor ahorra.' ) ) ) );
		$this->run_job( $other );
		$this->assertSame( 0, $this->links_in( $other ) );
		$this->assertNotNull( $rule );
	}

	/**
	 * Hallazgos 3 y 4: reenviar el mismo destino y las frases no revalida el destino; cambiarlo, sí.
	 */
	public function test_editing_with_the_same_target_revalidates_only_what_changed(): void {
		$rule = $this->rule();
		wp_update_post(
			array(
				'ID'          => $this->target,
				'post_status' => 'draft',
			)
		);
		$validator = Plugin::container()->get( RuleValidator::class );

		$edited = $validator->validate(
			array(
				'target_id'    => $this->target,
				'phrases'      => array( 'bomba de calor', 'aerotermia' ),
				'max_per_post' => 3,
			),
			$rule,
			$this->admin
		);
		$this->assertInstanceOf( Rule::class, $edited, 'Mismo destino: solo se revalidan las frases.' );
		$this->assertSame( array( 'bomba de calor', 'aerotermia' ), $edited->variants );
		$this->assertWPError(
			$validator->validate(
				array(
					'target_id' => $this->target,
					'phrases'   => array( 'de la' ),
				),
				$rule,
				$this->admin
			),
			'Una frase mala sigue fallando.'
		);

		$other = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertWPError( $validator->validate( array( 'target_id' => $other ), $rule, $this->admin ), 'Un destino nuevo sí se valida.' );

		// Un destino ignorado que ya era el de la regla tampoco bloquea editarla.
		wp_update_post(
			array(
				'ID'          => $this->target,
				'post_status' => 'publish',
			)
		);
		Plugin::container()->get( Settings::class )->update( array( 'ignored_urls' => array( (string) get_permalink( $this->target ) ) ) );
		$this->assertInstanceOf(
			Rule::class,
			$validator->validate(
				array(
					'target_id'    => $this->target,
					'phrases'      => array( 'bomba de calor' ),
					'max_per_post' => 2,
				),
				$rule,
				$this->admin
			)
		);
	}

	/**
	 * Hallazgo 6: la marca de «regla aplicada» no se duplica.
	 */
	public function test_the_applied_mark_is_not_duplicated(): void {
		$rule = $this->rule(
			array(
				'first_only'   => false,
				'max_per_post' => 2,
			)
		);
		Plugin::container()->get( Settings::class )->update( array( 'links_per_target_max' => 3 ) );
		$post = $this->entry( $this->body( array( 'Una bomba de calor ahorra.', 'Otra bomba de calor distinta.' ), 300 ) );

		$this->assertSame( 2, $this->service()->apply( $post, $rule, $this->admin )->inserted );
		Plugin::container()->get( Settings::class )->update( array( 'links_per_target_max' => 5 ) );
		$this->service()->apply( get_post( $post->ID ), $rule, $this->admin );

		$this->assertCount( 1, get_post_meta( $post->ID, RuleService::APPLIED_META, false ) );
	}

	/**
	 * Hallazgo 7: pausar o borrar una regla vale al instante aunque haya una cola ya leída.
	 */
	public function test_a_paused_or_deleted_rule_is_not_applied_from_a_stale_cache(): void {
		global $wpdb;
		$rule = $this->rule();
		$a    = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra.' ) ) ) );
		$b    = self::factory()->post->create( array( 'post_content' => $this->body( array( 'Una bomba de calor ahorra otra vez.' ) ) ) );
		$this->assertCount( 1, $this->repo()->active() ); // La cola ya leyó la regla.

		// Otro proceso la pausa.
		$other = new \MagicLinking\Rules\RuleRepository( $wpdb );
		$other->update( new Rule( $rule->id, $rule->variants, $rule->target_id, $rule->target_url, $rule->lang, 1, true, false, array(), array(), false ) );
		$this->run_job( $a );
		$this->assertSame( 0, $this->links_in( $a ), 'Pausada: no se aplica.' );

		$other->update( new Rule( $rule->id, $rule->variants, $rule->target_id, $rule->target_url, $rule->lang, 1, true, false, array(), array(), true ) );
		$this->assertTrue( $this->repo()->has_active() );
		$other->delete( $rule->id );
		$this->run_job( $b );
		$this->assertSame( 0, $this->links_in( $b ), 'Borrada: no se aplica.' );
	}
}
