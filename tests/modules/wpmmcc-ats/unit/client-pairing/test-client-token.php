<?php
/**
 * Client Token Tests — device-scoped only (pre-release single truth).
 *
 * @package WPTSALL
 * @since 1.0.0
 */

use WPTSALL\Client_Pairing\Services\Client_Token_Service;

class Test_Client_Token extends SimpleTestCase {

	/**
	 * @var array
	 */
	private $cleanup_options = array();

	public function setUp(): void {
		parent::setUp();

		$ref  = new ReflectionClass( Client_Token_Service::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		delete_option( Client_Token_Service::OPTION_CLIENT_TOKEN );
		delete_option( Client_Token_Service::OPTION_CLIENT_TOKEN_STATE );
		delete_option( Client_Token_Service::OPTION_CLIENT_TOKEN_SECRET );
		delete_option( Client_Token_Service::OPTION_CLIENT_DEVICES );

		$this->cleanup_options = array(
			Client_Token_Service::OPTION_CLIENT_TOKEN,
			Client_Token_Service::OPTION_CLIENT_TOKEN_STATE,
			Client_Token_Service::OPTION_CLIENT_TOKEN_SECRET,
			Client_Token_Service::OPTION_CLIENT_DEVICES,
		);
	}

	public function tearDown(): void {
		foreach ( $this->cleanup_options as $option ) {
			delete_option( $option );
		}
		parent::tearDown();
	}

	private function get_service() {
		return Client_Token_Service::instance();
	}

	public function test_get_client_token_clears_install_level_and_returns_empty() {
		update_option( Client_Token_Service::OPTION_CLIENT_TOKEN, 'stale-install-token', false );
		$service = $this->get_service();
		$this->assertSame( '', $service->get_client_token() );
		$this->assertSame( '', (string) get_option( Client_Token_Service::OPTION_CLIENT_TOKEN, '' ) );
	}

	public function test_issue_and_verify_device_token() {
		$service = $this->get_service();
		$issued  = $service->issue_device_token( 'unit-device-a', 'unit' );
		$this->assertNotEmpty( $issued['token'] );
		$this->assertSame( 'unit-device-a', $issued['device_id'] );
		$this->assertTrue( $service->verify_client_token( $issued['token'], $issued['device_id'] ) );
	}

	public function test_verify_requires_device_id() {
		$service = $this->get_service();
		$issued  = $service->issue_device_token( 'unit-device-b', 'unit' );
		$this->assertFalse( $service->verify_client_token( $issued['token'], '' ) );
	}

	public function test_verify_rejects_wrong_device() {
		$service = $this->get_service();
		$issued  = $service->issue_device_token( 'unit-device-c', 'unit' );
		$this->assertFalse( $service->verify_client_token( $issued['token'], 'other-device' ) );
	}

	public function test_verify_rejects_install_level_plaintext() {
		$service = $this->get_service();
		update_option( Client_Token_Service::OPTION_CLIENT_TOKEN, 'planted-legacy', false );
		$this->assertFalse( $service->verify_client_token( 'planted-legacy', 'any-device' ) );
	}

	public function test_revoke_device_token() {
		$service = $this->get_service();
		$issued  = $service->issue_device_token( 'unit-device-d', 'unit' );
		$this->assertTrue( $service->revoke_device_token( $issued['device_id'] ) );
		$this->assertFalse( $service->verify_client_token( $issued['token'], $issued['device_id'] ) );
	}
}
