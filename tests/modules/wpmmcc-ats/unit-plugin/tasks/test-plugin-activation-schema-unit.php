<?php
/**
 * Plugin activation DB schema safety tests.
 *
 * @package WPTSALL
 */

class Test_Plugin_Activation_Schema_Unit extends WP_UnitTestCase {

	/**
	 * Ensure task schema files are available.
	 *
	 * @return void
	 */
	private static function ensure_schema_loaded() {
		$task_candidates = array(
			WP_PLUGIN_DIR . '/wptsall/includes/tasks/database/',
			WP_PLUGIN_DIR . '/wptsall-pro/includes/tasks/database/',
		);
		$template_candidates = array(
			WP_PLUGIN_DIR . '/wptsall/includes/templates/database/',
			WP_PLUGIN_DIR . '/wptsall-pro/includes/templates/database/',
		);
		$model_candidates = array(
			WP_PLUGIN_DIR . '/wptsall/includes/models/database/',
			WP_PLUGIN_DIR . '/wptsall-pro/includes/models/database/',
		);

		$schema_dir = '';
		foreach ( $task_candidates as $candidate ) {
			if ( is_dir( $candidate ) ) {
				$schema_dir = $candidate;
				break;
			}
		}

		if ( '' === $schema_dir ) {
			self::fail( 'Unable to locate tasks schema directory under WP_PLUGIN_DIR.' );
		}

		$template_schema_dir = '';
		foreach ( $template_candidates as $candidate ) {
			if ( is_dir( $candidate ) ) {
				$template_schema_dir = $candidate;
				break;
			}
		}

		if ( '' === $template_schema_dir ) {
			self::fail( 'Unable to locate templates schema directory under WP_PLUGIN_DIR.' );
		}

		$model_schema_dir = '';
		foreach ( $model_candidates as $candidate ) {
			if ( is_dir( $candidate ) ) {
				$model_schema_dir = $candidate;
				break;
			}
		}

		if ( '' === $model_schema_dir ) {
			self::fail( 'Unable to locate models schema directory under WP_PLUGIN_DIR.' );
		}

		if ( ! function_exists( 'wptsall_create_tasks_table' ) ) {
			require_once $schema_dir . 'schema-tasks.php';
		}

		if ( ! function_exists( 'wptsall_create_manual_queue_table' ) ) {
			require_once $schema_dir . 'schema-manual-queue.php';
		}

		if ( ! function_exists( 'wptsall_create_translation_results_table' ) ) {
			require_once $schema_dir . 'schema-translation-results.php';
		}

		if ( ! function_exists( 'wptsall_create_origin_visits_table' ) ) {
			require_once $schema_dir . 'schema-origin-visits.php';
		}

		if ( ! function_exists( 'wptsall_create_templates_table' ) ) {
			require_once $template_schema_dir . 'schema-templates.php';
		}

		if ( ! function_exists( 'wptsall_create_post_mappings_table' ) ) {
			require_once $model_schema_dir . 'schema-field-mappings.php';
		}
	}

	/**
	 * Prepare test environment.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		self::ensure_schema_loaded();
	}

	/**
	 * Capture CREATE TABLE SQL passed into dbDelta without executing DDL.
	 *
	 * @param callable $callback Function that invokes dbDelta.
	 * @return array<int,string> Captured SQL queries.
	 */
	private function capture_dbdelta_create_queries( $callback ) {
		$captured = array();

		$filter = function( $queries ) use ( &$captured ) {
			foreach ( $queries as $query ) {
				$captured[] = $query;
			}

			// Prevent actual CREATE/ALTER execution during this unit test.
			return array();
		};

		add_filter( 'dbdelta_create_queries', $filter, 9999 );
		try {
			call_user_func( $callback );
		} finally {
			remove_filter( 'dbdelta_create_queries', $filter, 9999 );
		}

		$this->assertNotEmpty( $captured, 'Expected dbDelta create query to be captured.' );
		return $captured;
	}

	/**
	 * Capture a single CREATE TABLE SQL statement passed into dbDelta.
	 *
	 * @param callable $callback Function that invokes dbDelta.
	 * @return string Captured SQL query.
	 */
	private function capture_dbdelta_create_query( $callback ) {
		$queries = $this->capture_dbdelta_create_queries( $callback );
		return (string) $queries[0];
	}

	/**
	 * Assert CREATE TABLE field/index section has no blank lines.
	 *
	 * Empty lines are parsed as anonymous indexes by dbDelta on modern WP, which
	 * can trigger noisy activation warnings and invalid ALTER TABLE statements.
	 *
	 * @param string $sql CREATE TABLE SQL.
	 * @return void
	 */
	private function assert_schema_has_no_blank_lines( $sql ) {
		$matches = array();
		$this->assertSame(
			1,
			preg_match( '/\((.*)\)\s+[^\)]*ENGINE=/ms', $sql, $matches ),
			'Expected to parse CREATE TABLE body.'
		);

		$lines = explode( "\n", trim( $matches[1] ) );
		foreach ( $lines as $line_no => $line ) {
			$normalized = trim( $line, " \t\n\r\0\x0B," );
			$this->assertNotSame(
				'',
				$normalized,
				'Blank schema line detected in dbDelta body at line offset ' . $line_no
			);
		}
	}

