<?php
/**
 * Site verification tests.
 *
 * @package WPTSALL
 */

use WPTSALL\Core\Site_Verification;

class Test_Site_Verification_Canonical extends SimpleTestCase {

	/**
	 * Saved home option value.
	 *
	 * @var mixed
	 */
	private $home_option_backup = null;

	/**
	 * Site secret option existed before test.
	 *
	 * @var bool
	 */
	private $had_site_secret = false;

	/**
	 * Saved site secret value.
	 *
	 * @var mixed
	 */
	private $site_secret_backup = null;

	/**
	 * Transient keys created in test.
	 *
	 * @var array
	 */
	private $transient_keys = array();

	public function setUp(): void {
		parent::setUp();
		$this->ensure_site_verification_loaded();
		$this->home_option_backup = get_option( 'home', null );
		$this->had_site_secret    = false !== get_option( Site_Verification::SITE_SECRET_OPTION, false );
		$this->site_secret_backup = get_option( Site_Verification::SITE_SECRET_OPTION, null );
		add_filter( 'pre_option_home', array( $this, 'filter_valid_home_url' ) );
	}

	public function tearDown(): void {
		remove_filter( 'pre_option_home', array( $this, 'filter_valid_home_url' ) );

		foreach ( $this->transient_keys as $transient_key ) {
			delete_transient( $transient_key );
		}
		$this->transient_keys = array();

		if ( $this->had_site_secret ) {
			update_option( Site_Verification::SITE_SECRET_OPTION, $this->site_secret_backup, false );
		} else {
			delete_option( Site_Verification::SITE_SECRET_OPTION );
		}

		parent::tearDown();
	}

	private function ensure_site_verification_loaded() {
		if ( class_exists( '\WPTSALL\Core\Site_Verification' ) ) {
			return;
		}

		require_once WP_PLUGIN_DIR . '/wptsall-pro/includes/core/functions.php';
		require_once WP_PLUGIN_DIR . '/wptsall-pro/includes/core/class-site-verification.php';
	}

	public function filter_valid_home_url() {
		return 'https://example.com';
	}

	public function test_generate_verification_nonce_returns_expected_shape() {
		$result = Site_Verification::generate_verification_nonce();

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertNotEmpty( $result['nonce'] );
		$this->assertSame( Site_Verification::NONCE_TTL, (int) $result['expires_in'] );
		$this->assertStringContainsString( 'nonce=' . $result['nonce'], $result['verification_url'] );

		$transient_key           = Site_Verification::TRANSIENT_PREFIX . $result['nonce'];
		$this->transient_keys[]  = $transient_key;
		$stored                  = get_transient( $transient_key );

		$this->assertIsArray( $stored );
		$this->assertSame( 'https://example.com', $stored['site_url'] );
	}

	public function test_consume_nonce_and_sign_is_one_time_use() {
		$generated = Site_Verification::generate_verification_nonce();
		$this->assertFalse( is_wp_error( $generated ) );

		$nonce = $generated['nonce'];
		$this->transient_keys[] = Site_Verification::TRANSIENT_PREFIX . $nonce;

		$first = Site_Verification::consume_nonce_and_sign( $nonce );
		$this->assertFalse( is_wp_error( $first ) );
		$this->assertSame( $nonce, $first['nonce'] );
		$this->assertSame( 'https://example.com', $first['site_url'] );
		$this->assertNotEmpty( $first['signature'] );
		$this->assertNotEmpty( $first['encrypted_secret'] );
		$this->assertArrayHasKey( 'route_secret', $first );

		$second = Site_Verification::consume_nonce_and_sign( $nonce );
		$this->assertTrue( is_wp_error( $second ) );
		$this->assertSame( 'verification_expired', $second->get_error_code() );
	}

	public function test_consume_nonce_and_sign_rejects_expired_nonce() {
		$nonce         = wp_generate_uuid4();
		$transient_key = Site_Verification::TRANSIENT_PREFIX . $nonce;
		$this->transient_keys[] = $transient_key;

		set_transient(
			$transient_key,
			array(
				'nonce'      => $nonce,
				'site_url'   => 'https://example.com',
				'expires_at' => time() - 5,
				'created_at' => time() - 10,
			),
			Site_Verification::NONCE_TTL
		);

		$result = Site_Verification::consume_nonce_and_sign( $nonce );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'verification_expired', $result->get_error_code() );
		$this->assertFalse( get_transient( $transient_key ) );
	}
}
