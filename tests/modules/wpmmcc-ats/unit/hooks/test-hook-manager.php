<?php
/**
 * Hook Manager Tests
 *
 * Tests for WPTSALL\Hooks\Hook_Manager class
 *
 * Updated for v0.9.0:
 * - hooks table has been removed
 * - All hooks are now auto-generated via generate_auto_hooks()
 * - Database-related methods are deprecated and return null/0/false
 *
 * @package WPTSALL
 * @since 0.5.0
 * @updated 0.9.0 Updated tests for auto-generation architecture
 */

use WPTSALL\Hooks\Hook_Manager;

class Test_Hook_Manager extends SimpleTestCase {

	/**
	 * Test site relation ID
	 *
	 * @var int
	 */
	protected $test_relation_id = 0;

	/**
	 * Test model ID
	 *
	 * @var int
	 */
	protected $test_model_id = 0;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up as admin
		$admin = get_user_by( 'login', 'admin' );
		if ( $admin ) {
			wp_set_current_user( $admin->ID );
		}

		// Create test site relation
		global $wpdb;
		$relations_table = $wpdb->prefix . 'wptsall_site_relations';
		$now = current_time( 'mysql' );

		// Check if table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $relations_table )
		);

		if ( $table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$relations_table,
				array(
					'source_site_id'   => get_current_blog_id(),
					'source_site_type' => 'wp',
					'source_lang'      => 'zh_CN',
					'template'         => 'test-hook-manager-plugin',
					'target_site_id'   => 'v_test_en',
					'target_site_type' => 'virtual',
					'target_lang'      => 'en_US',
					'status'           => 'active',
					'created_at'       => $now,
					'updated_at'       => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			$this->test_relation_id = $wpdb->insert_id;
		}

		// Create test model
		$models_table = $wpdb->prefix . 'wptsall_models';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$models_table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $models_table )
		);

		if ( $models_table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$models_table,
				array(
					'plugin_slug'  => 'test-hook-manager-plugin',
					'plugin_name'  => 'Test Hook Manager Plugin',
					'post_types'   => wp_json_encode( array( array( 'name' => 'post' ), array( 'name' => 'page' ) ) ),
					'taxonomies'   => wp_json_encode( array( array( 'name' => 'category' ) ) ),
					'scan_status'  => 'complete',
					'created_at'   => $now,
					'updated_at'   => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			$this->test_model_id = $wpdb->insert_id;
		}
	}

	/**
	 * Clean up test data
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test site relation
		if ( $this->test_relation_id ) {
			$relations_table = $wpdb->prefix . 'wptsall_site_relations';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $relations_table, array( 'id' => $this->test_relation_id ), array( '%d' ) );
		}

		// Clean up test model
		if ( $this->test_model_id ) {
			$models_table = $wpdb->prefix . 'wptsall_models';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $models_table, array( 'id' => $this->test_model_id ), array( '%d' ) );
		}

		// Clean up any test relations by template pattern
		$relations_table = $wpdb->prefix . 'wptsall_site_relations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$relations_table} WHERE template LIKE %s",
				'test-hook-manager%'
			)
		);

		// Clean up any test models by slug pattern
		$models_table = $wpdb->prefix . 'wptsall_models';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$models_table} WHERE plugin_slug LIKE %s",
				'test-hook-manager%'
			)
		);

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// ========================================
	// Class Existence Tests
	// ========================================

	/**
	 * Test Hook_Manager class exists
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Hooks\Hook_Manager' ) );
	}

	// ========================================
	// Init Method Tests
	// ========================================

	/**
	 * Test init method adds action hook
	 */
	public function test_init_adds_action() {
		// Remove existing hook if any
		remove_all_actions( 'plugins_loaded' );

		Hook_Manager::init();

		$this->assertTrue(
			has_action( 'plugins_loaded', array( Hook_Manager::class, 'register_dynamic_hooks' ) ) !== false,
			'init() should add register_dynamic_hooks to plugins_loaded'
		);
	}

	/**
	 * Test init adds relation update listener
	 */
	public function test_init_adds_relation_update_listener() {
		remove_all_actions( 'wptsall_relation_updated' );
		remove_all_actions( 'wptsall_relation_models_updated' );

		Hook_Manager::init();

		$this->assertTrue(
			has_action( 'wptsall_relation_updated' ) !== false,
			'init() should add listener for wptsall_relation_updated'
		);

		$this->assertTrue(
			has_action( 'wptsall_relation_models_updated' ) !== false,
			'init() should add listener for wptsall_relation_models_updated'
		);
	}

	// ========================================
	// Auto-Generation Tests
	// ========================================

	/**
	 * Test collect_hooks returns array
	 */
	public function test_collect_hooks_returns_array() {
		$hooks = Hook_Manager::collect_hooks();
		$this->assertIsArray( $hooks );
	}

	/**
	 * Test generate_auto_hooks returns array
	 */
	public function test_generate_auto_hooks_returns_array() {
		$hooks = Hook_Manager::generate_auto_hooks();
		$this->assertIsArray( $hooks );
	}

	/**
	 * Test generate_auto_hooks with specific relation_id
	 */
	public function test_generate_auto_hooks_with_relation_id() {
		// Should not throw error even with invalid relation
		$hooks = Hook_Manager::generate_auto_hooks( 999999 );
		$this->assertIsArray( $hooks );
		$this->assertEmpty( $hooks ); // No hooks for non-existent relation
	}

	/**
	 * Test generate_auto_hooks returns empty for relation without models
	 */
	public function test_generate_auto_hooks_empty_for_relation_without_models() {
		if ( ! $this->test_relation_id ) {
			$this->assertTrue( true ); // Skip if no relation
			return;
		}

		// Relation exists but has no associated models via relation_models table
		// (we only created a model, not associated it)
		$hooks = Hook_Manager::generate_auto_hooks( $this->test_relation_id );
		$this->assertIsArray( $hooks );
	}

	// ========================================
	// Deduplication Key Tests
	// ========================================

	/**
	 * Test get_dedup_key generates correct key
	 */
	public function test_get_dedup_key_basic() {
		$hook = array(
			'hook_name' => 'save_post',
			'site_id'   => 1,
			'model_id'  => 10,
			'template'  => 'woocommerce',
			'expected'  => array(
				'subtype' => 'product',
			),
		);

		$key = Hook_Manager::get_dedup_key( $hook );

		$this->assertIsString( $key );
		$this->assertStringContainsString( 'save_post', $key );
		$this->assertStringContainsString( '1', $key );
		$this->assertStringContainsString( '10', $key );
		$this->assertStringContainsString( 'woocommerce', $key );
		$this->assertStringContainsString( 'product', $key );
	}

	/**
	 * Test get_dedup_key with empty expected
	 */
	public function test_get_dedup_key_empty_expected() {
		$hook = array(
			'hook_name' => 'template_include',
			'site_id'   => 2,
			'model_id'  => 5,
			'template'  => 'bbpress',
			'expected'  => array(),
		);

		$key = Hook_Manager::get_dedup_key( $hook );

		$this->assertIsString( $key );
		$this->assertStringContainsString( 'template_include', $key );
	}

	/**
	 * Test get_dedup_key uniqueness
	 */
	public function test_get_dedup_key_uniqueness() {
		$hook1 = array(
			'hook_name' => 'save_post',
			'site_id'   => 1,
			'model_id'  => 10,
			'template'  => 'woocommerce',
			'expected'  => array( 'subtype' => 'product' ),
		);

		$hook2 = array(
			'hook_name' => 'save_post',
			'site_id'   => 1,
			'model_id'  => 10,
			'template'  => 'woocommerce',
			'expected'  => array( 'subtype' => 'shop_order' ), // Different subtype
		);

		$key1 = Hook_Manager::get_dedup_key( $hook1 );
		$key2 = Hook_Manager::get_dedup_key( $hook2 );

		$this->assertNotEquals( $key1, $key2, 'Different subtypes should produce different keys' );
	}

	// ========================================
	// Hook Recommendations Tests
	// ========================================

	/**
	 * Test get_hook_recommendations for WooCommerce
	 */
	public function test_get_hook_recommendations_woocommerce() {
		$recommendations = Hook_Manager::get_hook_recommendations( 'woocommerce' );

		$this->assertIsArray( $recommendations );
		$this->assertArrayHasKey( 'frontend', $recommendations );
		$this->assertArrayHasKey( 'save', $recommendations );
		$this->assertArrayHasKey( 'notes', $recommendations );
		$this->assertNotContains( 'template_include', $recommendations['frontend'] );
		$this->assertNotContains( 'woocommerce_before_single_product', $recommendations['frontend'] );
		$this->assertContains( 'save_post_product', $recommendations['save'] );
		$this->assertTrue( Hook_Manager::post_type_is_listenable( 'product' ) );
		$this->assertFalse( Hook_Manager::post_type_is_listenable( 'shop_order' ) );
		$this->assertFalse( Hook_Manager::post_type_is_listenable( 'acf-field-group' ) );
	}

	/**
	 * Test get_hook_recommendations for bbPress
	 */
	public function test_get_hook_recommendations_bbpress() {
		$recommendations = Hook_Manager::get_hook_recommendations( 'bbpress' );

		$this->assertIsArray( $recommendations );
		$this->assertArrayHasKey( 'frontend', $recommendations );
		$this->assertArrayHasKey( 'save', $recommendations );
		$this->assertContains( 'bbp_new_topic', $recommendations['save'] );
	}

	/**
	 * Test get_hook_recommendations for Easy Digital Downloads
	 */
	public function test_get_hook_recommendations_edd() {
		$recommendations = Hook_Manager::get_hook_recommendations( 'easy-digital-downloads' );

		$this->assertIsArray( $recommendations );
		$this->assertArrayHasKey( 'save', $recommendations );
		$this->assertContains( 'save_post_download', $recommendations['save'] );
	}

	/**
	 * Test get_hook_recommendations for BuddyPress
	 */
	public function test_get_hook_recommendations_buddypress() {
		$recommendations = Hook_Manager::get_hook_recommendations( 'buddypress' );

		$this->assertIsArray( $recommendations );
		$this->assertArrayHasKey( 'frontend', $recommendations );
		$this->assertArrayHasKey( 'save', $recommendations );
	}

	/**
	 * Test get_hook_recommendations for unknown plugin
	 */
	public function test_get_hook_recommendations_unknown() {
		$recommendations = Hook_Manager::get_hook_recommendations( 'unknown-plugin-xyz' );

		$this->assertIsArray( $recommendations );
		$this->assertArrayHasKey( 'notes', $recommendations );
		// Accept both English source and zh-CN translation (i18n) — the wording
		// is load-bearing only as a 'no recommendations' marker, not as a literal.
		$notes = $recommendations['notes'];
		$is_marker = ( false !== strpos( $notes, 'No hook recommendations defined' ) )
			|| ( false !== strpos( $notes, '此插件尚未' ) );
		$this->assertTrue( $is_marker, 'notes should be a no-recommendations marker (en or zh-CN): ' . $notes );
	}

	/**
	 * Test get_hook_recommendations sanitizes input
	 */
	public function test_get_hook_recommendations_sanitizes_input() {
		$recommendations = Hook_Manager::get_hook_recommendations( 'WooCommerce' );

		// Should sanitize to 'woocommerce' and return valid recommendations
		$this->assertIsArray( $recommendations );
		$this->assertArrayHasKey( 'frontend', $recommendations );
	}

	// ========================================
	// Site Relation Helper Tests
	// ========================================

	/**
	 * Test get_site_rel with valid ID
	 */
	public function test_get_site_rel_valid() {
		if ( ! $this->test_relation_id ) {
			$this->assertTrue( true ); // Skip if no relation
			return;
		}

		$relation = Hook_Manager::get_site_rel( $this->test_relation_id );

		// Should return relation or null (depends on service implementation)
		$this->assertTrue( $relation === null || is_array( $relation ) );
	}

	/**
	 * Test get_site_rel with invalid ID
	 */
	public function test_get_site_rel_invalid() {
		$relation = Hook_Manager::get_site_rel( 999999 );
		$this->assertNull( $relation );
	}

	/**
	 * Test get_site_rel with zero ID
	 */
	public function test_get_site_rel_zero() {
		$relation = Hook_Manager::get_site_rel( 0 );
		$this->assertNull( $relation );
	}

	// ========================================
	// Model Helper Tests
	// ========================================

	/**
	 * Test get_model_by_id with valid ID
	 */
	public function test_get_model_by_id_valid() {
		if ( ! $this->test_model_id ) {
			$this->assertTrue( true ); // Skip if no model
			return;
		}

		$model = Hook_Manager::get_model_by_id( $this->test_model_id );

		$this->assertIsArray( $model );
		$this->assertEquals( 'test-hook-manager-plugin', $model['plugin_slug'] );
		// post_types should be parsed as array
		$this->assertIsArray( $model['post_types'] );
	}

	/**
	 * Test get_model_by_id with invalid ID
	 */
	public function test_get_model_by_id_invalid() {
		$model = Hook_Manager::get_model_by_id( 999999 );
		$this->assertNull( $model );
	}

	/**
	 * Test get_model_by_id parses JSON fields
	 */
	public function test_get_model_by_id_parses_json() {
		if ( ! $this->test_model_id ) {
			$this->assertTrue( true ); // Skip if no model
			return;
		}

		$model = Hook_Manager::get_model_by_id( $this->test_model_id );

		$this->assertIsArray( $model['post_types'] );
		$this->assertIsArray( $model['taxonomies'] );

		// Should have the test post types
		$pt_names = array_column( $model['post_types'], 'name' );
		$this->assertContains( 'post', $pt_names );
		$this->assertContains( 'page', $pt_names );
	}

	// ========================================
	// Relation Hooks Summary Tests
	// ========================================

	/**
	 * Test get_relation_hooks_summary with non-existent relation
	 */
	public function test_get_relation_hooks_summary_nonexistent() {
		$summary = Hook_Manager::get_relation_hooks_summary( 999999 );

		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'error', $summary );
		$this->assertEquals( 0, $summary['total'] );
	}

	/**
	 * Test get_relation_hooks_summary returns correct structure
	 */
	public function test_get_relation_hooks_summary_structure() {
		if ( ! $this->test_relation_id ) {
			$this->assertTrue( true ); // Skip if no relation
			return;
		}

		$summary = Hook_Manager::get_relation_hooks_summary( $this->test_relation_id );

		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'relation_id', $summary );
		$this->assertArrayHasKey( 'target_type', $summary );
		$this->assertArrayHasKey( 'source_type', $summary );
		$this->assertArrayHasKey( 'models_count', $summary );
		$this->assertArrayHasKey( 'total_hooks', $summary );
		$this->assertArrayHasKey( 'post_type_hooks', $summary );
		$this->assertArrayHasKey( 'taxonomy_hooks', $summary );
		$this->assertArrayHasKey( 'frontend_hooks', $summary );
		$this->assertArrayHasKey( 'models', $summary );
	}

	// ========================================
	// Cache Management Tests
	// ========================================

	/**
	 * Test clear_registered_cache doesn't throw error
	 */
	public function test_clear_registered_cache() {
		// Should not throw any errors
		Hook_Manager::clear_registered_cache();
		$this->assertTrue( true );
	}

	/**
	 * Test clear_registered_cache can be called multiple times
	 */
	public function test_clear_registered_cache_multiple_calls() {
		Hook_Manager::clear_registered_cache();
		Hook_Manager::clear_registered_cache();
		Hook_Manager::clear_registered_cache();
		$this->assertTrue( true );
	}

	// ========================================
	// Hooks for Model Tests
	// ========================================

	/**
	 * Test get_hooks_for_model returns array
	 */
	public function test_get_hooks_for_model_returns_array() {
		$hooks = Hook_Manager::get_hooks_for_model( 999999 );
		$this->assertIsArray( $hooks );
		$this->assertEmpty( $hooks ); // Non-existent model
	}

	/**
	 * Test get_hooks_for_model with valid model
	 */
	public function test_get_hooks_for_model_valid() {
		if ( ! $this->test_model_id ) {
			$this->assertTrue( true ); // Skip if no model
			return;
		}

		$hooks = Hook_Manager::get_hooks_for_model( $this->test_model_id );
		$this->assertIsArray( $hooks );
		// May be empty if model has no associated relations
	}

	// ========================================
	// Plugin Specific Hooks Tests
	// ========================================

	/**
	 * Test get_plugin_specific_hooks returns array
	 */
	public function test_get_plugin_specific_hooks_returns_array() {
		$model = array(
			'id'          => 1,
			'plugin_slug' => 'test-plugin',
		);
		$relation = array(
			'id' => 1,
		);

		$hooks = Hook_Manager::get_plugin_specific_hooks( $model, $relation );
		$this->assertIsArray( $hooks );
	}

	/**
	 * Test get_plugin_specific_hooks with empty model
	 */
	public function test_get_plugin_specific_hooks_empty_model() {
		$model = array();
		$relation = array( 'id' => 1 );

		$hooks = Hook_Manager::get_plugin_specific_hooks( $model, $relation );
		$this->assertIsArray( $hooks );
	}

	// ========================================
	// Regenerate Hooks Tests
	// ========================================

	/**
	 * Test regenerate_hooks_for_relation with invalid ID
	 */
	public function test_regenerate_hooks_for_relation_invalid() {
		// Should not throw error
		Hook_Manager::regenerate_hooks_for_relation( 0 );
		Hook_Manager::regenerate_hooks_for_relation( -1 );
		Hook_Manager::regenerate_hooks_for_relation( 999999 );
		$this->assertTrue( true );
	}

	/**
	 * Test regenerate_hooks_for_relation with valid ID
	 */
	public function test_regenerate_hooks_for_relation_valid() {
		if ( ! $this->test_relation_id ) {
			$this->assertTrue( true ); // Skip if no relation
			return;
		}

		// Should not throw error
		Hook_Manager::regenerate_hooks_for_relation( $this->test_relation_id );
		$this->assertTrue( true );
	}

	// ========================================
	// Register Dynamic Hooks Tests
	// ========================================

	/**
	 * Test register_dynamic_hooks doesn't throw error
	 */
	public function test_register_dynamic_hooks() {
		// Should not throw any errors
		Hook_Manager::register_dynamic_hooks();
		$this->assertTrue( true );
	}

	/**
	 * Test register_dynamic_hooks can be called multiple times
	 */
	public function test_register_dynamic_hooks_idempotent() {
		Hook_Manager::register_dynamic_hooks();
		Hook_Manager::register_dynamic_hooks();
		// Should not register duplicate hooks due to deduplication
		$this->assertTrue( true );
	}

	// ========================================
	// Many-to-Many Relation-Model Tests (v0.6.0+)
	// ========================================

	/**
	 * Test auto-generated hooks from relation-model associations
	 *
	 * v0.6.0: Site relations can have multiple associated models.
	 * Hooks should be generated for all associated models.
	 *
	 * @since 0.9.1
	 */
	public function test_auto_register_hooks_from_relation_models() {
		if ( ! $this->test_relation_id || ! $this->test_model_id ) {
			$this->assertTrue( true ); // Skip if no test data
			return;
		}

		// Associate the model with the relation
		global $wpdb;
		$relation_models_table = $wpdb->prefix . 'wptsall_relation_models';

		// Check if table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $relation_models_table )
		);

		if ( ! $table_exists ) {
			$this->assertTrue( true ); // Skip if table doesn't exist
			return;
		}

		// Insert association
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$relation_models_table,
			array(
				'relation_id' => $this->test_relation_id,
				'model_id'    => $this->test_model_id,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s' )
		);

		// Generate hooks for this specific relation
		$hooks = Hook_Manager::generate_auto_hooks( $this->test_relation_id );

		$this->assertIsArray( $hooks );

		// Should have hooks generated for the associated model
		if ( ! empty( $hooks ) ) {
			// Check that hooks include the model_id
			$model_ids = array_unique( array_column( $hooks, 'model_id' ) );
			$this->assertContains( $this->test_model_id, $model_ids );

			// Check that hooks include various types
			$hook_names = array_column( $hooks, 'hook_name' );
			// Should have post type hooks (save_post_post, save_post_page) and frontend hooks
			$this->assertTrue(
				in_array( 'save_post_post', $hook_names, true ) ||
				in_array( 'save_post_page', $hook_names, true ) ||
				in_array( 'save_post', $hook_names, true ),
				'Should have save_post hooks for post types'
			);
		}

		// Clean up
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$relation_models_table,
			array(
				'relation_id' => $this->test_relation_id,
				'model_id'    => $this->test_model_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Test that taxonomy hooks use model configuration
	 *
	 * Taxonomies from model config should generate appropriate hooks.
	 *
	 * @since 0.9.1
	 */
	public function test_get_associated_taxonomies_uses_model_config() {
		// Use reflection to test generate_model_hooks directly
		$method = new \ReflectionMethod( Hook_Manager::class, 'generate_model_hooks' );
		$method->setAccessible( true );

		$relation = array(
			'id' => 1,
		);

		// Model with taxonomies from config
		$model = array(
			'id'          => 100,
			'plugin_slug' => 'test-taxonomy-plugin',
			'post_types'  => array(),
			'taxonomies'  => array(
				array( 'name' => 'product_cat' ),
				array( 'name' => 'product_tag' ),
			),
		);

		$hooks = $method->invoke( null, $relation, $model, false, true );

		$this->assertIsArray( $hooks );
		$this->assertNotEmpty( $hooks );

		// Check for taxonomy hooks
		$hook_names = array_column( $hooks, 'hook_name' );

		$this->assertContains( 'created_term', $hook_names, 'Should have created_term hook' );
		$this->assertContains( 'edited_term', $hook_names, 'Should have edited_term hook' );
		$this->assertContains( 'delete_term', $hook_names, 'Should have delete_term hook' );

		// Check that hooks have correct expected subtype
		$created_term_hooks = array_filter( $hooks, function( $h ) {
			return $h['hook_name'] === 'created_term' && ! empty( $h['expected']['subtype'] );
		} );

		$subtypes = array_column( array_column( $created_term_hooks, 'expected' ), 'subtype' );
		$this->assertContains( 'product_cat', $subtypes, 'Should have product_cat taxonomy' );
		$this->assertContains( 'product_tag', $subtypes, 'Should have product_tag taxonomy' );
	}

	// ========================================
	// Parse Model Objects Tests (v0.9.1)
	// ========================================

	/**
	 * Test parse_model_objects with JSON string
	 *
	 * @since 0.9.1
	 */
	public function test_parse_model_objects_json_string() {
		$method = new \ReflectionMethod( Hook_Manager::class, 'parse_model_objects' );
		$method->setAccessible( true );

		$json = wp_json_encode( array(
			array( 'name' => 'post' ),
			array( 'name' => 'page' ),
		) );

		$result = $method->invoke( null, $json );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertEquals( 'post', $result[0]['name'] );
		$this->assertEquals( 'page', $result[1]['name'] );
	}

	/**
	 * Test parse_model_objects with array input
	 *
	 * @since 0.9.1
	 */
	public function test_parse_model_objects_array_input() {
		$method = new \ReflectionMethod( Hook_Manager::class, 'parse_model_objects' );
		$method->setAccessible( true );

		$array = array(
			array( 'name' => 'product' ),
			array( 'name' => 'shop_order' ),
		);

		$result = $method->invoke( null, $array );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertEquals( 'product', $result[0]['name'] );
	}

	/**
	 * Test parse_model_objects with empty input
	 *
	 * @since 0.9.1
	 */
	public function test_parse_model_objects_empty_input() {
		$method = new \ReflectionMethod( Hook_Manager::class, 'parse_model_objects' );
		$method->setAccessible( true );

		$result1 = $method->invoke( null, '' );
		$this->assertIsArray( $result1 );
		$this->assertEmpty( $result1 );

		$result2 = $method->invoke( null, null );
		$this->assertIsArray( $result2 );
		$this->assertEmpty( $result2 );

		$result3 = $method->invoke( null, array() );
		$this->assertIsArray( $result3 );
		$this->assertEmpty( $result3 );
	}

	/**
	 * Test parse_model_objects with invalid JSON
	 *
	 * @since 0.9.1
	 */
	public function test_parse_model_objects_invalid_json() {
		$method = new \ReflectionMethod( Hook_Manager::class, 'parse_model_objects' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'not valid json' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// ========================================
	// Generate Model Hooks Tests (v0.9.1)
	// ========================================

	/**
	 * Test generate_model_hooks includes model_id in all hooks
	 *
	 * v0.6.0: Many-to-many tracking requires model_id in hooks.
	 *
	 * @since 0.9.1
	 */
	public function test_generate_model_hooks_includes_model_id() {
		$method = new \ReflectionMethod( Hook_Manager::class, 'generate_model_hooks' );
		$method->setAccessible( true );

		$relation = array( 'id' => 1 );
		$model = array(
			'id'          => 42,
			'plugin_slug' => 'test-plugin',
			'post_types'  => array( array( 'name' => 'post' ) ),
			'taxonomies'  => array(),
		);

		$hooks = $method->invoke( null, $relation, $model, false, true );

		foreach ( $hooks as $hook ) {
			$this->assertArrayHasKey( 'model_id', $hook );
			$this->assertEquals( 42, $hook['model_id'] );
		}
	}

	/**
	 * Test generate_model_hooks includes site_id (relation_id)
	 *
	 * @since 0.9.1
	 */
	public function test_generate_model_hooks_includes_site_id() {
		$method = new \ReflectionMethod( Hook_Manager::class, 'generate_model_hooks' );
		$method->setAccessible( true );

		$relation = array( 'id' => 99 );
		$model = array(
			'id'          => 5,
			'plugin_slug' => 'test-plugin',
			'post_types'  => array( array( 'name' => 'post' ) ),
			'taxonomies'  => array(),
		);

		$hooks = $method->invoke( null, $relation, $model, false, true );

		foreach ( $hooks as $hook ) {
			$this->assertArrayHasKey( 'site_id', $hook );
			$this->assertEquals( 99, $hook['site_id'] );
		}
	}

	/**
	 * Test generate_model_hooks does not register frontend delivery hooks as listen events.
	 *
	 * @since 2.1.0
	 */
	public function test_generate_model_hooks_virtual_target_has_no_frontend_listen_hooks() {
		$method = new \ReflectionMethod( Hook_Manager::class, 'generate_model_hooks' );
		$method->setAccessible( true );

		$relation = array( 'id' => 1 );
		$model = array(
			'id'          => 5,
			'plugin_slug' => 'test-plugin',
			'post_types'  => array( array( 'name' => 'post' ) ),
			'taxonomies'  => array(),
		);

		$hooks      = $method->invoke( null, $relation, $model, true, true );
		$hook_names = array_column( $hooks, 'hook_name' );

		$this->assertNotContains( 'template_include', $hook_names );
		$this->assertNotContains( 'template_redirect', $hook_names );
		$this->assertNotContains( 'pre_get_posts', $hook_names );
		$this->assertContains( 'added_post_meta', $hook_names );
		$this->assertContains( 'updated_post_meta', $hook_names );
		$this->assertContains( 'set_object_terms', $hook_names );
		$this->assertContains( 'save_post_post', $hook_names );
	}

	/**
	 * Test generate_model_hooks skips save hooks for non-WP source
	 *
	 * @since 0.9.1
	 */
	public function test_generate_model_hooks_non_wp_source_no_save_hooks() {
		$method = new \ReflectionMethod( Hook_Manager::class, 'generate_model_hooks' );
		$method->setAccessible( true );

		$relation = array( 'id' => 1 );
		$model = array(
			'id'          => 5,
			'plugin_slug' => 'test-plugin',
			'post_types'  => array( array( 'name' => 'post' ) ),
			'taxonomies'  => array( array( 'name' => 'category' ) ),
		);

		// Non-WP source (is_wp_source = false)
		$hooks = $method->invoke( null, $relation, $model, false, false );

		$hook_names = array_column( $hooks, 'hook_name' );

		// Should NOT have save/delete/meta listen hooks
		$this->assertNotContains( 'save_post_post', $hook_names );
		$this->assertNotContains( 'delete_post', $hook_names );
		$this->assertNotContains( 'created_term', $hook_names );
		$this->assertNotContains( 'added_post_meta', $hook_names );

		// Frontend delivery is not a listen event.
		$this->assertNotContains( 'template_include', $hook_names );
	}

	/**
	 * Listen extractors: post / term / meta / ignored keys.
	 *
	 * @since 2.1.0
	 */
	public function test_extract_listen_object_id_from_native_hook_args() {
		$this->assertEquals( 42, Hook_Manager::extract_listen_object_id( 'save_post_product', array( 42 ) ) );
		$this->assertEquals( 7, Hook_Manager::extract_listen_object_id( 'edited_term', array( 7, 11, 'product_cat' ) ) );
		$this->assertEquals( 99, Hook_Manager::extract_listen_object_id( 'updated_post_meta', array( 1, 99, '_elementor_data', '{}' ) ) );
		$this->assertEquals( 15, Hook_Manager::extract_listen_object_id( 'set_object_terms', array( 15, array( 1 ), array( 2 ), 'product_cat' ) ) );
		$this->assertEquals( 'post', Hook_Manager::classify_listen_target( 'updated_post_meta' ) );
		$this->assertEquals( 'term', Hook_Manager::classify_listen_target( 'edited_term' ) );
		$this->assertTrue( Hook_Manager::should_ignore_listen_meta_key( '_wptsall_virtual_site_id' ) );
		$this->assertTrue( Hook_Manager::should_ignore_listen_meta_key( '_edit_lock' ) );
		$this->assertFalse( Hook_Manager::should_ignore_listen_meta_key( '_elementor_data' ) );
		$this->assertTrue( Hook_Manager::is_frontend_delivery_hook( 'template_include' ) );
		$this->assertFalse( Hook_Manager::is_frontend_delivery_hook( 'save_post_product' ) );
	}

	/**
	 * run_hook_action must not mark resync for frontend delivery hooks or internal meta.
	 *
	 * @since 2.1.0
	 */
	public function test_run_hook_action_skips_frontend_and_internal_meta() {
		$before = $this->count_mappings_needing_resync();
		Hook_Manager::run_hook_action(
			array( 'hook_name' => 'template_include' ),
			array( 'index.php' )
		);
		Hook_Manager::run_hook_action(
			array( 'hook_name' => 'updated_post_meta' ),
			array( 1, 123456, '_wptsall_last_synced', 'now' )
		);
		$after = $this->count_mappings_needing_resync();
		$this->assertEquals( $before, $after, 'Frontend/internal-meta listen must not dirty-mark' );
	}

	/**
	 * @return int
	 */
	private function count_mappings_needing_resync() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_post_mappings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE needs_resync = 1" );
	}
}
