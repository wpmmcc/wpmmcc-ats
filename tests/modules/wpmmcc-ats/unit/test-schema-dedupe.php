<?php
/**
 * Schema Dedupe Tests
 *
 * Tests for the per-request schema work dedupe added in the 2026-09-11
 * audit remediation (create_tables repeated passes):
 * - wptsall_schema_table_exists(): cached SHOW TABLES existence check
 * - wptsall_schema_ensure_once(): named registrar runs once per request
 * - Plugin_Lifecycle::create_tables() is idempotent within a request
 *
 * @package WPTSALL
 * @since 2.1.4
 */

/**
 * Helpers live in the global namespace like the rest of the unit suite.
 */

class Test_Schema_Dedupe extends WP_UnitTestCase {

	/**
	 * Ensure-pass stamp cache key for a name (mirrors the helper).
	 *
	 * @param string $name Ensure-pass name.
	 * @return string
	 */
	private function stamp_key( $name ) {
		global $wpdb;
		return 'wptsall_schema_ensure_' . md5( $wpdb->prefix . '|' . $name );
	}

	/**
	 * Clean the stamps this test class creates.
	 */
	public function tearDown(): void {
		foreach ( array( 'x_dedupe_probe', 'y_missing_registrar', 'z_lifecycle_probe' ) as $name ) {
			wp_cache_delete( $this->stamp_key( $name ), 'wptsall_schema' );
		}
		parent::tearDown();
	}

	/**
	 * Cached existence check returns true for live tables, false for
	 * nonexistent ones, and is stable across repeated calls (memoized).
	 */
	public function test_schema_table_exists_truthful_and_stable() {
		$live = wptsall_table( 'models' );
		$this->assertTrue( wptsall_schema_table_exists( $live ), 'models table must exist after activation' );
		$this->assertTrue( wptsall_schema_table_exists( $live ), 'repeat call must return the same (memoized) result' );

		$missing = 'wptsall_definitely_missing_table_' . uniqid();
		$this->assertFalse( wptsall_schema_table_exists( $missing ) );
		$this->assertFalse( wptsall_schema_table_exists( $missing ), 'memoized negative result must stay stable' );
	}

	/**
	 * ensure_once runs the registrar on first call and skips on the second;
	 * clearing the stamp re-arms it (per-request semantics).
	 */
	public function test_schema_ensure_once_runs_once_until_stamp_cleared() {
		wp_cache_delete( $this->stamp_key( 'x_dedupe_probe' ), 'wptsall_schema' );

		$ran_first = wptsall_schema_ensure_once( 'x_dedupe_probe', 'wptsall_ensure_templates_tables' );
		$this->assertTrue( $ran_first, 'first call with a loaded registrar must run it' );

		$ran_second = wptsall_schema_ensure_once( 'x_dedupe_probe', 'wptsall_ensure_templates_tables' );
		$this->assertFalse( $ran_second, 'second call in the same request must skip (stamped)' );

		wp_cache_delete( $this->stamp_key( 'x_dedupe_probe' ), 'wptsall_schema' );
		$ran_third = wptsall_schema_ensure_once( 'x_dedupe_probe', 'wptsall_ensure_templates_tables' );
		$this->assertTrue( $ran_third, 'clearing the stamp re-arms the pass' );
	}

	/**
	 * A registrar that is not loaded must NOT stamp the name — a later pass
	 * (activation runs create_tables before modules load) can still run it.
	 */
	public function test_schema_ensure_once_does_not_stamp_missing_function() {
		$result = wptsall_schema_ensure_once( 'y_missing_registrar', 'wptsall_function_that_does_not_exist_xyz' );

		$this->assertFalse( $result, 'missing registrar must be a no-op' );
		$this->assertFalse( wp_cache_get( $this->stamp_key( 'y_missing_registrar' ), 'wptsall_schema' ), 'missing registrar must not leave a stamp' );
	}

	/**
	 * Plugin_Lifecycle::create_tables() (private) is safe to invoke twice in
	 * one request: the second pass is deduped and leaves the table family
	 * intact (activation does exactly this double pass, P1-TEST-03).
	 */
	public function test_lifecycle_create_tables_double_pass_is_safe() {
		$method = new ReflectionMethod( 'WPTSALL\Plugin_Lifecycle', 'create_tables' );
		$method->setAccessible( true );

		$method->invoke( null );
		$method->invoke( null );

		foreach ( array( 'models', 'plugin_mappings', 'site_relations', 'templates', 'translation_memory' ) as $key ) {
			$this->assertTrue(
				wptsall_schema_table_exists( wptsall_table( $key ) ),
				"table family member '{$key}' must exist after a double create_tables pass"
			);
		}
	}
}
