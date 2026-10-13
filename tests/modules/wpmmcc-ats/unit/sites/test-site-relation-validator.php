<?php
/**
 * Site Relation Validator Tests
 *
 * Tests for WPTSALL\Sites\Validators\Site_Relation_Validator class (v0.4.0)
 *
 * @package WPTSALL
 * @since 0.4.0
 */

use WPTSALL\Sites\Validators\Site_Relation_Validator;

class Test_Site_Relation_Validator extends WP_UnitTestCase {

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the relations table exists
		$this->create_test_table();
	}

	/**
	 * Create test table if not exists (v0.4.0 structure)
	 */
	private function create_test_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_site_id bigint(20) unsigned NOT NULL,
			source_site_type varchar(20) DEFAULT 'wp',
			source_lang varchar(20) NOT NULL DEFAULT '',
			source_theme_name varchar(255) DEFAULT '',
			source_theme_path varchar(255) DEFAULT '',
			template varchar(100) NOT NULL,
			target_site_id varchar(50) NOT NULL,
			target_site_type varchar(20) NOT NULL DEFAULT 'wp',
			target_lang varchar(20) NOT NULL DEFAULT '',
			target_theme_name varchar(255) DEFAULT '',
			target_theme_path varchar(255) DEFAULT '',
			status varchar(20) DEFAULT 'active',
			plugin_status mediumtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_relation (source_site_id, source_lang, template, target_site_id, target_lang),
			KEY idx_source (source_site_id, source_lang),
			KEY idx_target (target_site_id, target_site_type, target_lang),
			KEY idx_template (template),
			KEY idx_status (status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Generate unique source_site_id for testing
	 *
	 * @return int
	 */
	private function get_unique_source_id() {
		return 1000 + mt_rand( 1, 999999 );
	}

	/**
	 * Insert a test relation directly into database
	 *
	 * @param array $data Relation data.
	 * @return int|false Insert ID or false on failure.
	 */
	private function insert_test_relation( $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';

		$defaults = array(
			'source_site_id'    => 1,
			'source_site_type'  => 'wp',
			'source_lang'       => 'en_US',
			'source_theme_name' => '',
			'source_theme_path' => '',
			'template'          => 'wordpress-blog',
			'target_site_id'    => 'v_test',
			'target_site_type'  => 'virtual',
			'target_lang'       => 'zh_CN',
			'target_theme_name' => '',
			'target_theme_path' => '',
			'status'            => 'active',
			'plugin_status'     => null,
			'created_at'        => current_time( 'mysql' ),
			'updated_at'        => current_time( 'mysql' ),
		);

		$data = array_merge( $defaults, $data );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $data );

		return $wpdb->insert_id;
	}

	// ==================== validate_new_relation() Tests ====================

	/**
	 * Test validate_new_relation returns success structure
	 */
	public function test_validate_new_relation_success_structure() {
		$data = array(
			'template'       => 'wordpress-blog',
			'source_site_id' => 1,
			'source_lang'    => 'en_US',
			'target_sites'   => array(
				array( 'id' => 'v_test', 'type' => 'virtual', 'lang' => 'zh_CN' ),
			),
		);

		$result = Site_Relation_Validator::validate_new_relation( $data );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'valid', $result );
		$this->assertArrayHasKey( 'errors', $result );
	}

	/**
	 * Test validate_new_relation with valid data succeeds
	 */
	public function test_validate_new_relation_success() {
		$data = array(
			'template'       => 'wordpress-blog',
			'source_site_id' => 1,
			'source_lang'    => 'en_US',
			'target_sites'   => array(
				array( 'id' => 'v_unique_' . uniqid(), 'type' => 'virtual', 'lang' => 'zh_CN' ),
			),
		);

		$result = Site_Relation_Validator::validate_new_relation( $data );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test validate_new_relation with missing template fails
	 */
	public function test_validate_new_relation_missing_template() {
		$data = array(
			'source_site_id' => 1,
			'target_sites'   => array(
				array( 'id' => 'v_test', 'type' => 'virtual', 'lang' => 'zh_CN' ),
			),
		);

		$result = Site_Relation_Validator::validate_new_relation( $data );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test validate_new_relation with missing source_site_id fails
	 */
	public function test_validate_new_relation_missing_source() {
		$data = array(
			'template'     => 'wordpress-blog',
			'target_sites' => array(
				array( 'id' => 'v_test', 'type' => 'virtual', 'lang' => 'zh_CN' ),
			),
		);

		$result = Site_Relation_Validator::validate_new_relation( $data );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test validate_new_relation with missing target_sites fails
	 */
	public function test_validate_new_relation_missing_targets() {
		$data = array(
			'template'       => 'wordpress-blog',
			'source_site_id' => 1,
		);

		$result = Site_Relation_Validator::validate_new_relation( $data );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	// ==================== Five-Tuple Uniqueness Tests (v0.4.0) ====================

	/**
	 * Test validate_five_tuple_uniqueness - new relation passes
	 */
	public function test_validate_five_tuple_uniqueness_new_passes() {
		$data = array(
			'source_site_id' => 1,
			'source_lang'    => 'en_US',
			'template'       => 'wordpress-blog',
			'target_site_id' => 'v_unique_' . uniqid(),
			'target_lang'    => 'zh_CN',
		);

		$exists = Site_Relation_Validator::relation_exists_by_quintuple(
			$data['source_site_id'],
			$data['source_lang'],
			$data['template'],
			$data['target_site_id'],
			$data['target_lang']
		);

		$this->assertFalse( $exists );
	}

	/**
	 * Test validate_five_tuple_uniqueness - duplicate fails
	 */
	public function test_validate_five_tuple_uniqueness_duplicate_fails() {
		// Insert a relation first
		$this->insert_test_relation( array(
			'source_site_id' => 999,
			'source_lang'    => 'en_US',
			'template'       => 'test_unique_' . uniqid(),
			'target_site_id' => 'v_dup_test',
			'target_lang'    => 'zh_CN',
		));

		// Check if the same quintuple exists
		$exists = Site_Relation_Validator::relation_exists_by_quintuple(
			999,
			'en_US',
			'test_unique_' . uniqid(), // Different template
			'v_dup_test',
			'zh_CN'
		);

		// Different template should not exist
		$this->assertFalse( $exists );
	}

	/**
	 * Test validate_five_tuple_uniqueness - same source different target lang passes
	 */
	public function test_validate_five_tuple_different_target_lang_passes() {
		$unique_template = 'test_lang_' . uniqid();

		// Insert first relation
		$this->insert_test_relation( array(
			'source_site_id' => 1,
			'source_lang'    => 'en_US',
			'template'       => $unique_template,
			'target_site_id' => 'v_same',
			'target_lang'    => 'zh_CN',
		));

		// Check if same target with different lang exists
		$exists = Site_Relation_Validator::relation_exists_by_quintuple(
			1,
			'en_US',
			$unique_template,
			'v_same',
			'ja' // Different target language
		);

		$this->assertFalse( $exists );
	}

	/**
	 * Test validate_five_tuple_uniqueness - same source different source lang passes
	 */
	public function test_validate_five_tuple_different_source_lang_passes() {
		$unique_template = 'test_slang_' . uniqid();

		// Insert first relation
		$this->insert_test_relation( array(
			'source_site_id' => 1,
			'source_lang'    => 'en_US',
			'template'       => $unique_template,
			'target_site_id' => 'v_same',
			'target_lang'    => 'zh_CN',
		));

		// Check if same setup with different source lang exists
		$exists = Site_Relation_Validator::relation_exists_by_quintuple(
			1,
			'fr_FR', // Different source language
			$unique_template,
			'v_same',
			'zh_CN'
		);

		$this->assertFalse( $exists );
	}

	/**
	 * Test validate_five_tuple_uniqueness - exact duplicate detected
	 */
	public function test_validate_five_tuple_exact_duplicate_detected() {
		$unique_template = 'test_exact_' . uniqid();

		// Insert first relation
		$this->insert_test_relation( array(
			'source_site_id' => 1,
			'source_lang'    => 'en_US',
			'template'       => $unique_template,
			'target_site_id' => 'v_exact',
			'target_lang'    => 'zh_CN',
		));

		// Check if exact same quintuple exists
		$exists = Site_Relation_Validator::relation_exists_by_quintuple(
			1,
			'en_US',
			$unique_template,
			'v_exact',
			'zh_CN'
		);

		$this->assertTrue( $exists );
	}

	// ==================== source_lang Validation Tests ====================

	/**
	 * Test source_lang is properly handled in validation
	 */
	public function test_validate_source_lang_empty_allowed() {
		// Empty source_lang should be allowed (defaults to empty string)
		$data = array(
			'template'       => 'wordpress-blog',
			'source_site_id' => 1,
			'source_lang'    => '',
			'target_sites'   => array(
				array( 'id' => 'v_empty_lang_' . uniqid(), 'type' => 'virtual', 'lang' => 'zh_CN' ),
			),
		);

		$result = Site_Relation_Validator::validate_new_relation( $data );

		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test source_lang with specific value works
	 */
	public function test_validate_source_lang_specific_value() {
		$data = array(
			'template'       => 'wordpress-blog',
			'source_site_id' => 1,
			'source_lang'    => 'ja',
			'target_sites'   => array(
				array( 'id' => 'v_ja_' . uniqid(), 'type' => 'virtual', 'lang' => 'zh_CN' ),
			),
		);

		$result = Site_Relation_Validator::validate_new_relation( $data );

		$this->assertTrue( $result['valid'] );
	}

	// ==================== validate_add_targets() Tests ====================

	/**
	 * Test validate_add_targets success
	 */
	public function test_validate_add_targets_success() {
		$targets = array(
			array( 'id' => 'v_add_' . uniqid(), 'type' => 'virtual', 'lang' => 'ja' ),
		);

		$result = Site_Relation_Validator::validate_add_targets(
			1,
			'en_US',
			'wordpress-blog',
			$targets
		);

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test validate_add_targets with missing fields fails
	 */
	public function test_validate_add_targets_missing_fields() {
		$targets = array(
			array( 'id' => 'v_test' ), // Missing 'type'
		);

		$result = Site_Relation_Validator::validate_add_targets(
			1,
			'en_US',
			'wordpress-blog',
			$targets
		);

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test validate_add_targets detects duplicate
	 */
	public function test_validate_add_targets_duplicate_fails() {
		$unique_template = 'test_add_' . uniqid();

		// Insert existing relation
		$this->insert_test_relation( array(
			'source_site_id' => 1,
			'source_lang'    => 'en_US',
			'template'       => $unique_template,
			'target_site_id' => 'v_existing',
			'target_lang'    => 'zh_CN',
		));

		// Try to add same target
		$targets = array(
			array( 'id' => 'v_existing', 'type' => 'virtual', 'lang' => 'zh_CN' ),
		);

		$result = Site_Relation_Validator::validate_add_targets(
			1,
			'en_US',
			$unique_template,
			$targets
		);

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	// ==================== Helper Method Tests ====================

	/**
	 * Test is_virtual_site returns false for real site
	 */
	public function test_is_virtual_site_returns_false_for_real() {
		$result = Site_Relation_Validator::is_virtual_site( 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test check_plugin_active returns true for wordpress-blog
	 */
	public function test_check_plugin_active_wordpress_blog() {
		$result = Site_Relation_Validator::check_plugin_active( 'wordpress-blog' );

		$this->assertTrue( $result );
	}

	/**
	 * Test get_language_display_name for en_US
	 */
	public function test_get_language_display_name_english() {
		$result = Site_Relation_Validator::get_language_display_name( 'en_US' );

		$this->assertEquals( 'English (United States)', $result );
	}

	/**
	 * Test get_language_display_name for empty
	 */
	public function test_get_language_display_name_empty() {
		$result = Site_Relation_Validator::get_language_display_name( '' );

		$this->assertNotEmpty( $result );
	}

	// ==================== Model_Config_Provider Integration Tests (v0.9.0) ====================

	/**
	 * Test check_plugin_active uses Model_Config_Provider for virtual model detection
	 *
	 * Verifies that virtual models (like wordpress-blog) are handled via
	 * Model_Config_Provider::is_virtual_model() instead of hardcoded checks.
	 *
	 * @since 0.9.0
	 */
	public function test_check_plugin_active_uses_model_config_provider_for_virtual() {
		// wordpress-blog is a virtual model, should return true without plugin check
		$result = Site_Relation_Validator::check_plugin_active( 'wordpress-blog' );

		$this->assertTrue( $result, 'Virtual model should return true via Model_Config_Provider::is_virtual_model()' );
	}

	/**
	 * Test check_plugin_active handles non-virtual models correctly
	 *
	 * Non-virtual models that are not activated should return false.
	 *
	 * @since 0.9.0
	 */
	public function test_check_plugin_active_returns_false_for_inactive_plugin() {
		// A plugin slug that is definitely not activated
		$result = Site_Relation_Validator::check_plugin_active( 'non-existent-plugin-' . uniqid() );

		$this->assertFalse( $result, 'Inactive/unknown plugin should return false' );
	}

	/**
	 * Test check_plugin_active uses Model_Config_Provider::get_plugin_path
	 *
	 * When a plugin slug is provided, the validator should query
	 * Model_Config_Provider::get_plugin_path() to get the full plugin path.
	 *
	 * @since 0.9.0
	 */
	public function test_check_plugin_active_queries_plugin_path() {
		// If Plugin_Mapping_Service has this plugin registered, it should use that path
		// For unknown plugins, get_plugin_path returns null
		$result = Site_Relation_Validator::check_plugin_active( 'unknown-plugin-' . uniqid() );

		// Unknown plugin with no mapping should return false
		$this->assertFalse( $result );
	}

	/**
	 * Test is_content_plugin delegates to Model_Config_Provider
	 *
	 * The is_content_plugin() method should use Model_Config_Provider::is_content_plugin()
	 * instead of hardcoded content plugin list.
	 *
	 * @since 0.9.0
	 */
	public function test_is_content_plugin_uses_model_config_provider() {
		// wordpress-blog is a known content plugin (virtual model)
		$result = Site_Relation_Validator::is_content_plugin( 'wordpress-blog' );

		$this->assertTrue( $result, 'wordpress-blog should be detected as content plugin via Model_Config_Provider' );
	}

	/**
	 * Test is_content_plugin returns false for non-content plugin
	 *
	 * @since 0.9.0
	 */
	public function test_is_content_plugin_returns_false_for_non_content() {
		// Random plugin slug should not be a content plugin
		$result = Site_Relation_Validator::is_content_plugin( 'random-utility-plugin-' . uniqid() );

		$this->assertFalse( $result, 'Non-content plugin should return false' );
	}

	/**
	 * Test plugin_is_active_on_site handles virtual model
	 *
	 * Virtual models should return true without checking plugin activation.
	 *
	 * @since 0.9.0
	 */
	public function test_plugin_is_active_on_site_handles_virtual_model() {
		$result = Site_Relation_Validator::plugin_is_active_on_site( 'wordpress-blog', 1 );

		$this->assertTrue( $result, 'Virtual model should return true regardless of site' );
	}

	/**
	 * Test validate_new_relation uses is_virtual_model for plugin check skip
	 *
	 * When template is a virtual model, plugin activation check should be skipped.
	 *
	 * @since 0.9.0
	 */
	public function test_validate_new_relation_skips_plugin_check_for_virtual_model() {
		$data = array(
			'template'       => 'wordpress-blog', // Virtual model
			'source_site_id' => 1,
			'source_lang'    => 'en_US',
			'target_sites'   => array(
				array( 'id' => 'v_test_' . uniqid(), 'type' => 'virtual', 'lang' => 'zh_CN' ),
			),
		);

		$result = Site_Relation_Validator::validate_new_relation( $data );

		// Should pass validation without plugin check errors
		$this->assertTrue( $result['valid'], 'Virtual model should not require plugin activation check' );
	}

	/**
	 * Test validate_new_relation uses is_virtual_model for target site restriction
	 *
	 * Virtual models should only allow virtual sites as targets.
	 *
	 * @since 0.9.0
	 */
	public function test_validate_new_relation_virtual_model_restricts_target_type() {
		$data = array(
			'template'       => 'wordpress-blog', // Virtual model
			'source_site_id' => 1,
			'source_lang'    => 'en_US',
			'target_sites'   => array(
				array( 'id' => 2, 'type' => 'wp', 'lang' => 'zh_CN' ), // WP site (not allowed)
			),
		);

		$result = Site_Relation_Validator::validate_new_relation( $data );

		// Should fail because virtual model can only target virtual sites
		$this->assertFalse( $result['valid'], 'Virtual model should only allow virtual site targets' );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Clean up after all tests
	 */
	public function tearDown(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';

		// Clean up by template pattern
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE template LIKE %s', $table, 'test_%' ) );

		parent::tearDown();
	}
}
