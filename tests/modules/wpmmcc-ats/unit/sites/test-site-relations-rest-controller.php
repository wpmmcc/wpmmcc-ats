<?php
/**
 * Site Relations REST Controller Tests
 *
 * Tests for Site Relations REST API endpoints in Sites_REST_Controller.
 * Endpoints: /wptsall/v2/site-relations/*
 *
 * @package WPTSALL
 * @since 0.5.0
 */

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;

class Test_Site_Relations_REST_Controller extends WP_UnitTestCase {

	/**
	 * REST server instance
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

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
	 * Virtual site ids created by fixtures (for tearDown cleanup)
	 *
	 * @var array
	 */
	protected $test_virtual_site_ids = array();

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up REST server
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Create admin user
		$this->admin_id = $this->factory->user->create( array(
			'role' => 'administrator',
		) );

		// Ensure site relations table exists
		$this->create_test_table();
	}

	/**
	 * Create test table
	 */
	private function create_test_table() {
		global $wpdb;
		$table           = $wpdb->prefix . 'wptsall_site_relations';
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
			KEY idx_template (template)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Generate a source_site_id for fixtures.
	 *
	 * P1-TEST-01 (2026-09-02): this must be a REAL blog id. The previous
	 * random IDs (1000 + rand) made create_relation() call switch_to_blog()
	 * on a nonexistent blog, flooding the run log with
	 * `wp_<rand>_options doesn't exist` errors (the 900s full-run timeout
	 * root cause) and failing create/grouped/add-targets. On multisite use
	 * subsite blog 2 (exists in the Lab); on single-site slots the only
	 * real blog is 1. Uniqueness of the relation comes from the randomized
	 * virtual target ids, not from the source id.
	 *
	 * @return int
	 */
	private function get_unique_source_id() {
		return is_multisite() ? 2 : 1;
	}

	/**
	 * Helper: Create test relation via service
	 *
	 * Uses 'wordpress-blog' as default template because it's a special template
	 * that doesn't require plugin activation check.
	 *
	 * @param array $overrides Override values
	 * @return array
	 */
	private function create_test_relation( $overrides = array() ) {
		$defaults = array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $this->get_unique_source_id(),
			'source_lang'    => 'zh_CN',
			'target_sites'   => array(
				array(
					'id'   => 'v_' . uniqid(),
					'type' => 'virtual',
					'lang' => 'en_US',
				),
			),
		);

		$data   = array_merge( $defaults, $overrides );
		$result = Site_Relation_Service::create_relation( $data );

		if ( $result['success'] && ! empty( $result['relation_ids'] ) ) {
			$this->test_relation_ids = array_merge( $this->test_relation_ids, $result['relation_ids'] );
		}

		return $result;
	}

	/**
	 * Helper: Create a REAL virtual site fixture.
	 *
	 * P1-TEST-01 (2026-09-02): the REST relation endpoints validate target
	 * references with Virtual_Site_Service::get() before creating/updating
	 * relations ("Target virtual site does not exist.", 404). Random
	 * v_<uniqid> ids therefore fail; fixtures must create the virtual site
	 * first and use the returned id.
	 *
	 * @param string $lang Site language (e.g. en_US).
	 * @return string Virtual site id in the v_<id> format used by REST params.
	 */
	private function create_virtual_site_fixture( $lang = 'en_US' ) {
		if ( function_exists( 'wptsall_create_virtual_sites_table' ) ) {
			wptsall_create_virtual_sites_table();
		}

		$suffix = uniqid();
		$result = Virtual_Site_Service::create(
			array(
				'name'        => 'REST Fixture ' . $suffix,
				'path_prefix' => 'rest-fixture-' . $suffix,
				'lang'        => $lang,
			)
		);

		$this->assertNotEmpty( $result['success'] ?? false, 'virtual site fixture: ' . wp_json_encode( $result ) );

		$site_id = 'v_' . $result['site_id'];
		$this->test_virtual_site_ids[] = $result['site_id'];

		return $site_id;
	}

	/**
	 * Clean up test data
	 */
	public function tearDown(): void {
		global $wp_rest_server, $wpdb;
		$wp_rest_server = null;

		$table = $wpdb->prefix . 'wptsall_site_relations';

		// Delete tracked relations (2026-09-12: cascade through the service
		// so relation_models/hooks/configs rows go with the relation — raw
		// deletes orphaned them; doctor-probes.php §8 now guards this).
		foreach ( (array) $this->test_relation_ids as $rid ) {
			Site_Relation_Service::delete_relation( (int) $rid );
		}

		// Safety net: clean up by test-class-specific template pattern
		// (cascade too — see tracked-relations note above).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pattern_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE template LIKE %s", $table, 'test\_rest\_%' ) );
		foreach ( (array) $pattern_ids as $rid ) {
			Site_Relation_Service::delete_relation( (int) $rid );
		}

		// Delete tracked virtual site fixtures
		foreach ( $this->test_virtual_site_ids as $site_id ) {
			Virtual_Site_Service::delete( $site_id );
		}

		parent::tearDown();
	}

	// ========================================
	// Route Registration Tests
	// ========================================

	/**
	 * Test site-relations routes are registered
	 */
	public function test_routes_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wptsall/v2/site-relations', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/site-relations/grouped', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/site-relations/add-targets', $routes );
	}

	/**
	 * Test new endpoints are registered (v0.5.0)
	 */
	public function test_new_endpoints_registered() {
		$routes = $this->server->get_routes();

		// Check endpoints added in v0.5.0 migration
		$this->assertArrayHasKey( '/wptsall/v2/site-relations/check-plugin-status', $routes );

		// Check pattern-based routes exist
		$has_targets_route = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( strpos( $route, 'site-relations' ) !== false && strpos( $route, 'targets' ) !== false ) {
				$has_targets_route = true;
				break;
			}
		}
		$this->assertTrue( $has_targets_route, 'site-relations/{id}/targets route should exist' );
	}

	// ========================================
	// Authentication Tests
	// ========================================

	/**
	 * Test GET /site-relations requires authentication
	 */
	public function test_get_relations_requires_auth() {
		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/site-relations' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Test POST /site-relations requires authentication
	 */
	public function test_create_relation_requires_auth() {
		// Use complete valid parameters to ensure permission check runs first
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/site-relations' );
		$request->set_param( 'template', 'wordpress-blog' );
		$request->set_param( 'source_site_id', 1001 );
		$request->set_param( 'source_lang', 'zh_CN' );
		$request->set_param( 'target_sites', array(
			array( 'id' => 'v_test', 'type' => 'virtual', 'lang' => 'en_US' ),
		) );

		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	// ========================================
	// GET /site-relations Tests
	// ========================================

	/**
	 * Test GET /site-relations returns list
	 */
	public function test_get_relations_returns_list() {
		wp_set_current_user( $this->admin_id );

		// Create test relation
		$this->create_test_relation();

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/site-relations' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test GET /site-relations with template filter
	 */
	public function test_get_relations_filter_template() {
		wp_set_current_user( $this->admin_id );

		// Use wordpress-blog as it's the valid template
		$this->create_test_relation( array( 'template' => 'wordpress-blog' ) );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/site-relations' );
		$request->set_param( 'template', 'wordpress-blog' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		foreach ( $data as $relation ) {
			$this->assertEquals( 'wordpress-blog', $relation['template'] );
		}
	}

	// ========================================
	// POST /site-relations Tests
	// ========================================

	/**
	 * Test POST /site-relations creates relation
	 */
	public function test_create_relation_success() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/site-relations' );
		$request->set_param( 'template', 'wordpress-blog' );
		$request->set_param( 'source_site_id', $this->get_unique_source_id() );
		$request->set_param( 'source_lang', 'zh_CN' );
		$request->set_param( 'target_sites', array(
			array( 'id' => $this->create_virtual_site_fixture( 'en_US' ), 'type' => 'virtual', 'lang' => 'en_US' ),
		) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'create relation response: ' . wp_json_encode( $data ) );
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['relation_ids'] );

		// Track for cleanup
		$this->test_relation_ids = array_merge( $this->test_relation_ids, $data['relation_ids'] );
	}

	/**
	 * Test POST /site-relations validates required fields
	 */
	public function test_create_relation_validates_required() {
		wp_set_current_user( $this->admin_id );

		// Missing template
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/site-relations' );
		$request->set_param( 'source_site_id', 1 );
		$request->set_param( 'target_sites', array(
			array( 'id' => 'v1', 'type' => 'virtual', 'lang' => 'en' ),
		) );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	// ========================================
	// GET /site-relations/{id} Tests
	// ========================================

	/**
	 * Test GET /site-relations/{id} returns relation
	 */
	public function test_get_single_relation() {
		wp_set_current_user( $this->admin_id );

		$result      = $this->create_test_relation();
		$relation_id = $result['relation_ids'][0];

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/site-relations/' . $relation_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( $relation_id, (int) $data['id'] );
	}

	/**
	 * Test GET /site-relations/{id} returns 404 for non-existent
	 */
	public function test_get_relation_not_found() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/site-relations/999999' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	// ========================================
	// DELETE /site-relations/{id} Tests
	// ========================================

	/**
	 * Test DELETE /site-relations/{id} deletes relation
	 */
	public function test_delete_relation() {
		wp_set_current_user( $this->admin_id );

		$result      = $this->create_test_relation();
		$relation_id = $result['relation_ids'][0];

		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v2/site-relations/' . $relation_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Verify deleted
		$relation = Site_Relation_Service::get_relation( $relation_id );
		$this->assertNull( $relation );

		// Remove from tracking
		$this->test_relation_ids = array_diff( $this->test_relation_ids, array( $relation_id ) );
	}

	// ========================================
	// GET /site-relations/grouped Tests
	// ========================================

	/**
	 * Test GET /site-relations/grouped returns grouped structure
	 */
	public function test_get_grouped_relations() {
		wp_set_current_user( $this->admin_id );

		$source_id = $this->get_unique_source_id();

		// Create relations with multiple targets using valid template
		$this->create_test_relation( array(
			'source_site_id' => $source_id,
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array( 'id' => 'v1_' . uniqid(), 'type' => 'virtual', 'lang' => 'en_US' ),
				array( 'id' => 'v2_' . uniqid(), 'type' => 'virtual', 'lang' => 'ja' ),
			),
		) );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/site-relations/grouped' );
		$request->set_param( 'source_site_id', $source_id );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data );

		// Verify grouped structure
		$group = $data[0];
		$this->assertArrayHasKey( 'source_site_id', $group );
		$this->assertArrayHasKey( 'template', $group );
		$this->assertArrayHasKey( 'targets', $group );
	}

	// ========================================
	// GET /site-relations/{id}/targets Tests
	// ========================================

	/**
	 * Test GET /site-relations/{id}/targets returns targets
	 */
	public function test_get_relation_targets() {
		wp_set_current_user( $this->admin_id );

		$result      = $this->create_test_relation();
		$relation_id = $result['relation_ids'][0];

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/site-relations/' . $relation_id . '/targets' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		// API returns 'target_sites' not 'targets'
		$this->assertArrayHasKey( 'target_sites', $data );
		$this->assertArrayHasKey( 'target_count', $data );
	}

	// ========================================
	// POST /site-relations/add-targets Tests
	// ========================================

	/**
	 * Test POST /site-relations/add-targets adds targets
	 */
	public function test_add_targets() {
		wp_set_current_user( $this->admin_id );

		$source_id = $this->get_unique_source_id();

		// Create initial relation using valid template
		$this->create_test_relation( array(
			'source_site_id' => $source_id,
			'source_lang'    => 'zh_CN',
			'template'       => 'wordpress-blog',
		) );

		// Add more targets (must reference a real virtual site, see
		// create_virtual_site_fixture()).
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/site-relations/add-targets' );
		$request->set_param( 'source_site_id', $source_id );
		$request->set_param( 'source_lang', 'zh_CN' );
		$request->set_param( 'template', 'wordpress-blog' );
		$request->set_param( 'new_targets', array(
			array( 'id' => $this->create_virtual_site_fixture( 'fr_FR' ), 'type' => 'virtual', 'lang' => 'fr_FR' ),
		) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'add targets response: ' . wp_json_encode( $data ) );
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['relation_ids'] );

		// Track for cleanup
		$this->test_relation_ids = array_merge( $this->test_relation_ids, $data['relation_ids'] );
	}

	// ========================================
	// GET /site-relations/check-plugin-status Tests
	// ========================================

	/**
	 * Test GET /site-relations/check-plugin-status
	 */
	public function test_check_plugin_status() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/site-relations/check-plugin-status' );
		$request->set_param( 'template', 'woocommerce' );
		$request->set_param( 'site_id', 1 );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		// API returns 'active' (boolean) not 'active_plugins'
		$this->assertArrayHasKey( 'active', $data );
		$this->assertArrayHasKey( 'template', $data );
		$this->assertArrayHasKey( 'message', $data );
	}

	/**
	 * Test check-plugin-status requires parameters
	 */
	public function test_check_plugin_status_requires_params() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/site-relations/check-plugin-status' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	// ========================================
	// Response Format Tests
	// ========================================

	/**
	 * Test relation response contains expected fields
	 */
	public function test_relation_response_format() {
		wp_set_current_user( $this->admin_id );

		$result      = $this->create_test_relation();
		$relation_id = $result['relation_ids'][0];

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/site-relations/' . $relation_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$expected_fields = array(
			'id',
			'source_site_id',
			'source_lang',
			'template',
			'target_site_id',
			'target_site_type',
			'target_lang',
			'status',
		);

		foreach ( $expected_fields as $field ) {
			$this->assertArrayHasKey( $field, $data, "Response should have '{$field}' field" );
		}
	}
}
