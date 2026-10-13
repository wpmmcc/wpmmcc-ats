<?php
/**
 * Site Verification identity field tests (WP-ID-0 / contract/identity-v1)
 *
 * Golden assertions for the additive plugin_identity field carried in the
 * /site/verify signed payload:
 *
 * - plugin_identity === 'wpmmcc_ats' (fixtures/wpmmcc-ats-identity.json)
 * - plugin_identity is NOT part of the signed canonical message, so the
 *   HMAC signature over (site_url, nonce, expires_at, plugin_version)
 *   still verifies unchanged (additive contract field, no break).
 *
 * catalog: WP-IDENTITY-wpmmcc-ats-site-verify
 * oracle: contract/identity-v1
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Core\Site_Verification;

class Test_Site_Verification_Identity extends SimpleTestCase {

	/**
	 * Site secret option existed before the test ran.
	 *
	 * @var bool
	 */
	private $secret_existed = false;

	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\\WPTSALL\\Core\\Site_Verification' ) ) {
			$this->markTestSkipped( 'Site_Verification 类未加载' );
			return; // markTestSkipped throws, but return for clarity.
		}

		$this->secret_existed = get_option( Site_Verification::SITE_SECRET_OPTION ) !== false;
	}

	public function tearDown(): void {
		// Only remove the site secret if this test created it.
		if ( ! $this->secret_existed ) {
			delete_option( Site_Verification::SITE_SECRET_OPTION );
		}

		parent::tearDown();
	}

	/**
	 * The signed /site/verify payload carries the contract identity.
	 */
	public function test_signed_payload_carries_contract_identity() {
		$nonce      = wp_generate_uuid4();
		$expires_at = time() + Site_Verification::NONCE_TTL;
		$site_url   = home_url();

		// Seed the nonce transient directly (bypasses domain-format validation,
		// which rejects localhost unit environments).
		set_transient(
			Site_Verification::TRANSIENT_PREFIX . $nonce,
			array(
				'nonce'      => $nonce,
				'site_url'   => $site_url,
				'expires_at' => $expires_at,
				'created_at' => time(),
			),
			Site_Verification::NONCE_TTL
		);

		$result = Site_Verification::consume_nonce_and_sign( $nonce );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'plugin_identity', $result );

		// Golden value: fixtures/wpmmcc-ats-identity.json (contract/identity-v1).
		$this->assertSame( 'wpmmcc_ats', $result['plugin_identity'] );
		$this->assertSame( WPTSALL_VERSION, $result['plugin_version'] );

		// The signature must still cover the original canonical message only:
		// identity is additive and MUST NOT break server-side verification.
		$expected_canonical = Site_Verification::build_canonical_message(
			$site_url,
			$nonce,
			$expires_at,
			WPTSALL_VERSION
		);
		$site_secret  = Site_Verification::get_site_secret();
		$expected_sig = hash_hmac( 'sha256', $expected_canonical, hex2bin( $site_secret ) );

		$this->assertSame( $expected_sig, $result['signature'] );

		delete_transient( Site_Verification::TRANSIENT_PREFIX . $nonce );
	}

	/**
	 * The identity constant matches the contract value.
	 */
	public function test_identity_constant_is_defined() {
		$this->assertTrue( defined( 'WPMMCC_ATS_IDENTITY' ) );

		// Golden value: fixtures/wpmmcc-ats-identity.json (contract/identity-v1).
		$this->assertSame( 'wpmmcc_ats', WPMMCC_ATS_IDENTITY );
	}
}
