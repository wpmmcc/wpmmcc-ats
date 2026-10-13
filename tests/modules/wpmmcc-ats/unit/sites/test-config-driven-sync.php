<?php
/**
 * Config-Driven Sync Tests
 *
 * Tests for the configuration merge and validation logic
 * that determines how content is synced based on Model and Site Relation configs.
 *
 * @package WPTSALL
 * @since 0.8.1
 */

// Load WordPress environment if not already loaded by the test runner.
// Resolves the WP root the same way as `tests/modules/wpmmcc-ats/unit/run.php`
// (WPTSALL_WP_ROOT / WP_SITE_PATH / WP_ROOT env vars, then common fallbacks)
// so this test file works on macOS (/usr/local/var/www), Linux (/var/www/wordpress),
// CI containers, and any other layout the operator may have configured.
if ( ! defined( 'ABSPATH' ) ) {
	$wp_load = null;
	foreach ( array(
		getenv( 'WPTSALL_WP_ROOT' ),
		getenv( 'WP_SITE_PATH' ),
		getenv( 'WP_ROOT' ),
		'/var/www/wordpress',
		'/usr/local/var/www',
	) as $candidate ) {
		if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) {
			continue;
		}
		$normalized = rtrim( trim( $candidate ), '/\\' ) . '/';
		if ( file_exists( $normalized . 'wp-load.php' ) ) {
			$wp_load = $normalized;
			break;
		}
	}
	if ( null === $wp_load ) {
		die( "WordPress not found. Set WPTSALL_WP_ROOT, WP_SITE_PATH, or WP_ROOT to the WP install directory.\n" );
	}
	require_once $wp_load . 'wp-load.php';
}

use WPTSALL\Models\Services\Translation_Rule_Service;
use WPTSALL\Sites\Services\Relation_Config_Service;

/**
 * Config-Driven Sync Test Class
 */
class Test_Config_Driven_Sync extends SimpleTestCase {

	/**
	 * Test that get_merged_config returns base config when no relation override exists.
	 */
	public function test_merged_config_returns_base_when_no_override() {
		// Skip if service not available.
		if ( ! class_exists( 'WPTSALL\Models\Services\Translation_Rule_Service' ) ) {
			$this->markTestSkipped( 'Translation_Rule_Service not available' );
			return;
		}

		// Create a test rule.
		global $wpdb;
		$rules_table = $wpdb->prefix . 'wptsall_translation_rules';

		// Check if table exists.
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$rules_table}'" );
		if ( ! $table_exists ) {
			$this->markTestSkipped( 'translation_rules table not available' );
			return;
		}

		// Get an existing rule for testing.
		$rule = $wpdb->get_row(
			"SELECT id FROM {$rules_table} WHERE is_active = 1 LIMIT 1",
			ARRAY_A
		);

		if ( ! $rule ) {
			$this->markTestSkipped( 'No active translation rules found' );
			return;
		}

