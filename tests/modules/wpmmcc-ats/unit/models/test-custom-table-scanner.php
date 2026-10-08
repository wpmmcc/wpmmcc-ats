<?php
/**
 * Custom Table Scanner Tests
 *
 * Tests for WPTSALL\Models\Scanners\Custom_Table_Scanner class
 *
 * @package WPTSALL
 * @since 0.9.1
 */

use WPTSALL\Models\Scanners\Custom_Table_Scanner;

class Test_Custom_Table_Scanner extends WP_UnitTestCase {

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Tear down test environment
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	// ========================================
	// Class Existence Tests
	// ========================================

	/**
	 * Test Custom_Table_Scanner class exists
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Models\Scanners\Custom_Table_Scanner' ) );
	}

	/**
	 * Test required methods exist
	 */
	public function test_required_methods_exist() {
		$this->assertTrue( method_exists( Custom_Table_Scanner::class, 'find_plugin_tables' ) );
		$this->assertTrue( method_exists( Custom_Table_Scanner::class, 'is_content_table' ) );
		$this->assertTrue( method_exists( Custom_Table_Scanner::class, 'analyze_table_fields' ) );
	}

	// ========================================
	// find_plugin_tables Tests
	// ========================================

	/**
	 * Test find_plugin_tables returns array
	 */
	public function test_find_plugin_tables_returns_array() {
		$tables = Custom_Table_Scanner::find_plugin_tables( 'nonexistent-plugin' );

		$this->assertIsArray( $tables );
	}

	/**
	 * Test find_plugin_tables for wptsall
	 *
	 * Our own tables should be filtered out.
	 */
	public function test_find_plugin_tables_excludes_wptsall() {
		$tables = Custom_Table_Scanner::find_plugin_tables( 'wptsall' );

		// WPTSALL tables should be filtered out
		foreach ( $tables as $table ) {
			$this->assertStringNotContainsString( 'wptsall_', $table['name'] );
		}
	}

	/**
	 * Test find_plugin_tables table structure
	 */
	public function test_find_plugin_tables_table_structure() {
		// Use woocommerce as a known plugin with custom tables.
		$tables = Custom_Table_Scanner::find_plugin_tables( 'woocommerce' );

		foreach ( $tables as $table ) {
			$this->assertArrayHasKey( 'name', $table );
			$this->assertArrayHasKey( 'row_count', $table );
			$this->assertArrayHasKey( 'columns', $table );
			$this->assertArrayHasKey( 'is_content', $table );
			$this->assertArrayHasKey( 'field_analysis', $table );
			break;
		}
	}

	// ========================================
	// is_content_table Tests
	// ========================================

	/**
	 * Test is_content_table with booking table name
	 */
	public function test_is_content_table_booking() {
		$result = Custom_Table_Scanner::is_content_table( 'wp_bookings', array() );
		$this->assertTrue( $result );
	}

	/**
	 * Test is_content_table with appointment table name
	 */
	public function test_is_content_table_appointment() {
		$result = Custom_Table_Scanner::is_content_table( 'wp_appointments', array() );
		$this->assertTrue( $result );
	}

	/**
	 * Test is_content_table with order table name
	 */
	public function test_is_content_table_order() {
		$result = Custom_Table_Scanner::is_content_table( 'wp_orders', array() );
		$this->assertTrue( $result );
	}

	/**
	 * Test is_content_table rejects log table
	 */
	public function test_is_content_table_rejects_log() {
		$result = Custom_Table_Scanner::is_content_table( 'wp_plugin_log', array() );
		$this->assertFalse( $result );
	}

	/**
	 * Test is_content_table rejects cache table
	 */
	public function test_is_content_table_rejects_cache() {
		$result = Custom_Table_Scanner::is_content_table( 'wp_plugin_cache', array() );
		$this->assertFalse( $result );
	}

	/**
	 * Test is_content_table rejects settings table
	 */
	public function test_is_content_table_rejects_settings() {
		$result = Custom_Table_Scanner::is_content_table( 'wp_plugin_settings', array() );
		$this->assertFalse( $result );
	}

	/**
	 * Test is_content_table rejects session table
	 */
	public function test_is_content_table_rejects_session() {
		$result = Custom_Table_Scanner::is_content_table( 'wp_plugin_sessions', array() );
		$this->assertFalse( $result );
	}

	/**
	 * Test is_content_table with content-like columns
	 */
	public function test_is_content_table_with_content_columns() {
		$columns = array(
			array( 'name' => 'id' ),
			array( 'name' => 'title' ),
			array( 'name' => 'created_at' ),
		);

		$result = Custom_Table_Scanner::is_content_table( 'wp_unknown_table', $columns );
		$this->assertTrue( $result );
	}

	/**
	 * Test is_content_table meta table exception
	 */
	public function test_is_content_table_meta_exception() {
		// Table ending with _meta should be content-related
		$result = Custom_Table_Scanner::is_content_table( 'wp_booking_meta', array() );
		$this->assertTrue( $result );
	}

