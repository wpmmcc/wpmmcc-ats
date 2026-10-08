<?php
/**
 * Field Discovery Service Tests
 *
 * Tests for WPTSALL\Models\Services\Field_Discovery_Service class
 *
 * @package WPTSALL\Tests\Unit\Models
 * @since 0.9.0
 */

use WPTSALL\Models\Services\Field_Discovery_Service;

class Test_Field_Discovery_Service extends SimpleTestCase {

	/**
	 * Test post ID for cleanup
	 *
	 * @var int
	 */
	private $test_post_id = 0;

	/**
	 * Clean up after each test
	 */
	public function tearDown(): void {
		if ( $this->test_post_id ) {
			wp_delete_post( $this->test_post_id, true );
			$this->test_post_id = 0;
		}
		parent::tearDown();
	}

	// ==================== Class Existence Tests ====================

	/**
	 * Test service class exists
	 */
	public function test_service_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Models\Services\Field_Discovery_Service' ) );
	}

	/**
	 * Test service has required methods
	 */
	public function test_service_has_required_methods() {
		$methods = array(
			'get_tables',
			'get_table_columns',
			'get_post_meta_keys',
			'get_distinct_values',
			'test_field_config',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( Field_Discovery_Service::class, $method ),
				"Method {$method} should exist"
			);
		}
	}

	// ==================== get_tables() Tests ====================

	/**
	 * Test get_tables returns array
	 */
	public function test_get_tables_returns_array() {
		$tables = Field_Discovery_Service::get_tables();

		$this->assertIsArray( $tables );
		$this->assertNotEmpty( $tables );
	}

	/**
	 * Test get_tables contains core WordPress tables
	 */
	public function test_get_tables_contains_core_tables() {
		global $wpdb;

		$tables = Field_Discovery_Service::get_tables();

		$this->assertContains( $wpdb->posts, $tables );
		$this->assertContains( $wpdb->postmeta, $tables );
		$this->assertNotContains( $wpdb->options, $tables );
		$this->assertNotContains( $wpdb->users, $tables );
		$this->assertNotContains( $wpdb->usermeta, $tables );
	}

	/**
	 * Test get_tables only returns tables with WordPress prefix
	 */
	public function test_get_tables_only_wp_prefix() {
		global $wpdb;

		$tables = Field_Discovery_Service::get_tables();

		foreach ( $tables as $table ) {
			$this->assertStringStartsWith(
				$wpdb->prefix,
				$table,
				"Table {$table} should start with WordPress prefix"
			);
		}
	}

	// ==================== get_table_columns() Tests ====================

	/**
	 * Test get_table_columns returns array for valid table
	 */
	public function test_get_table_columns_returns_array() {
		global $wpdb;

		$columns = Field_Discovery_Service::get_table_columns( $wpdb->posts );

		$this->assertIsArray( $columns );
		$this->assertNotEmpty( $columns );
	}

	/**
	 * Test get_table_columns contains expected columns for posts table
	 */
	public function test_get_table_columns_posts_structure() {
		global $wpdb;

		$columns = Field_Discovery_Service::get_table_columns( $wpdb->posts );
		$field_names = array_column( $columns, 'field' );

		$this->assertContains( 'ID', $field_names );
		$this->assertContains( 'post_title', $field_names );
		$this->assertContains( 'post_content', $field_names );
		$this->assertContains( 'post_status', $field_names );
		$this->assertContains( 'post_type', $field_names );
	}

	/**
	 * Test get_table_columns returns column type info
	 */
	public function test_get_table_columns_includes_type() {
		global $wpdb;

		$columns = Field_Discovery_Service::get_table_columns( $wpdb->posts );

		foreach ( $columns as $column ) {
			$this->assertArrayHasKey( 'field', $column );
			$this->assertArrayHasKey( 'type', $column );
			$this->assertNotEmpty( $column['type'] );
		}
	}

	/**
	 * Test get_table_columns returns empty for invalid table
	 */
	public function test_get_table_columns_invalid_table() {
		$columns = Field_Discovery_Service::get_table_columns( 'nonexistent_table' );

		$this->assertIsArray( $columns );
		$this->assertEmpty( $columns );
	}

	/**
	 * Test get_table_columns rejects tables without WordPress prefix
	 */
	public function test_get_table_columns_rejects_non_wp_table() {
		$columns = Field_Discovery_Service::get_table_columns( 'mysql.user' );

		$this->assertIsArray( $columns );
		$this->assertEmpty( $columns );
	}

	// ==================== get_post_meta_keys() Tests ====================

	/**
	 * Test get_post_meta_keys returns array
	 */
	public function test_get_post_meta_keys_returns_array() {
		$keys = Field_Discovery_Service::get_post_meta_keys();

		$this->assertIsArray( $keys );
	}

	/**
	 * Test get_post_meta_keys with post_type filter
	 */
	public function test_get_post_meta_keys_with_post_type() {
		// Create test post with meta
		$this->test_post_id = wp_insert_post( array(
			'post_title'  => 'Test Post for Meta Keys',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		update_post_meta( $this->test_post_id, 'test_meta_key_' . uniqid(), 'test_value' );

		$keys = Field_Discovery_Service::get_post_meta_keys( 'post' );

		$this->assertIsArray( $keys );
	}

	/**
	 * Test get_post_meta_keys excludes hidden keys by default
	 */
	public function test_get_post_meta_keys_excludes_hidden() {
		// Create test post with hidden meta
		$this->test_post_id = wp_insert_post( array(
			'post_title'  => 'Test Post for Hidden Meta',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$hidden_key = '_hidden_meta_' . uniqid();
		update_post_meta( $this->test_post_id, $hidden_key, 'hidden_value' );

		$keys = Field_Discovery_Service::get_post_meta_keys( 'post', false );

		// Should not contain the hidden key
		$this->assertNotContains( $hidden_key, $keys );
	}

	/**
	 * Test get_post_meta_keys includes hidden keys when requested
	 */
	public function test_get_post_meta_keys_includes_hidden() {
		// Create test post with hidden meta
		$this->test_post_id = wp_insert_post( array(
			'post_title'  => 'Test Post for Hidden Meta Include',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$hidden_key = '_hidden_meta_include_' . uniqid();
		update_post_meta( $this->test_post_id, $hidden_key, 'hidden_value' );

		$keys = Field_Discovery_Service::get_post_meta_keys( 'post', true );

		// Should contain the hidden key
		$this->assertContains( $hidden_key, $keys );
	}

	/**
	 * Test get_post_meta_keys all types
	 */
	public function test_get_post_meta_keys_all_types() {
		$keys = Field_Discovery_Service::get_post_meta_keys( '', false );

		$this->assertIsArray( $keys );
	}

	// ==================== get_distinct_values() Tests ====================

	/**
	 * Test get_distinct_values returns array
	 */
	public function test_get_distinct_values_returns_array() {
		global $wpdb;

		$values = Field_Discovery_Service::get_distinct_values( $wpdb->posts, 'post_status' );

		$this->assertIsArray( $values );
	}

	/**
	 * Test get_distinct_values contains expected post statuses
	 */
	public function test_get_distinct_values_post_status() {
		global $wpdb;

		// Ensure we have a published post
		$this->test_post_id = wp_insert_post( array(
			'post_title'  => 'Test Post for Distinct Values',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$values = Field_Discovery_Service::get_distinct_values( $wpdb->posts, 'post_status' );

		$this->assertContains( 'publish', $values );
	}

	/**
	 * Test get_distinct_values respects limit
	 */
	public function test_get_distinct_values_limit() {
		global $wpdb;

		$values = Field_Discovery_Service::get_distinct_values( $wpdb->posts, 'post_status', 2 );

		$this->assertLessThanOrEqual( 2, count( $values ) );
	}

	/**
	 * Test get_distinct_values returns empty for invalid table
	 */
	public function test_get_distinct_values_invalid_table() {
		$values = Field_Discovery_Service::get_distinct_values( 'nonexistent_table', 'column' );

		$this->assertIsArray( $values );
		$this->assertEmpty( $values );
	}

	/**
	 * Test get_distinct_values returns empty for invalid column
	 */
	public function test_get_distinct_values_invalid_column() {
		global $wpdb;

		$values = Field_Discovery_Service::get_distinct_values( $wpdb->posts, 'nonexistent_column' );

		$this->assertIsArray( $values );
		$this->assertEmpty( $values );
	}

	/**
	 * Test get_distinct_values rejects non-wp prefix table
	 */
	public function test_get_distinct_values_rejects_non_wp_table() {
		$values = Field_Discovery_Service::get_distinct_values( 'mysql.user', 'Host' );

		$this->assertIsArray( $values );
		$this->assertEmpty( $values );
	}

	// ==================== test_field_config() Tests ====================

	/**
	 * Test test_field_config with valid postmeta config
	 */
	public function test_test_field_config_postmeta() {
		global $wpdb;

		// Create test post with meta
		$this->test_post_id = wp_insert_post( array(
			'post_title'  => 'Test Post for Field Config',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$meta_key = 'test_config_meta_' . uniqid();
		$meta_value = 'test_config_value_' . uniqid();
		update_post_meta( $this->test_post_id, $meta_key, $meta_value );

		$config = array(
			'table'             => $wpdb->postmeta,
			'field'             => $meta_key,
			'associated_id_map' => 'post_id',
		);

		$result = Field_Discovery_Service::test_field_config( $config, $this->test_post_id );

		$this->assertEquals( $meta_value, $result );
	}

	/**
	 * Test test_field_config with missing config fields
	 */
	public function test_test_field_config_missing_fields() {
		$config = array(
			'table' => 'wp_posts',
			// missing field and associated_id_map
		);

		$result = Field_Discovery_Service::test_field_config( $config, 1 );

		$this->assertStringContainsString( 'Error', $result );
	}

	/**
	 * Test test_field_config with empty table
	 */
	public function test_test_field_config_empty_table() {
		$config = array(
			'table'             => '',
			'field'             => 'test_field',
			'associated_id_map' => 'post_id',
		);

		$result = Field_Discovery_Service::test_field_config( $config, 1 );

		$this->assertStringContainsString( 'Error', $result );
	}

	/**
	 * Test test_field_config returns no data for nonexistent meta
	 */
	public function test_test_field_config_no_data() {
		global $wpdb;

		// Create test post without the specific meta
		$this->test_post_id = wp_insert_post( array(
			'post_title'  => 'Test Post No Meta',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$config = array(
			'table'             => $wpdb->postmeta,
			'field'             => 'nonexistent_meta_key_' . uniqid(),
			'associated_id_map' => 'post_id',
		);

		$result = Field_Discovery_Service::test_field_config( $config, $this->test_post_id );

		$this->assertStringContainsString( 'No Data Found', $result );
	}

	/**
	 * Test test_field_config with custom table
	 */
	public function test_test_field_config_custom_table() {
		global $wpdb;

		// Create test post
		$this->test_post_id = wp_insert_post( array(
			'post_title'  => 'Test Post for Custom Table',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$config = array(
			'table'             => $wpdb->posts,
			'field'             => 'post_title',
			'associated_id_map' => 'ID',
		);

		$result = Field_Discovery_Service::test_field_config( $config, $this->test_post_id );

		$this->assertEquals( 'Test Post for Custom Table', $result );
	}

	/**
	 * Test test_field_config rejects invalid table prefix
	 */
	public function test_test_field_config_invalid_prefix() {
		$config = array(
			'table'             => 'mysql_user',
			'field'             => 'Host',
			'associated_id_map' => 'User',
		);

		$result = Field_Discovery_Service::test_field_config( $config, 1 );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	// ==================== Integration Tests ====================

	/**
	 * Test discovery workflow: tables -> columns -> values
	 */
	public function test_discovery_workflow() {
		global $wpdb;

		// Step 1: Get tables
		$tables = Field_Discovery_Service::get_tables();
		$this->assertContains( $wpdb->posts, $tables );

		// Step 2: Get columns for posts table
		$columns = Field_Discovery_Service::get_table_columns( $wpdb->posts );
		$field_names = array_column( $columns, 'field' );
		$this->assertContains( 'post_status', $field_names );

		// Step 3: Get distinct values for post_status
		$values = Field_Discovery_Service::get_distinct_values( $wpdb->posts, 'post_status' );
		$this->assertIsArray( $values );
	}

	/**
	 * Test meta discovery workflow
	 */
	public function test_meta_discovery_workflow() {
		global $wpdb;

		// Create test post with meta
		$this->test_post_id = wp_insert_post( array(
			'post_title'  => 'Test Post for Meta Workflow',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$meta_key = 'workflow_test_meta_' . uniqid();
		$meta_value = 'workflow_test_value';
		update_post_meta( $this->test_post_id, $meta_key, $meta_value );

		// Step 1: Discover meta keys
		$keys = Field_Discovery_Service::get_post_meta_keys( 'post', false );
		$this->assertContains( $meta_key, $keys );

		// Step 2: Test field config
		$config = array(
			'table'             => $wpdb->postmeta,
			'field'             => $meta_key,
			'associated_id_map' => 'post_id',
		);

		$result = Field_Discovery_Service::test_field_config( $config, $this->test_post_id );
		$this->assertEquals( $meta_value, $result );
	}
}
