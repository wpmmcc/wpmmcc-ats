<?php
/**
 * Automation cron behavior tests (opus5 T-03 + A-02)
 *
 * T-03: the stuck-task recovery cases were previously only runnable in the
 * opt-in unit-plugin lane; they now run in the default wp-unit lane.
 * A-02 adds the retry-cron cases: 'error' rows are retry-eligible and hit
 * the same retry_count dead-letter cap as 'retry' rows, and the sync
 * orchestrator counts error writes toward that cap (previously it bypassed
 * validation and never touched retry_count, so error tasks stalled forever).
 *
 * @package WPTSALL
 * @since 2.1.4
 */

use WPTSALL\Tasks\API\Client_Tasks_REST_Controller;
use WPTSALL\Tasks\Services\Task_Orchestrator;
use WPTSALL\Tasks\Services\Task_Status;

/**
 * Test_Automation_Cron
 */
class Test_Automation_Cron extends SimpleTestCase {

	/**
	 * Inserted task IDs (cleanup).
	 *
	 * @var array<int>
	 */
	private $task_ids = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wptsall_ensure_task_table' ) ) {
			wptsall_ensure_task_table();
		}
		wp_cache_flush();

		// The unit lane boots the full plugin; the guard only matters for
		// bootstraps that defer module loading.
		if ( ! class_exists( Client_Tasks_REST_Controller::class ) ) {
			require_once WP_PLUGIN_DIR . '/wpmmcc-ats/includes/tasks/api/class-client-tasks-rest-controller.php';
		}
	}

	/**
	 * Clean up test data.
	 */
	public function tearDown(): void {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		foreach ( $this->task_ids as $task_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'id' => (int) $task_id ), array( '%d' ) );
		}
		$this->task_ids = array();

		parent::tearDown();
	}

	/**
	 * Helper: claim lease seconds from its single source.
	 *
	 * @return int
	 */
	private function lease_seconds() {
		return (int) Client_Tasks_REST_Controller::DEFAULT_CLIENT_CLAIM_LEASE_SECONDS;
	}

	/**
	 * Insert a task row with DB-relative timestamps.
	 *
	 * @param string   $status         Task status.
	 * @param int      $age_seconds    Age in seconds relative to NOW().
	 * @param int      $recovery_count Existing recovery count (meta).
	 * @param string   $type           Task type.
	 * @param int|null $retry_at       Retry-at offset in seconds (null = column NULL,
	 *                                 positive = future, negative = past).
	 * @param int      $retry_count    Stored retry_count.
	 * @return int Task ID.
	 */
	private function insert_task_with_age( $status, $age_seconds, $recovery_count = 0, $type = 'sync', $retry_at = null, $retry_count = 0 ) {
		global $wpdb;

		$table     = wptsall_table( 'tasks' );
		$object_id = 900000 + count( $this->task_ids ) + 1;
		$template  = 'test-automation-cron-' . uniqid();
		$meta     = wp_json_encode(
			array(
				'recovery_count' => (int) $recovery_count,
			)
		);

		if ( null === $retry_at ) {
			$retry_at_expr = 'NULL';
		} elseif ( $retry_at >= 0 ) {
			$retry_at_expr = 'DATE_ADD(NOW(), INTERVAL ' . (int) $retry_at . ' SECOND)';
		} else {
			$retry_at_expr = 'DATE_SUB(NOW(), INTERVAL ' . abs( (int) $retry_at ) . ' SECOND)';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
				(blog_id, target_blog, target_type, target_identifier, site_id, relation_id, template, type, model_ids, priority, object_type, subtype, object_id, lang_from, lang_to, site_mode, status, status_note, retry_count, retry_at, progress, meta, last_check_at, next_check_at, payload, created_at, updated_at)
				VALUES
				(%d, %d, %s, %s, %d, %d, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %d, {$retry_at_expr}, %s, %s, NULL, NULL, %s, DATE_SUB(NOW(), INTERVAL %d SECOND), DATE_SUB(NOW(), INTERVAL %d SECOND))",
				get_current_blog_id(),
				0,
				'virtual',
				'v_test_' . $object_id,
				0,
				0,
				$template,
				$type,
				'[]',
				'normal',
				'post',
				'post',
				$object_id,
				'zh_CN',
				'en_US',
				'',
				$status,
				'',
				(int) $retry_count,
				'{}',
				$meta,
				'{}',
				(int) $age_seconds,
				(int) $age_seconds
			)
		);

		$task_id          = (int) $wpdb->insert_id;
		$this->task_ids[] = $task_id;

		return $task_id;
	}

	/**
	 * Fetch a task row.
	 *
	 * @param int $task_id Task ID.
	 * @return array<string,mixed>
	 */
	private function get_task_row( $task_id ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $task_id ),
			ARRAY_A
		);
	}

	/**
	 * T-03 (ported): stuck tasks older than the claim lease are recovered.
	 */
	public function test_recover_stuck_tasks_resets_processing_task_beyond_lease() {
		$lease_timeout = $this->lease_seconds();
		$task_id       = $this->insert_task_with_age( 'processing', $lease_timeout + 120 );

		wptsall_recover_stuck_tasks();

		$task = $this->get_task_row( $task_id );
		$meta = json_decode( (string) $task['meta'], true );

		$this->assertSame( 'pending', $task['status'] );
		$this->assertIsArray( $meta );
		$this->assertSame( 1, (int) $meta['recovery_count'] );
		$this->assertStringContainsString( 'Auto-recovered from stuck', (string) $task['status_note'] );
	}

	/**
	 * T-03 (ported): recent processing tasks keep their lease.
	 */
	public function test_recover_stuck_tasks_does_not_reset_recent_processing_task() {
		$lease_timeout = $this->lease_seconds();
		$task_id       = $this->insert_task_with_age( 'processing', max( 1, $lease_timeout - 120 ) );

		wptsall_recover_stuck_tasks();

		$task = $this->get_task_row( $task_id );
		$meta = json_decode( (string) $task['meta'], true );

		$this->assertSame( 'processing', $task['status'] );
		$this->assertIsArray( $meta );
		$this->assertSame( 0, (int) $meta['recovery_count'] );
	}

	/**
	 * T-03 (ported): too many recoveries dead-letter the task.
	 */
	public function test_recover_stuck_tasks_marks_task_failed_after_max_recovery_count() {
		$lease_timeout = $this->lease_seconds();
		$task_id       = $this->insert_task_with_age( 'processing', $lease_timeout + 120, 3 );

		wptsall_recover_stuck_tasks();

		$task = $this->get_task_row( $task_id );
		$meta = json_decode( (string) $task['meta'], true );

		$this->assertSame( 'failed', $task['status'] );
		$this->assertIsArray( $meta );
		$this->assertSame( 4, (int) $meta['recovery_count'] );
		$this->assertStringContainsString( 'Dead-letter: stuck 4 times', (string) $task['status_note'] );
	}

	/**
	 * T-03 (ported): monitoring tasks are excluded from stuck recovery.
	 */
	public function test_recover_stuck_tasks_skips_monitoring_tasks() {
		$lease_timeout = $this->lease_seconds();
		$task_id       = $this->insert_task_with_age( 'processing', $lease_timeout + 120, 0, 'monitoring' );

		wptsall_recover_stuck_tasks();

		$task = $this->get_task_row( $task_id );
		$meta = json_decode( (string) $task['meta'], true );

		$this->assertSame( 'processing', $task['status'] );
		$this->assertIsArray( $meta );
		$this->assertSame( 0, (int) $meta['recovery_count'] );
	}

	/**
	 * A-02: 'error' tasks are retry-eligible and get reset to pending.
	 */
	public function test_retry_failed_tasks_resets_error_task() {
		$task_id = $this->insert_task_with_age( 'error', 600, 0, 'sync', null, 1 );

		wptsall_retry_failed_tasks();

		$task = $this->get_task_row( $task_id );

		$this->assertSame( 'pending', $task['status'], 'error task must be re-dispatched by the retry cron' );
	}

	/**
	 * A-02 regression: 'retry' tasks are still reset to pending.
	 */
	public function test_retry_failed_tasks_still_resets_retry_task() {
		$task_id = $this->insert_task_with_age( 'retry', 600, 0, 'sync', null, 1 );

		wptsall_retry_failed_tasks();

		$task = $this->get_task_row( $task_id );

		$this->assertSame( 'pending', $task['status'] );
	}

	/**
	 * A-02: error tasks past the retry cap dead-letter to failed, not pending.
	 */
	public function test_retry_failed_tasks_dead_letters_error_over_limit() {
		$task_id = $this->insert_task_with_age( 'error', 600, 0, 'sync', null, 3 );

		wptsall_retry_failed_tasks();

		$task = $this->get_task_row( $task_id );

		$this->assertSame( 'failed', $task['status'], 'error task at the cap must dead-letter, never re-dispatch' );
		$this->assertStringContainsString( 'Exceeded maximum retry count', (string) $task['status_note'] );
	}

	/**
	 * A-02 regression: retry tasks past the cap still dead-letter to failed.
	 */
	public function test_retry_failed_tasks_dead_letters_retry_over_limit() {
		$task_id = $this->insert_task_with_age( 'retry', 600, 0, 'sync', null, 3 );

		wptsall_retry_failed_tasks();

		$task = $this->get_task_row( $task_id );

		$this->assertSame( 'failed', $task['status'] );
		$this->assertStringContainsString( 'Exceeded maximum retry count', (string) $task['status_note'] );
	}

	/**
	 * A-02: tasks in terminal states are outside the retry whitelist.
	 */
	public function test_retry_failed_tasks_does_not_touch_failed_tasks() {
		$task_id = $this->insert_task_with_age( 'failed', 600, 0, 'sync', null, 0 );

		wptsall_retry_failed_tasks();

		$task = $this->get_task_row( $task_id );

		$this->assertSame( 'failed', $task['status'] );
		$this->assertStringNotContainsString( 'Exceeded maximum retry count', (string) $task['status_note'] );
	}

	/**
	 * A-02: the retry_at backoff gate still applies to the eligible set.
	 */
	public function test_retry_failed_tasks_respects_retry_at_backoff() {
		$task_id = $this->insert_task_with_age( 'retry', 600, 0, 'sync', 3600, 1 );

		wptsall_retry_failed_tasks();

		$task = $this->get_task_row( $task_id );

		$this->assertSame( 'retry', $task['status'], 'future retry_at must hold the task back' );
	}

	/**
	 * A-02: the orchestrator error write increments retry_count so the
	 * dead-letter cap covers repeated processing exceptions.
	 */
	public function test_orchestrator_error_write_increments_retry_count() {
		$task_id = $this->insert_task_with_age( 'pending', 60 );
		$method  = new ReflectionMethod( Task_Orchestrator::class, 'update_task_status' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $task_id, Task_Status::ERROR, 'unit-test boom', true );

		$task = $this->get_task_row( $task_id );

		$this->assertTrue( $result );
		$this->assertSame( 'error', $task['status'] );
		$this->assertSame( 1, (int) $task['retry_count'], 'error write must count toward the dead-letter cap' );
		$this->assertSame( 'unit-test boom', (string) $task['status_note'] );
	}

	/**
	 * A-02: the orchestrator status write is vocabulary-validated — an
	 * out-of-vocabulary value is rejected and leaves the row untouched.
	 */
	public function test_orchestrator_rejects_invalid_status() {
		$task_id = $this->insert_task_with_age( 'pending', 60 );
		$method  = new ReflectionMethod( Task_Orchestrator::class, 'update_task_status' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $task_id, 'bogus-status', 'should never land' );

		$task = $this->get_task_row( $task_id );

		$this->assertFalse( $result );
		$this->assertSame( 'pending', $task['status'] );
		$this->assertStringNotContainsString( 'should never land', (string) $task['status_note'] );
	}
}
