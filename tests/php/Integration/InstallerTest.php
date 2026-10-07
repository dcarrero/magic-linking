<?php
/**
 * Instalador: tablas, migraciones, activación, desactivación y desinstalación.
 *
 * @package MagicLinking
 */

declare(strict_types=1);

namespace MagicLinking\Tests\Integration;

use MagicLinking\Core\Installer;
use MagicLinking\Core\Plugin;
use MagicLinking\Core\Schema;
use MagicLinking\Index\LexicalIndexer;
use MagicLinking\Index\TableRepository;
use MagicLinking\Jobs\Jobs;
use WP_UnitTestCase;
use wpdb;

final class InstallerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// La suite convierte CREATE/DROP TABLE en tablas temporales, que no
		// aparecen en SHOW TABLES. Aquí se prueban tablas reales y se borran al final.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->drop_everything();
	}

	public function tear_down(): void {
		Installer::forget_check();
		$this->drop_everything();
		parent::tear_down();
	}

	public function test_install_creates_all_tables_and_stores_version_without_autoload(): void {
		$installer = $this->installer();

		$this->assertSame( 0, $installer->installed_version() );
		$this->assertTrue( $installer->maybe_upgrade() );

		$this->assertSame( array(), $installer->missing_tables() );
		$this->assertCount( 7, Schema::TABLES );
		$this->assertSame( MAGICLINKING_DB_VERSION, $installer->installed_version() );
		$this->assertSame( 3, MAGICLINKING_DB_VERSION );
		$this->assertContains( $this->autoload_of( Installer::DB_VERSION_OPTION ), array( 'no', 'off' ) );
		$this->assertSame( 0, $this->own_autoloaded_options() );
	}

	public function test_tables_have_the_documented_columns(): void {
		$this->installer()->maybe_upgrade();

		$this->assertColumns( 'docs', array( 'post_id', 'post_type', 'lang', 'status', 'content_hash', 'word_count', 'doc_len', 'inbound', 'outbound', 'external', 'broken', 'embedding', 'embedding_model', 'indexed_at', 'lex_hash', 'lex_at' ) );
		$this->assertColumns( 'terms', array( 'term_id', 'lang', 'stem', 'surface', 'df', 'n' ) );
		$this->assertColumns( 'postings', array( 'term_id', 'post_id', 'weight', 'field', 'pos' ) );
		$this->assertColumns( 'links', array( 'id', 'source_id', 'target_id', 'target_url', 'anchor', 'block_path', 'kind', 'is_internal', 'is_broken' ) );
		$this->assertColumns( 'rules', array( 'id', 'phrase', 'target_id', 'target_url', 'lang', 'max_per_post', 'first_only', 'exclude', 'active', 'created_at', 'created_by' ) );
		$this->assertColumns( 'changes', array( 'id', 'batch_id', 'post_id', 'action', 'block_path', 'before_html', 'after_html', 'content_hash_after', 'user_id', 'created_at', 'undone_at' ) );
		$this->assertColumns( 'jobs', array( 'id', 'type', 'status', 'total', 'done', 'params', 'ai_tokens_est', 'ai_tokens_real', 'ai_cost_est', 'ai_cost_real', 'ai_provider', 'ai_model', 'error', 'created_by', 'created_at', 'updated_at' ) );
	}

	public function test_documented_indexes_exist(): void {
		$this->installer()->maybe_upgrade();

		$this->assertSame( array( 'PRIMARY', 'inbound', 'lang_status', 'type_status' ), $this->index_names( 'docs' ) );
		$this->assertSame( array( 'PRIMARY', 'lang_stem_n' ), $this->index_names( 'terms' ) );
		$this->assertSame( array( 'PRIMARY', 'post_id' ), $this->index_names( 'postings' ) );
		$this->assertSame( array( 'PRIMARY', 'is_broken', 'source_id', 'target_id' ), $this->index_names( 'links' ) );
		$this->assertSame( array( 'PRIMARY', 'batch_id', 'created_at', 'post_created' ), $this->index_names( 'changes' ) );
	}

	public function test_stems_differing_only_in_accents_do_not_collide(): void {
		global $wpdb;
		$this->installer()->maybe_upgrade();
		$table = Schema::table( $wpdb->prefix, 'terms' );

		$this->assertSame(
			1,
			$wpdb->insert(
				$table,
				array(
					'lang'    => 'es',
					'stem'    => 'cancion',
					'surface' => 'cancion',
					'n'       => 1,
				)
			)
		);
		$this->assertSame(
			1,
			$wpdb->insert(
				$table,
				array(
					'lang'    => 'es',
					'stem'    => 'canción',
					'surface' => 'canción',
					'n'       => 1,
				)
			)
		);
	}

	public function test_schema_is_idempotent(): void {
		$installer = $this->installer();
		$installer->maybe_upgrade();

		$this->assertSame( array(), $installer->create_tables(), 'Una segunda pasada de dbDelta no debe cambiar nada.' );
	}

	public function test_upgrade_from_a_fictitious_previous_version(): void {
		global $wpdb;

		// «Versión 1» ficticia: el esquema actual sin embedding_model ni el índice inbound.
		$this->installer()->maybe_upgrade();
		$docs = Schema::table( $wpdb->prefix, 'docs' );
		$wpdb->query( "ALTER TABLE {$docs} DROP COLUMN embedding_model, DROP INDEX inbound" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		update_option( Installer::DB_VERSION_OPTION, 1, false );
		$wpdb->insert(
			$docs,
			array(
				'post_id'    => 7,
				'post_type'  => 'post',
				'lang'       => 'es',
				'indexed_at' => '2026-09-01 00:00:00',
			)
		);
		$this->assertNotContains( 'embedding_model', $this->columns( 'docs' ) );

		$runs      = array();
		$installer = new Installer(
			$wpdb,
			3,
			array(
				3 => static function ( wpdb $db ) use ( &$runs, $docs ): void {
					$runs[] = 3;
					// Una migración de datos ve ya las columnas nuevas.
					$db->query( "UPDATE {$docs} SET embedding_model = 'lexical'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				},
				2 => static function () use ( &$runs ): void {
					$runs[] = 2;
				},
				1 => static function () use ( &$runs ): void {
					$runs[] = 1;
				},
			)
		);

		$this->assertTrue( $installer->maybe_upgrade() );

		$this->assertSame( array( 2, 3 ), $runs, 'Solo las migraciones posteriores a la versión instalada, en orden.' );
		$this->assertContains( 'embedding_model', $this->columns( 'docs' ) );
		$this->assertContains( 'inbound', $this->index_names( 'docs' ) );
		$this->assertSame( 'lexical', $wpdb->get_var( "SELECT embedding_model FROM {$docs} WHERE post_id = 7" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 3, $installer->installed_version() );

		// Ya actualizado: no se repite nada.
		$this->assertTrue( $installer->maybe_upgrade() );
		$this->assertSame( array( 2, 3 ), $runs );
	}

	public function test_schema_3_marks_an_existing_lexical_index_for_rebuild(): void {
		global $wpdb;

		// Esquema 2 ficticio: postings sin la columna `pos`, con una entrada indexada.
		$this->installer()->maybe_upgrade();
		$docs     = Schema::table( $wpdb->prefix, 'docs' );
		$postings = Schema::table( $wpdb->prefix, 'postings' );
		$wpdb->query( "ALTER TABLE {$postings} DROP COLUMN pos" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "INSERT INTO {$docs} (post_id, post_type, lang, doc_len, indexed_at, lex_hash, lex_at) VALUES (7, 'post', 'es', 120, '2026-09-01 00:00:00', 'abc', '2026-09-01 00:00:00')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "INSERT INTO {$postings} (term_id, post_id, weight, field) VALUES (1, 7, 0.5, 1)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( Installer::DB_VERSION_OPTION, 2, false );
		delete_option( LexicalIndexer::BUILDING_OPTION );

		$this->assertTrue( $this->installer()->maybe_upgrade() );

		$this->assertContains( 'pos', $this->columns( 'postings' ) );
		$this->assertSame( 3, $this->installer()->installed_version() );
		$this->assertNotFalse( get_option( LexicalIndexer::BUILDING_OPTION, false ), 'el índice léxico antiguo no vale: no cuenta como construido' );
		$this->assertNull( $wpdb->get_var( "SELECT NULLIF(lex_hash, '') FROM {$docs} WHERE post_id = 7" ), 'la huella léxica se vacía para que se reindexe' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_schema_3_keeps_the_id_of_a_build_in_progress(): void {
		global $wpdb;

		$this->installer()->maybe_upgrade();
		$postings = Schema::table( $wpdb->prefix, 'postings' );
		$wpdb->query( "INSERT INTO {$postings} (term_id, post_id, weight, field) VALUES (1, 7, 0.5, 1)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( Installer::DB_VERSION_OPTION, 2, false );
		update_option( LexicalIndexer::BUILDING_OPTION, 42, false );

		$this->assertTrue( $this->installer()->maybe_upgrade() );

		$this->assertSame( 42, (int) get_option( LexicalIndexer::BUILDING_OPTION ), 'solo el proceso que empezó la construcción puede cerrarla' );
	}

	public function test_the_index_checks_the_schema_before_using_it(): void {
		global $wpdb;

		// Una autoactualización deja el código nuevo contra el esquema 2 hasta la siguiente visita al administrador.
		$this->installer()->maybe_upgrade();
		$postings = Schema::table( $wpdb->prefix, 'postings' );
		$wpdb->query( "ALTER TABLE {$postings} DROP COLUMN pos" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		update_option( Installer::DB_VERSION_OPTION, 2, false );
		Installer::forget_check();

		( new TableRepository( $wpdb ) )->terms( 7 );

		$this->assertContains( 'pos', $this->columns( 'postings' ) );
		$this->assertSame( 3, $this->installer()->installed_version() );
	}

	public function test_upgrading_requests_a_reconcile_but_a_fresh_install_does_not(): void {
		as_unschedule_all_actions( '', array(), Installer::ACTION_GROUP );

		$this->installer()->maybe_upgrade();
		$this->assertFalse( as_has_scheduled_action( Jobs::HOOK_RECONCILE, null, Installer::ACTION_GROUP ), 'Instalación nueva.' );

		update_option( Installer::DB_VERSION_OPTION, 1, false );
		$this->installer()->maybe_upgrade();
		$this->assertTrue( as_has_scheduled_action( Jobs::HOOK_RECONCILE, null, Installer::ACTION_GROUP ), 'Actualización.' );
	}

	public function test_reactivating_reschedules_the_nightly_run(): void {
		Installer::activate();
		Plugin::container()->get( Jobs::class )->ensure_schedules();
		$this->assertTrue( as_has_scheduled_action( Jobs::HOOK_NIGHTLY, null, Installer::ACTION_GROUP ) );

		Installer::deactivate();
		$this->assertFalse( as_has_scheduled_action( Jobs::HOOK_NIGHTLY, null, Installer::ACTION_GROUP ) );

		Installer::activate();
		do_action( Jobs::HOOK_RECONCILE );

		$this->assertTrue( as_has_scheduled_action( Jobs::HOOK_NIGHTLY, null, Installer::ACTION_GROUP ) );
	}

	public function test_schema_3_leaves_an_empty_lexical_index_alone(): void {
		$this->installer()->maybe_upgrade();
		update_option( Installer::DB_VERSION_OPTION, 2, false );
		delete_option( LexicalIndexer::BUILDING_OPTION );

		$this->assertTrue( $this->installer()->maybe_upgrade() );

		$this->assertFalse( get_option( LexicalIndexer::BUILDING_OPTION, false ) );
	}

	public function test_newer_installed_version_is_left_alone(): void {
		global $wpdb;
		update_option( Installer::DB_VERSION_OPTION, 99, false );

		$runs      = 0;
		$installer = new Installer(
			$wpdb,
			1,
			array(
				1 => static function () use ( &$runs ): void {
					++$runs;
				},
			)
		);

		$this->assertTrue( $installer->maybe_upgrade() );
		$this->assertSame( 0, $runs );
		$this->assertSame( 99, $installer->installed_version() );
		$this->assertCount( 7, $installer->missing_tables(), 'No crea tablas al volver a una versión anterior del plugin.' );
	}

	public function test_activate_deactivate_reactivate_keeps_data_without_errors(): void {
		global $wpdb;

		Installer::activate();
		$this->assertSame( '', $wpdb->last_error );
		$docs = Schema::table( $wpdb->prefix, 'docs' );
		$wpdb->insert(
			$docs,
			array(
				'post_id'    => 42,
				'indexed_at' => '2026-09-01 00:00:00',
			)
		);

		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'magiclinking_test_job', array(), Installer::ACTION_GROUP );
		$this->assertTrue( as_has_scheduled_action( 'magiclinking_test_job', null, Installer::ACTION_GROUP ) );

		Installer::deactivate();
		$this->assertFalse( as_has_scheduled_action( 'magiclinking_test_job', null, Installer::ACTION_GROUP ), 'La desactivación cancela los procesos pendientes.' );
		$this->assertSame( array(), $this->installer()->missing_tables(), 'La desactivación no borra tablas.' );

		Installer::activate();
		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$docs} WHERE post_id = 42" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( MAGICLINKING_DB_VERSION, $this->installer()->installed_version() );
	}

	public function test_schema_check_runs_only_in_admin(): void {
		$installer = Plugin::container()->get( Installer::class );

		$this->assertSame( 10, has_action( 'admin_init', array( $installer, 'on_admin_init' ) ) );
		$this->assertFalse( has_action( 'init', array( $installer, 'on_admin_init' ) ) );
		$this->assertFalse( has_action( 'wp', array( $installer, 'on_admin_init' ) ) );
	}

	public function test_admin_init_installs_after_a_plugin_update(): void {
		$installer = Plugin::container()->get( Installer::class );
		$this->assertInstanceOf( Installer::class, $installer );

		$installer->on_admin_init();

		$this->assertSame( MAGICLINKING_DB_VERSION, $installer->installed_version() );
		$this->assertSame( array(), $installer->missing_tables() );
	}

	public function test_uninstall_keeps_data_by_default(): void {
		$installer = $this->installer();
		$installer->maybe_upgrade();

		$this->assertFalse( $installer->uninstall() );
		update_option( Installer::SETTINGS_OPTION, array( Installer::DELETE_DATA_SETTING => false ) );
		$this->assertFalse( $installer->uninstall() );

		$this->assertSame( array(), $installer->missing_tables() );
		$this->assertSame( MAGICLINKING_DB_VERSION, $installer->installed_version() );
	}

	public function test_uninstall_deletes_everything_when_the_user_asked_for_it(): void {
		$installer = $this->installer();
		$installer->maybe_upgrade();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'magiclinking_dismissed', array( 3 ) );
		update_post_meta( $post_id, 'other_plugin_meta', 'keep' );
		set_transient( 'magiclinking_suggestions_1', array( 'x' ) );
		update_option( 'other_plugin_option', 'keep' );
		update_option( Installer::SETTINGS_OPTION, array( Installer::DELETE_DATA_SETTING => true ) );

		Installer::uninstall_everywhere();

		$this->assertCount( 7, $installer->missing_tables() );
		$this->assertFalse( get_option( Installer::DB_VERSION_OPTION ) );
		$this->assertFalse( get_option( Installer::SETTINGS_OPTION ) );
		$this->assertFalse( get_transient( 'magiclinking_suggestions_1' ) );
		$this->assertSame( '', get_post_meta( $post_id, 'magiclinking_dismissed', true ) );
		$this->assertSame( 'keep', get_post_meta( $post_id, 'other_plugin_meta', true ) );
		$this->assertSame( 'keep', get_option( 'other_plugin_option' ) );
	}

	public function test_uninstall_never_touches_content_and_cancels_pending_processes(): void {
		$installer = $this->installer();
		$installer->maybe_upgrade();
		$content = '<!-- wp:paragraph --><p>Ver <a href="' . home_url( '/otra/' ) . '">otra</a>.</p><!-- /wp:paragraph -->';
		$post_id = self::factory()->post->create( array( 'post_content' => $content ) );
		as_enqueue_async_action( 'magiclinking_index_posts', array( array( $post_id ), false ), Installer::ACTION_GROUP );
		$this->assertTrue( as_has_scheduled_action( 'magiclinking_index_posts', null, Installer::ACTION_GROUP ) );
		update_option( Installer::SETTINGS_OPTION, array( Installer::DELETE_DATA_SETTING => true ) );

		Installer::uninstall_everywhere();

		$this->assertFalse( as_has_scheduled_action( 'magiclinking_index_posts', null, Installer::ACTION_GROUP ) );
		$this->assertSame( $content, get_post( $post_id )->post_content, 'Los enlaces escritos en el contenido se quedan, byte a byte.' );
	}

	public function test_uninstall_deletes_every_action_of_the_plugin_but_not_those_of_others(): void {
		global $wpdb;

		$installer = $this->installer();
		$installer->maybe_upgrade();
		$pending = as_enqueue_async_action( 'magiclinking_index_posts', array( array( 1 ), false ), Installer::ACTION_GROUP );
		$done    = as_enqueue_async_action( 'magiclinking_index_posts', array( array( 2 ), false ), Installer::ACTION_GROUP );
		$failed  = as_enqueue_async_action( 'magiclinking_index_posts', array( array( 3 ), false ), Installer::ACTION_GROUP );
		$other   = as_enqueue_async_action( 'other_plugin_hook', array(), 'other-plugin' );
		\ActionScheduler::store()->mark_complete( $done );
		\ActionScheduler::store()->mark_failure( $failed );
		\ActionScheduler::logger()->log( $done, 'Hecho' );
		update_option( Installer::SETTINGS_OPTION, array( Installer::DELETE_DATA_SETTING => true ) );
		update_option( LexicalIndexer::BUILDING_OPTION, 5, false );
		set_transient( 'magiclinking_epoch_abc', array( 'x' ) );

		Installer::uninstall_everywhere();

		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';
		foreach ( array( $pending, $done, $failed ) as $id ) {
			$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action_id = %d', $actions, $id ) ), 'Acción del plugin borrada.' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action_id = %d', $logs, $done ) ), 'Su registro, también.' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action_id = %d', $actions, $other ) ), 'La acción de otro plugin se queda.' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertFalse( get_option( LexicalIndexer::BUILDING_OPTION, false ) );
		$this->assertFalse( get_transient( 'magiclinking_epoch_abc' ) );
		$this->assertCount( 7, $installer->missing_tables(), 'Incluidas las tablas de historial (changes) y de procesos (jobs).' );
	}

	public function test_uninstall_keeps_history_jobs_and_options_when_the_user_did_not_ask(): void {
		global $wpdb;

		$installer = $this->installer();
		$installer->maybe_upgrade();
		$wpdb->insert(
			Schema::table( $wpdb->prefix, 'jobs' ),
			array(
				'type'   => 'purge',
				'status' => 'done',
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Schema::table( $wpdb->prefix, 'jobs' ),
			array(
				'type'   => 'undo',
				'status' => 'done',
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		update_option( LexicalIndexer::BUILDING_OPTION, 5, false );
		update_option( Installer::SETTINGS_OPTION, array( Installer::DELETE_DATA_SETTING => false ) );
		set_transient( 'magiclinking_epoch_abc', array( 'x' ) );

		Installer::uninstall_everywhere();

		$this->assertSame( array(), $installer->missing_tables() );
		$this->assertSame( 2, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $wpdb->prefix, 'jobs' ) ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 5, (int) get_option( LexicalIndexer::BUILDING_OPTION ) );
		$this->assertSame( array( 'x' ), get_transient( 'magiclinking_epoch_abc' ) );

		delete_transient( 'magiclinking_epoch_abc' );
		delete_option( LexicalIndexer::BUILDING_OPTION );
	}

	private function installer(): Installer {
		global $wpdb;

		return new Installer( $wpdb, MAGICLINKING_DB_VERSION );
	}

	/**
	 * @param list<string> $expected
	 */
	private function assertColumns( string $table, array $expected ): void {
		$this->assertSame( $expected, $this->columns( $table ), "Columnas de {$table}" );
	}

	/**
	 * @return list<string>
	 */
	private function columns( string $table ): array {
		global $wpdb;

		return $wpdb->get_col( 'DESCRIBE ' . Schema::table( $wpdb->prefix, $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Nombre de tabla desde constantes.
	}

	/**
	 * @return list<string>
	 */
	private function index_names( string $table ): array {
		global $wpdb;

		$names = array_values( array_unique( $wpdb->get_col( 'SHOW INDEX FROM ' . Schema::table( $wpdb->prefix, $table ), 2 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Nombre de tabla desde constantes.
		sort( $names );

		return $names;
	}

	private function autoload_of( string $option ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
	}

	private function own_autoloaded_options(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> 'magiclinking_settings' AND autoload IN ('yes','on','auto-on','auto')",
				$wpdb->esc_like( MAGICLINKING_PREFIX ) . '%'
			)
		);
	}

	private function drop_everything(): void {
		global $wpdb;

		foreach ( Schema::TABLES as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::table( $wpdb->prefix, $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
		}
		delete_option( Installer::DB_VERSION_OPTION );
		delete_option( Installer::SETTINGS_OPTION );
	}
}
