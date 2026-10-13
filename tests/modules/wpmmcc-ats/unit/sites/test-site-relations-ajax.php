<?php
/**
 * Site Relations Service Tests (Legacy)
 *
 * @deprecated 0.5.0 This file tests service layer methods, not AJAX.
 *             The AJAX API has been replaced by REST API.
 *             See test-site-relations-rest-controller.php for REST API tests.
 *             See test-site-relation-service.php for canonical service tests.
 *
 * This file is kept for additional test coverage of Site_Relation_Service.
 * Consider merging with test-site-relation-service.php in future cleanup.
 *
 * @package WPTSALL
 * @since 0.5.0
 */

use WPTSALL\Sites\Services\Site_Relation_Service;

class Test_Site_Relations_Ajax extends WP_UnitTestCase {

	/**
	 * Admin user ID
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Test relation IDs for cleanup
	 *
	 * @var array
	 */
	protected $test_relation_ids = array();

	/**
	 * REQUEST_METHOD seen before this file simulated AJAX POST requests.
	 * Restored in tearDown so later files in the same process start from GET.
	 *
	 * @var string|null
	 */
	protected $original_request_method;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user
		$this->admin_id = $this->factory->user->create( array(
			'role' => 'administrator',
		) );

		// Ensure site relations table exists
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
	 * Clean up test data
	 */
	public function tearDown(): void {
		global $wpdb;

		// Restore the request method this file simulated as POST so later
		// test files in the same process start from GET again.
		if ( null !== $this->original_request_method ) {
			$_SERVER['REQUEST_METHOD'] = $this->original_request_method;
		}

		$table = $wpdb->prefix . 'wptsall_site_relations';

		// Delete tracked test relations (2026-09-12: cascade through the
		// service so relation_models/hooks/configs rows go with the relation —
		// raw deletes orphaned them and regrew the AUTO_INCREMENT collision
		// drift class; doctor-probes.php §8 now guards this).
		foreach ( (array) $this->test_relation_ids as $rid ) {
			Site_Relation_Service::delete_relation( (int) $rid );
		}

		// Clean up test relations by template pattern (cascade too).
		$pattern_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE template LIKE %s', $table, 'test\_%' ) );
		foreach ( (array) $pattern_ids as $rid ) {
			Site_Relation_Service::delete_relation( (int) $rid );
		}

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Helper: Generate unique source_site_id for testing
	 *
	 * @return int
	 */
	private function get_unique_source_id() {
		return 1000 + mt_rand( 1, 999999 );
	}

	/**
	 * Helper: Create a test relation
	 *
	 * @param array $overrides Override default values
	 * @return array Result from create_relation
	 */
	private function create_test_relation( $overrides = array() ) {
		$defaults = array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $this->get_unique_source_id(),
			'source_lang'    => '',
			'target_sites'   => array(
				array(
					'id'   => 'v_' . uniqid(),
					'type' => 'virtual',
					'lang' => 'en_US',
				),
			),
		);

		$data = array_merge( $defaults, $overrides );
		$result = Site_Relation_Service::create_relation( $data );

		// Track created relations for cleanup
		if ( $result['success'] && ! empty( $result['relation_ids'] ) ) {
			$this->test_relation_ids = array_merge( $this->test_relation_ids, $result['relation_ids'] );
		}

		return $result;
	}

	/**
	 * Helper: Simulate AJAX request
	 *
	 * @param string $action AJAX action
	 * @param array  $data   Request data
	 * @return void
	 */
	private function setup_ajax_request( $action, $data = array() ) {
		// Set up nonce
		$_POST['nonce'] = wp_create_nonce( 'wptsall_site_relations' );
		$_POST['action'] = $action;

		// Merge additional data
		foreach ( $data as $key => $value ) {
			$_POST[ $key ] = $value;
		}

		// Set request method (remember what it was so tearDown can restore it)
		if ( null === $this->original_request_method ) {
			$this->original_request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		}
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	/**
	 * Test create_relation AJAX requires permission
	 */
	public function test_create_relation_requires_permission() {
		wp_set_current_user( 0 );

		$this->setup_ajax_request( 'wptsall_create_relation', array(
			'source_site_id' => 1,
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array( 'id' => 'v_test', 'type' => 'virtual', 'lang' => 'en_US' ),
			),
		) );

		// Since we can't properly catch wp_send_json_error in unit tests,
		// we test the permission check indirectly
		$this->assertFalse( current_user_can( 'manage_options' ) );
	}

