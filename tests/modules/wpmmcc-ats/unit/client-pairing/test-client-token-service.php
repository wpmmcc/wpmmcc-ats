<?php
/**
 * Client_Token_Service / client token tests (device-scoped only).
 *
 * @package WPTSALL
 */

use WPTSALL\Client_Pairing\Services\Client_Token_Service;

class Test_Client_Token_Service extends SimpleTestCase {

	public function setUp(): void {
		parent::setUp();
		$ref  = new ReflectionClass( Client_Token_Service::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
		delete_option( Client_Token_Service::OPTION_CLIENT_TOKEN );
		delete_option( Client_Token_Service::OPTION_CLIENT_DEVICES );
	}

	public function test_pro_always_enabled() {
		$this->assertTrue( Client_Token_Service::instance()->is_pro_enabled() );
	}

	public function test_install_token_retired() {
		$svc = Client_Token_Service::instance();
		update_option( Client_Token_Service::OPTION_CLIENT_TOKEN, 'stale', false );
		$this->assertSame( '', $svc->get_client_token() );
		$this->assertFalse( $svc->verify_client_token( 'stale', 'dev-1' ) );
	}

	public function test_device_token_roundtrip() {
		$svc = Client_Token_Service::instance();
		$issued = $svc->issue_device_token( 'lic-unit-1', 'unit' );
		$this->assertTrue( $svc->verify_client_token( $issued['token'], $issued['device_id'] ) );
		$svc->revoke_device_token( $issued['device_id'] );
		$this->assertFalse( $svc->verify_client_token( $issued['token'], $issued['device_id'] ) );
	}

	public function test_snapshot_reports_active_devices() {
		$svc = Client_Token_Service::instance();
		$svc->issue_device_token( 'lic-unit-2', 'unit' );
		$snap = $svc->get_client_token_snapshot();
		$this->assertTrue( $snap['has_token'] );
		$this->assertGreaterThanOrEqual( 1, (int) $snap['active_devices'] );
	}

	// ==================== 批 D ③: rotation overlap window ====================

	/**
	 * Helper: force the stored previous_valid_until for a device to a value.
	 */
	private function force_previous_valid_until( $device_id, $value ) {
		$devices = get_option( Client_Token_Service::OPTION_CLIENT_DEVICES, array() );
		foreach ( $devices as &$device ) {
			if ( (string) ( $device['device_id'] ?? '' ) === $device_id ) {
				$device['previous_valid_until'] = $value;
			}
		}
		unset( $device );
		update_option( Client_Token_Service::OPTION_CLIENT_DEVICES, $devices, false );
	}

	public function test_rotation_overlap_keeps_previous_token_valid() {
		$svc = Client_Token_Service::instance();
		$first  = $svc->issue_device_token( 'rot-dev-1', 'unit' );
		$second = $svc->issue_device_token( 'rot-dev-1', 'unit' );

		$this->assertTrue(
			$svc->verify_client_token( $second['token'], 'rot-dev-1' ),
			'new generation must verify'
		);
		$this->assertTrue(
			$svc->verify_client_token( $first['token'], 'rot-dev-1' ),
			'previous generation must stay valid inside the overlap window'
		);
		$this->assertFalse(
			$svc->verify_client_token( $first['token'], 'other-device' ),
			'overlap is device-scoped — the token must not verify for another device'
		);
	}

	public function test_rotation_overlap_expires_after_window() {
		$svc = Client_Token_Service::instance();
		$first  = $svc->issue_device_token( 'rot-dev-2', 'unit' );
		$second = $svc->issue_device_token( 'rot-dev-2', 'unit' );
		$this->assertTrue( $svc->verify_client_token( $first['token'], 'rot-dev-2' ) );

		$this->force_previous_valid_until( 'rot-dev-2', time() - 1 );

		$this->assertFalse(
			$svc->verify_client_token( $first['token'], 'rot-dev-2' ),
			'previous generation must fail once the overlap window passed'
		);
		$this->assertTrue(
			$svc->verify_client_token( $second['token'], 'rot-dev-2' ),
			'current generation is unaffected by the window expiring'
		);
	}

	public function test_rotation_overlap_never_resurrects_expired_token() {
		$svc    = Client_Token_Service::instance();
		$first  = $svc->issue_device_token( 'rot-dev-3', 'unit', 0 );
		$second = $svc->issue_device_token( 'rot-dev-3', 'unit' );

		$this->assertFalse(
			$svc->verify_client_token( $first['token'], 'rot-dev-3' ),
			'an expired credential must not be resurrected by a later rotation'
		);
		$this->assertTrue( $svc->verify_client_token( $second['token'], 'rot-dev-3' ) );
	}

	public function test_second_rotation_replaces_overlap_chain() {
		$svc    = Client_Token_Service::instance();
		$first  = $svc->issue_device_token( 'rot-dev-4', 'unit' );
		$second = $svc->issue_device_token( 'rot-dev-4', 'unit' );
		$third  = $svc->issue_device_token( 'rot-dev-4', 'unit' );

		$this->assertTrue( $svc->verify_client_token( $third['token'], 'rot-dev-4' ) );
		$this->assertTrue(
			$svc->verify_client_token( $second['token'], 'rot-dev-4' ),
			'immediate predecessor stays valid after a second rotation'
		);
		$this->assertFalse(
			$svc->verify_client_token( $first['token'], 'rot-dev-4' ),
			'two generations back must be dropped — no chain of three'
		);
	}

	public function test_revoke_kills_both_generations_immediately() {
		$svc    = Client_Token_Service::instance();
		$first  = $svc->issue_device_token( 'rot-dev-5', 'unit' );
		$second = $svc->issue_device_token( 'rot-dev-5', 'unit' );
		$this->assertTrue( $svc->verify_client_token( $first['token'], 'rot-dev-5' ) );

		$svc->revoke_device_token( 'rot-dev-5' );

		$this->assertFalse( $svc->verify_client_token( $first['token'], 'rot-dev-5' ) );
		$this->assertFalse( $svc->verify_client_token( $second['token'], 'rot-dev-5' ) );
	}

	public function test_rotation_overlap_disabled_by_filter_zero() {
		add_filter( 'wptsall_device_token_overlap_seconds', '__return_zero' );
		$svc    = Client_Token_Service::instance();
		$first  = $svc->issue_device_token( 'rot-dev-6', 'unit' );
		$second = $svc->issue_device_token( 'rot-dev-6', 'unit' );
		remove_filter( 'wptsall_device_token_overlap_seconds', '__return_zero' );

		$this->assertFalse(
			$svc->verify_client_token( $first['token'], 'rot-dev-6' ),
			'overlap=0 must restore hard cutover (pre-batch-D-③ behavior)'
		);
		$this->assertTrue( $svc->verify_client_token( $second['token'], 'rot-dev-6' ) );
	}

	public function test_list_devices_surfaces_live_overlap_window() {
		$svc    = Client_Token_Service::instance();
		$svc->issue_device_token( 'rot-dev-7', 'unit' );
		$svc->issue_device_token( 'rot-dev-7', 'unit' );

		$rows = $svc->list_devices();
		$row  = null;
		foreach ( $rows as $candidate ) {
			if ( 'rot-dev-7' === $candidate['device_id'] ) {
				$row = $candidate;
			}
		}
		$this->assertNotNull( $row );
		$this->assertArrayHasKey( 'rotation_overlap_until', $row );
		$this->assertGreaterThan(
			time(),
			(int) $row['rotation_overlap_until'],
			'a mid-transition device must expose a future overlap deadline'
		);

		$this->force_previous_valid_until( 'rot-dev-7', time() - 1 );
		$rows = $svc->list_devices();
		foreach ( $rows as $candidate ) {
			if ( 'rot-dev-7' === $candidate['device_id'] ) {
				$this->assertNull(
					$candidate['rotation_overlap_until'],
					'an elapsed window must report null, not a past timestamp'
				);
			}
		}
	}
}
