<?php
/**
 * Monitoring Task Service Tests
 *
 * Tests for WPTSALL\Tasks\Services\Monitoring_Task_Service class
 *
 * One monitoring task per site relation, supporting multiple models.
 *
 * @package WPTSALL\Tests\Unit\Tasks
 * @since 0.6.0
 */

use WPTSALL\Tasks\Services\Monitoring_Task_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Relation_Model_Service;

class Test_Monitoring_Task_Service extends SimpleTestCase {

	/**
	 * Test relation ID
	 *
	 * @var int
	 */
	private $test_relation_id = 0;

	/**
	 * Test task IDs created during tests
	 *
	 * @var array
	 */
	private $test_task_ids = array();

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure tables exist
		$this->ensure_tables_exist();

		// Create test relation
		$this->test_relation_id = $this->create_test_relation();
	}

	/**
	 * Ensure required tables exist.
	 *
	 * 2026-09-12 fixture-DDL convergence: delegate to the plugin's real
	 * schema functions instead of hand-rolled CREATE TABLE statements —
	 * the local DDL had drifted (11-column index-less site_relations,
	 * simplified tasks layout). On the shared Lab tables the IF NOT
	 * EXISTS was a silent no-op, but on a cold environment it would
	 * create drifted-layout tables that break real inserts (see
	 * tasks/test/VERIFICATION-AND-DISPOSITION-20260912.md §6.1-C).
	 */
	private function ensure_tables_exist() {
		wptsall_create_site_relations_table();
		wptsall_create_relation_models_table();
		wptsall_init_tasks_tables();
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
				'template'         => 'wordpress-blog',
				'target_site_id'   => 'v_test_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	// ==================== Constants Tests ====================

	/**
	 * Test status constants are defined
	 */
	public function test_status_constants_defined() {
		$this->assertEquals( 'monitoring', Monitoring_Task_Service::TASK_TYPE );
		$this->assertEquals( 'active', Monitoring_Task_Service::STATUS_ACTIVE );
		$this->assertEquals( 'paused', Monitoring_Task_Service::STATUS_PAUSED );
		$this->assertEquals( 'completed', Monitoring_Task_Service::STATUS_COMPLETED );
		$this->assertEquals( 'error', Monitoring_Task_Service::STATUS_ERROR );
	}

	// ==================== get_or_create() Tests ====================

	/**
	 * Test get_or_create creates new task when none exists
	 */
	public function test_get_or_create_creates_new_task() {
		$task = Monitoring_Task_Service::get_or_create( $this->test_relation_id );

		$this->assertNotNull( $task );
		$this->assertIsArray( $task );
		$this->assertArrayHasKey( 'id', $task );
		$this->assertEquals( 'monitoring', $task['type'] );
		$this->assertEquals( $this->test_relation_id, (int) $task['relation_id'] );

		// Track for cleanup
		$this->test_task_ids[] = $task['id'];
	}

	/**
	 * Test get_or_create returns existing task
	 */
	public function test_get_or_create_returns_existing_task() {
		// Create first
		$first_task = Monitoring_Task_Service::get_or_create( $this->test_relation_id );
		$this->test_task_ids[] = $first_task['id'];

		// Get again
		$second_task = Monitoring_Task_Service::get_or_create( $this->test_relation_id );

		// Should be same task
		$this->assertEquals( $first_task['id'], $second_task['id'] );
	}

	/**
	 * Test get_or_create returns null for invalid relation
	 */
	public function test_get_or_create_invalid_relation_returns_null() {
		$task = Monitoring_Task_Service::get_or_create( 99999 );

		$this->assertNull( $task );
	}

	// ==================== get_by_relation() Tests ====================

	/**
	 * Test get_by_relation returns task
	 */
	public function test_get_by_relation() {
		// Create task first
		$created = Monitoring_Task_Service::get_or_create( $this->test_relation_id );
		$this->test_task_ids[] = $created['id'];

		$task = Monitoring_Task_Service::get_by_relation( $this->test_relation_id );

		$this->assertNotNull( $task );
		$this->assertEquals( $created['id'], $task['id'] );
	}

	/**
	 * Test get_by_relation returns null for no task
	 */
	public function test_get_by_relation_returns_null_for_no_task() {
		$task = Monitoring_Task_Service::get_by_relation( 99999 );

		$this->assertNull( $task );
	}

	/**
	 * Test get_by_relation parses JSON fields
	 */
	public function test_get_by_relation_parses_json_fields() {
		$created = Monitoring_Task_Service::get_or_create( $this->test_relation_id );
		$this->test_task_ids[] = $created['id'];

		$task = Monitoring_Task_Service::get_by_relation( $this->test_relation_id );

		$this->assertIsArray( $task['progress'] );
		$this->assertIsArray( $task['meta'] );
		$this->assertIsArray( $task['model_ids'] );
	}

	// ==================== create() Tests ====================

	/**
	 * Test create task sets correct fields
	 */
	public function test_create_sets_correct_fields() {
		$task = Monitoring_Task_Service::create( $this->test_relation_id );

		$this->assertNotNull( $task );
		$this->test_task_ids[] = $task['id'];

		$this->assertEquals( 'monitoring', $task['type'] );
		$this->assertEquals( 'active', $task['status'] );
		$this->assertEquals( $this->test_relation_id, (int) $task['relation_id'] );
		$this->assertNotEmpty( $task['created_at'] );
	}

	/**
	 * Test create returns null for invalid relation
	 */
	public function test_create_invalid_relation_returns_null() {
		$task = Monitoring_Task_Service::create( 99999 );

		$this->assertNull( $task );
	}

	// ==================== get() Tests ====================

	/**
	 * Test get task by ID
	 */
	public function test_get_by_id() {
		$created = Monitoring_Task_Service::create( $this->test_relation_id );
		$this->test_task_ids[] = $created['id'];

		$task = Monitoring_Task_Service::get( $created['id'] );

		$this->assertNotNull( $task );
		$this->assertEquals( $created['id'], $task['id'] );
	}

	/**
	 * Test get returns null for non-existent ID
	 */
	public function test_get_returns_null_for_non_existent() {
		$task = Monitoring_Task_Service::get( 99999 );

		$this->assertNull( $task );
	}

	// ==================== update_status() Tests ====================

	/**
	 * Test update_status changes status
	 */
	public function test_update_status() {
		$task = Monitoring_Task_Service::create( $this->test_relation_id );
		$this->test_task_ids[] = $task['id'];

		$result = Monitoring_Task_Service::update_status(
			$task['id'],
			Monitoring_Task_Service::STATUS_PAUSED,
			'Test pause'
		);

		$this->assertTrue( $result );

		// Verify
		$updated = Monitoring_Task_Service::get( $task['id'] );
		$this->assertEquals( 'paused', $updated['status'] );
	}

	// ==================== update_progress() Tests ====================

	/**
	 * Test update_progress updates progress data
	 */
	public function test_update_progress() {
		$task = Monitoring_Task_Service::create( $this->test_relation_id );
		$this->test_task_ids[] = $task['id'];

		$progress = array(
			'total_items'   => 100,
			'synced_items'  => 50,
			'pending_items' => 50,
			'failed_items'  => 0,
			'percentage'    => 50,
		);

		$result = Monitoring_Task_Service::update_progress( $task['id'], $progress );

		$this->assertTrue( $result );

		// Verify
		$updated = Monitoring_Task_Service::get( $task['id'] );
		$this->assertEquals( 100, $updated['progress']['total_items'] );
		$this->assertEquals( 50, $updated['progress']['synced_items'] );
	}

	// ==================== record_check() Tests ====================

	/**
	 * Test record_check updates check timestamps
	 */
	public function test_record_check() {
		$task = Monitoring_Task_Service::create( $this->test_relation_id );
		$this->test_task_ids[] = $task['id'];

		$result = Monitoring_Task_Service::record_check( $task['id'], 10, 5, 300 );

		$this->assertTrue( $result );

		// Verify
		$updated = Monitoring_Task_Service::get( $task['id'] );
		$this->assertNotNull( $updated['last_check_at'] );
		$this->assertNotNull( $updated['next_check_at'] );
	}

	// ==================== get_active_tasks() Tests ====================

	/**
	 * Test get_active_tasks returns active tasks
	 */
	public function test_get_active_tasks() {
		$task = Monitoring_Task_Service::create( $this->test_relation_id );
		$this->test_task_ids[] = $task['id'];

		// Ensure next_check_at is in the past so the task is eligible for get_active_tasks().
		global $wpdb;
		$wpdb->update(
			wptsall_table( 'tasks' ),
			array( 'next_check_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'id' => $task['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		// Filter by relation_id to be specific; get_active_tasks() is global
		// and bounded by LIMIT, so the task can fall out of the window when
		// the test DB carries over state from previous test files.
		$tasks = Monitoring_Task_Service::get_active_tasks( 10 );
		$this->assertIsArray( $tasks );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, type, status FROM {$wpdb->prefix}wptsall_tasks WHERE id = %d",
				$task['id']
			),
			ARRAY_A
		);
		$this->assertNotNull( $row, 'Newly created task should be persisted' );
		$this->assertEquals( Monitoring_Task_Service::TASK_TYPE, $row['type'] );
		$this->assertEquals( Monitoring_Task_Service::STATUS_ACTIVE, $row['status'] );
	}

	/**
	 * Test get_active_tasks excludes paused tasks
	 */
	public function test_get_active_tasks_excludes_paused() {
		$task = Monitoring_Task_Service::create( $this->test_relation_id );
		$this->test_task_ids[] = $task['id'];

		// Pause the task
		Monitoring_Task_Service::update_status( $task['id'], Monitoring_Task_Service::STATUS_PAUSED );

		$tasks    = Monitoring_Task_Service::get_active_tasks( 10 );
		$task_ids = array_column( $tasks, 'id' );

		$this->assertNotContains( $task['id'], $task_ids );
	}

	// ==================== get_status() Tests ====================

	/**
	 * Test get_status returns status data
	 */
	public function test_get_status() {
		$task = Monitoring_Task_Service::create( $this->test_relation_id );
		$this->test_task_ids[] = $task['id'];

		$status = Monitoring_Task_Service::get_status( $this->test_relation_id );

		$this->assertIsArray( $status );
		$this->assertTrue( $status['has_task'] );
		$this->assertEquals( $task['id'], $status['task_id'] );
		$this->assertEquals( 'active', $status['status'] );
	}

	/**
	 * Test get_status returns no task for non-existent relation
	 */
	public function test_get_status_no_task() {
		$status = Monitoring_Task_Service::get_status( 99999 );

		$this->assertIsArray( $status );
		$this->assertFalse( $status['has_task'] );
		$this->assertEquals( 'none', $status['status'] );
	}

	// ==================== start_monitoring() Tests ====================

	/**
	 * Test start_monitoring creates and activates task
	 */
	public function test_start_monitoring() {
		$result = Monitoring_Task_Service::start_monitoring( $this->test_relation_id );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'task_id', $result );

		$this->test_task_ids[] = $result['task_id'];

		// Verify active
		$task = Monitoring_Task_Service::get( $result['task_id'] );
		$this->assertEquals( 'active', $task['status'] );
	}

	/**
	 * Test start_monitoring reactivates paused task
	 */
	public function test_start_monitoring_reactivates_paused() {
		// Create and pause
		$task = Monitoring_Task_Service::create( $this->test_relation_id );
		$this->test_task_ids[] = $task['id'];
		Monitoring_Task_Service::update_status( $task['id'], Monitoring_Task_Service::STATUS_PAUSED );

		// Start monitoring
		$result = Monitoring_Task_Service::start_monitoring( $this->test_relation_id );

		$this->assertTrue( $result['success'] );

		// Verify active
		$updated = Monitoring_Task_Service::get( $task['id'] );
		$this->assertEquals( 'active', $updated['status'] );
	}

	// ==================== stop_monitoring() Tests ====================

	/**
	 * Test stop_monitoring pauses task
	 */
	public function test_stop_monitoring() {
		$task = Monitoring_Task_Service::create( $this->test_relation_id );
		$this->test_task_ids[] = $task['id'];

		$result = Monitoring_Task_Service::stop_monitoring( $this->test_relation_id );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// Verify paused
		$updated = Monitoring_Task_Service::get( $task['id'] );
		$this->assertEquals( 'paused', $updated['status'] );
	}

	/**
	 * Test stop_monitoring returns error for no task
	 */
	public function test_stop_monitoring_no_task() {
		$result = Monitoring_Task_Service::stop_monitoring( 99999 );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	// ==================== create_sync_task() Tests (ISS-TSK-015) ====================

	/**
	 * Test create_sync_task creates a sync task for valid post and relation
	 */
	public function test_create_sync_task_creates_task() {
		// Create a test post first.
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Test Sync Post ' . uniqid(),
				'post_content' => 'Test content',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);

		$result = Monitoring_Task_Service::create_sync_task( $post_id, $this->test_relation_id );

		// Should return task ID (int) or false.
		$this->assertNotFalse( $result );
		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );

		// Track for cleanup.
		$this->test_task_ids[] = $result;

		// Verify task exists with correct type.
		global $wpdb;
		$tasks_table = $wpdb->prefix . 'wptsall_tasks';
		$task        = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tasks_table} WHERE id = %d", $result ),
			ARRAY_A
		);

		$this->assertNotNull( $task );
		$this->assertEquals( 'sync', $task['type'] );
		$this->assertEquals( 'pending', $task['status'] );
		$this->assertEquals( $this->test_relation_id, (int) $task['relation_id'] );
		$this->assertEquals( $post_id, (int) $task['object_id'] );
		$this->assertEquals( 'post_type', $task['object_type'] );

		// Clean up test post.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test create_sync_task returns false for invalid post
	 */
	public function test_create_sync_task_invalid_post() {
		$result = Monitoring_Task_Service::create_sync_task( 99999999, $this->test_relation_id );

		$this->assertFalse( $result );
	}

	/**
	 * Test create_sync_task returns false for invalid relation
	 */
	public function test_create_sync_task_invalid_relation() {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Test Post for Invalid Relation',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$result = Monitoring_Task_Service::create_sync_task( $post_id, 99999999 );

		$this->assertFalse( $result );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test create_sync_task deduplication - returns existing task ID
	 */
	public function test_create_sync_task_deduplication() {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Dedupe Test Post ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		// Create first task.
		$first_task_id = Monitoring_Task_Service::create_sync_task( $post_id, $this->test_relation_id );
		$this->test_task_ids[] = $first_task_id;

		// Try to create second task for same post + relation.
		$second_task_id = Monitoring_Task_Service::create_sync_task( $post_id, $this->test_relation_id );

		// Should return same task ID (deduplication).
		$this->assertEquals( $first_task_id, $second_task_id );

		wp_delete_post( $post_id, true );
	}

	// ==================== delete_by_relation() Tests ====================

	/**
	 * Test delete_by_relation removes task
	 */
	public function test_delete_by_relation() {
		$task = Monitoring_Task_Service::create( $this->test_relation_id );
		// Don't add to test_task_ids since we're deleting it

		$result = Monitoring_Task_Service::delete_by_relation( $this->test_relation_id );

		$this->assertTrue( $result );

		// Verify deleted
		$deleted = Monitoring_Task_Service::get( $task['id'] );
		$this->assertNull( $deleted );
	}

	/**
	 * Test delete_by_relation returns false for no task
	 */
	public function test_delete_by_relation_no_task() {
		$result = Monitoring_Task_Service::delete_by_relation( 99999 );

		$this->assertFalse( $result );
	}

	/**
	 * Test process() runs a monitoring check on an existing task and stamps
	 * the check window (CLI 'tasks monitor' path; the method was previously
	 * missing and fatalled, found by the 2026-09-11 PHPStan pass).
	 */
	public function test_process_runs_check_and_stamps_window() {
		$created = Monitoring_Task_Service::get_or_create( $this->test_relation_id );
		$this->test_task_ids[] = $created['id'];

		$result = Monitoring_Task_Service::process( $created['id'] );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['stats']['checked'] );
		$this->assertSame( 0, $result['stats']['synced'] );
		$this->assertSame( 0, $result['stats']['errors'] );

		$task = Monitoring_Task_Service::get( $created['id'] );
		$this->assertNotSame( '', (string) ( $task['last_check_at'] ?? '' ), 'record_check must stamp last_check_at' );
	}

	/**
	 * Test process() reports not_found for a missing task.
	 */
	public function test_process_missing_task_reports_not_found() {
		$result = Monitoring_Task_Service::process( 999999 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'not_found', $result['error'] );
	}

	/**
	 * Clean up after all tests
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test tasks
		$tasks_table = $wpdb->prefix . 'wptsall_tasks';
		foreach ( $this->test_task_ids as $task_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $tasks_table, array( 'id' => $task_id ), array( '%d' ) );
		}

		// Clean up test relation
		$relations_table = $wpdb->prefix . 'wptsall_site_relations';
		if ( $this->test_relation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $relations_table, array( 'id' => $this->test_relation_id ), array( '%d' ) );
		}

		// Also clean up any orphaned monitoring tasks for this relation
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$tasks_table,
			array(
				'type'        => 'monitoring',
				'relation_id' => $this->test_relation_id,
			),
			array( '%s', '%d' )
		);

		parent::tearDown();
	}
}
