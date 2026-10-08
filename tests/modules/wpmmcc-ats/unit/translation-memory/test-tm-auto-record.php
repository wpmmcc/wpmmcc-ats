<?php
/**
 * Translation memory auto-record gate (opus5 M-04).
 *
 * is_auto_record_enabled() must honor, in order: the site option
 * (wptsall_tm_auto_record, set by Translation Memory → settings), then the
 * per-environment wptsall_tm_auto_record_enabled filter override.
 *
 * catalog: WP-HOOK-wptsall-tm-auto-record-enabled
 * oracle: L2
 *
 * @package WPTSALL
 */

use WPTSALL\TranslationMemory\Services\Translation_Memory_Service;

/**
 * Test_TM_Auto_Record_Gate
 */
class Test_TM_Auto_Record_Gate extends SimpleTestCase {

	/**
	 * Default is on; the site option toggles it both ways.
	 */
	public function test_default_and_option_toggle() {
		delete_option( Translation_Memory_Service::OPTION_AUTO_RECORD );
		$this->assertTrue(
			Translation_Memory_Service::is_auto_record_enabled(),
			'default auto-record must be enabled'
		);

		update_option( Translation_Memory_Service::OPTION_AUTO_RECORD, 0 );
		$this->assertFalse(
			Translation_Memory_Service::is_auto_record_enabled(),
			'site admins must be able to switch recording off'
		);

		update_option( Translation_Memory_Service::OPTION_AUTO_RECORD, 1 );
		$this->assertTrue( Translation_Memory_Service::is_auto_record_enabled() );

		delete_option( Translation_Memory_Service::OPTION_AUTO_RECORD );
	}

	/**
	 * The filter can force the gate either way, overriding the option.
	 */
	public function test_filter_overrides_site_option() {
		update_option( Translation_Memory_Service::OPTION_AUTO_RECORD, 0 );

		add_filter( 'wptsall_tm_auto_record_enabled', '__return_true' );
		$this->assertTrue(
			Translation_Memory_Service::is_auto_record_enabled(),
			'the filter must be able to force-enable per environment'
		);
		remove_filter( 'wptsall_tm_auto_record_enabled', '__return_true' );

		add_filter( 'wptsall_tm_auto_record_enabled', '__return_false' );
		update_option( Translation_Memory_Service::OPTION_AUTO_RECORD, 1 );
		$this->assertFalse(
			Translation_Memory_Service::is_auto_record_enabled(),
			'the filter must be able to force-disable even with the option on'
		);
		remove_filter( 'wptsall_tm_auto_record_enabled', '__return_false' );

		delete_option( Translation_Memory_Service::OPTION_AUTO_RECORD );
	}
}
