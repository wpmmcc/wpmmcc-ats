<?php
/**
 * Chain 20: REST API 补充测试 (STUB)
 *
 * This chain has been moved to legacy/.
 * Run with: php run.php --suite=legacy
 *
 * Reason: noisy tests against old/non-existent endpoints (stats/*, status/*,
 * cache/flush, index/rebuild, logs, backup/*) and mostly assertContains(404) patterns.
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 20 Test: REST API Additional (Stub)
 */
class Test_Chain_20_REST_API_Additional extends REST_Integration_Test_Case {

	/**
	 * Stub: chain moved to legacy/
	 */
	public function test_moved_to_legacy() {
		$this->markTestSkipped( 'Chain 20 moved to legacy/. Run with --suite=legacy.' );
	}
}
