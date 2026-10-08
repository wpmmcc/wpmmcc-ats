<?php
/**
 * Template REST Controller Tests
 *
 * Tests for WPTSALL\Templates\API\Template_REST_Controller class
 *
 * @package WPTSALL
 * @since 0.5.0
 */

use WPTSALL\Templates\Services\Template_Service;
use WPTSALL\Templates\Services\Template_Entry_Service;

class Test_Template_REST_Controller extends WP_UnitTestCase {

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
	 * Test relation ID
	 *
	 * @var int
	 */
	protected $test_relation_id = 0;

	/**
	 * Test template IDs for cleanup
	 *
	 * @var array
	 */
	protected $test_template_ids = array();

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

		// Ensure templates tables exist
		if ( function_exists( 'wptsall_ensure_templates_tables' ) ) {
			wptsall_ensure_templates_tables();
		}

		// Create test relation directly in database
		global $wpdb;
		$table = wptsall_table( 'site_relations' );
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			$table,
			array(
				'source_site_id'   => 1,
				'source_site_type' => 'wp',
				'source_lang'      => 'en',
				'template'         => 'test_rest_' . time(),
				'target_site_id'   => '2',
				'target_site_type' => 'wp',
				'target_lang'      => 'zh_CN',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->test_relation_id = $wpdb->insert_id;
	}

	/**
	 * Clean up test data
	 */
	public function tearDown(): void {
		global $wp_rest_server, $wpdb;
		$wp_rest_server = null;

		// Clean up test templates
		foreach ( $this->test_template_ids as $id ) {
			Template_Service::delete( $id );
		}

		// Clean up test relation
		if ( $this->test_relation_id ) {
			$table = wptsall_table( 'site_relations' );
			$wpdb->delete( $table, array( 'id' => $this->test_relation_id ), array( '%d' ) );
		}

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test REST routes are registered
	 */
	public function test_routes_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wptsall/v2/templates', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/templates/scan', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/templates/(?P<id>\\d+)', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/templates/(?P<id>\\d+)/rescan', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/templates/(?P<id>\\d+)/export', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/templates/(?P<id>\\d+)/import', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/templates/(?P<id>\\d+)/entries', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/template-entries/(?P<id>\\d+)', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/template-entries/bulk-update', $routes );
	}