	// ========================================
	// analyze_table_fields Tests
	// ========================================

	/**
	 * Test analyze_table_fields returns correct structure
	 */
	public function test_analyze_table_fields_structure() {
		$columns = array(
			array( 'name' => 'id', 'type' => 'bigint', 'key' => 'PRI', 'extra' => 'auto_increment', 'Null' => 'NO', 'Default' => null ),
			array( 'name' => 'title', 'type' => 'varchar(255)', 'key' => '', 'extra' => '', 'Null' => 'YES', 'Default' => null ),
			array( 'name' => 'description', 'type' => 'text', 'key' => '', 'extra' => '', 'Null' => 'YES', 'Default' => null ),
			array( 'name' => 'user_id', 'type' => 'bigint', 'key' => '', 'extra' => '', 'Null' => 'YES', 'Default' => null ),
			array( 'name' => 'created_at', 'type' => 'datetime', 'key' => '', 'extra' => '', 'Null' => 'YES', 'Default' => null ),
		);

		$analysis = Custom_Table_Scanner::analyze_table_fields( $columns );

		$this->assertArrayHasKey( 'translate_fields', $analysis );
		$this->assertArrayHasKey( 'sync_fields', $analysis );
		$this->assertArrayHasKey( 'field_mappings', $analysis );
		$this->assertArrayHasKey( 'compute_fields', $analysis );
	}

	/**
	 * Test analyze_table_fields identifies translate fields
	 */
	public function test_analyze_table_fields_translate() {
		$columns = array(
			array( 'name' => 'title', 'type' => 'varchar(255)', 'key' => '', 'extra' => '', 'Null' => 'YES', 'Default' => null ),
			array( 'name' => 'description', 'type' => 'text', 'key' => '', 'extra' => '', 'Null' => 'YES', 'Default' => null ),
			array( 'name' => 'content', 'type' => 'longtext', 'key' => '', 'extra' => '', 'Null' => 'YES', 'Default' => null ),
		);

		$analysis = Custom_Table_Scanner::analyze_table_fields( $columns );

		$this->assertContains( 'title', $analysis['translate_fields'] );
		$this->assertContains( 'description', $analysis['translate_fields'] );
		$this->assertContains( 'content', $analysis['translate_fields'] );
	}

	/**
	 * Test analyze_table_fields identifies ID mapping fields
	 */
	public function test_analyze_table_fields_id_mapping() {
		$columns = array(
			array( 'name' => 'user_id', 'type' => 'bigint', 'key' => '', 'extra' => '', 'Null' => 'YES', 'Default' => null ),
			array( 'name' => 'post_id', 'type' => 'bigint', 'key' => '', 'extra' => '', 'Null' => 'YES', 'Default' => null ),
			array( 'name' => 'parent_id', 'type' => 'bigint', 'key' => '', 'extra' => '', 'Null' => 'YES', 'Default' => null ),
		);

		$analysis = Custom_Table_Scanner::analyze_table_fields( $columns );

		$this->assertContains( 'user_id', $analysis['field_mappings'] );
		$this->assertContains( 'post_id', $analysis['field_mappings'] );
		$this->assertContains( 'parent_id', $analysis['field_mappings'] );
	}

	/**
	 * Test analyze_table_fields identifies compute fields
	 */
	public function test_analyze_table_fields_compute() {
		$columns = array(
			array( 'name' => 'slug', 'type' => 'varchar(255)', 'key' => '', 'extra' => '', 'Null' => 'YES', 'Default' => null ),
			array( 'name' => 'url', 'type' => 'varchar(500)', 'key' => '', 'extra' => '', 'Null' => 'YES', 'Default' => null ),
			array( 'name' => 'hash', 'type' => 'varchar(64)', 'key' => '', 'extra' => '', 'Null' => 'YES', 'Default' => null ),
		);

		$analysis = Custom_Table_Scanner::analyze_table_fields( $columns );

		$this->assertContains( 'slug', $analysis['compute_fields'] );
		$this->assertContains( 'url', $analysis['compute_fields'] );
		$this->assertContains( 'hash', $analysis['compute_fields'] );
	}

	/**
	 * Test analyze_table_fields skips auto_increment primary key
	 */
	public function test_analyze_table_fields_skips_primary() {
		$columns = array(
			array( 'name' => 'id', 'type' => 'bigint', 'key' => 'PRI', 'extra' => 'auto_increment', 'Null' => 'NO', 'Default' => null ),
		);

		$analysis = Custom_Table_Scanner::analyze_table_fields( $columns );

		$this->assertNotContains( 'id', $analysis['translate_fields'] );
		$this->assertNotContains( 'id', $analysis['sync_fields'] );
		$this->assertNotContains( 'id', $analysis['field_mappings'] );
		$this->assertNotContains( 'id', $analysis['compute_fields'] );
	}

}
