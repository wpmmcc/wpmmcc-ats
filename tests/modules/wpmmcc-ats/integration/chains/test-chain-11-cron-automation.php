<?php
/**
 * Chain 11: 定时任务与 Cron 调度链路测试 (STUB)
 *
 * This chain has been moved to legacy/.
 * Run with: php run.php --suite=legacy
 *
 * Reason: mostly cron smoke checks with assertTrue(true) placeholders, low value.
 * Non-existent endpoints: tasks/auto-sync, tasks/run-cron, tasks/cron-status.
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 11 Test: Cron Scheduling & Task Automation (Stub)
 */
class Test_Chain_11_Cron_Automation extends REST_Integration_Test_Case {

	/**
	 * Stub: chain moved to legacy/
	 */
	public function test_moved_to_legacy() {
		$this->markTestSkipped( 'Chain 11 moved to legacy/. Run with --suite=legacy.' );
	}
}