	/**
	 * Test get templates requires authentication
	 */
	public function test_get_templates_requires_auth() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/templates' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Test get templates as admin
	 */
	public function test_get_templates_as_admin() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/templates' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'pages', $data );
	}

	/**
	 * Test create template
	 */
	public function test_create_template() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/templates' );
		$request->set_param( 'relation_id', $this->test_relation_id );
		$request->set_param( 'slug', 'test-create-' . uniqid() );
		$request->set_param( 'source_type', 'plugin' );
		$request->set_param( 'text_domain', 'test-plugin' );
		$request->set_param( 'source_name', 'Test Plugin' );
		$request->set_param( 'source_version', '1.0.0' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'template', $data );
		$this->assertEquals( 'plugin', $data['template']['source_type'] );

		$this->test_template_ids[] = $data['template']['id'];
	}

	/**
	 * Test create template with duplicate slug fails
	 */
	public function test_create_template_duplicate_slug() {
		wp_set_current_user( $this->admin_id );

		$slug = 'duplicate-slug-' . uniqid();

		// Create first template
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'slug'         => $slug,
			'source_type'  => 'plugin',
			'text_domain'  => 'test',
		) );
		$this->test_template_ids[] = $template_id;

		// Try to create second with same slug
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/templates' );
		$request->set_param( 'relation_id', $this->test_relation_id );
		$request->set_param( 'slug', $slug );
		$request->set_param( 'source_type', 'plugin' );
		$request->set_param( 'text_domain', 'test2' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test get single template
	 */
	public function test_get_single_template() {
		wp_set_current_user( $this->admin_id );

		// Create template
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'theme',
			'text_domain'  => 'test-theme',
			'source_name'  => 'Test Theme',
		) );
		$this->test_template_ids[] = $template_id;

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/templates/' . $template_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( (int) $template_id, (int) $data['id'] );
		$this->assertEquals( 'theme', $data['source_type'] );
		$this->assertEquals( 'Test Theme', $data['source_name'] );
	}

	/**
	 * Test get non-existent template returns 404
	 */
	public function test_get_template_not_found() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/templates/999999' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Test delete template
	 */
	public function test_delete_template() {
		wp_set_current_user( $this->admin_id );

		// Create template
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'delete-test',
		) );

		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v2/templates/' . $template_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Verify deletion
		$template = Template_Service::get( $template_id );
		$this->assertNull( $template );
	}

	/**
	 * Test get template entries
	 */
	public function test_get_template_entries() {
		wp_set_current_user( $this->admin_id );

		// Create template
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'entries-test',
		) );
		$this->test_template_ids[] = $template_id;

		// Create entries
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Hello',
			'msgstr'      => '你好',
			'status'      => 'translated',
		) );

		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'World',
			'status'      => 'pending',
		) );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/templates/' . $template_id . '/entries' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertGreaterThanOrEqual( 2, $data['total'] );
	}

	/**
	 * Test get entries with status filter
	 */
	public function test_get_entries_filter_status() {
		wp_set_current_user( $this->admin_id );

		// Create template
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'filter-test',
		) );
		$this->test_template_ids[] = $template_id;

		// Create entries
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Translated',
			'msgstr'      => '已翻译',
			'status'      => 'translated',
		) );

		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Pending',
			'status'      => 'pending',
		) );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/templates/' . $template_id . '/entries' );
		$request->set_param( 'status', 'translated' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		foreach ( $data['items'] as $item ) {
			$this->assertEquals( 'translated', $item['status'] );
		}
	}

	/**
	 * Test get single entry
	 */
	public function test_get_single_entry() {
		wp_set_current_user( $this->admin_id );

		// Create template and entry
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'single-entry-test',
		) );
		$this->test_template_ids[] = $template_id;

		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Test message',
			'msgstr'      => '测试消息',
			'status'      => 'translated',
		) );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/template-entries/' . $entry_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'Test message', $data['msgid'] );
		$this->assertEquals( '测试消息', $data['msgstr'] );
	}

	/**
	 * Test update entry
	 */
	public function test_update_entry() {
		wp_set_current_user( $this->admin_id );

		// Create template and entry
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'update-entry-test',
		) );
		$this->test_template_ids[] = $template_id;

		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Original',
			'status'      => 'pending',
		) );

		$request = new WP_REST_Request( 'PUT', '/wptsall/v2/template-entries/' . $entry_id );
		$request->set_param( 'msgstr', '已更新' );
		$request->set_param( 'status', 'translated' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertEquals( '已更新', $data['entry']['msgstr'] );
		$this->assertEquals( 'translated', $data['entry']['status'] );
	}

	/**
	 * Test delete entry
	 */
	public function test_delete_entry() {
		wp_set_current_user( $this->admin_id );

		// Create template and entry
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'delete-entry-test',
		) );
		$this->test_template_ids[] = $template_id;

		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'To delete',
		) );

		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v2/template-entries/' . $entry_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Verify deletion
		$entry = Template_Entry_Service::get( $entry_id );
		$this->assertNull( $entry );
	}

	/**
	 * Test bulk update entries
	 */
	public function test_bulk_update_entries() {
		wp_set_current_user( $this->admin_id );

		// Create template and entries
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'bulk-update-test',
		) );
		$this->test_template_ids[] = $template_id;

		$ids = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$ids[] = Template_Entry_Service::create( array(
				'template_id' => $template_id,
				'msgid'       => "Bulk entry {$i}",
				'status'      => 'pending',
			) );
		}

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/template-entries/bulk-update' );
		$request->set_param( 'ids', $ids );
		$request->set_param( 'data', array( 'status' => 'reviewed' ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 3, $data['updated'] );

		// Verify updates
		foreach ( $ids as $id ) {
			$entry = Template_Entry_Service::get( $id );
			$this->assertEquals( 'reviewed', $entry['status'] );
		}
	}

	/**
	 * Test export template PO
	 */
	public function test_export_template() {
		wp_set_current_user( $this->admin_id );

		// Create template and entry
		$template_id = Template_Service::create( array(
			'relation_id'    => $this->test_relation_id,
			'source_type'    => 'plugin',
			'text_domain'    => 'export-test',
			'source_name'    => 'Export Test',
			'source_version' => '1.0.0',
		) );
		$this->test_template_ids[] = $template_id;

		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Export string',
			'msgstr'      => '导出字符串',
			'status'      => 'translated',
		) );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/templates/' . $template_id . '/export' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'filename', $data );
		$this->assertArrayHasKey( 'content', $data );
		$this->assertStringContainsString( 'export-test', $data['filename'] );
	}

	/**
	 * Test import template PO
	 */
	public function test_import_template() {
		wp_set_current_user( $this->admin_id );

		// Create template
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'import-test',
		) );
		$this->test_template_ids[] = $template_id;

		// Create a minimal PO content
		$po_content = '
msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\n"
"Language: zh_CN\n"

msgid "Import string"
msgstr "导入字符串"
';

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/templates/' . $template_id . '/import' );
		$request->set_param( 'content', $po_content );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'imported', $data );
	}

	/**
	 * Test templates pagination
	 */
	public function test_templates_pagination() {
		wp_set_current_user( $this->admin_id );

		// Create multiple templates
		for ( $i = 0; $i < 5; $i++ ) {
			$id = Template_Service::create( array(
				'relation_id'  => $this->test_relation_id,
				'source_type'  => 'plugin',
				'text_domain'  => 'pagination-test-' . $i,
			) );
			$this->test_template_ids[] = $id;
		}

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/templates' );
		$request->set_param( 'per_page', 2 );
		$request->set_param( 'page', 1 );
		$request->set_param( 'relation_id', $this->test_relation_id );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertLessThanOrEqual( 2, count( $data['items'] ) );
		$this->assertGreaterThanOrEqual( 5, $data['total'] );
		$this->assertGreaterThanOrEqual( 3, $data['pages'] );
	}

	/**
	 * Test templates filter by source_type
	 */
	public function test_templates_filter_source_type() {
		wp_set_current_user( $this->admin_id );

		// Create templates of different types
		$id1 = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'type-filter-plugin',
		) );
		$this->test_template_ids[] = $id1;

		$id2 = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'theme',
			'text_domain'  => 'type-filter-theme',
		) );
		$this->test_template_ids[] = $id2;

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/templates' );
		$request->set_param( 'source_type', 'plugin' );
		$request->set_param( 'relation_id', $this->test_relation_id );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		foreach ( $data['items'] as $item ) {
			$this->assertEquals( 'plugin', $item['source_type'] );
		}
	}
}
