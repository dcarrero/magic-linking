<?php
/**
 * Reglas: crear, editar, listar y borrar, sin límite de reglas.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration\Rules;

use MagicLinking\Core\Plugin;
use MagicLinking\Rules\Rule;
use MagicLinking\Rules\RuleValidator;

final class RuleCrudTest extends RulesTestCase {

	private function validator(): RuleValidator {
		return Plugin::container()->get( RuleValidator::class );
	}

	public function test_creates_reads_updates_and_deletes_a_rule(): void {
		$rule = $this->rule(
			array(
				'phrases'      => "bomba de calor\nbombas de calor\naerotermia doméstica",
				'max_per_post' => 2,
				'first_only'   => false,
				'word_gap'     => true,
			)
		);

		// «bomba de calor» y «bombas de calor» tienen la misma raíz: una sola variante.
		$this->assertSame( array( 'bomba de calor', 'aerotermia doméstica' ), $rule->variants );
		$this->assertSame( $this->target, $rule->target_id );
		$this->assertSame( 2, $rule->max_per_post );
		$this->assertFalse( $rule->first_only );
		$this->assertTrue( $rule->gap );
		$this->assertTrue( $rule->active );
		$this->assertSame( $this->admin, $rule->created_by );
		$this->assertSame( (string) get_permalink( $this->target ), $rule->target_url );

		$edited = $this->validator()->validate(
			array(
				'phrases'            => array( 'calefacción eficiente' ),
				'active'             => false,
				'exclude_post_types' => array( 'page' ),
				'exclude_categories' => array( 3, 3, 0 ),
			),
			$rule,
			$this->admin
		);
		$this->assertInstanceOf( Rule::class, $edited );
		$this->assertTrue( $this->repo()->update( $edited ) );

		$again = $this->repo()->find( $rule->id );
		$this->assertSame( array( 'calefacción eficiente' ), $again->variants );
		$this->assertFalse( $again->active );
		$this->assertSame( array( 'page' ), $again->exclude_post_types );
		$this->assertSame( array( 3 ), $again->exclude_categories );
		$this->assertSame( 2, $again->max_per_post, 'Lo que no llega se conserva.' );
		$this->assertTrue( $again->gap );
		$this->assertSame( $this->admin, $again->created_by );

		$this->assertTrue( $this->repo()->delete( $rule->id ) );
		$this->assertNull( $this->repo()->find( $rule->id ) );
		$this->assertFalse( $this->repo()->delete( $rule->id ) );
	}

	public function test_there_is_no_limit_of_rules(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			$this->rule( array( 'phrases' => array( 'frase número ' . $i . ' única' ) ) );
		}

		$page = $this->repo()->page( 1, 100 );
		$this->assertSame( 60, $page['total'] );
		$this->assertCount( 60, $page['items'] );
		$this->assertCount( 60, $this->repo()->active() );
		$this->assertSame( 10, count( $this->repo()->page( 6, 10 )['items'] ) );
	}

	public function test_invalid_rules_are_refused_with_a_plain_message(): void {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		foreach (
			array(
				'sin frases'                => array( 'phrases' => array( '  ', '' ) ),
				'solo palabras vacías'      => array( 'phrases' => array( 'de la' ) ),
				'demasiado larga'           => array( 'phrases' => array( str_repeat( 'a', 130 ) ) ),
				'demasiadas palabras'       => array( 'phrases' => array( 'uno dos tres cuatro cinco seis siete ocho nueve' ) ),
				'destino en borrador'       => array( 'target_id' => $draft ),
				'destino que no existe'     => array( 'target_id' => 999999 ),
				'más enlaces que el máximo' => array( 'max_per_post' => 11 ),
				'cero enlaces por entrada'  => array( 'max_per_post' => 0 ),
			) as $case => $input
		) {
			$result = $this->validator()->validate(
				$input + array(
					'phrases'   => array( 'bomba de calor' ),
					'target_id' => $this->target,
				),
				null,
				$this->admin
			);
			$this->assertWPError( $result, $case );
			$this->assertSame( 400, $result->get_error_data()['status'], $case );
			$this->assertNotSame( '', $result->get_error_message(), $case );
		}
	}

	public function test_a_target_with_a_password_or_of_an_ignored_type_is_refused(): void {
		$locked = self::factory()->post->create( array( 'post_password' => 'secreto' ) );
		$this->assertWPError(
			$this->validator()->validate(
				array(
					'phrases'   => array( 'bomba de calor' ),
					'target_id' => $locked,
				),
				null,
				1
			)
		);

		register_post_type( 'ficha', array( 'public' => true ) );
		$ficha = self::factory()->post->create( array( 'post_type' => 'ficha' ) );
		$this->assertWPError(
			$this->validator()->validate(
				array(
					'phrases'   => array( 'bomba de calor' ),
					'target_id' => $ficha,
				),
				null,
				1
			),
			'Los tipos que no se analizan no son destino.'
		);
		unregister_post_type( 'ficha' );
	}

	public function test_the_language_of_a_rule_is_the_one_of_its_destination(): void {
		add_filter( 'magiclinking_post_language', fn( $lang, $id ) => $id === $this->target ? 'en' : $lang, 5, 2 );

		$this->assertSame( 'en', $this->rule()->lang );
	}

	public function test_active_rules_exclude_the_inactive_ones(): void {
		$on  = $this->rule();
		$off = $this->rule(
			array(
				'active'  => false,
				'phrases' => array( 'otra frase distinta' ),
			)
		);

		$this->assertSame( array( $on->id ), array_map( static fn( Rule $r ): int => $r->id, $this->repo()->active() ) );
		$this->assertNotSame( $on->id, $off->id );
		$this->assertTrue( $this->repo()->has_active() );
	}
}