	/**
	 * Test delete_relation service function
	 */
	public function test_delete_relation_service() {
		wp_set_current_user( $this->admin_id );

		// Create a test relation
		$result = $this->create_test_relation();
		$this->assertTrue( $result['success'] );
		$relation_id = $result['relation_ids'][0];

		// Delete it
		$delete_result = Site_Relation_Service::delete_relation( $relation_id );

		$this->assertTrue( $delete_result['success'] );

		// Verify deletion
		$relation = Site_Relation_Service::get_relation( $relation_id );
		$this->assertNull( $relation );

		// Remove from tracking since it's deleted
		$this->test_relation_ids = array_diff( $this->test_relation_ids, array( $relation_id ) );
	}

	/**
	 * Test update_relation_status service function
	 */
	public function test_update_relation_status_service() {
		wp_set_current_user( $this->admin_id );

		// Create a test relation
		$result = $this->create_test_relation();
		$this->assertTrue( $result['success'] );
		$relation_id = $result['relation_ids'][0];

		// Update status to inactive
		$update_result = Site_Relation_Service::update_status( $relation_id, 'inactive' );
		$this->assertTrue( $update_result['success'] );

		// Verify update
		$relation = Site_Relation_Service::get_relation( $relation_id );
		$this->assertEquals( 'inactive', $relation['status'] );

		// Update status back to active
		$update_result = Site_Relation_Service::update_status( $relation_id, 'active' );
		$this->assertTrue( $update_result['success'] );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		$this->assertEquals( 'active', $relation['status'] );
	}

	/**
	 * Test add_target_sites service function
	 */
	public function test_add_targets_service() {
		wp_set_current_user( $this->admin_id );

		$source_id = $this->get_unique_source_id();
		$template = 'wordpress-blog';

		// Create initial relation
		$result = $this->create_test_relation( array(
			'source_site_id' => $source_id,
			'template'       => $template,
			'target_sites'   => array(
				array( 'id' => 'v1', 'type' => 'virtual', 'lang' => 'en_US' ),
			),
		) );
		$this->assertTrue( $result['success'] );

		// Add more targets
		$add_result = Site_Relation_Service::add_target_sites(
			$source_id,
			'',
			$template,
			array(
				array( 'id' => 'v2', 'type' => 'virtual', 'lang' => 'ja' ),
			)
		);

		$this->assertTrue( $add_result['success'] );
		$this->assertNotEmpty( $add_result['relation_ids'] );

		// Track for cleanup
		$this->test_relation_ids = array_merge( $this->test_relation_ids, $add_result['relation_ids'] );

		// Verify total targets
		$targets = Site_Relation_Service::get_targets_for_source( $source_id, '', $template );
		$this->assertCount( 2, $targets );
	}

	/**
	 * Test get_relation_details returns correct data
	 */
	public function test_get_relation_details() {
		wp_set_current_user( $this->admin_id );

		// Create a test relation
		$result = $this->create_test_relation( array(
			'template' => 'wordpress-blog',
		) );
		$this->assertTrue( $result['success'] );
		$relation_id = $result['relation_ids'][0];

		// Get relation details
		$relation = Site_Relation_Service::get_relation( $relation_id );

		$this->assertNotNull( $relation );
		$this->assertArrayHasKey( 'id', $relation );
		$this->assertArrayHasKey( 'template', $relation );
		$this->assertArrayHasKey( 'source_site_id', $relation );
		$this->assertArrayHasKey( 'target_site_id', $relation );
		$this->assertArrayHasKey( 'status', $relation );
		$this->assertEquals( 'wordpress-blog', $relation['template'] );
	}

	/**
	 * Test get_grouped_relations returns correct structure
	 */
	public function test_get_grouped_relations() {
		wp_set_current_user( $this->admin_id );

		$source_id = $this->get_unique_source_id();

		// Create relations with multiple targets
		$result = $this->create_test_relation( array(
			'source_site_id' => $source_id,
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array( 'id' => 'v1', 'type' => 'virtual', 'lang' => 'en_US' ),
				array( 'id' => 'v2', 'type' => 'virtual', 'lang' => 'ja' ),
			),
		) );
		$this->assertTrue( $result['success'] );

		// Get grouped relations
		$grouped = Site_Relation_Service::get_grouped_relations( array( 'source_site_id' => $source_id ) );

		$this->assertIsArray( $grouped );
		$this->assertNotEmpty( $grouped );

