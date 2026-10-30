<?php
/**
 * Reglas: aplicar a una entrada con la inserción segura, topes duros, idioma, Historial y deshacer.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Rules;

use MagicLinking\Core\Plugin;
use MagicLinking\Engine\RulePlan;
use MagicLinking\History\ChangeRepository;
use MagicLinking\History\Undo;
use MagicLinking\History\UndoResult;
use MagicLinking\Rules\RuleService;

final class RuleApplyTest extends RulesTestCase {

	public function test_adds_one_link_through_the_safe_insertion_and_it_can_be_undone_byte_for_byte(): void {
		$content = $this->body( array( 'Una bomba de calor ahorra energía en casa.', 'Otra bomba de calor aparece más abajo.' ) );
		$post    = $this->entry( $content );
		$rule    = $this->rule();

		$outcome = $this->service()->apply( $post, $rule, $this->admin );

		$this->assertSame( 1, $outcome->inserted );
		$this->assertNull( $outcome->skipped );
		$this->assertSame( array(), $outcome->failed );
		$saved = $this->content_of( $post->ID );
		$url   = (string) get_permalink( $this->target );
		$this->assertSame( 1, substr_count( $saved, '<a href="' . $url . '">bomba de calor</a>' ), 'Solo la primera aparición.' );
		// Fuera del enlace, el documento es idéntico byte a byte.
		$this->assertSame( $content, str_replace( array( '<a href="' . $url . '">', '</a>' ), '', $saved ) );

		// Historial: origen «regla», con el diff y la clave de la regla.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}magiclinking_changes WHERE batch_id = %s", $outcome->batch_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertSame( ChangeRepository::ORIGIN_RULE, $row['origin'] );
		$this->assertSame( $rule->key_for( $post->ID ), $row['origin_key'] );
		$this->assertStringContainsString( '<a href=', $row['after_html'] );
		$this->assertStringNotContainsString( '<a href=', $row['before_html'] );

		$undone = Plugin::container()->get( Undo::class )->revert_batch( $outcome->batch_id );
		$this->assertSame( UndoResult::RESTORED, array_values( $undone )[0]->status );
		$this->assertSame( $content, $this->content_of( $post->ID ) );
	}

	public function test_never_removes_or_changes_an_existing_link_and_never_links_inside_one(): void {
		$other    = self::factory()->post->create(
			array(
				'post_title' => 'Otra guía',
				'post_name'  => 'otra-guia',
			)
		);
		$existing = '<a href="' . get_permalink( $other ) . '" title="x">la bomba de calor</a>';
		$content  = $this->body( array( "Mira {$existing} primero.", 'Aquí hay una bomba de calor suelta.' ), 300 );
		$post     = $this->entry( $content );

		$outcome = $this->service()->apply( $post, $this->rule(), $this->admin );

		// La primera aparición está dentro de un enlace de otro destino: no se toca; se usa la suelta.
		$this->assertSame( 1, $outcome->inserted );
		$saved = $this->content_of( $post->ID );
		$this->assertStringContainsString( $existing, $saved );
		$this->assertSame( 1, substr_count( $saved, '</a>' ) - 1 );
		$this->assertSame( 2, substr_count( $saved, '<a ' ) );
		$this->assertSame( 1, preg_match( '#<a href="[^"]*bomba-de-calor/">bomba de calor</a> suelta#', $saved ) );
	}

	public function test_does_not_add_links_to_a_post_that_already_has_the_ones_its_length_allows(): void {
		$other = self::factory()->post->create( array( 'post_title' => 'Otra guía' ) );
		$short = $this->entry( $this->body( array( 'Mira <a href="' . get_permalink( $other ) . '">otra guía</a> primero.', 'Una bomba de calor ahorra energía.' ) ) );
		$long  = $this->entry( $this->body( array( 'Mira <a href="' . get_permalink( $other ) . '">otra guía</a> primero.', 'Una bomba de calor ahorra energía.' ), 320 ) );
		$rule  = $this->rule();

		$blocked = $this->service()->apply( $short, $rule, $this->admin );
		$this->assertSame( 0, $blocked->inserted );
		$this->assertSame( RulePlan::DENSITY, $blocked->skipped );
		$this->assertSame( $short->post_content, $this->content_of( $short->ID ), 'No se escribe nada.' );

		$this->assertSame( 1, $this->service()->apply( $long, $rule, $this->admin )->inserted, 'Un texto largo admite más enlaces.' );
	}

	public function test_the_density_cap_is_a_setting_of_the_user(): void {
		$other = self::factory()->post->create( array( 'post_title' => 'Otra guía' ) );
		$post  = $this->entry( $this->body( array( 'Mira <a href="' . get_permalink( $other ) . '">otra guía</a> primero.', 'Una bomba de calor ahorra energía.' ), 320 ) );
		$rule  = $this->rule();

		Plugin::container()->get( \MagicLinking\Core\Settings::class )->update( array( 'words_per_link' => 1000 ) );
		$this->assertSame( RulePlan::DENSITY, $this->service()->apply( $post, $rule, $this->admin )->skipped );
	}

	public function test_one_link_per_destination_in_a_post_by_default_and_more_if_the_user_allows(): void {
		$content = $this->body( array( 'Una bomba de calor ahorra.', 'Otra bomba de calor distinta.' ), 400 );
		$this->allow_links( 3 );
		$rule = $this->rule(
			array(
				'first_only'   => false,
				'max_per_post' => 3,
			)
		);

		$this->allow_links( 1 );
		$one = $this->entry( $content );
		$this->assertSame( 1, $this->service()->apply( $one, $rule, $this->admin )->inserted );
		$again = $this->service()->apply( get_post( $one->ID ), $rule, $this->admin );
		$this->assertSame( 0, $again->inserted );
		$this->assertSame( RulePlan::TARGET_LINKED, $again->skipped );

		Plugin::container()->get( \MagicLinking\Core\Settings::class )->update( array( 'links_per_target_max' => 3 ) );
		$two = $this->entry( $content );
		$this->assertSame( 2, $this->service()->apply( $two, $rule, $this->admin )->inserted, 'Las dos apariciones, cada una con su fila en el Historial.' );
	}

	public function test_stops_at_the_user_cap_of_entries_with_the_same_anchor_toward_the_same_destination(): void {
		$url = get_permalink( $this->target );
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->entry( $this->body( array( 'Ya enlazamos la <a href="' . $url . '">bomba de calor</a> aquí ' . $i . '.' ) ) )->ID;
		}
		$this->index( $ids );

		$rule = $this->rule();
		$post = $this->entry( $this->body( array( 'Una bomba de calor ahorra energía.' ) ) );

		$full = $this->service()->apply( $post, $rule, $this->admin );
		$this->assertSame( 0, $full->inserted );
		$this->assertSame( RulePlan::ANCHOR_OVERUSE, $full->skipped );

		Plugin::container()->get( \MagicLinking\Core\Settings::class )->update( array( 'same_anchor_max' => 6 ) );
		$this->assertSame( 1, $this->service()->apply( $post, $rule, $this->admin )->inserted );
	}

	public function test_never_links_across_languages(): void {
		add_filter( 'magiclinking_post_language', fn( $lang, $id ) => $id === $this->target ? 'en' : $lang, 5, 2 );
		$post = $this->entry( $this->body( array( 'Una bomba de calor ahorra energía.' ) ) );
		$rule = $this->rule( array( 'phrases' => array( 'bomba de calor' ) ) );

		$outcome = $this->service()->apply( $post, $rule, $this->admin );

		$this->assertSame( RuleService::OTHER_LANGUAGE, $outcome->skipped );
		$this->assertSame( $post->post_content, $this->content_of( $post->ID ) );
	}

	public function test_respects_the_exclusions_of_the_rule(): void {
		$cat    = self::factory()->category->create( array( 'name' => 'Pruebas' ) );
		$tagged = $this->entry( $this->body( array( 'Una bomba de calor ahorra.' ) ) );
		wp_set_post_categories( $tagged->ID, array( $cat ) );
		$page = $this->entry( $this->body( array( 'Una bomba de calor ahorra.' ) ), array( 'post_type' => 'page' ) );

		$rule = $this->rule(
			array(
				'exclude_categories' => array( $cat ),
				'exclude_post_types' => array( 'page' ),
			)
		);

		$this->assertSame( RuleService::EXCLUDED_CATEGORY, $this->service()->apply( get_post( $tagged->ID ), $rule, $this->admin )->skipped );
		$this->assertSame( RuleService::EXCLUDED_TYPE, $this->service()->apply( $page, $rule, $this->admin )->skipped );
	}

	public function test_variants_and_the_word_gap_work_end_to_end(): void {
		$rule = $this->rule(
			array(
				'phrases'  => array( 'reforma de cocina', 'obra de baño' ),
				'word_gap' => true,
			)
		);
		$post = $this->entry( $this->body( array( 'Quiero una reforma de la cocina cuanto antes.' ) ) );

		$this->assertSame( 1, $this->service()->apply( $post, $rule, $this->admin )->inserted );
		$this->assertStringContainsString( '>reforma de la cocina</a>', $this->content_of( $post->ID ) );

		$no_gap = $this->rule( array( 'phrases' => array( 'reforma de cocina' ) ) );
		$other  = $this->entry( $this->body( array( 'Quiero una reforma de la cocina cuanto antes.' ) ) );
		$this->assertSame( RulePlan::NO_MATCH, $this->service()->apply( $other, $no_gap, $this->admin )->skipped );
	}

	public function test_requires_permission_to_edit_the_entry(): void {
		$post   = $this->entry( $this->body( array( 'Una bomba de calor ahorra.' ) ), array( 'post_author' => $this->admin ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );

		$outcome = $this->service()->apply( $post, $this->rule(), $author );

		$this->assertSame( 0, $outcome->inserted );
		$this->assertSame( 'not_allowed', $outcome->failed[0]['reason'] );
		$this->assertSame( $post->post_content, $this->content_of( $post->ID ) );
	}

	public function test_does_not_write_while_someone_has_the_entry_open(): void {
		$post = $this->entry( $this->body( array( 'Una bomba de calor ahorra.' ) ) );
		update_post_meta( $post->ID, '_edit_lock', time() . ':' . $this->admin );

		$outcome = $this->service()->apply( $post, $this->rule(), 0 );

		$this->assertSame( 0, $outcome->inserted );
		$this->assertSame( 'locked', $outcome->failed[0]['reason'] );
		$this->assertSame( $post->post_content, $this->content_of( $post->ID ) );
	}

	public function test_scan_finds_the_entries_where_the_rule_fits_and_resumes_by_cursor(): void {
		$fit = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$fit[] = $this->entry( $this->body( array( 'Una bomba de calor ahorra ' . $i . '.' ) ) )->ID;
			$this->entry( $this->body( array( 'Nada que ver aquí ' . $i . '.' ) ) );
		}
		$rule = $this->rule();

		$first = $this->service()->scan( $rule, $this->admin, 0, 2, 5.0 );
		$this->assertCount( 2, $first['items'] );
		$this->assertFalse( $first['done'] );

		$found = array_column( $first['items'], 'post_id' );
		$next  = $first;
		while ( ! $next['done'] ) {
			$next  = $this->service()->scan( $rule, $this->admin, $next['cursor'], 2, 5.0 );
			$found = array_merge( $found, array_column( $next['items'], 'post_id' ) );
		}
		$this->assertEqualsCanonicalizing( $fit, $found, 'Sin repetir ni saltar ninguna, y sin tocar la del destino.' );
		$this->assertStringContainsString( 'bomba de calor', $first['items'][0]['links'][0]['anchor'] );
	}

	public function test_scan_only_lists_entries_the_user_can_edit(): void {
		$mine   = $this->entry( $this->body( array( 'Una bomba de calor ahorra.' ) ), array( 'post_author' => $this->admin ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$theirs = $this->entry( $this->body( array( 'Una bomba de calor ahorra.' ) ), array( 'post_author' => $author ) );

		$found = $this->service()->scan( $this->rule(), $author, 0, 10, 5.0 );

		$this->assertSame( array( $theirs->ID ), array_column( $found['items'], 'post_id' ) );
		$this->assertNotSame( $mine->ID, $theirs->ID );
	}
}
