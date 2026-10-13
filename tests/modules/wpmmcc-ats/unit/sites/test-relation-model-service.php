<?php
/**
 * Relation Model Service Tests
 *
 * Tests for WPTSALL\Sites\Services\Relation_Model_Service class
 *
 * Tests the many-to-many relationship between site relations and models (plugins).
 *
 * @package WPTSALL\Tests\Unit\Sites
 * @since 0.6.0
 */

use WPTSALL\Sites\Services\Relation_Model_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

class Test_Relation_Model_Service extends SimpleTestCase {

	/**
	 * Test relation ID created during tests
	 *
	 * @var int
	 */
	private $test_relation_id = 0;

	/**
	 * Test model IDs created during tests
	 *
	 * @var array
	 */
	private $test_model_ids = array();

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure tables exist
		$this->ensure_tables_exist();

		// Create test relation
		$this->test_relation_id = $this->create_test_relation();

		// Create test models
		$this->test_model_ids = $this->create_test_models( 3 );
	}

	/**
	 * Ensure required tables exist
	 */
	private function ensure_tables_exist() {
		global $wpdb;

		// Relation models table
		$rm_table = $wpdb->prefix . 'wptsall_relation_models';
		$sql      = "CREATE TABLE IF NOT EXISTS {$rm_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			relation_id BIGINT(20) UNSIGNED NOT NULL,
			model_id BIGINT(20) UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY relation_model (relation_id, model_id),
			INDEX idx_relation_id (relation_id),
			INDEX idx_model_id (model_id)
		) {$wpdb->get_charset_collate()};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Models table
		$models_table = $wpdb->prefix . 'wptsall_models';
		$sql          = "CREATE TABLE IF NOT EXISTS {$models_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			plugin_slug VARCHAR(100) NOT NULL,
			plugin_name VARCHAR(255) NOT NULL,
			plugin_version VARCHAR(20) DEFAULT NULL,
			post_types LONGTEXT DEFAULT NULL,
			taxonomies LONGTEXT DEFAULT NULL,
			status VARCHAR(20) DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY unique_plugin (plugin_slug)
		) {$wpdb->get_charset_collate()};";

		dbDelta( $sql );

		// Site relations table
		$relations_table = $wpdb->prefix . 'wptsall_site_relations';
		$sql             = "CREATE TABLE IF NOT EXISTS {$relations_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			source_site_id BIGINT(20) UNSIGNED NOT NULL,
			source_site_type VARCHAR(20) DEFAULT 'wp',
			source_lang VARCHAR(20) NOT NULL DEFAULT '',
			template VARCHAR(100) NOT NULL,
			target_site_id VARCHAR(50) NOT NULL,
			target_site_type VARCHAR(20) NOT NULL DEFAULT 'wp',
			target_lang VARCHAR(20) NOT NULL DEFAULT '',
			status VARCHAR(20) DEFAULT 'active',
			models_count INT DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id)
		) {$wpdb->get_charset_collate()};";

		dbDelta( $sql );
	}

	/**
	 * Create a test relation
	 *
	 * @return int Relation ID
	 */
	private function create_test_relation() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'source_site_id'   => 1,
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'test-plugin-' . uniqid(),
				'target_site_id'   => 'v_test',
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'models_count'     => 0,
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	/**
	 * Create test models
	 *
	 * @param int $count Number of models to create
	 * @return array Model IDs
	 */
	private function create_test_models( $count = 3 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_models';
		$ids   = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$slug = 'test-model-' . uniqid();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'plugin_slug' => $slug,
					'plugin_name' => 'Test Model ' . ( $i + 1 ),
					'status'      => 'active',
					'created_at'  => current_time( 'mysql' ),
					'updated_at'  => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);
			$ids[] = $wpdb->insert_id;
		}

		return $ids;
	}

	// ==================== add_model_to_relation() Tests ====================

	/**
	 * Test add_model_to_relation adds successfully
	 */
	public function test_add_model_to_relation_success() {
		$model_id = $this->test_model_ids[0];

		$result = Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $model_id );

		$this->assertNotFalse( $result );
		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
	}

	/**
	 * Test add_model_to_relation returns existing ID for duplicate
	 */
	public function test_add_model_duplicate_returns_existing_id() {
		$model_id = $this->test_model_ids[0];

		// First add
		$first_id = Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $model_id );
		$this->assertNotFalse( $first_id );

		// Query the actual row ID from the database to avoid $wpdb->insert_id
		// being clobbered by side-effects (hooks, logging, etc.).
		global $wpdb;
		$rm_table   = $wpdb->prefix . 'wptsall_relation_models';
		$actual_id  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$rm_table} WHERE relation_id = %d AND model_id = %d",
				$this->test_relation_id,
				$model_id
			)
		);

		// Second add (duplicate)
		$second_id = Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $model_id );

		// Should return the actual row ID (existing record)
		$this->assertEquals( $actual_id, (int) $second_id );

		// Should still be just one row (no duplicate inserted)
		$count = Relation_Model_Service::get_models_count( $this->test_relation_id );
		$this->assertEquals( 1, $count );
	}

	/**
	 * Test add multiple models to same relation
	 */
	public function test_add_multiple_models_to_relation() {
		foreach ( $this->test_model_ids as $model_id ) {
			$result = Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $model_id );
			$this->assertNotFalse( $result );
		}

		// Verify count
		$count = Relation_Model_Service::get_models_count( $this->test_relation_id );
		$this->assertEquals( count( $this->test_model_ids ), $count );
	}

	// ==================== get_models_by_relation() Tests ====================

	/**
	 * Test get_models_by_relation returns array
	 */
	public function test_get_models_by_relation_returns_array() {
		$models = Relation_Model_Service::get_models_by_relation( $this->test_relation_id );

		$this->assertIsArray( $models );
	}

	/**
	 * Test get_models_by_relation returns correct models
	 */
	public function test_get_models_by_relation_returns_correct_models() {
		// Add models
		foreach ( $this->test_model_ids as $model_id ) {
			Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $model_id );
		}

		$models = Relation_Model_Service::get_models_by_relation( $this->test_relation_id );

		$this->assertCount( count( $this->test_model_ids ), $models );

		// Check model IDs
		$returned_ids = array_column( $models, 'id' );
		foreach ( $this->test_model_ids as $expected_id ) {
			$this->assertContains( (string) $expected_id, $returned_ids );
		}
	}

	/**
	 * Test get_models_by_relation returns empty array for non-existent relation
	 */
	public function test_get_models_by_relation_empty_for_non_existent() {
		$models = Relation_Model_Service::get_models_by_relation( 99999 );

		$this->assertIsArray( $models );
		$this->assertEmpty( $models );
	}

	/**
	 * Test get_models_by_relation includes associated_at field
	 */
	public function test_get_models_by_relation_includes_associated_at() {
		Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $this->test_model_ids[0] );

		$models = Relation_Model_Service::get_models_by_relation( $this->test_relation_id );

		$this->assertNotEmpty( $models );
		$this->assertArrayHasKey( 'associated_at', $models[0] );
	}

	// ==================== get_relations_by_model() Tests ====================

	/**
	 * Test get_relations_by_model returns correct relations
	 */
	public function test_get_relations_by_model() {
		$model_id = $this->test_model_ids[0];
		Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $model_id );

		$relations = Relation_Model_Service::get_relations_by_model( $model_id );

		$this->assertIsArray( $relations );
		$this->assertNotEmpty( $relations );
		$this->assertEquals( (string) $this->test_relation_id, $relations[0]['id'] );
	}

	/**
	 * Test get_relations_by_model returns empty for non-associated model
	 */
	public function test_get_relations_by_model_empty_for_non_associated() {
		$relations = Relation_Model_Service::get_relations_by_model( 99999 );

		$this->assertIsArray( $relations );
		$this->assertEmpty( $relations );
	}

	// ==================== remove_model_from_relation() Tests ====================

	/**
	 * Test remove_model_from_relation success
	 */
	public function test_remove_model_from_relation_success() {
		$model_id = $this->test_model_ids[0];

		// Add first
		Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $model_id );

		// Remove
		$result = Relation_Model_Service::remove_model_from_relation( $this->test_relation_id, $model_id );

		$this->assertTrue( $result );

		// Verify removed
		$is_associated = Relation_Model_Service::is_model_associated( $this->test_relation_id, $model_id );
		$this->assertFalse( $is_associated );
	}

	/**
	 * Test remove_model_from_relation returns true even for non-existent
	 */
	public function test_remove_model_non_existent() {
		$result = Relation_Model_Service::remove_model_from_relation( 99999, 99999 );

		// wpdb->delete returns 0 for no rows affected, not false
		$this->assertTrue( $result );
	}

	// ==================== set_relation_models() Tests ====================

	/**
	 * Test set_relation_models replaces all models
	 */
	public function test_set_relation_models_replaces_all() {
		// Add initial model
		Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $this->test_model_ids[0] );

		// Replace with different models
		$new_model_ids = array( $this->test_model_ids[1], $this->test_model_ids[2] );
		$result        = Relation_Model_Service::set_relation_models( $this->test_relation_id, $new_model_ids );

		$this->assertTrue( $result );

		// Verify old model removed
		$is_associated = Relation_Model_Service::is_model_associated( $this->test_relation_id, $this->test_model_ids[0] );
		$this->assertFalse( $is_associated );

		// Verify new models added
		$count = Relation_Model_Service::get_models_count( $this->test_relation_id );
		$this->assertEquals( 2, $count );
	}

	/**
	 * Test set_relation_models with empty array clears all
	 */
	public function test_set_relation_models_empty_clears_all() {
		// Add models first
		foreach ( $this->test_model_ids as $model_id ) {
			Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $model_id );
		}

		// Clear all
		$result = Relation_Model_Service::set_relation_models( $this->test_relation_id, array() );

		$this->assertTrue( $result );

		// Verify cleared
		$count = Relation_Model_Service::get_models_count( $this->test_relation_id );
		$this->assertEquals( 0, $count );
	}

	// ==================== is_model_associated() Tests ====================

	/**
	 * Test is_model_associated returns true for associated model
	 */
	public function test_is_model_associated_true() {
		$model_id = $this->test_model_ids[0];
		Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $model_id );

		$result = Relation_Model_Service::is_model_associated( $this->test_relation_id, $model_id );

		$this->assertTrue( $result );
	}

	/**
	 * Test is_model_associated returns false for non-associated model
	 */
	public function test_is_model_associated_false() {
		$result = Relation_Model_Service::is_model_associated( $this->test_relation_id, 99999 );

		$this->assertFalse( $result );
	}

	// ==================== get_models_count() Tests ====================

	/**
	 * Test get_models_count returns correct count
	 */
	public function test_get_models_count() {
		// Add models
		Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $this->test_model_ids[0] );
		Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $this->test_model_ids[1] );

		$count = Relation_Model_Service::get_models_count( $this->test_relation_id );

		$this->assertEquals( 2, $count );
	}

	/**
	 * Test get_models_count returns 0 for empty relation
	 */
	public function test_get_models_count_zero_for_empty() {
		$count = Relation_Model_Service::get_models_count( $this->test_relation_id );

		$this->assertEquals( 0, $count );
	}

	// ==================== delete_relation_models() Tests ====================

	/**
	 * Test delete_relation_models removes all associations
	 */
	public function test_delete_relation_models() {
		// Add models first
		foreach ( $this->test_model_ids as $model_id ) {
			Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $model_id );
		}

		// Delete all
		$result = Relation_Model_Service::delete_relation_models( $this->test_relation_id );

		$this->assertTrue( $result );

		// Verify all removed
		$count = Relation_Model_Service::get_models_count( $this->test_relation_id );
		$this->assertEquals( 0, $count );
	}

	// ==================== update_models_count() Tests ====================

	/**
	 * Test update_models_count updates the cache field
	 */
	public function test_update_models_count_updates_cache() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';

		// Add models
		Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $this->test_model_ids[0] );
		Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $this->test_model_ids[1] );

		// Check models_count in site_relations table
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$cached_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT models_count FROM {$table} WHERE id = %d",
				$this->test_relation_id
			)
		);

		$this->assertEquals( 2, (int) $cached_count );
	}

	// ==================== Model_Config_Provider Integration Tests (v0.9.0) ====================

	/**
	 * Test model association works with valid content plugin
	 *
	 * Verifies that the association flow works correctly when
	 * the model corresponds to a valid content plugin.
	 *
	 * @since 0.9.0
	 */
	public function test_associate_model_works_with_content_plugin() {
		$model_id = $this->test_model_ids[0];

		// Add model to relation
		$result = Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $model_id );

		// Should succeed
		$this->assertNotFalse( $result );
		$this->assertTrue( Relation_Model_Service::is_model_associated( $this->test_relation_id, $model_id ) );
	}

	/**
	 * Test get_models_by_relation returns model metadata
	 *
	 * The returned models should include plugin_slug and plugin_name
	 * which can be used with Model_Config_Provider for additional queries.
	 *
	 * @since 0.9.0
	 */
	public function test_get_models_returns_plugin_metadata() {
		// Add a model
		Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $this->test_model_ids[0] );

		// Get models
		$models = Relation_Model_Service::get_models_by_relation( $this->test_relation_id );

		$this->assertNotEmpty( $models );
		$this->assertArrayHasKey( 'plugin_slug', $models[0] );
		$this->assertArrayHasKey( 'plugin_name', $models[0] );
	}

	/**
	 * Test multiple models can be associated with same relation
	 *
	 * Verifies the many-to-many relationship works correctly,
	 * which is essential for relations that sync multiple content types.
	 *
	 * @since 0.9.0
	 */
	public function test_multiple_content_plugins_can_be_associated() {
		// Associate all test models
		foreach ( $this->test_model_ids as $model_id ) {
			Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $model_id );
		}

		// Get models count
		$count = Relation_Model_Service::get_models_count( $this->test_relation_id );

		$this->assertEquals( count( $this->test_model_ids ), $count );

		// Get models and verify all are present
		$models      = Relation_Model_Service::get_models_by_relation( $this->test_relation_id );
		$model_ids   = array_column( $models, 'id' );

		foreach ( $this->test_model_ids as $expected_id ) {
			$this->assertContains( (string) $expected_id, $model_ids );
		}
	}

	/**
	 * Test model association preserves plugin slug for API queries
	 *
	 * The plugin_slug field is used by Model_Config_Provider to query
	 * field configurations and other metadata.
	 *
	 * @since 0.9.0
	 */
	public function test_association_preserves_plugin_slug_for_api() {
		global $wpdb;
		$models_table = $wpdb->prefix . 'wptsall_models';

		// Get the plugin_slug of the first test model
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$expected_slug = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT plugin_slug FROM {$models_table} WHERE id = %d",
				$this->test_model_ids[0]
			)
		);

		// Associate the model
		Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $this->test_model_ids[0] );

		// Get models
		$models = Relation_Model_Service::get_models_by_relation( $this->test_relation_id );

		// Verify plugin_slug is preserved
		$this->assertEquals( $expected_slug, $models[0]['plugin_slug'] );
	}

	/**
	 * Test models_count cache is updated for API efficiency
	 *
	 * The models_count field in site_relations table provides quick access
	 * without needing to count from relation_models table.
	 *
	 * @since 0.9.0
	 */
	public function test_models_count_cache_supports_api_efficiency() {
		global $wpdb;
		$relations_table = $wpdb->prefix . 'wptsall_site_relations';

		// Add models
		Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $this->test_model_ids[0] );
		Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $this->test_model_ids[1] );

		// Check cached count
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$cached_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT models_count FROM {$relations_table} WHERE id = %d",
				$this->test_relation_id
			)
		);

		// API can quickly get count without JOIN
		$this->assertEquals( 2, (int) $cached_count );
	}

	/**
	 * Clean up after all tests
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up relation_models
		$rm_table = $wpdb->prefix . 'wptsall_relation_models';
		if ( $this->test_relation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $rm_table, array( 'relation_id' => $this->test_relation_id ), array( '%d' ) );
		}

		// Clean up test models
		$models_table = $wpdb->prefix . 'wptsall_models';
		foreach ( $this->test_model_ids as $model_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $models_table, array( 'id' => $model_id ), array( '%d' ) );
		}

		// Clean up test relation
		$relations_table = $wpdb->prefix . 'wptsall_site_relations';
		if ( $this->test_relation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $relations_table, array( 'id' => $this->test_relation_id ), array( '%d' ) );
		}

		parent::tearDown();
	}
}
