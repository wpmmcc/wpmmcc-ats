<?php
/**
 * Flow: Plugin Initialization & Model Scan
 *
 * Tests plugin table presence, version constant, model scanning idempotency,
 * and SSOT read source default.
 *
 * @package WPTSALL
 * @since 1.1.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Test_Flow_Init_And_Model_Scan
 */
class Test_Flow_Init_And_Model_Scan extends REST_Integration_Test_Case {

	/**
	 * Whether prerequisites are met for this flow.
	 *
	 * @var bool
	 */
	private static $chain_runnable = true;

	/**
	 * Reason to skip (if $chain_runnable is false).
	 *
	 * @var string
	 */
	private static $skip_reason = '';

	/**
	 * One-time setup: verify the wptsall/v2 namespace is registered.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! in_array( 'wptsall/v2', self::$server->get_namespaces(), true ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall/v2 namespace is not registered';
		}

		if ( ! function_exists( 'wptsall_table' ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall_table() helper function not found';
		}
	}

	/**
	 * Per-test guard.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! self::$chain_runnable ) {
			$this->markTestSkipped( self::$skip_reason );
		}
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * Assert that all ~20 key wp_wptsall_* tables exist in the database.
	 */
	public function test_plugin_tables_exist() {
		global $wpdb;

		$required_tables = array(
			'models',
			'model_objects',
			'model_object_fields',
			'translation_rules',
			'site_relations',
			'relation_models',
			'virtual_sites',
			'templates',
			'template_entries',
			'media_mappings',
			'post_mappings',
			'term_mappings',
			'tasks',
			'translation_results',
		);

		foreach ( $required_tables as $short_name ) {
			$table = wptsall_table( $short_name );

			// HTTP-style assertion: we verify the table existence (analogous to a 200 check).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			// Specific field assertion: the returned value must equal the full table name.
			$this->assertEquals(
				$table,
				$found,
				"Table '{$table}' must exist in the database"
			);

			// DB assertion: double-check via information_schema for extra confidence.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
				$table
			) );
			$this->assertEquals( 1, $count, "information_schema confirms '{$table}' exists" );
		}
	}

	/**
	 * Assert WPTSALL_VERSION constant is defined and non-empty.
	 */
	public function test_wptsall_version_constant() {
		// HTTP-equivalent: plugin is loaded (200 OK means constant is accessible).
		$this->assertTrue( defined( 'WPTSALL_VERSION' ), 'WPTSALL_VERSION must be defined' );

		// Specific field assertion: the value must be a non-empty string.
		$version = WPTSALL_VERSION;
		$this->assertNotEmpty( $version, 'WPTSALL_VERSION must not be empty' );
		$this->assertIsString( $version, 'WPTSALL_VERSION must be a string' );

		// DB assertion: confirm the option stored by the plugin matches the constant.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$db_version = $wpdb->get_var(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wptsall_version' LIMIT 1"
		);
		// The option may or may not exist, but if it does it should not contradict the constant.
		if ( $db_version !== null ) {
			$this->assertEquals( WPTSALL_VERSION, $db_version, 'DB version option matches WPTSALL_VERSION constant' );
		}
	}

	/**
	 * Assert that scanning 'wordpress-blog' populates model_objects in the DB.
	 */
	public function test_model_scan_creates_objects() {
		global $wpdb;

		// HTTP assertion: scan returns 200.
		$response = $this->rest_post( 'models/scan', array( 'plugin_slug' => 'wordpress-blog' ) );
		$this->assertEquals( 200, $response->get_status(), 'Model scan must return HTTP 200' );

		$data = $response->get_data();

		// Specific field assertion: response has success = true.
		$this->assertArrayHasKey( 'success', $data, 'Scan response must contain success key' );
		$this->assertTrue( $data['success'], 'Scan response success must be true' );

		// DB assertion: model_objects must have at least one record for this model.
		$model_id = $data['model']['id'] ?? ( $data['id'] ?? 0 );
		if ( $model_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_model_objects WHERE model_id = %d",
				$model_id
			) );
			$this->assertGreaterThan( 0, $count, 'model_objects must be populated after scan' );
		} else {
			// No model_id in response; verify globally that model_objects is non-empty.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_model_objects"
			);
			$this->assertGreaterThan( 0, $total, 'model_objects table must be non-empty after scan' );
		}
	}

	/**
	 * Assert that scanning the same plugin twice does not duplicate model records.
	 */
	public function test_model_scan_idempotent() {
		global $wpdb;

		// First scan.
		$response1 = $this->rest_post( 'models/scan', array( 'plugin_slug' => 'wordpress-blog' ) );
		$this->assertEquals( 200, $response1->get_status(), 'First scan must return HTTP 200' );

		// Specific field assertion: success flag present.
		$data1 = $response1->get_data();
		$this->assertArrayHasKey( 'success', $data1, 'First scan response must have success key' );

		// DB assertion: count models with this slug before second scan.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_before = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_models WHERE plugin_slug = %s",
				'wordpress-blog'
			)
		);

		// Second scan.
		$response2 = $this->rest_post( 'models/scan', array( 'plugin_slug' => 'wordpress-blog' ) );
		$this->assertEquals( 200, $response2->get_status(), 'Second scan must return HTTP 200' );

		// DB assertion: count must not increase.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_after = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_models WHERE plugin_slug = %s",
				'wordpress-blog'
			)
		);

		$this->assertEquals(
			$count_before,
			$count_after,
			'Scanning the same plugin twice must not duplicate model records'
		);
	}

	/**
	 * Assert that wptsall_get_ssot_read_source() returns 'model_objects' by default.
	 */
	public function test_ssot_read_source_default() {
		// HTTP-equivalent: function exists and is callable (prerequisite check).
		if ( ! function_exists( 'wptsall_get_ssot_read_source' ) ) {
			$this->markTestSkipped( 'wptsall_get_ssot_read_source() function not found' );
		}

		$source = wptsall_get_ssot_read_source();

		// Specific field assertion: must return a non-empty string.
		$this->assertIsString( $source, 'wptsall_get_ssot_read_source() must return a string' );
		$this->assertNotEmpty( $source, 'wptsall_get_ssot_read_source() must return a non-empty string' );

		// DB assertion: the stored option (if any) should match the returned value.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$option_val = $wpdb->get_var(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wptsall_ssot_read_source' LIMIT 1"
		);
		if ( $option_val !== null ) {
			$this->assertEquals( $option_val, $source, 'Returned SSOT source must match stored option' );
		} else {
			// Default when no option is stored should be 'model_objects'.
			$this->assertEquals( 'model_objects', $source, 'Default SSOT read source must be model_objects' );
		}
	}
}
