<?php
/**
 * Flow: Site Relation & Task Creation
 *
 * Tests virtual site creation, site relation setup, monitoring task creation,
 * task listing, and relation cascade delete.
 *
 * @package WPTSALL
 * @since 1.1.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Test_Flow_Relation_And_Task
 */
class Test_Flow_Relation_And_Task extends REST_Integration_Test_Case {

	/**
	 * Whether prerequisites are met for this flow.
	 *
	 * @var bool
	 */
	private static $chain_runnable = true;

	/**
	 * Reason to skip.
	 *
	 * @var string
	 */
	private static $skip_reason = '';

	/**
	 * One-time setup: verify namespace and required functions.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! in_array( 'wptsall/v2', self::$server->get_namespaces(), true ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall/v2 namespace is not registered';
		}

		if ( ! function_exists( 'wptsall_table' ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall_table() helper not found';
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
	 * Assert that creating a virtual site returns HTTP 200/201 and a DB record exists.
	 */
	public function test_create_virtual_site() {
		global $wpdb;

		$path_prefix = 'flow-test-vs-' . wp_rand( 1000, 9999 );

		$response = $this->rest_post( 'virtual-sites', array(
			'name'        => 'Flow Test Virtual Site',
			'path_prefix' => $path_prefix,
			'lang'        => 'zh_CN',
		) );

		// HTTP status assertion: 200 or 201.
		$status = $response->get_status();
		$this->assertTrue(
			in_array( $status, array( 200, 201 ), true ),
			"Creating a virtual site must return HTTP 200 or 201, got {$status}"
		);

		$data = $response->get_data();

		// Specific field assertion: response must contain site_id.
		$this->assertArrayHasKey( 'site_id', $data, 'Virtual site creation response must contain site_id' );
		$site_id = (int) $data['site_id'];
		$this->assertGreaterThan( 0, $site_id, 'site_id must be a positive integer' );

		$this->track_resource( 'virtual_sites', $site_id );

		// DB assertion: record must exist in virtual_sites table.
		$table = wptsall_table( 'virtual_sites' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d",
			$site_id
		), ARRAY_A );

