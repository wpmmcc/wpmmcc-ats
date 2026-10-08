<?php
/**
 * Chain 9: 同步预览与冲突解决链路测试 (STUB)
 *
 * This chain has been moved to legacy/.
 * Run with: php run.php --suite=legacy
 *
 * Reason: endpoints tasks/preview, tasks/resolve-conflict, tasks/rollback, tasks/conflicts
 * do not exist in the current API.
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 9 Test: Sync Preview & Conflict Resolution (Stub)
 */
class Test_Chain_9_Sync_Preview extends REST_Integration_Test_Case {

	/**
	 * Stub: chain moved to legacy/
	 */
	public function test_moved_to_legacy() {
		$this->markTestSkipped( 'Chain 9 moved to legacy/. Run with --suite=legacy.' );
	}
}
