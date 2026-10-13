<?php
/**
 * Client API request IP resolution tests.
 *
 * @package WPTSALL
 */

class Test_Client_API_Request_IP extends SimpleTestCase {

	/**
	 * Original $_SERVER snapshot.
	 *
	 * @var array
	 */
	private $server_backup = array();

	/**
	 * Original trusted proxy env.
	 *
	 * @var string|false
	 */
	private $trusted_proxies_backup = false;

	/**
	 * Injected allow-list rule.
	 *
	 * @var string
	 */
	private $whitelist_rule = '';

	public function setUp(): void {
		parent::setUp();
		$this->server_backup          = $_SERVER;
		$this->trusted_proxies_backup = getenv( 'WPTSALL_TRUSTED_PROXIES' );
		$this->ensure_core_functions_loaded();
		add_filter( 'wptsall_client_api_ip_whitelist', array( $this, 'inject_whitelist_rule' ) );
	}

	public function tearDown(): void {
		$_SERVER = $this->server_backup;
		$this->whitelist_rule = '';
		remove_filter( 'wptsall_client_api_ip_whitelist', array( $this, 'inject_whitelist_rule' ) );

		if ( false === $this->trusted_proxies_backup ) {
			putenv( 'WPTSALL_TRUSTED_PROXIES' );
		} else {
			putenv( 'WPTSALL_TRUSTED_PROXIES=' . $this->trusted_proxies_backup );
		}

		parent::tearDown();
	}

	private function ensure_core_functions_loaded() {
		if ( function_exists( 'wptsall_get_client_api_request_ip' ) ) {
			return;
		}

		require_once WP_PLUGIN_DIR . '/wptsall-pro/includes/core/functions.php';
	}

	public function inject_whitelist_rule( $raw ) {
		return '' !== $this->whitelist_rule ? $this->whitelist_rule : (string) $raw;
	}

	public function test_trusted_proxy_prefers_last_untrusted_x_forwarded_for_hop() {
		putenv( 'WPTSALL_TRUSTED_PROXIES=127.0.0.1,::1' );
		$_SERVER['REMOTE_ADDR']          = '127.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.24, 127.0.0.1';

		$this->assertSame( '198.51.100.24', wptsall_get_client_api_request_ip() );
	}

	public function test_untrusted_proxy_ignores_forwarded_headers() {
		putenv( 'WPTSALL_TRUSTED_PROXIES=127.0.0.1,::1' );
		$_SERVER['REMOTE_ADDR']          = '203.0.113.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.24';
		$_SERVER['HTTP_X_REAL_IP']       = '198.51.100.25';

		$this->assertSame( '203.0.113.5', wptsall_get_client_api_request_ip() );
	}

	public function test_trusted_proxy_falls_back_to_x_real_ip() {
		putenv( 'WPTSALL_TRUSTED_PROXIES=127.0.0.1' );
		$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
		$_SERVER['HTTP_X_REAL_IP'] = '198.51.100.77';

		$this->assertSame( '198.51.100.77', wptsall_get_client_api_request_ip() );
	}

	public function test_ip_allow_list_uses_forwarded_client_ip() {
		putenv( 'WPTSALL_TRUSTED_PROXIES=127.0.0.1' );
		$this->whitelist_rule            = '198.51.100.0/24';
		$_SERVER['REMOTE_ADDR']          = '127.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.88, 127.0.0.1';

		$this->assertTrue( wptsall_is_client_api_ip_allowed() );
	}

	public function test_ip_match_rule_supports_ipv6_cidr() {
		$this->assertTrue(
			wptsall_client_api_ip_matches_rule( '2001:db8::1234', '2001:db8::/32' )
		);
		$this->assertFalse(
			wptsall_client_api_ip_matches_rule( '2001:db9::1234', '2001:db8::/32' )
		);
	}

	public function test_ip_match_rule_rejects_invalid_mask_length() {
		$this->assertFalse(
			wptsall_client_api_ip_matches_rule( '198.51.100.10', '198.51.100.0/99' )
		);
		$this->assertFalse(
			wptsall_client_api_ip_matches_rule( '2001:db8::1', '2001:db8::/129' )
		);
	}
}