	/**
	 * Tasks table schema should not contain empty lines in dbDelta input.
	 *
	 * @return void
	 */
	public function test_tasks_table_schema_has_no_blank_lines_for_dbdelta() {
		$sql = $this->capture_dbdelta_create_query( 'wptsall_create_tasks_table' );
		$this->assert_schema_has_no_blank_lines( $sql );
	}

	/**
	 * Manual queue table schema should not contain empty lines in dbDelta input.
	 *
	 * @return void
	 */
	public function test_manual_queue_schema_has_no_blank_lines_for_dbdelta() {
		$sql = $this->capture_dbdelta_create_query( 'wptsall_create_manual_queue_table' );
		$this->assert_schema_has_no_blank_lines( $sql );
	}

	/**
	 * Origin visits schema should not contain empty lines in dbDelta input.
	 *
	 * @return void
	 */
	public function test_origin_visits_schema_has_no_blank_lines_for_dbdelta() {
		$sql = $this->capture_dbdelta_create_query( 'wptsall_create_origin_visits_table' );
		$this->assert_schema_has_no_blank_lines( $sql );
	}

	/**
	 * Plugin activation schema path should include a safe origin_visits query.
	 *
	 * @return void
	 */
	public function test_plugin_activation_path_captures_safe_origin_visits_schema() {
		$queries = $this->capture_dbdelta_create_queries( 'wptsall_init_tasks_tables' );
		$origin  = '';

		foreach ( $queries as $query ) {
			if ( false !== strpos( $query, 'origin_visits' ) ) {
				$origin = (string) $query;
				break;
			}
		}

		$this->assertNotSame( '', $origin, 'Expected origin_visits schema query during activation.' );
		$this->assert_schema_has_no_blank_lines( $origin );
	}

	/**
	 * Task schema should include a composite index for client claim/list scans.
	 *
	 * @return void
	 */
	public function test_tasks_schema_includes_claim_list_index() {
		$sql = $this->capture_dbdelta_create_query( 'wptsall_create_tasks_table' );
		$this->assertStringContainsString( 'KEY status_type_created_at (status, type, created_at)', $sql );
		$this->assertStringContainsString( 'KEY idx_task_identity_status (site_id, template, object_type, subtype, object_id, status)', $sql );
		$this->assertStringNotContainsString( 'UNIQUE KEY uniq_task', $sql );
	}

	/**
	 * Template schemas should include composite indexes for language-pack claim/list flows.
	 *
	 * @return void
	 */
	public function test_template_schemas_include_query_optimization_indexes() {
		$templates_sql = $this->capture_dbdelta_create_query( 'wptsall_create_templates_table' );
		$entries_sql   = $this->capture_dbdelta_create_query( 'wptsall_create_template_entries_table' );

		$this->assertStringContainsString( 'INDEX idx_relation_source_type_id (relation_id, source_type, id)', $templates_sql );
		$this->assertStringContainsString( 'INDEX idx_template_status_claimed_id (template_id, status, claimed_at, id)', $entries_sql );
	}

	/**
	 * Mapping schemas should include composite indexes for placeholder claim lookups.
	 *
	 * @return void
	 */
	public function test_mapping_schemas_include_claim_lookup_indexes() {
		$term_sql = $this->capture_dbdelta_create_query( 'wptsall_create_term_mappings_table' );
		$post_sql = $this->capture_dbdelta_create_query( 'wptsall_create_post_mappings_table' );

		$this->assertStringContainsString( 'INDEX idx_relation_term_claimed (relation_id, source_term_id, source_taxonomy, source_site_id, target_site_id, target_lang, claimed_at)', $term_sql );
		$this->assertStringContainsString( 'INDEX idx_relation_source_claimed (relation_id, source_post_id, source_post_type, source_site_id, target_site_id, claimed_at)', $post_sql );
	}

	/**
	 * Translation results schema should support status/created_at sync scans.
	 *
	 * @return void
	 */
	public function test_translation_results_schema_includes_status_created_index() {
		$sql = $this->capture_dbdelta_create_query( 'wptsall_create_translation_results_table' );
		$this->assertStringContainsString( 'KEY status_created_at (status, created_at)', $sql );
	}

	/**
	 * Migration registry should include the relation_id backfill version.
	 *
	 * @return void
	 */
	public function test_migration_registry_includes_mapping_relation_backfill() {
		$migration_candidates = array(
			WP_PLUGIN_DIR . '/wptsall/includes/core/database/migrations.php',
			WP_PLUGIN_DIR . '/wptsall-pro/includes/core/database/migrations.php',
		);
		$migration_file       = '';
		foreach ( $migration_candidates as $candidate ) {
			if ( is_file( $candidate ) ) {
				$migration_file = $candidate;
				break;
			}
		}

		$this->assertNotSame( '', $migration_file, 'Expected migrations.php to exist.' );
		$source = (string) file_get_contents( $migration_file );

		$this->assertStringContainsString( "\$plugin_version  = '1.2.4';", $source );
		$this->assertStringContainsString( "version_compare( \$current_version, '1.2.4', '<' )", $source );
		$this->assertStringContainsString( 'wptsall_migrate_backfill_mapping_relation_ids_v124', $source );
	}
}
