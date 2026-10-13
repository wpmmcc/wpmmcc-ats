<?php
/**
 * Chain 19: 管理页面 UI 链路测试 (STUB)
 *
 * This chain has been moved to legacy/.
 * Run with: php run.php --suite=legacy
 *
 * Reason: admin UI is not automatable in headless test context; most tests
 * are assertTrue(true) placeholders or require a browser environment.
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 19 Test: Admin Pages UI (Stub)
 */
class Test_Chain_19_Admin_Pages extends REST_Integration_Test_Case {

	/**
	 * Stub: chain moved to legacy/
	 */
	public function test_moved_to_legacy() {
		$this->markTestSkipped( 'Chain 19 moved to legacy/. Run with --suite=legacy.' );
	}
}