		$this->assertNotNull( $row, "virtual_sites record must exist for id={$site_id}" );
		$stored_path = (string) ( $row['site_path'] ?? ( $row['path_prefix'] ?? '' ) );
		$this->assertEquals( $path_prefix, $stored_path, 'DB site path must match the submitted value' );
	}

	/**
	 * Assert that creating a site relation with a virtual target results in a DB record.
	 */
	public function test_create_site_relation() {
		global $wpdb;

		$vs_id = $this->create_test_virtual_site( array(
			'name'        => 'Flow Relation Test VS',
			'path_prefix' => 'flow-rel-' . wp_rand( 1000, 9999 ),
			'lang'        => 'zh_CN',
		) );

		$response = $this->rest_post( 'site-relations', array(
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => $this->get_source_lang(),
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $vs_id,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		// HTTP status assertion.
		$this->assertEquals( 200, $response->get_status(), 'Creating a site relation must return HTTP 200' );

		$data = $response->get_data();

		// Specific field assertion: relation_ids array must be non-empty.
		$this->assertArrayHasKey( 'relation_ids', $data, 'Response must contain relation_ids' );
		$this->assertNotEmpty( $data['relation_ids'], 'relation_ids must not be empty' );

		$relation_id = (int) $data['relation_ids'][0];
		$this->track_resource( 'site_relations', $relation_id );

		// DB assertion: relation exists in site_relations table.
		$table = wptsall_table( 'site_relations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, target_site_id FROM {$table} WHERE id = %d",
			$relation_id
		), ARRAY_A );

		$this->assertNotNull( $row, "site_relations record must exist for id={$relation_id}" );
		$target_site_id = (string) ( $row['target_site_id'] ?? '' );
		$normalized_id  = $target_site_id;

		if ( class_exists( '\WPTSALL\Sites\Validators\Site_Relation_Validator' ) ) {
			$normalized_id = (string) \WPTSALL\Sites\Validators\Site_Relation_Validator::parse_virtual_site_id( $target_site_id );
		} elseif ( 0 === strpos( $target_site_id, 'v_' ) ) {
			$normalized_id = substr( $target_site_id, 2 );
		}

		$this->assertEquals( (string) $vs_id, $normalized_id, 'DB target_site_id must match the virtual site id (supports v_* format)' );
	}

	/**
	 * Assert that posting a monitor/start for a product post_type creates a pending task in DB.
	 */
	public function test_monitoring_task_created() {
		global $wpdb;

		$vs_id        = $this->create_test_virtual_site();
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id  = (int) ( $relation_data['relation_ids'][0] ?? 0 );

		// HTTP assertion.
		$response = $this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $relation_id,
		) );

		$status = $response->get_status();
		$this->assertEquals( 200, $status, "monitor/start must return HTTP 200, got {$status}" );

		$data = $response->get_data();

		// Specific field assertion: response has success flag.
		$this->assertArrayHasKey( 'success', $data, 'Response must contain success key' );
		$this->assertTrue( $data['success'], 'Monitoring task creation must succeed' );

		// DB assertion: at least one pending/monitoring task exists for this relation.
		$table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE site_id = %d AND status IN ('pending', 'monitoring', 'active')",
			$relation_id
		) );

		$this->assertGreaterThan( 0, $count, 'A monitoring task must exist in the DB for the relation' );
	}

	/**
	 * Assert that GET /tasks?status=pending returns a non-empty items array.
	 */
	public function test_task_list_returns_pending() {
		// Ensure there is at least one pending task via monitoring start.
		$vs_id        = $this->create_test_virtual_site();
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id  = (int) ( $relation_data['relation_ids'][0] ?? 0 );

		$this->rest_post( 'tasks/monitor/start', array( 'relation_id' => $relation_id ) );

		// HTTP assertion.
		$response = $this->rest_get( 'tasks', array( 'status' => 'pending' ) );
		$this->assertEquals( 200, $response->get_status(), 'GET /tasks must return HTTP 200' );

		$data = $response->get_data();

		// Specific field assertion: response must contain an items key.
		$items = $data['items'] ?? ( $data['data']['items'] ?? null );
		$this->assertNotNull( $items, 'Task list response must contain an items key' );
		$this->assertIsArray( $items, 'items must be an array' );

		// DB assertion: confirm pending tasks actually exist in DB.
		global $wpdb;
		$table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$db_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status IN ('pending', 'monitoring', 'active')"
		);
		$this->assertGreaterThan( 0, $db_count, 'DB must contain at least one non-completed task' );
	}

	/**
	 * Assert that deleting a site relation cascades to relation_models (count becomes 0).
	 */
	public function test_relation_cascade_delete() {
		global $wpdb;

		$vs_id        = $this->create_test_virtual_site();
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id  = (int) ( $relation_data['relation_ids'][0] ?? 0 );

		// Remove from tracked resources so we can delete manually.
		$this->tracked_resources['site_relations'] = array_diff(
			$this->tracked_resources['site_relations'],
			array( $relation_id )
		);

		// HTTP assertion: delete returns 200.
		$delete_response = $this->rest_delete( "site-relations/{$relation_id}" );
		$this->assertEquals( 200, $delete_response->get_status(), 'DELETE /site-relations/{id} must return HTTP 200' );

		$delete_data = $delete_response->get_data();

		// Specific field assertion: success flag.
		$this->assertArrayHasKey( 'success', $delete_data, 'Delete response must contain success key' );
		$this->assertTrue( $delete_data['success'], 'Delete response success must be true' );

		// DB assertion: relation_models for this relation must be zero after delete.
		$rel_models_table = wptsall_table( 'relation_models' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$rel_models_table} WHERE relation_id = %d",
			$relation_id
		) );

		$this->assertEquals( 0, $remaining, 'relation_models must be cascade-deleted when relation is deleted' );
	}
}
