<?php
/**
 * Client Token Auth Tests — device-scoped only.
 *
 * @package WPTSALL\Tests\Unit\Client_Pairing
 * @since 1.1.0
 */

use WPTSALL\Client_Pairing\Services\Client_Token_Service;

class Test_Client_Token_Auth_Unit extends SimpleTestCase {

	/**
	 * @var string[]
	 */
	private array $option_keys = array(
		'wptsall_client_api_token',
		'wptsall_client_api_token_state',
		'wptsall_client_api_token_secret',
		'wptsall_client_devices',
	);

	/**
	 * @var array<string,mixed>
	 */
	private array $option_snapshot = array();

	public function setUp(): void {
		parent::setUp();

		foreach ( $this->option_keys as $key ) {
			$this->option_snapshot[ $key ] = get_option( $key, null );
		}

		$ref = new ReflectionClass( Client_Token_Service::class );
		if ( $ref->hasProperty( 'instance' ) ) {
			$prop = $ref->getProperty( 'instance' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}

		foreach ( $this->option_keys as $key ) {
			delete_option( $key );
		}
	}

	public function tearDown(): void {
		foreach ( $this->option_keys as $key ) {
			if ( array_key_exists( $key, $this->option_snapshot ) && null !== $this->option_snapshot[ $key ] ) {
				update_option( $key, $this->option_snapshot[ $key ], true );
			} else {
				delete_option( $key );
			}
		}
		parent::tearDown();
	}

	public function test_device_token_hash_at_rest() {
		$service = Client_Token_Service::instance();
		$issued  = $service->issue_device_token( 'auth-unit-a', 'unit' );
		$devices = get_option( Client_Token_Service::OPTION_CLIENT_DEVICES, array() );
		$this->assertIsArray( $devices );
		$this->assertNotEmpty( $devices );
		$hash = (string) ( $devices[0]['token_hash'] ?? '' );
		$this->assertNotEmpty( $hash );
		$this->assertNotEquals( $issued['token'], $hash );
		$this->assertTrue( $service->verify_client_token( $issued['token'], $issued['device_id'] ) );
	}

	public function test_verify_uses_hash_equals_bound_to_device() {
		$service = Client_Token_Service::instance();
		$issued  = $service->issue_device_token( 'auth-unit-b', 'unit' );
		$token   = $issued['token'];
		$this->assertTrue( $service->verify_client_token( $token, $issued['device_id'] ) );

		$corrupted = substr( $token, 0, -1 ) . ( '0' === substr( $token, -1 ) ? '1' : '0' );
		$this->assertNotEquals( $token, $corrupted, 'corrupted token must differ from the original' );
		$this->assertFalse( $service->verify_client_token( $corrupted, $issued['device_id'] ) );
	}

	public function test_empty_devices_rejects_all() {
		$service = Client_Token_Service::instance();
		$this->assertFalse( $service->verify_client_token( bin2hex( random_bytes( 32 ) ), 'any-device' ) );
	}
}
