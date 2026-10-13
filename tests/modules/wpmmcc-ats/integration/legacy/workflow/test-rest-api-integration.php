<?php
/**
 * REST API Integration Tests
 *
 * 测试 REST API 端点集成
 *
 * Usage: php tests/integration/workflow/test-rest-api-integration.php
 *
 * @package WPTSALL
 * @since 0.5.0
 */

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;

class Test_REST_API_Integration extends SimpleTestCase {

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
	 * Test virtual site IDs for cleanup
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

		// Get or create admin user
		$admin = get_user_by( 'login', 'admin' );
		if ( $admin ) {
			$this->admin_id = $admin->ID;
		} else {
			$this->admin_id = wp_insert_user( array(
				'user_login' => 'test_admin_' . time(),
				'user_pass'  => 'password',
				'role'       => 'administrator',
			) );
		}
	}

	/**
	 * Clean up test data
	 */
	public function tearDown(): void {
		global $wp_rest_server, $wpdb;
		$wp_rest_server = null;

		// Delete test relations
		foreach ( $this->test_relation_ids as $id ) {
			Site_Relation_Service::delete_relation( $id );
		}

		// Delete test virtual sites
		foreach ( $this->test_virtual_site_ids as $id ) {
			Virtual_Site_Service::delete( $id );
		}

		// Clean up test data
		$table = $wpdb->prefix . 'wptsall_site_relations';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE template LIKE %s", 'test_rest_%' ) );

		parent::tearDown();
	}

	/**
	 * Generate unique source ID
	 */
	private function get_unique_source_id() {
		return 2000 + wp_rand( 1, 999999 );
	}

	// ==================== REST Endpoints Registration Tests ====================

	/**
	 * Test REST endpoints are registered
	 */
	public function test_rest_endpoints_registered() {
		$routes = $this->server->get_routes();

		// Site Relations endpoints
		$this->assertArrayHasKey( '/wptsall/v1/site-relations', $routes );
		$this->assertArrayHasKey( '/wptsall/v1/site-relations/grouped', $routes );
		$this->assertArrayHasKey( '/wptsall/v1/site-relations/add-targets', $routes );
		$this->assertArrayHasKey( '/wptsall/v1/site-relations/check-plugin-status', $routes );

		// Virtual Sites endpoints
		$this->assertArrayHasKey( '/wptsall/v1/virtual-sites', $routes );
		$this->assertArrayHasKey( '/wptsall/v1/virtual-sites/check-url', $routes );

		// Sites endpoints
		$this->assertArrayHasKey( '/wptsall/v1/sites', $routes );

		// Tasks endpoints
		$this->assertArrayHasKey( '/wptsall/v2/tasks', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/tasks/stats', $routes );
	}

	/**
	 * Test Models V2 endpoints are registered
	 */
	public function test_models_v2_endpoints_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wptsall/v2/models', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/models/stats', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/models/scan', $routes );
	}

	// ==================== Site Relations REST API Tests ====================

	/**
	 * Test GET /site-relations requires authentication
	 */
	public function test_get_relations_requires_auth() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v1/site-relations' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Test GET /site-relations returns list
	 */
	public function test_get_relations_as_admin() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v1/site-relations' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test POST /site-relations creates relation
	 */
	public function test_create_relation_via_rest() {
		wp_set_current_user( $this->admin_id );

		$source_id = $this->get_unique_source_id();

		$request = new WP_REST_Request( 'POST', '/wptsall/v1/site-relations' );
		$request->set_param( 'template', 'wordpress-blog' );
		$request->set_param( 'source_site_id', $source_id );
		$request->set_param( 'source_lang', 'zh_CN' );
		$request->set_param( 'target_sites', array(
			array( 'id' => 'v_rest_test_' . uniqid(), 'type' => 'virtual', 'lang' => 'en_US' ),
		) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['relation_ids'] );

		// Track for cleanup
		$this->test_relation_ids = array_merge( $this->test_relation_ids, $data['relation_ids'] );
	}

	/**
	 * Test GET /site-relations/{id} returns single relation
	 */
	public function test_get_single_relation_via_rest() {
		wp_set_current_user( $this->admin_id );

		// Create relation first
		$result = Site_Relation_Service::create_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $this->get_unique_source_id(),
			'source_lang'    => 'zh_CN',
			'target_sites'   => array(
				array( 'id' => 'v_get_test_' . uniqid(), 'type' => 'virtual', 'lang' => 'en_US' ),
			),
		) );

		$this->assertTrue( $result['success'] );
		$relation_id = $result['relation_ids'][0];
		$this->test_relation_ids[] = $relation_id;

		// Get via REST
		$request  = new WP_REST_Request( 'GET', '/wptsall/v1/site-relations/' . $relation_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( $relation_id, (int) $data['id'] );
		$this->assertEquals( 'wordpress-blog', $data['template'] );
	}

	/**
	 * Test DELETE /site-relations/{id} deletes relation
	 */
	public function test_delete_relation_via_rest() {
		wp_set_current_user( $this->admin_id );

		// Create relation first
		$result = Site_Relation_Service::create_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $this->get_unique_source_id(),
			'source_lang'    => 'zh_CN',
			'target_sites'   => array(
				array( 'id' => 'v_del_test_' . uniqid(), 'type' => 'virtual', 'lang' => 'en_US' ),
			),
		) );

		$this->assertTrue( $result['success'] );
		$relation_id = $result['relation_ids'][0];

		// Delete via REST
		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v1/site-relations/' . $relation_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Verify deleted
		$relation = Site_Relation_Service::get_relation( $relation_id );
		$this->assertNull( $relation );
	}

	/**
	 * Test GET /site-relations/grouped returns grouped structure
	 */
	public function test_get_grouped_relations_via_rest() {
		wp_set_current_user( $this->admin_id );

		$source_id = $this->get_unique_source_id();

		// Create relation with multiple targets
		$result = Site_Relation_Service::create_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $source_id,
			'source_lang'    => 'zh_CN',
			'target_sites'   => array(
				array( 'id' => 'v_grp1_' . uniqid(), 'type' => 'virtual', 'lang' => 'en_US' ),
				array( 'id' => 'v_grp2_' . uniqid(), 'type' => 'virtual', 'lang' => 'ja' ),
			),
		) );

		$this->assertTrue( $result['success'] );
		$this->test_relation_ids = array_merge( $this->test_relation_ids, $result['relation_ids'] );

		// Get grouped via REST
		$request = new WP_REST_Request( 'GET', '/wptsall/v1/site-relations/grouped' );
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

	/**
	 * Test POST /site-relations/add-targets adds targets
	 */
	public function test_add_targets_via_rest() {
		wp_set_current_user( $this->admin_id );

		$source_id = $this->get_unique_source_id();

		// Create initial relation
		$result = Site_Relation_Service::create_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $source_id,
			'source_lang'    => 'zh_CN',
			'target_sites'   => array(
				array( 'id' => 'v_add1_' . uniqid(), 'type' => 'virtual', 'lang' => 'en_US' ),
			),
		) );

		$this->assertTrue( $result['success'] );
		$this->test_relation_ids = array_merge( $this->test_relation_ids, $result['relation_ids'] );

		// Add more targets via REST
		$request = new WP_REST_Request( 'POST', '/wptsall/v1/site-relations/add-targets' );
		$request->set_param( 'source_site_id', $source_id );
		$request->set_param( 'source_lang', 'zh_CN' );
		$request->set_param( 'template', 'wordpress-blog' );
		$request->set_param( 'new_targets', array(
			array( 'id' => 'v_add2_' . uniqid(), 'type' => 'virtual', 'lang' => 'fr_FR' ),
		) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['relation_ids'] );

		// Track for cleanup
		$this->test_relation_ids = array_merge( $this->test_relation_ids, $data['relation_ids'] );
	}

	// ==================== Virtual Sites REST API Tests ====================

	/**
	 * Test GET /virtual-sites requires authentication
	 */
	public function test_get_virtual_sites_requires_auth() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v1/virtual-sites' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Test POST /virtual-sites creates site
	 */
	public function test_create_virtual_site_via_rest() {
		wp_set_current_user( $this->admin_id );

		$path_prefix = 'rest-int-test-' . uniqid();

		$request = new WP_REST_Request( 'POST', '/wptsall/v1/virtual-sites' );
		$request->set_param( 'name', 'REST Integration Test Site' );
		$request->set_param( 'path_prefix', $path_prefix );
		$request->set_param( 'lang', 'en_US' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['site_id'] );

		// Track for cleanup
		$this->test_virtual_site_ids[] = $data['site_id'];
	}

	/**
	 * Test PUT /virtual-sites/{id} updates site
	 */
	public function test_update_virtual_site_via_rest() {
		wp_set_current_user( $this->admin_id );

		// Create site first
		$result = Virtual_Site_Service::create( array(
			'name'        => 'Update Test Site',
			'path_prefix' => 'upd-test-' . uniqid(),
			'lang'        => 'en_US',
		) );

		$this->assertTrue( $result['success'] );
		$site_id = $result['site_id'];
		$this->test_virtual_site_ids[] = $site_id;

		// Update via REST
		$request = new WP_REST_Request( 'PUT', '/wptsall/v1/virtual-sites/' . $site_id );
		$request->set_param( 'name', 'Updated Site Name' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Verify update
		$site = Virtual_Site_Service::get( $site_id );
		$this->assertEquals( 'Updated Site Name', $site['name'] );
	}

	/**
	 * Test DELETE /virtual-sites/{id} deletes site
	 */
	public function test_delete_virtual_site_via_rest() {
		wp_set_current_user( $this->admin_id );

		// Create site first
		$result = Virtual_Site_Service::create( array(
			'name'        => 'Delete Test Site',
			'path_prefix' => 'del-test-' . uniqid(),
			'lang'        => 'en_US',
		) );

		$this->assertTrue( $result['success'] );
		$site_id = $result['site_id'];

		// Delete via REST
		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v1/virtual-sites/' . $site_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Verify deleted
		$site = Virtual_Site_Service::get( $site_id );
		$this->assertNull( $site );
	}

	/**
	 * Test GET /virtual-sites/check-url detects conflicts
	 */
	public function test_check_url_conflict_via_rest() {
		wp_set_current_user( $this->admin_id );

		$path_prefix = 'conflict-test-' . uniqid();

		// Create site first
		$result = Virtual_Site_Service::create( array(
			'name'        => 'Conflict Test Site',
			'path_prefix' => $path_prefix,
			'lang'        => 'en_US',
		) );

		$this->assertTrue( $result['success'] );
		$this->test_virtual_site_ids[] = $result['site_id'];

		// Check for conflict via REST
		$request = new WP_REST_Request( 'GET', '/wptsall/v1/virtual-sites/check-url' );
		$request->set_param( 'path_prefix', $path_prefix );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['has_conflict'] );
	}

	// ==================== Tasks REST API Tests ====================

	/**
	 * Test GET /tasks requires authentication
	 */
	public function test_get_tasks_requires_auth() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/tasks' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Test GET /tasks returns list
	 */
	public function test_get_tasks_as_admin() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/tasks' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		// API returns array of tasks directly
		$this->assertIsArray( $data );
	}

	/**
	 * Test GET /tasks/stats returns statistics
	 */
	public function test_get_task_stats_via_rest() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/tasks/stats' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		// API returns 'overview' key for stats
		$this->assertArrayHasKey( 'overview', $data );
		$this->assertArrayHasKey( 'by_template', $data );
	}

	// ==================== Models V2 REST API Tests ====================

	/**
	 * Test GET /v2/models returns list
	 */
	public function test_get_models_v2_as_admin() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/models' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'models', $data );
	}

	/**
	 * Test GET /v2/models/stats returns statistics
	 */
	public function test_get_model_stats_v2() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/models/stats' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		// API returns 'total_models' and 'total_rules' keys
		$this->assertArrayHasKey( 'total_models', $data );
		$this->assertArrayHasKey( 'total_rules', $data );
	}

	// ==================== Complete Workflow Test ====================

	/**
	 * Test complete workflow via REST API
	 */
	public function test_complete_rest_workflow() {
		wp_set_current_user( $this->admin_id );

		$source_id   = $this->get_unique_source_id();
		$path_prefix = 'workflow-' . uniqid();

		// Step 1: Create virtual site
		$request = new WP_REST_Request( 'POST', '/wptsall/v1/virtual-sites' );
		$request->set_param( 'name', 'Workflow Test Site' );
		$request->set_param( 'path_prefix', $path_prefix );
		$request->set_param( 'lang', 'en_US' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$virtual_site_id = $data['site_id'];
		$this->test_virtual_site_ids[] = $virtual_site_id;

		// Step 2: Create site relation
		$request = new WP_REST_Request( 'POST', '/wptsall/v1/site-relations' );
		$request->set_param( 'template', 'wordpress-blog' );
		$request->set_param( 'source_site_id', $source_id );
		$request->set_param( 'source_lang', 'zh_CN' );
		$request->set_param( 'target_sites', array(
			array( 'id' => $virtual_site_id, 'type' => 'virtual', 'lang' => 'en_US' ),
		) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$relation_id = $data['relation_ids'][0];
		$this->test_relation_ids[] = $relation_id;

		// Step 3: Verify relation via grouped endpoint
		$request = new WP_REST_Request( 'GET', '/wptsall/v1/site-relations/grouped' );
		$request->set_param( 'source_site_id', $source_id );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertNotEmpty( $data );
		$this->assertEquals( 'wordpress-blog', $data[0]['template'] );

		// Step 4: Delete relation
		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v1/site-relations/' . $relation_id );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		// Remove from cleanup list
		$this->test_relation_ids = array_diff( $this->test_relation_ids, array( $relation_id ) );

		// Step 5: Delete virtual site
		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v1/virtual-sites/' . $virtual_site_id );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		// Remove from cleanup list
		$this->test_virtual_site_ids = array_diff( $this->test_virtual_site_ids, array( $virtual_site_id ) );
	}
}