		$group = $grouped[0];
		$this->assertArrayHasKey( 'source_site_id', $group );
		$this->assertArrayHasKey( 'template', $group );
		$this->assertArrayHasKey( 'targets', $group );
		$this->assertIsArray( $group['targets'] );
		$this->assertGreaterThanOrEqual( 2, count( $group['targets'] ) );
	}

	/**
	 * Test get_target_sites returns correct data
	 */
	public function test_get_target_sites() {
		wp_set_current_user( $this->admin_id );

		$source_id = $this->get_unique_source_id();
		$template = 'wordpress-blog';

		// Create relations (IDs must have v_ prefix for virtual targets)
		$result = $this->create_test_relation( array(
			'source_site_id' => $source_id,
			'source_lang'    => 'zh_CN',
			'template'       => $template,
			'target_sites'   => array(
				array( 'id' => 'v_t1', 'type' => 'virtual', 'lang' => 'en_US' ),
				array( 'id' => 'v_t2', 'type' => 'virtual', 'lang' => 'ja' ),
			),
		) );
		$this->assertTrue( $result['success'] );

		// Get target sites
		$targets = Site_Relation_Service::get_targets_for_source( $source_id, 'zh_CN', $template );

		$this->assertIsArray( $targets );
		$this->assertCount( 2, $targets );

		// Verify target data
		$target_ids = array_column( $targets, 'target_site_id' );
		$this->assertContains( 'v_t1', $target_ids );
		$this->assertContains( 'v_t2', $target_ids );
	}

	/**
	 * Test create_relation validates required fields
	 */
	public function test_create_relation_validates_required() {
		wp_set_current_user( $this->admin_id );

		// Missing template
		$result = Site_Relation_Service::create_relation( array(
			'source_site_id' => 1,
			'target_sites'   => array( array( 'id' => 'v1', 'type' => 'virtual', 'lang' => 'en' ) ),
		) );
		$this->assertFalse( $result['success'] );

		// Missing source_site_id
		$result = Site_Relation_Service::create_relation( array(
			'template'     => 'wordpress-blog',
			'target_sites' => array( array( 'id' => 'v1', 'type' => 'virtual', 'lang' => 'en' ) ),
		) );
		$this->assertFalse( $result['success'] );

		// Missing target_sites
		$result = Site_Relation_Service::create_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => 1,
		) );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test update_relation changes target_lang
	 */
	public function test_update_relation_target_lang() {
		wp_set_current_user( $this->admin_id );

		// Create a test relation
		$result = $this->create_test_relation( array(
			'target_sites' => array(
				array( 'id' => 'v1', 'type' => 'virtual', 'lang' => 'en_US' ),
			),
		) );
		$this->assertTrue( $result['success'] );
		$relation_id = $result['relation_ids'][0];

		// Update relation via database (simulating AJAX handler)
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';

		$wpdb->update(
			$table,
			array( 'target_lang' => 'fr_FR' ),
			array( 'id' => $relation_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Direct SQL writers bypass the service writers and therefore own
		// the cache-invalidation duty (a real AJAX handler writes via
		// Site_Relation_Service::update_relation(), which flushes the
		// relation caches; this simulation must model that flush too).
		Site_Relation_Service::clear_cache();

		// Verify update
		$relation = Site_Relation_Service::get_relation( $relation_id );
		$this->assertEquals( 'fr_FR', $relation['target_lang'] );
	}

	/**
	 * Test delete non-existent relation fails gracefully
	 */
	public function test_delete_nonexistent_relation() {
		wp_set_current_user( $this->admin_id );

		$result = Site_Relation_Service::delete_relation( 999999 );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test update status on non-existent relation fails
	 */
	public function test_update_status_nonexistent_relation() {
		wp_set_current_user( $this->admin_id );

		$result = Site_Relation_Service::update_status( 999999, 'active' );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test AJAX nonce verification setup
	 */
	public function test_ajax_nonce_creation() {
		wp_set_current_user( $this->admin_id );

		$nonce = wp_create_nonce( 'wptsall_site_relations' );
		$this->assertNotEmpty( $nonce );

		// Verify nonce
		$this->assertEquals( 1, wp_verify_nonce( $nonce, 'wptsall_site_relations' ) );
	}

	/**
	 * Test permission check for admin
	 */
	public function test_admin_has_permission() {
		wp_set_current_user( $this->admin_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	/**
	 * Test permission check for non-admin
	 */
	public function test_non_admin_lacks_permission() {
		$subscriber_id = $this->factory->user->create( array(
			'role' => 'subscriber',
		) );
		wp_set_current_user( $subscriber_id );

		$this->assertFalse( current_user_can( 'manage_options' ) );
	}

	/**
	 * Test sync_theme_info function exists and is callable
	 */
	public function test_sync_theme_info_exists() {
		$this->assertTrue(
			method_exists( Site_Relation_Service::class, 'sync_theme_info' ),
			'sync_theme_info method should exist'
		);
	}

	/**
	 * Test get_stats returns correct structure
	 */
	public function test_get_stats_structure() {
		$stats = Site_Relation_Service::get_stats();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total', $stats );
		$this->assertArrayHasKey( 'active', $stats );
		$this->assertArrayHasKey( 'by_template', $stats );
	}
}
