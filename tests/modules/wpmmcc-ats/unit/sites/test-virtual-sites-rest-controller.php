<?php
/**
 * Virtual Sites REST Controller Tests
 *
 * Tests for Virtual Sites REST API endpoints in Sites_REST_Controller.
 * Endpoints: /wptsall/v2/virtual-sites/*
 *
 * @package WPTSALL
 * @since 0.5.0
 */

use WPTSALL\Sites\Services\Virtual_Site_Service;

class Test_Virtual_Sites_REST_Controller extends WP_UnitTestCase {

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
	 * Test virtual site IDs for cleanup
	 *
	 * @var array
	 */
	protected $test_site_ids = array();

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

		// Ensure virtual sites table exists
		$this->create_test_table();
	}

	/**
	 * Ensure the virtual sites table exists.
	 *
	 * 2026-09-12: delegate to the plugin's real schema function
	 * (site_name/site_path/site_language/... layout). This file used to
	 * carry a hand-rolled pre-rename DDL (name/path_prefix/lang): a no-op
	 * wherever the real table already exists, but on a cold DB it would
	 * create a wrong-layout table that breaks the service inserts, and the
	 * tearDown cleanup referenced a nonexistent column (Unknown column
	 * 'path_prefix' noise on every run).
	 */
	private function create_test_table() {
		if ( function_exists( 'wptsall_create_virtual_sites_table' ) ) {
			wptsall_create_virtual_sites_table();
			return;
		}
		// Minimal harness without the plugin loaded: the service degrades
		// to option storage when the table is missing; nothing to create.
	}

	/**
	 * Helper: Create test virtual site via service
	 *
	 * @param array $overrides Override values
	 * @return array
	 */
	private function create_test_site( $overrides = array() ) {
		$defaults = array(
			'name'        => 'Test Site ' . uniqid(),
			'path_prefix' => 'test-' . uniqid(),
			'lang'        => 'en_US',
		);

		$data   = array_merge( $defaults, $overrides );
		$result = Virtual_Site_Service::create( $data );

		if ( $result['success'] && ! empty( $result['site_id'] ) ) {
			$this->test_site_ids[] = $result['site_id'];
		}

		return $result;
	}

	/**
	 * Clean up test data
	 */
	public function tearDown(): void {
		global $wp_rest_server, $wpdb;
		$wp_rest_server = null;

		$table = $wpdb->prefix . 'wptsall_virtual_sites';

		// Delete tracked sites
		foreach ( $this->test_site_ids as $id ) {
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		}

		// Clean up test sites by path pattern (site_path is the DB column;
		// path_prefix is only the API-level field name — 2026-09-12).
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE site_path LIKE %s", 'test-%' ) );

		parent::tearDown();
	}

	// ========================================
	// Route Registration Tests
	// ========================================

	/**
	 * Test virtual-sites routes are registered
	 */
	public function test_routes_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wptsall/v2/virtual-sites', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/virtual-sites/check-url', $routes );

		// Check pattern-based route
		$has_single_route = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( preg_match( '#/wptsall/v2/virtual-sites/\(\?P<id>.*\)#', $route ) ) {
				$has_single_route = true;
				break;
			}
		}
		$this->assertTrue( $has_single_route, 'virtual-sites/{id} route should exist' );
	}

	// ========================================
	// Authentication Tests
	// ========================================

	/**
	 * Test GET /virtual-sites requires authentication
	 */
	public function test_get_sites_requires_auth() {
		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/virtual-sites' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Test POST /virtual-sites requires authentication
	 */
	public function test_create_site_requires_auth() {
		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/virtual-sites' );
		$request->set_param( 'name', 'Test' );
		$request->set_param( 'path_prefix', 'test' );
		$request->set_param( 'lang', 'en' );

		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	// ========================================
	// GET /virtual-sites Tests
	// ========================================

	/**
	 * Test GET /virtual-sites returns list
	 */
	public function test_get_sites_returns_list() {
		wp_set_current_user( $this->admin_id );

		// Create test site
		$this->create_test_site();

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/virtual-sites' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test GET /virtual-sites returns all sites (no filter support)
	 *
	 * Note: The API currently does not support lang filtering.
	 * This test verifies that GET returns all sites regardless of lang param.
	 */
	public function test_get_sites_filter_lang() {
		wp_set_current_user( $this->admin_id );

		$this->create_test_site( array( 'lang' => 'en_US' ) );
		$this->create_test_site( array( 'lang' => 'ja' ) );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/virtual-sites' );
		$request->set_param( 'lang', 'en_US' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		// API returns all sites, filter is not implemented
		$this->assertIsArray( $data );
		$this->assertGreaterThanOrEqual( 2, count( $data ), 'Should return all created sites' );
	}

	// ========================================
	// POST /virtual-sites Tests
	// ========================================

	/**
	 * Test POST /virtual-sites creates site
	 */
	public function test_create_site_success() {
		wp_set_current_user( $this->admin_id );

		$path_prefix = 'rest-test-' . uniqid();

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/virtual-sites' );
		$request->set_param( 'name', 'REST API Test Site' );
		$request->set_param( 'path_prefix', $path_prefix );
		$request->set_param( 'lang', 'en_US' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['site_id'] );

		// Track for cleanup
		$this->test_site_ids[] = $data['site_id'];
	}

	/**
	 * Test POST /virtual-sites validates required fields
	 */
	public function test_create_site_validates_required() {
		wp_set_current_user( $this->admin_id );

		// Missing name
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/virtual-sites' );
		$request->set_param( 'path_prefix', 'test' );
		$request->set_param( 'lang', 'en' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test POST /virtual-sites prevents duplicate path
	 */
	public function test_create_site_duplicate_path() {
		wp_set_current_user( $this->admin_id );

		$path_prefix = 'dup-test-' . uniqid();

		// Create first site
		$result = $this->create_test_site( array( 'path_prefix' => $path_prefix ) );
		$this->assertTrue( $result['success'] );

		// Try to create duplicate
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/virtual-sites' );
		$request->set_param( 'name', 'Duplicate Test' );
		$request->set_param( 'path_prefix', $path_prefix );
		$request->set_param( 'lang', 'en_US' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	// ========================================
	// GET /virtual-sites/{id} Tests
	// ========================================

	/**
	 * Test GET /virtual-sites/{id} returns site
	 */
	public function test_get_single_site() {
		wp_set_current_user( $this->admin_id );

		$result  = $this->create_test_site( array( 'name' => 'Single Get Test' ) );
		$site_id = $result['site_id'];

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/virtual-sites/' . $site_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( $site_id, (int) $data['id'] );
		$this->assertEquals( 'Single Get Test', $data['name'] );
	}

	/**
	 * Test GET /virtual-sites/{id} returns 404 for non-existent
	 */
	public function test_get_site_not_found() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/virtual-sites/999999' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	// ========================================
	// PUT /virtual-sites/{id} Tests
	// ========================================

	/**
	 * Test PUT /virtual-sites/{id} updates site
	 *
	 * Note: API uses sanitize_key() for lang, converting to lowercase.
	 */
	public function test_update_site() {
		wp_set_current_user( $this->admin_id );

		$result  = $this->create_test_site( array( 'name' => 'Original Name' ) );
		$site_id = $result['site_id'];

		$request = new WP_REST_Request( 'PUT', '/wptsall/v2/virtual-sites/' . $site_id );
		$request->set_param( 'name', 'Updated Name' );
		$request->set_param( 'lang', 'fr_FR' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Verify update - API uses sanitize_text_field() which preserves case
		$site = Virtual_Site_Service::get( $site_id );
		$this->assertEquals( 'Updated Name', $site['name'] );
		$this->assertEquals( 'fr_FR', $site['lang'] );
	}

	/**
	 * Test PUT /virtual-sites/{id} returns 404 for non-existent
	 */
	public function test_update_site_not_found() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'PUT', '/wptsall/v2/virtual-sites/999999' );
		$request->set_param( 'name', 'Test' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	// ========================================
	// DELETE /virtual-sites/{id} Tests
	// ========================================

	/**
	 * Test DELETE /virtual-sites/{id} deletes site
	 */
	public function test_delete_site() {
		wp_set_current_user( $this->admin_id );

		$result  = $this->create_test_site();
		$site_id = $result['site_id'];

		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v2/virtual-sites/' . $site_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Verify deleted
		$site = Virtual_Site_Service::get( $site_id );
		$this->assertNull( $site );

		// Remove from tracking
		$this->test_site_ids = array_diff( $this->test_site_ids, array( $site_id ) );
	}

	/**
	 * Test DELETE /virtual-sites/{id} returns 404 for non-existent
	 */
	public function test_delete_site_not_found() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v2/virtual-sites/999999' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	// ========================================
	// GET /virtual-sites/check-url Tests
	// ========================================

	/**
	 * Test GET /virtual-sites/check-url detects no conflict
	 */
	public function test_check_url_no_conflict() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/virtual-sites/check-url' );
		$request->set_param( 'path_prefix', 'unique-path-' . uniqid() );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertFalse( $data['has_conflict'] );
	}

	/**
	 * Test GET /virtual-sites/check-url detects conflict
	 */
	public function test_check_url_with_conflict() {
		wp_set_current_user( $this->admin_id );

		$path_prefix = 'conflict-test-' . uniqid();

		// Create site first
		$this->create_test_site( array( 'path_prefix' => $path_prefix ) );

		// Check for conflict
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/virtual-sites/check-url' );
		$request->set_param( 'path_prefix', $path_prefix );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['has_conflict'] );
	}

	/**
	 * Test GET /virtual-sites/check-url with exclude_id
	 */
	public function test_check_url_exclude_self() {
		wp_set_current_user( $this->admin_id );

		$path_prefix = 'exclude-test-' . uniqid();

		// Create site
		$result  = $this->create_test_site( array( 'path_prefix' => $path_prefix ) );
		$site_id = $result['site_id'];

		// Check for conflict excluding self (should be no conflict)
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/virtual-sites/check-url' );
		$request->set_param( 'path_prefix', $path_prefix );
		$request->set_param( 'exclude_id', $site_id );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertFalse( $data['has_conflict'] );
	}

	/**
	 * Test check-url requires path_prefix parameter
	 */
	public function test_check_url_requires_param() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/virtual-sites/check-url' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	// ========================================
	// Response Format Tests
	// ========================================

	/**
	 * Test site response contains expected fields
	 */
	public function test_site_response_format() {
		wp_set_current_user( $this->admin_id );

		$result  = $this->create_test_site();
		$site_id = $result['site_id'];

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/virtual-sites/' . $site_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$expected_fields = array(
			'id',
			'name',
			'path_prefix',
			'lang',
			'permalink_structure',
			'category_base',
			'tag_base',
			'status',
			'created_at',
		);

		foreach ( $expected_fields as $field ) {
			$this->assertArrayHasKey( $field, $data, "Response should have '{$field}' field" );
		}
	}

	// ========================================
	// Permalink Settings Tests (v0.6.0)
	// ========================================

	/**
	 * Test POST /virtual-sites with permalink settings
	 */
	public function test_create_site_with_permalink_settings() {
		wp_set_current_user( $this->admin_id );

		$path_prefix = 'permalink-test-' . uniqid();

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/virtual-sites' );
		$request->set_param( 'name', 'Permalink Test Site' );
		$request->set_param( 'path_prefix', $path_prefix );
		$request->set_param( 'lang', 'en_US' );
		$request->set_param( 'permalink_structure', '/%year%/%monthnum%/%postname%/' );
		$request->set_param( 'category_base', 'topics' );
		$request->set_param( 'tag_base', 'labels' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['site_id'] );

		// Track for cleanup
		$this->test_site_ids[] = $data['site_id'];

		// Verify created site has correct settings
		$site = Virtual_Site_Service::get( $data['site_id'] );
		$this->assertEquals( '/%year%/%monthnum%/%postname%/', $site['permalink_structure'] );
		$this->assertEquals( 'topics', $site['category_base'] );
		$this->assertEquals( 'labels', $site['tag_base'] );
	}

	/**
	 * Test PUT /virtual-sites/{id} updates permalink settings
	 */
	public function test_update_site_permalink_settings() {
		wp_set_current_user( $this->admin_id );

		$result  = $this->create_test_site( array(
			'permalink_structure' => '/%postname%/',
		) );
		$site_id = $result['site_id'];

		$request = new WP_REST_Request( 'PUT', '/wptsall/v2/virtual-sites/' . $site_id );
		$request->set_param( 'permalink_structure', '/%year%/%monthnum%/%day%/%postname%/' );
		$request->set_param( 'category_base', 'cat' );
		$request->set_param( 'tag_base', 'tag' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Verify update
		$site = Virtual_Site_Service::get( $site_id );
		$this->assertEquals( '/%year%/%monthnum%/%day%/%postname%/', $site['permalink_structure'] );
		$this->assertEquals( 'cat', $site['category_base'] );
		$this->assertEquals( 'tag', $site['tag_base'] );
	}

	/**
	 * Test creating site with empty permalink settings (inherit from source)
	 */
	public function test_create_site_empty_permalink_settings() {
		wp_set_current_user( $this->admin_id );

		$path_prefix = 'empty-permalink-' . uniqid();

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/virtual-sites' );
		$request->set_param( 'name', 'Empty Permalink Site' );
		$request->set_param( 'path_prefix', $path_prefix );
		$request->set_param( 'lang', 'en_US' );
		// Don't set permalink settings - should default to empty (inherit)

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Track for cleanup
		$this->test_site_ids[] = $data['site_id'];

		// Verify settings are empty (will inherit from source)
		$site = Virtual_Site_Service::get( $data['site_id'] );
		$this->assertEquals( '', $site['permalink_structure'] );
		$this->assertEquals( '', $site['category_base'] );
		$this->assertEquals( '', $site['tag_base'] );
	}
}
