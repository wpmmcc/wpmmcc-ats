<?php
/**
 * Model Scanner V2 Tests
 *
 * Tests for WPTSALL\Models\Scanners\Model_Scanner_V2 class
 *
 * @package WPTSALL
 * @since 0.5.0
 */

use WPTSALL\Models\Scanners\Model_Scanner_V2;

class Test_Model_Scanner_V2 extends WP_UnitTestCase {

	/**
	 * Scanner instance
	 *
	 * @var Model_Scanner_V2
	 */
	protected $scanner;

	/**
	 * Test model IDs for cleanup
	 *
	 * @var array
	 */
	protected $test_model_ids = array();

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure tables exist
		if ( ! wptsall_models_table_exists() ) {
			wptsall_create_model_tables();
		}

		// Initialize scanner
		$this->scanner = new Model_Scanner_V2();
	}

	/**
	 * Tear down test environment
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test models
		if ( ! empty( $this->test_model_ids ) ) {
			$models_table        = wptsall_table( 'models' );
			$rules_table         = wptsall_table( 'translation_rules' );
			$model_objects_table = wptsall_table( 'model_objects' );
			$model_obj_fields    = wptsall_table( 'model_object_fields' );

			foreach ( $this->test_model_ids as $model_id ) {
				// P1-TEST-01 (2026-09-02): model_object_fields has no
				// model_id column — fields hang off object_id via
				// model_objects. Delete via the object ids first.
				$object_ids = $wpdb->get_col(
					$wpdb->prepare( 'SELECT id FROM %i WHERE model_id = %d', $model_objects_table, $model_id )
				);

				foreach ( (array) $object_ids as $object_id ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->delete( $model_obj_fields, array( 'object_id' => (int) $object_id ) );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $model_objects_table, array( 'model_id' => $model_id ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $rules_table, array( 'model_id' => $model_id ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $models_table, array( 'id' => $model_id ) );
			}
		}

		parent::tearDown();
	}

	/**
	 * Test scanner instantiation
	 */
	public function test_scanner_instantiation() {
		$this->assertInstanceOf( Model_Scanner_V2::class, $this->scanner );
	}

	/**
	 * Test scanner has required methods
	 */
	public function test_scanner_has_required_methods() {
		$this->assertTrue( method_exists( $this->scanner, 'scan_plugin' ) );
		$this->assertTrue( method_exists( $this->scanner, 'save_scan_result' ) );
		$this->assertTrue( method_exists( $this->scanner, 'scan_all_plugins' ) );
	}

	/**
	 * Test scan_plugin returns expected structure
	 */
	public function test_scan_plugin_returns_structure() {
		// Auto-activate WooCommerce if installed but not active
		$this->requirePlugin( 'woocommerce' );

		$result = $this->scanner->scan_plugin( 'woocommerce' );

		$this->assertFalse( is_wp_error( $result ), 'Scan should succeed' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'model', $result );
		$this->assertArrayHasKey( 'rules', $result );
	}

	/**
	 * Test scan_plugin model structure
	 */
	public function test_scan_plugin_model_structure() {
		// Auto-activate WooCommerce if installed but not active
		$this->requirePlugin( 'woocommerce' );

		$result = $this->scanner->scan_plugin( 'woocommerce' );

		$this->assertFalse( is_wp_error( $result ), 'Scan should succeed' );

		$model = $result['model'];
		$this->assertIsArray( $model );
		$this->assertArrayHasKey( 'plugin_slug', $model );
		$this->assertArrayHasKey( 'plugin_name', $model );
		$this->assertArrayHasKey( 'plugin_version', $model );
		$this->assertArrayHasKey( 'text_domain', $model );
		$this->assertArrayHasKey( 'post_types', $model );
		$this->assertArrayHasKey( 'taxonomies', $model );
	}

	/**
	 * Test scan_plugin rules structure
	 */
	public function test_scan_plugin_rules_structure() {
		// Auto-activate WooCommerce if installed but not active
		$this->requirePlugin( 'woocommerce' );

		$result = $this->scanner->scan_plugin( 'woocommerce' );

		$this->assertFalse( is_wp_error( $result ), 'Scan should succeed' );

		$rules = $result['rules'];
		$this->assertIsArray( $rules );

		if ( ! empty( $rules ) ) {
			$rule = $rules[0];
			$this->assertArrayHasKey( 'name', $rule );
			$this->assertArrayHasKey( 'url_pattern', $rule );
			$this->assertArrayHasKey( 'url_type', $rule );
			$this->assertArrayHasKey( 'data_type', $rule );
		}
	}

	/**
	 * Test save_scan_result returns model_id on success
	 *
	 * Note: save_scan_result returns model_id directly (not an array)
	 */
	public function test_save_scan_result_returns_model_id() {
		$result = $this->scanner->scan_plugin( 'wordpress-blog' );

		$this->assertFalse( is_wp_error( $result ), 'blog scan should succeed' );

		$save_result = $this->scanner->save_scan_result( $result );

		// save_scan_result returns model_id directly or WP_Error
		$this->assertFalse( is_wp_error( $save_result ), 'save should succeed' );
		$this->assertGreaterThan( 0, (int) $save_result, 'model_id should be positive' );

		// Track for cleanup
		$this->test_model_ids[] = $save_result;
	}

	/**
	 * Test save_scan_result with blog (WordPress core)
	 */
	public function test_save_blog_scan_result() {
		$result = $this->scanner->scan_plugin( 'wordpress-blog' );

		$this->assertFalse( is_wp_error( $result ), 'blog scan should succeed' );

		$save_result = $this->scanner->save_scan_result( $result );

		$this->assertFalse( is_wp_error( $save_result ), 'save should succeed' );
		$this->assertIsArray( $save_result, 'save_scan_result should return array' );
		$this->assertArrayHasKey( 'model_id', $save_result );

		$model_id = $save_result['model_id'];
		$this->assertGreaterThan( 0, (int) $model_id );

		// Verify the model was saved correctly
		global $wpdb;
		$model_table = wptsall_table( 'models' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$saved_model = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $model_table, $model_id )
		);

		$this->assertNotNull( $saved_model );
		$this->assertEquals( 'wordpress-blog', $saved_model->plugin_slug );
		$this->assertEquals( 'WordPress Blog', $saved_model->plugin_name );

		$this->test_model_ids[] = $model_id;
	}

	/**
	 * Test save_scan_result creates rules
	 */
	public function test_save_scan_result_creates_rules() {
		$result = $this->scanner->scan_plugin( 'wordpress-blog' );

		$this->assertFalse( is_wp_error( $result ), 'blog scan should succeed' );

		// Rules are only created in 'full' mode; the default 'incremental' mode skips rule creation.
		$this->scanner->set_mode( 'full' );

		$save_result = $this->scanner->save_scan_result( $result );

		$this->assertFalse( is_wp_error( $save_result ), 'save should succeed' );
		$this->assertIsArray( $save_result, 'save_scan_result should return array' );

		$model_id = $save_result['model_id'];

		// Verify rules were saved. Note: create_rules_for_model uses the model's
		// post_types/taxonomies columns. Currently only post_types are mirrored
		// into the model row during sync (taxonomies are stored in model_objects
		// but not back to model.taxonomies), so rule_count reflects post_types only.
		$expected_rule_count = (int) ( $save_result['rule_count'] ?? 0 );

		global $wpdb;
		$rules_table = wptsall_table( 'translation_rules' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$saved_rule_count = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE model_id = %d', $rules_table, $model_id )
		);

		$this->assertEquals( $expected_rule_count, (int) $saved_rule_count );

		$this->test_model_ids[] = $model_id;
	}

	/**
	 * Test save_scan_result update (second save same model)
	 */
	public function test_save_scan_result_update() {
		$result = $this->scanner->scan_plugin( 'wordpress-blog' );

		$this->assertFalse( is_wp_error( $result ), 'blog scan should succeed' );

		// First save
		$model_id1 = $this->scanner->save_scan_result( $result );
		$this->assertFalse( is_wp_error( $model_id1 ), 'first save should succeed' );

		// Second save (should update, not create new)
		$model_id2 = $this->scanner->save_scan_result( $result );
		$this->assertFalse( is_wp_error( $model_id2 ), 'second save should succeed' );

		// Should be same model ID
		$this->assertEquals(
			(int) $model_id1,
			(int) $model_id2,
			'Update should use same model ID'
		);

		$this->test_model_ids[] = $model_id1;
	}

	/**
	 * Test scan non-existent plugin returns error
	 */
	public function test_scan_nonexistent_plugin() {
		$result = $this->scanner->scan_plugin( 'nonexistent-plugin-xyz-12345' );

		$this->assertTrue( is_wp_error( $result ) );
	}

	/**
	 * Test scan_all_plugins returns expected structure
	 */
	public function test_scan_all_plugins_structure() {
		$result = $this->scanner->scan_all_plugins();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'models_created', $result );
		$this->assertArrayHasKey( 'models_updated', $result );
		$this->assertArrayHasKey( 'models_failed', $result );
		$this->assertArrayHasKey( 'total_rules', $result );
		$this->assertArrayHasKey( 'details', $result );
	}

	/**
	 * Test VERSION constant exists
	 */
	public function test_version_constant_exists() {
		$this->assertTrue( defined( Model_Scanner_V2::class . '::VERSION' ) );
		$this->assertNotEmpty( Model_Scanner_V2::VERSION );
	}

	// =========================================================================
	// NEW TESTS: WordPress Core (blog) and Plugin Mappings Integration
	// These tests were added to catch namespace and data structure bugs
	// =========================================================================

	/**
	 * Test scan_plugin for 'wordpress-blog' (WordPress Core)
	 *
	 * This test ensures that 'wordpress-blog' is properly handled as a special case.
	 * Previously, the scanner would fail with "未找到插件: blog" because
	 * blog is not a real plugin in get_plugins().
	 */
	public function test_scan_blog() {
		$result = $this->scanner->scan_plugin( 'wordpress-blog' );

		// blog must always succeed - it's WordPress core, not an optional plugin
		$this->assertFalse(
			is_wp_error( $result ),
			'blog scan should succeed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'model', $result );
		$this->assertArrayHasKey( 'rules', $result );
	}

	/**
	 * Test blog scan returns correct post types (post and page)
	 */
	public function test_scan_blog_returns_post_and_page() {
		$result = $this->scanner->scan_plugin( 'wordpress-blog' );

		$this->assertFalse( is_wp_error( $result ), 'blog scan should succeed' );

		$post_types = $result['model']['post_types'];
		$this->assertIsArray( $post_types );
		$this->assertContains( 'post', $post_types, 'blog should include post type' );
		$this->assertContains( 'page', $post_types, 'blog should include page type' );
	}

	/**
	 * Test blog scan returns correct taxonomies (category and post_tag)
	 */
	public function test_scan_blog_returns_taxonomies() {
		$result = $this->scanner->scan_plugin( 'wordpress-blog' );

		$this->assertFalse( is_wp_error( $result ), 'blog scan should succeed' );

		$taxonomies = $result['model']['taxonomies'];
		$this->assertIsArray( $taxonomies );
		$this->assertContains( 'category', $taxonomies, 'blog should include category taxonomy' );
		$this->assertContains( 'post_tag', $taxonomies, 'blog should include post_tag taxonomy' );
	}

	/**
	 * Test blog model has correct plugin info
	 */
	public function test_scan_blog_plugin_info() {
		$result = $this->scanner->scan_plugin( 'wordpress-blog' );

		$this->assertFalse( is_wp_error( $result ), 'blog scan should succeed' );

		$model = $result['model'];
		$this->assertEquals( 'wordpress-blog', $model['plugin_slug'] );
		$this->assertEquals( 'WordPress Blog', $model['plugin_name'] );
		$this->assertEquals( 'default', $model['text_domain'] );
	}

	/**
	 * Test blog scan generates translation rules
	 */
	public function test_scan_blog_generates_rules() {
		$result = $this->scanner->scan_plugin( 'wordpress-blog' );

		$this->assertFalse( is_wp_error( $result ), 'blog scan should succeed' );

		$rules = $result['rules'];
		$this->assertIsArray( $rules );
		$this->assertNotEmpty( $rules, 'blog should generate at least one rule' );

		// Should have at least 4 rules: post single, page single, category archive, post_tag archive
		$this->assertGreaterThanOrEqual( 4, count( $rules ), 'blog should have at least 4 rules' );
	}

	/**
	 * Test scan reads from models table
	 *
	 * This test verifies that the scanner correctly reads post_types and taxonomies
	 * from the models table. Previously, there was a namespace bug where
	 * class_exists() would fail because of wrong namespace.
	 */
	public function test_scan_reads_from_plugin_mappings() {
		// Ensure models table has data for blog
		$mapping = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( 'wordpress-blog' );

		if ( ! $mapping ) {
			$result = \WPTSALL\Models\Services\Plugin_Scanner::scan_plugin( 'wordpress-blog' );
			if ( $result ) {
				\WPTSALL\Models\Services\Plugin_Mapping_Service::save( $result );
			}
			$mapping = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( 'wordpress-blog' );
		}
		$this->assertNotNull( $mapping, 'models table should have wordpress-blog mapping' );

		// Scan blog
		$result = $this->scanner->scan_plugin( 'wordpress-blog' );

		$this->assertFalse( is_wp_error( $result ), 'blog scan should succeed when plugin_mappings has data' );

		// Verify the scanner used models data
		$this->assertNotEmpty( $result['model']['post_types'], 'Should have post_types from models' );
	}

	/**
	 * Test plugin_not_found error for truly non-existent plugin
	 */
	public function test_scan_nonexistent_plugin_returns_plugin_not_found() {
		$result = $this->scanner->scan_plugin( 'nonexistent-plugin-xyz-12345' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'plugin_not_found', $result->get_error_code() );
	}

	/**
	 * Test scan woocommerce with proper error handling
	 *
	 * This test distinguishes between "plugin not installed" and "scan bug".
	 * Auto-activates if installed but not active.
	 */
	public function test_scan_woocommerce_with_proper_error_handling() {
		// Auto-activate WooCommerce if installed but not active, skip if not installed
		$this->requirePlugin( 'woocommerce' );

		$result = $this->scanner->scan_plugin( 'woocommerce' );

		// Since plugin is now guaranteed active, any error is a bug
		$this->assertFalse(
			is_wp_error( $result ),
			'Scan should succeed for active WooCommerce: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);

		// Verify content
		$this->assertNotEmpty( $result['model']['post_types'], 'WooCommerce should have post_types' );
		$this->assertContains( 'product', $result['model']['post_types'], 'WooCommerce should have product post_type' );
	}

	/**
	 * Test scan bbpress with proper error handling
	 *
	 * Auto-activates if installed but not active.
	 * Note: The scanner requires plugin data in models table OR
	 * the plugin to have been active when models was populated.
	 */
	public function test_scan_bbpress_with_proper_error_handling() {
		// Auto-activate bbPress if installed but not active, skip if not installed
		$this->requirePlugin( 'bbpress' );

		// Check if bbPress was scanned into models
		$mapping = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( 'bbpress' );
		if ( ! $mapping ) {
			$result = \WPTSALL\Models\Services\Plugin_Scanner::scan_plugin( 'bbpress' );
			$this->assertNotNull( $result, 'bbPress scan should return result for active plugin' );
			\WPTSALL\Models\Services\Plugin_Mapping_Service::save( $result );
			$mapping = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( 'bbpress' );
		}
		$this->assertNotNull( $mapping, 'bbPress mapping should exist in models after scan' );

		$result = $this->scanner->scan_plugin( 'bbpress' );

		// Since plugin is in mappings, scan should succeed
		$this->assertFalse(
			is_wp_error( $result ),
			'Scan should succeed for bbPress in mappings: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);

		$this->assertNotEmpty( $result['model']['post_types'], 'bbPress should have post_types' );
		$this->assertContains( 'forum', $result['model']['post_types'], 'bbPress should have forum post_type' );
	}

	/**
	 * Test Plugin_Mapping_Service class exists with correct namespace
	 *
	 * This test catches the namespace bug where the scanner used wrong namespace.
	 */
	public function test_plugin_mapping_service_namespace() {
		$correct_class = '\\WPTSALL\\Models\\Services\\Plugin_Mapping_Service';
		$wrong_class   = '\\WPTSALL\\Services\\Plugin_Mapping_Service';

		$this->assertTrue(
			class_exists( $correct_class ),
			'Plugin_Mapping_Service should exist at WPTSALL\\Models\\Services namespace'
		);

		$this->assertFalse(
			class_exists( $wrong_class ),
			'Plugin_Mapping_Service should NOT exist at old WPTSALL\\Services namespace'
		);
	}

	/**
	 * Test that models data structure is correctly parsed
	 *
	 * models stores post_types as [{name: 'post', ...}, ...] objects,
	 * but the scanner needs simple string array ['post', 'page'].
	 */
	public function test_plugin_mappings_data_structure_parsing() {
		$mapping = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( 'wordpress-blog' );

		if ( ! $mapping ) {
			$result = \WPTSALL\Models\Services\Plugin_Scanner::scan_plugin( 'wordpress-blog' );
			if ( $result ) {
				\WPTSALL\Models\Services\Plugin_Mapping_Service::save( $result );
			}
			$mapping = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( 'wordpress-blog' );
		}
		$this->assertNotNull( $mapping, 'models table should have wordpress-blog mapping' );

		// Check the raw data structure
		$post_types_raw = $mapping['post_types'];
		$this->assertIsArray( $post_types_raw );

		// The first element might be an object (with 'name' key) or a string
		if ( ! empty( $post_types_raw ) ) {
			$first = $post_types_raw[0];
			if ( is_array( $first ) ) {
				$this->assertArrayHasKey( 'name', $first, 'Object format should have name key' );
			} else {
				$this->assertIsString( $first, 'Simple format should be string' );
			}
		}

		// Now verify the scanner correctly extracts the names
		$result = $this->scanner->scan_plugin( 'wordpress-blog' );
		$this->assertFalse( is_wp_error( $result ) );

		// The scanner's output should be simple string array
		$model_post_types = $result['model']['post_types'];
		foreach ( $model_post_types as $pt ) {
			$this->assertIsString( $pt, 'Scanner output post_types should be strings, not objects' );
		}
	}
}
