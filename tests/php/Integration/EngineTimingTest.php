<?php
/**
 * Tiempo del motor por fase (F1-27, D-30): se apunta en un búfer circular sin autoload, sin consultas en la
 * petición, y la ruta REST de la pestaña Estado lo resume.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Index\EngineTiming;
use MagicLinking\Index\Suggestions;
use MagicLinking\Jobs\Jobs;
use WP_REST_Request;

final class EngineTimingTest extends GraphTestCase {

	private int $target = 0;

	/**
	 * @var list<int>
	 */
	private array $sources = array();

	public function set_up(): void {
		parent::set_up();
		foreach ( EngineTiming::OPTIONS as $option ) {
			delete_option( $option );
		}
		// El servicio es uno por petición y otras pruebas dejan medidas sin escribir (nadie ejecuta `shutdown`).
		$pending = new \ReflectionProperty( EngineTiming::class, 'pending' );
		$pending->setValue( $this->timing(), array() );
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$p            = static fn( string $html ): string => "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
		$this->target = self::factory()->post->create(
			array(
				'post_title'   => 'Aerotermia: guía completa',
				'post_content' => $p( 'La aerotermia aprovecha la energía del aire exterior para calentar la vivienda con una bomba de calor eficiente.' ),
			)
		);
		foreach ( array( 'azulejos, mampara y grifería', 'ventanas, persianas y herrajes', 'rodillos, esmalte y brochas' ) as $words ) {
			$this->sources[] = self::factory()->post->create(
				array(
					'post_title'   => 'Obras en casa ' . $words,
					'post_content' => $p( 'Este fin de semana hemos repasado ' . $words . ' antes de comprar nada.' ) . $p( 'Mientras tanto, la aerotermia nos ha dado mucho que pensar, porque ' . $words . ' cuestan lo suyo.' ),
				)
			);
		}
		foreach ( array( 'aceite de oliva', 'gazpacho andaluz', 'tortilla de patatas', 'pan de masa madre' ) as $dish ) {
			self::factory()->post->create(
				array(
					'post_title'   => 'Receta de ' . $dish,
					'post_content' => $p( 'Una receta sencilla de ' . $dish . ' con ingredientes de temporada, paso a paso y con trucos para que salga jugosa en la mesa.' ),
				)
			);
		}
		$jobs = Plugin::container()->get( Jobs::class );
		$job  = $jobs->start_index( 0, false, true, true, true );
		$jobs->run_to_completion( $job['id'] );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function service(): Suggestions {
		return Plugin::container()->get( Suggestions::class );
	}

	private function timing(): EngineTiming {
		return Plugin::container()->get( EngineTiming::class );
	}

	public function test_requests_are_recorded_by_direction_with_their_phases(): void {
		$out = $this->service()->outgoing( $this->sources[0] );
		$in  = $this->service()->incoming( $this->target );
		$this->assertNotEmpty( $out );
		$this->assertNotEmpty( $in );

		$summary = $this->timing()->summary();
		foreach ( array( 'outgoing', 'incoming' ) as $direction ) {
			$row = $summary[ $direction ];
			$this->assertSame( 1, $row['count'], $direction );
			$this->assertGreaterThan( 0.0, $row['median'], $direction );
			$this->assertSame( $row['median'], $row['p95'], $direction );
			$this->assertGreaterThan( 0.0, $row['retrieval'], $direction );
			$this->assertGreaterThan( 0.0, $row['load'], $direction );
			$this->assertGreaterThan( 0.0, $row['phrases'], $direction );
			$this->assertGreaterThan( 0.0, $row['scoring'], $direction );
			$this->assertGreaterThan( 0.0, $row['candidates'], $direction );
			$phases = $row['retrieval'] + $row['load'] + $row['phrases'] + $row['scoring'];
			$this->assertLessThanOrEqual( $row['median'] + 0.3, $phases, 'las fases no pueden sumar más que el total' );
			$this->assertGreaterThanOrEqual( 0.4 * $row['median'], $phases, 'las fases explican la mayor parte del total' );
		}
	}

	public function test_recording_costs_no_queries_and_writes_only_at_shutdown(): void {
		$queries = array();
		$collect = static function ( $query ) use ( &$queries ) {
			$queries[] = (string) $query;
			return $query;
		};
		add_filter( 'query', $collect, 1 );
		$this->service()->outgoing( $this->sources[0] );
		$this->service()->outgoing( $this->sources[1] );
		remove_filter( 'query', $collect, 1 );

		$own = array_filter( $queries, static fn( string $q ): bool => false !== stripos( $q, 'magiclinking_engine_timing' ) );
		$this->assertSame( array(), array_values( $own ), 'durante la petición no se toca la opción' );
		$this->assertFalse( get_option( EngineTiming::OPTIONS['outgoing'], false ) );
		$this->assertNotFalse( has_action( 'shutdown', array( $this->timing(), 'flush' ) ) );

		$this->timing()->flush();
		$this->assertSame( 2, $this->timing()->summary()['outgoing']['count'] );
	}

	public function test_buffer_keeps_the_last_twenty_and_is_never_autoloaded(): void {
		$timing = $this->timing();
		for ( $i = 1; $i <= 25; $i++ ) {
			$timing->record(
				EngineTiming::OUTGOING,
				$i * 1000000,
				array(
					'retrieval'  => 0,
					'load'       => 0,
					'phrases'    => 0,
					'scoring'    => 0,
					'candidates' => $i,
				)
			);
		}
		$pending = ( new \ReflectionProperty( EngineTiming::class, 'pending' ) )->getValue( $timing );
		$this->assertCount( 20, $pending['outgoing'], 'la memoria también se recorta a KEEP' );
		$timing->flush();

		$stored = get_option( EngineTiming::OPTIONS['outgoing'] );
		$this->assertCount( 20, $stored['rows'] );
		$this->assertSame( 6.0, $stored['rows'][0][0], 'se descartan las más antiguas' );
		$this->assertSame( 25.0, $stored['rows'][19][0] );

		global $wpdb;
		$autoloaded = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload IN ('yes', 'on', 'auto', 'auto-on')", 'magiclinking%' ) ); // phpcs:ignore WordPress.DB
		foreach ( EngineTiming::OPTIONS as $option ) {
			$this->assertNotContains( $option, $autoloaded );
		}
	}

	public function test_median_and_p95_follow_the_rule_of_the_performance_script(): void {
		$timing = $this->timing();
		foreach ( range( 1, 20 ) as $ms ) {
			$timing->record(
				EngineTiming::INCOMING,
				$ms * 1000000,
				array(
					'retrieval'  => 0,
					'load'       => 0,
					'phrases'    => 0,
					'scoring'    => 0,
					'candidates' => 4,
				)
			);
		}
		$row = $timing->summary()['incoming'];
		$this->assertSame( 11.0, $row['median'], 'el elemento central del orden: índice 10 de 20' );
		$this->assertSame( 19.0, $row['p95'], 'rango más cercano: ceil(0,95 * 20) - 1' );
		$this->assertSame( 4.0, $row['candidates'] );
		$this->assertSame( 0, $timing->summary()['outgoing']['count'] );
	}

	public function test_two_directions_arriving_together_do_not_overwrite_each_other(): void {
		$one = new EngineTiming();
		$two = new EngineTiming();
		$one->record( EngineTiming::OUTGOING, 5000000, $this->phases() );
		$two->record( EngineTiming::INCOMING, 7000000, $this->phases() );
		$one->flush();
		$two->flush();

		$summary = $this->timing()->summary();
		$this->assertSame( 1, $summary['outgoing']['count'] );
		$this->assertSame( 1, $summary['incoming']['count'] );
		$this->assertSame( 5.0, $summary['outgoing']['median'] );
		$this->assertSame( 7.0, $summary['incoming']['median'] );
	}

	public function test_writes_are_sampled_to_one_per_interval_per_direction(): void {
		$timing = $this->timing();
		$timing->record( EngineTiming::OUTGOING, 1000000, $this->phases() );
		$timing->flush();
		// Las pausas del editor llegan una tras otra: dentro del intervalo no se vuelve a escribir.
		$timing->record( EngineTiming::OUTGOING, 2000000, $this->phases() );
		$timing->record( EngineTiming::INCOMING, 3000000, $this->phases() );
		$timing->flush();
		$stored = get_option( EngineTiming::OPTIONS['outgoing'] );
		$this->assertCount( 1, $stored['rows'] );
		$this->assertCount( 1, get_option( EngineTiming::OPTIONS['incoming'] )['rows'], 'otra dirección tiene su propio intervalo' );

		// Pasado el intervalo, se escribe.
		$stored['t'] -= EngineTiming::MIN_INTERVAL + 1;
		update_option( EngineTiming::OPTIONS['outgoing'], $stored, false );
		$timing->record( EngineTiming::OUTGOING, 4000000, $this->phases() );
		$timing->flush();
		$this->assertCount( 2, get_option( EngineTiming::OPTIONS['outgoing'] )['rows'] );

		// `force` (resumen, banco de pruebas) escribe siempre.
		$timing->record( EngineTiming::OUTGOING, 6000000, $this->phases() );
		$this->assertSame( 3, $timing->summary()['outgoing']['count'] );
	}

	public function test_cached_responses_count_for_latency_but_not_for_phases(): void {
		$timing = $this->timing();
		$timing->record( EngineTiming::OUTGOING, 40000000, array_merge( $this->phases(), array( 'retrieval' => 30000000 ) ) );
		$timing->record_cached( EngineTiming::OUTGOING, 2000000 );
		$timing->record_cached( EngineTiming::OUTGOING, 3000000 );
		$row = $timing->summary()['outgoing'];
		$this->assertSame( 3, $row['count'] );
		$this->assertSame( 2, $row['cached'] );
		$this->assertSame( 3.0, $row['median'], 'la mediana incluye las de caché: es lo que ve el usuario' );
		$this->assertSame( 30.0, $row['retrieval'], 'las fases, solo de las que calculó el motor' );
	}

	public function test_a_cached_rest_response_is_recorded_as_cached(): void {
		if ( ! wp_using_ext_object_cache() ) {
			wp_using_ext_object_cache( true );
		}
		wp_cache_flush();
		$request = new WP_REST_Request( 'GET', '/magic-linking/v1/suggestions/inbound' );
		$request->set_param( 'post_id', $this->target );
		rest_get_server()->dispatch( $request );
		rest_get_server()->dispatch( $request );
		wp_using_ext_object_cache( false );

		$row = $this->timing()->summary()['incoming'];
		$this->assertSame( 2, $row['count'] );
		$this->assertSame( 1, $row['cached'] );
	}

	public function test_early_exits_that_never_reach_the_engine_are_not_recorded(): void {
		// Con «Exigir etiqueta» y ninguna etiqueta en el origen ni en el destino, el motor sale sin calcular nada.
		( new \MagicLinking\Core\Settings() )->update( array( 'filters' => array( 'tag' => 'require' ) ) );
		$this->assertSame( array(), $this->service()->outgoing( $this->sources[0] ) );
		$this->assertSame( array(), $this->service()->incoming( $this->target ) );

		$summary = $this->timing()->summary();
		$this->assertSame( 0, $summary['outgoing']['count'] );
		$this->assertSame( 0, $summary['incoming']['count'] );
	}

	public function test_float_nanoseconds_from_32_bit_php_are_accepted(): void {
		$timing = $this->timing();
		// En PHP de 32 bits hrtime( true ) devuelve float: no puede romper la petición.
		$timing->record( EngineTiming::OUTGOING, 4500000.0, array_merge( $this->phases(), array( 'retrieval' => 1500000.0 ) ) );
		$timing->record_cached( EngineTiming::INCOMING, 2000000.0 );
		$summary = $timing->summary();
		$this->assertSame( 4.5, $summary['outgoing']['median'] );
		$this->assertSame( 1.5, $summary['outgoing']['retrieval'] );
		$this->assertSame( 2.0, $summary['incoming']['median'] );
	}

	/**
	 * @return array{retrieval: int, load: int, phrases: int, scoring: int, candidates: int}
	 */
	private function phases(): array {
		return array(
			'retrieval'  => 0,
			'load'       => 0,
			'phrases'    => 0,
			'scoring'    => 0,
			'candidates' => 1,
		);
	}

	public function test_rest_route_is_for_editors_and_has_the_budgets(): void {
		$this->service()->outgoing( $this->sources[0] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$denied = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/magic-linking/v1/engine-timing' ) );
		$this->assertContains( $denied->get_status(), array( 401, 403 ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/magic-linking/v1/engine-timing' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 20, $data['keep'] );
		$this->assertSame( 1, $data['outgoing']['count'] );
		$this->assertSame( EngineTiming::BUDGET_OUTGOING, $data['outgoing']['budget'] );
		$this->assertSame( EngineTiming::BUDGET_INCOMING, $data['incoming']['budget'] );
		$this->assertSame( 500, $data['outgoing']['budget'] );
		$this->assertSame( 800, $data['incoming']['budget'] );
		$this->assertSame( 0, $data['incoming']['count'] );
	}

	public function test_nothing_is_recorded_when_the_entry_is_not_analyzed(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			)
		);
		$this->assertSame( array(), $this->service()->outgoing( $page ) );
		$this->assertSame( 0, $this->timing()->summary()['outgoing']['count'] );
	}
}
