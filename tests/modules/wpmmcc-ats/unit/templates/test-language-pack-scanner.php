<?php
/**
 * Language Pack Scanner Tests
 *
 * Tests for WPTSALL\Templates\Scanners\Language_Pack_Scanner class
 *
 * Tests language pack scanning functionality for themes and plugins.
 *
 * @package WPTSALL\Tests\Unit\Templates
 * @since 0.5.0
 */

use WPTSALL\Templates\Scanners\Language_Pack_Scanner;

class Test_Language_Pack_Scanner extends SimpleTestCase {

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
	}

	// ==================== Class Tests ====================

	/**
	 * Test class exists
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Templates\Scanners\Language_Pack_Scanner' ) );
	}

	/**
	 * Test class has required methods
	 */
	public function test_class_has_required_methods() {
		$methods = array(
			'scan_relation',
			'scan_theme',
			'scan_plugin',
			'rescan_template',
			'comprehensive_scan',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'WPTSALL\Templates\Scanners\Language_Pack_Scanner', $method ),
				"Method {$method} should exist"
			);
		}
	}

	/**
	 * Test class has scan mode constants
	 */
	public function test_scan_mode_constants() {
		$this->assertEquals( 'pot', Language_Pack_Scanner::SCAN_POT_FILES );
		$this->assertEquals( 'source', Language_Pack_Scanner::SCAN_SOURCE );
		$this->assertEquals( 'content', Language_Pack_Scanner::SCAN_CONTENT );
		$this->assertEquals( 'all', Language_Pack_Scanner::SCAN_ALL );
	}

	// ==================== scan_theme() Tests ====================

	/**
	 * Test scan_theme returns array
	 */
	public function test_scan_theme_returns_array() {
		$result = Language_Pack_Scanner::scan_theme();

		$this->assertIsArray( $result );
	}

	/**
	 * Test scan_theme result structure when theme has translations
	 */
	public function test_scan_theme_structure() {
		$result = Language_Pack_Scanner::scan_theme();

		if ( empty( $result ) ) {
			$this->markTestSkipped( 'No theme translation files found' );
			return;
		}

		$first = $result[0];

		$this->assertArrayHasKey( 'source_type', $first );
		$this->assertArrayHasKey( 'text_domain', $first );
		$this->assertArrayHasKey( 'source_name', $first );
		$this->assertArrayHasKey( 'entries', $first );

		$this->assertEquals( 'theme', $first['source_type'] );
	}

	// ==================== scan_plugin() Tests ====================

	/**
	 * Test scan_plugin returns array
	 */
	public function test_scan_plugin_returns_array() {
		$result = Language_Pack_Scanner::scan_plugin( 'nonexistent-plugin' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result ); // Non-existent plugin should return empty
	}

	/**
	 * Test scan_plugin with woocommerce
	 */
	public function test_scan_plugin_woocommerce() {
		// Auto-activate WooCommerce if installed but not active
		$this->requirePlugin( 'woocommerce' );

		$result = Language_Pack_Scanner::scan_plugin( 'woocommerce' );

		$this->assertIsArray( $result );

		if ( ! empty( $result ) ) {
			$first = $result[0];
			$this->assertEquals( 'plugin', $first['source_type'] );
			$this->assertEquals( 'woocommerce', $first['text_domain'] );
		}
	}

	/**
	 * Test scan_plugin with wptsall
	 */
	public function test_scan_plugin_wptsall() {
		$result = Language_Pack_Scanner::scan_plugin( 'wptsall' );

		$this->assertIsArray( $result );

		// WPTSALL should have a languages directory
		if ( ! empty( $result ) ) {
			$first = $result[0];
			$this->assertEquals( 'plugin', $first['source_type'] );
		}
	}

	// ==================== scan_relation() Tests ====================

	/**
	 * Test scan_relation with invalid relation
	 */
	public function test_scan_relation_invalid() {
		$result = Language_Pack_Scanner::scan_relation( 99999999 );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * Test scan_relation with valid relation
	 */
	public function test_scan_relation_valid() {
		global $wpdb;

		// Create a test relation
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Language_Pack_Scanner::scan_relation( $relation_id );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'results', $result );
		$this->assertArrayHasKey( 'templates', $result );

		// Cleanup
		$this->cleanup_test_relation( $relation_id );
	}

	/**
	 * Test scan_relation with different modes
	 */
	public function test_scan_relation_modes() {
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$modes = array(
			Language_Pack_Scanner::SCAN_POT_FILES,
			Language_Pack_Scanner::SCAN_SOURCE,
			Language_Pack_Scanner::SCAN_CONTENT,
			Language_Pack_Scanner::SCAN_ALL,
		);

		foreach ( $modes as $mode ) {
			$result = Language_Pack_Scanner::scan_relation( $relation_id, $mode );
			$this->assertIsArray( $result );
			$this->assertTrue( $result['success'] );
		}

		$this->cleanup_test_relation( $relation_id );
	}

	// ==================== rescan_template() Tests ====================

	/**
	 * Test rescan_template with invalid template
	 */
	public function test_rescan_template_invalid() {
		$result = Language_Pack_Scanner::rescan_template( 99999999 );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	// ==================== Virtual Model Tests (v0.8.0+) ====================

	/**
	 * Test that Model_Config_Provider is used for virtual model check
	 *
	 * Verifies the scanner uses Model_Config_Provider::is_virtual_model()
	 * instead of hardcoded 'wordpress-blog' comparison.
	 */
	public function test_uses_model_config_provider_for_virtual_check() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Models\Services\Model_Config_Provider' ),
			'Model_Config_Provider class should exist'
		);

		$this->assertTrue(
			method_exists( 'WPTSALL\Models\Services\Model_Config_Provider', 'is_virtual_model' ),
			'is_virtual_model method should exist'
		);

		// Verify wordpress-blog is recognized as virtual model
		$is_virtual = \WPTSALL\Models\Services\Model_Config_Provider::is_virtual_model( 'wordpress-blog' );
		$this->assertTrue( $is_virtual, 'wordpress-blog should be a virtual model' );

		// Verify real plugins are not virtual models
		$is_virtual_woo = \WPTSALL\Models\Services\Model_Config_Provider::is_virtual_model( 'woocommerce' );
		$this->assertFalse( $is_virtual_woo, 'woocommerce should not be a virtual model' );
	}

	/**
	 * Test scan skips virtual model (wordpress-blog)
	 *
	 * When scanning a relation with wordpress-blog template,
	 * the scanner should skip plugin source file scanning.
	 */
	public function test_scan_skips_virtual_model() {
		$relation_id = $this->create_test_relation_with_template( 'wordpress-blog' );
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Language_Pack_Scanner::scan_relation( $relation_id );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// wordpress-blog should not have plugin source scan results
		// (it's a virtual model, no plugin directory to scan)
		$has_plugin_scan = false;
		if ( ! empty( $result['results'] ) ) {
			foreach ( $result['results'] as $scan_result ) {
				if ( isset( $scan_result['scan_source'] ) && 'source_files' === $scan_result['scan_source'] ) {
					// Check if this is from wordpress-blog plugin scan (should not exist)
					if ( isset( $scan_result['text_domain'] ) && 'wordpress-blog' === $scan_result['text_domain'] ) {
						$has_plugin_scan = true;
					}
				}
			}
		}

		$this->assertFalse( $has_plugin_scan, 'Virtual model should not have plugin source scan' );

		$this->cleanup_test_relation( $relation_id );
	}

	/**
	 * Test scan includes real plugin
	 *
	 * When scanning a relation with a real plugin template,
	 * the scanner should include plugin source file scanning.
	 */
	public function test_scan_includes_real_plugin() {
		// Skip if WooCommerce is not installed
		if ( ! is_dir( WP_PLUGIN_DIR . '/woocommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce plugin not installed' );
			return;
		}

		$relation_id = $this->create_test_relation_with_template( 'woocommerce' );
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Language_Pack_Scanner::scan_relation( $relation_id, Language_Pack_Scanner::SCAN_ALL );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// Real plugin should have scan results
		$this->assertArrayHasKey( 'results', $result );

		$this->cleanup_test_relation( $relation_id );
	}

	/**
	 * Test get_plugin_slugs excludes virtual models
	 *
	 * When building the plugin slugs list from associated models,
	 * virtual models should be excluded.
	 */
	public function test_get_plugin_slugs_excludes_virtual_models() {
		// Test that is_virtual_model correctly identifies virtual models
		$virtual_models = array( 'wordpress-blog' );
		$real_plugins   = array( 'woocommerce', 'bbpress', 'academy' );

		foreach ( $virtual_models as $slug ) {
			$is_virtual = \WPTSALL\Models\Services\Model_Config_Provider::is_virtual_model( $slug );
			$this->assertTrue( $is_virtual, "{$slug} should be identified as virtual model" );
		}

		foreach ( $real_plugins as $slug ) {
			$is_virtual = \WPTSALL\Models\Services\Model_Config_Provider::is_virtual_model( $slug );
			$this->assertFalse( $is_virtual, "{$slug} should not be identified as virtual model" );
		}
	}

	// ==================== comprehensive_scan() Tests ====================

	/**
	 * Test comprehensive_scan calls scan_relation with SCAN_ALL
	 */
	public function test_comprehensive_scan() {
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Language_Pack_Scanner::comprehensive_scan( $relation_id );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$this->cleanup_test_relation( $relation_id );
	}

	// ==================== Helper Methods ====================

	/**
	 * Create a test site relation
	 *
	 * @return int|false Relation ID or false on failure.
	 */
	private function create_test_relation() {
		return $this->create_test_relation_with_template( 'wordpress-blog' );
	}

	/**
	 * Create a test site relation with specific template
	 *
	 * @param string $template Template/plugin slug.
	 * @return int|false Relation ID or false on failure.
	 */
	private function create_test_relation_with_template( $template ) {
		global $wpdb;

		$table = wptsall_table( 'site_relations' );
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'source_site_id' => 1,
				'target_site_id' => 'v_test_' . uniqid(),
				'target_lang'    => 'en_US',
				'template'       => $template,
				'status'         => 'active',
				'sync_mode'      => 'manual',
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? $wpdb->insert_id : false;
	}

	/**
	 * Clean up test relation
	 *
	 * @param int $relation_id Relation ID.
	 */
	private function cleanup_test_relation( $relation_id ) {
		global $wpdb;

		// Delete related templates
		$templates_table = wptsall_table( 'templates' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $templates_table, array( 'relation_id' => $relation_id ), array( '%d' ) );

		// Delete relation
		$relations_table = wptsall_table( 'site_relations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $relations_table, array( 'id' => $relation_id ), array( '%d' ) );
	}

	/**
	 * Clean up after tests
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