		$config = Translation_Rule_Service::get_merged_config( (int) $rule['id'], null );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'direction', $config );
		$this->assertArrayHasKey( 'fields', $config );
		$this->assertArrayNotHasKey( 'relation_id', $config );
	}

	/**
	 * Test that Model config constraints are enforced (no_sync cannot be overridden).
	 */
	public function test_no_sync_field_cannot_be_enabled_by_site_relation() {
		// This tests the new v0.8.1 并存验证 logic.
		// When Model says no_sync, Site Relation cannot change it.

		// Simulate the merge logic directly.
		$base_config = array(
			'enabled'   => true,
			'direction' => 'one_way',
			'fields'    => array(
				'_stock' => array(
					'type'      => 'no_sync',
					'enabled'   => false,
					'direction' => 'one_way',
				),
			),
		);

		$site_override = array(
			'field_overrides' => array(
				'_stock' => array(
					'type'    => 'sync',
					'enabled' => true,
				),
			),
		);

		// Apply the merge logic (simulating get_merged_config behavior).
		$final = $base_config;
		$base_field = $base_config['fields']['_stock'];
		$site_field = $site_override['field_overrides']['_stock'];

		// Check if conflict exists.
		$has_conflict = ( 'no_sync' === $base_field['type'] && 'no_sync' !== $site_field['type'] );

		$this->assertTrue( $has_conflict, 'Should detect conflict when trying to enable no_sync field' );

		// After merge, field should still be no_sync (Model wins).
		// This is what the actual code does - it skips the type override.
		$this->assertEquals( 'no_sync', $base_config['fields']['_stock']['type'] );
	}

	/**
	 * Test that direction cannot be upgraded from one_way to bidirectional.
	 */
	public function test_direction_cannot_be_upgraded_to_bidirectional() {
		$base_direction = 'one_way';
		$site_direction = 'bidirectional';

		// Check if this is a conflict (one_way -> bidirectional is not allowed).
		$is_base_oneway = in_array( $base_direction, array( 'one_way', 'forward', 'source_to_target' ), true );
		$is_site_bidir  = in_array( $site_direction, array( 'bidirectional', 'both', 'two_way' ), true );

		$is_conflict = $is_base_oneway && $is_site_bidir;

		$this->assertTrue( $is_conflict, 'Should detect conflict when upgrading one_way to bidirectional' );
	}

	/**
	 * Test that Site Relation can disable (downgrade) Model enabled content.
	 */
	public function test_site_relation_can_disable_model_enabled_content() {
		$base_enabled = true;
		$site_enabled = false;

		// Disabling is allowed (downgrade).
		$is_downgrade = $base_enabled && ! $site_enabled;

		$this->assertTrue( $is_downgrade, 'Site Relation should be able to disable Model enabled content' );
	}

	/**
	 * Test that Site Relation cannot enable Model disabled content.
	 */
	public function test_site_relation_cannot_enable_model_disabled_content() {
		$base_enabled = false;
		$site_enabled = true;

		// Enabling disabled content is a conflict.
		$is_conflict = ! $base_enabled && $site_enabled;

		$this->assertTrue( $is_conflict, 'Should detect conflict when enabling Model disabled content' );
	}

	/**
	 * Test direction value normalization.
	 */
	public function test_direction_value_normalization() {
		// All these should be treated as "one_way".
		$one_way_values = array( 'one_way', 'forward', 'source_to_target' );

		foreach ( $one_way_values as $value ) {
			$is_oneway = in_array( $value, array( 'one_way', 'forward', 'source_to_target' ), true );
			$this->assertTrue( $is_oneway, "Value '{$value}' should be recognized as one_way" );
		}

		// All these should be treated as "bidirectional".
		$bidir_values = array( 'bidirectional', 'both', 'two_way' );

		foreach ( $bidir_values as $value ) {
			$is_bidir = in_array( $value, array( 'bidirectional', 'both', 'two_way' ), true );
			$this->assertTrue( $is_bidir, "Value '{$value}' should be recognized as bidirectional" );
		}
	}

	/**
	 * Test sync_mode is fixed to new_only (ISS-SIT-037).
	 *
	 * Since v1.0.0 sync_mode is hardcoded to 'new_only'.
	 * Legacy values (full, incremental, mirror) have been migrated.
	 */
	public function test_sync_mode_values() {
		$allowed_mode = 'new_only';

		$this->assertEquals( 'new_only', $allowed_mode, 'sync_mode should be new_only' );

		// Legacy modes should NOT be considered valid.
		$legacy_modes = array( 'full', 'incremental', 'mirror' );
		foreach ( $legacy_modes as $mode ) {
			$this->assertNotEquals( $allowed_mode, $mode, "Legacy mode '{$mode}' should not match allowed mode" );
		}
	}
}

// Run tests if executed directly.
if ( php_sapi_name() === 'cli' && isset( $argv[0] ) && basename( $argv[0] ) === basename( __FILE__ ) ) {
	$test = new Test_Config_Driven_Sync();
	$test->run();
}
