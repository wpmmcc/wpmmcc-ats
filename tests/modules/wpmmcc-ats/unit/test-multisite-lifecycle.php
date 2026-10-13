<?php
/**
 * Multisite Lifecycle Tests
 *
 * BUG-LC-01 remediation: subsites created after network activation must be
 * initialized via wp_initialize_site (tables, default options, cron, rewrite
 * flush), and the per-site plugin tables must be declared through the core
 * wpmu_drop_tables filter so subsite deletion does not orphan them.
 *
 * catalog: WP-CLASS-Plugin_Lifecycle
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.1.3
 */

use WPTSALL\Plugin_Lifecycle;

class Test_Multisite_Lifecycle extends WP_UnitTestCase {

	/**
	 * The wp_initialize_site hook must be registered so newly created
	 * subsites on network-active installs receive the table family.
	 */
	public function test_wp_initialize_site_hook_is_registered() {
		$this->assertNotFalse(
			has_action( 'wp_initialize_site', array( 'WPTSALL\Plugin_Lifecycle', 'handle_new_site' ) ),
			'wp_initialize_site must be hooked so new subsites get tables/options after network activation'
		);
	}

	/**
	 * The wpmu_drop_tables filter must be registered so core drops the
	 * per-site plugin tables when a subsite is deleted.
	 */
	public function test_wpmu_drop_tables_filter_is_registered() {
		$this->assertNotFalse(
			has_filter( 'wpmu_drop_tables', array( 'WPTSALL\Plugin_Lifecycle', 'filter_wpmu_drop_tables' ) ),
			'wpmu_drop_tables must be filtered so subsite deletion does not orphan plugin tables'
		);
	}

	/**
	 * Single-site installs: the handler must be a safe no-op (early return
	 * before any switch_to_blog attempt).
	 */
	public function test_handle_new_site_is_safe_on_single_site_installs() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'single-site guard is only observable on a single-site runner' );
		}
		Plugin_Lifecycle::handle_new_site( 12345 );
		// Reaching this line without a fatal error is the assertion.
		$this->assertTrue( true );
	}

	/**
	 * Invalid blog IDs must be rejected before any switch_to_blog attempt.
	 */
	public function test_handle_new_site_ignores_invalid_blog_ids() {
		Plugin_Lifecycle::handle_new_site( 0 );
		Plugin_Lifecycle::handle_new_site( -1 );
		$this->assertTrue( true );
	}

	/**
	 * The drop-tables filter must pass core tables through and append the
	 * full per-site plugin table family with the current blog prefix.
	 */
	public function test_filter_wpmu_drop_tables_appends_all_plugin_tables() {
		global $wpdb;

		$core_tables = array( $wpdb->prefix . 'posts', $wpdb->prefix . 'options' );
		$merged      = Plugin_Lifecycle::filter_wpmu_drop_tables( $core_tables );

		$this->assertIsArray( $merged );
		$this->assertContains( $wpdb->prefix . 'posts', $merged, 'core tables pass through untouched' );

		$expected = array(
			'wptsall_models',
			'wptsall_site_relations',
			'wptsall_virtual_sites',
			'wptsall_tasks',
			'wptsall_templates',
			'wptsall_translation_memory',
			'wptsall_terminology',
			'wptsall_post_mappings',
			'wptsall_languages',
		);
		foreach ( $expected as $table ) {
			$this->assertContains( $wpdb->prefix . $table, $merged, "{$table} must be in the subsite drop list" );
		}
	}

	/**
	 * Non-array input (defensive: other filters may pass garbage) must
	 * normalize to an array containing the plugin tables.
	 */
	public function test_filter_wpmu_drop_tables_normalizes_non_array_input() {
		$merged = Plugin_Lifecycle::filter_wpmu_drop_tables( null );
		$this->assertIsArray( $merged );
		$this->assertNotEmpty( $merged );
	}
}
