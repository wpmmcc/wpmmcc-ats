<?php
/**
 * Classification Rules Tests
 *
 * Tests for WPTSALL\Core\Classification_Rules:
 * - URL rules: every pattern compiles, default rule is lowest priority
 *   and ordered last, known URL examples hit their intended rule
 * - Data rules: post/page/product sync-always with publish condition,
 *   user/shop_order sync-never with PII reason, comment default false
 * - Meta rules: internal keys in never_sync, PII patterns in pii_fields,
 *   thumbnail in configurable_sync, view counts in configurable_no_sync
 * - apply_filters(): the wptsall_classification_rules_<type> filter
 *   actually overrides the returned rules
 *
 * catalog: WP-CLASS-Classification_Rules
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Core\Classification_Rules;
use WPTSALL\Core\Classification_Constants as CC;

class Test_Classification_Rules extends SimpleTestCase {

	const FILTER_KEY_URL = 'wptsall_classification_rules_url';

	public function setUp(): void {
		parent::setUp();
		remove_all_filters( self::FILTER_KEY_URL );
		remove_all_filters( 'wptsall_classification_rules_data' );
	}

	public function tearDown(): void {
		remove_all_filters( self::FILTER_KEY_URL );
		remove_all_filters( 'wptsall_classification_rules_data' );
		parent::tearDown();
	}

	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Core\Classification_Rules' ) );
	}

	/**
	 * Every URL rule carries the documented shape and its pattern is a
	 * compilable regex (preg_match never returns false).
	 */
	public function test_url_rules_shape_and_patterns_compile() {
		$rules = Classification_Rules::get_url_rules();
		$this->assertNotEmpty( $rules );

		$names = array();
		foreach ( $rules as $rule ) {
			foreach ( array( 'name', 'pattern', 'location', 'access', 'syncable' ) as $key ) {
				$this->assertArrayHasKey( $key, $rule, "url rule '{$rule['name']}' must define '{$key}'" );
			}
			$this->assertIsBool( $rule['syncable'] );
			// Pattern must be a valid regex (preg_match returns 0/1, not false).
			$this->assertNotFalse( @preg_match( $rule['pattern'], '' ), "pattern for '{$rule['name']}' must compile" );
			$names[] = $rule['name'];
		}

		// Default rule is present, lowest priority, and ordered last.
		$this->assertContains( 'default', $names );
		$last = $rules[ count( $rules ) - 1 ];
		$this->assertEquals( 'default', $last['name'], 'default rule must be the final fallback' );
		$this->assertEquals( 999, $last['priority'] );
		$this->assertTrue( $last['syncable'] );
	}

	/**
	 * URL rules classify representative URLs the way their names promise.
	 */
	public function test_url_rules_match_examples() {
		$rules = Classification_Rules::get_url_rules();

		$cases = array(
			'/wp-admin/index.php'   => array( 'wp_admin', false ),
			'/wp-login.php'         => array( 'wp_login', false ),
			'/wp-json/wptsall/v2/x' => array( 'rest_api', true ),
			'/blog/hello-world'     => array( 'blog_post', true ),
			'/product/blue-shoe'    => array( 'product', true ),
			'/cart'                 => array( 'default', true ), // '/cart' alone does not match '^\/(cart|...)\/'
			'/cart/'                => array( 'checkout', false ),
			'/my-account/orders'    => array( 'user_account', false ),
			'/some/random/page'     => array( 'default', true ),
		);

		foreach ( $cases as $url => $expected ) {
			$matched = 'default';
			$syncable = null;
			foreach ( $rules as $rule ) {
				if ( 1 === @preg_match( $rule['pattern'], $url ) ) {
					$matched  = $rule['name'];
					$syncable = $rule['syncable'];
					break;
				}
			}
			$this->assertEquals(
				$expected[0],
				$matched,
				"'{$url}' should first-match rule '{$expected[0]}'"
			);
			$this->assertSame(
				$expected[1],
				$syncable,
				"'{$url}' syncable flag through rule '{$expected[0]}'"
			);
		}
	}

	public function test_data_rules_semantics() {
		$rules = Classification_Rules::get_data_rules();

		// post/page/product/portfolio: content, public, sync-always, publish-only.
		foreach ( array( 'post', 'page', 'product', 'portfolio' ) as $type ) {
			$this->assertArrayHasKey( $type, $rules );
			$this->assertEquals( CC::DATA_TYPE_CONTENT, $rules[ $type ]['data_type'] );
			$this->assertEquals( CC::SYNC_ALWAYS, $rules[ $type ]['sync_policy'] );
			$this->assertEquals( array( 'publish' ), $rules[ $type ]['conditions']['post_status'] );
		}

		// user / shop_order: PII, sync-never, with a reason string.
		foreach ( array( 'user', 'shop_order' ) as $type ) {
			$this->assertArrayHasKey( $type, $rules );
			$this->assertEquals( CC::DATA_PRIVACY_PII, $rules[ $type ]['privacy'] );
			$this->assertEquals( CC::SYNC_NEVER, $rules[ $type ]['sync_policy'] );
			$this->assertNotEmpty( $rules[ $type ]['reason'], "{$type} never-sync must carry a reason" );
		}

		// comment: configurable, default off.
		$this->assertEquals( CC::SYNC_CONFIGURABLE, $rules['comment']['sync_policy'] );
		$this->assertFalse( (bool) $rules['comment']['default'] );

		// attachment: configurable, default on.
		$this->assertEquals( CC::SYNC_CONFIGURABLE, $rules['attachment']['sync_policy'] );
		$this->assertTrue( (bool) $rules['attachment']['default'] );
	}

	public function test_meta_rules_group_memberships() {
		$rules = Classification_Rules::get_meta_rules();

		$this->assertContains( '_edit_lock', $rules['never_sync'] );
		$this->assertContains( '_transient_*', $rules['never_sync'] );

		$this->assertContains( 'billing_*', $rules['pii_fields'] );
		$this->assertContains( 'ip_address', $rules['pii_fields'] );

		$this->assertContains( '_thumbnail_id', $rules['configurable_sync'] );
		$this->assertContains( '_yoast_wpseo_*', $rules['configurable_sync'] );

		$this->assertContains( 'post_views_count', $rules['configurable_no_sync'] );
		$this->assertContains( '_wp_attachment_metadata', $rules['configurable_no_sync'] );
	}

	public function test_default_sync_config_pins_privacy_defaults() {
		$config = Classification_Rules::get_default_sync_config();

		// Content/attachment/term sync on; user/order/comment/settings off.
		$this->assertTrue( $config['data_types'][ CC::DATA_TYPE_CONTENT ] );
		$this->assertTrue( $config['data_types'][ CC::DATA_TYPE_ATTACHMENT ] );
		$this->assertTrue( $config['data_types'][ CC::DATA_TYPE_TERM ] );
		$this->assertFalse( $config['data_types'][ CC::DATA_TYPE_USER ] );
		$this->assertFalse( $config['data_types'][ CC::DATA_TYPE_COMMENT ] );
		$this->assertFalse( $config['data_types'][ CC::DATA_TYPE_ORDER ] );
		$this->assertFalse( $config['data_types'][ CC::DATA_TYPE_SETTINGS ] );

		// URL access: public only.
		$this->assertTrue( $config['url_access'][ CC::URL_ACCESS_PUBLIC ] );
		foreach ( array( CC::URL_ACCESS_LOGIN, CC::URL_ACCESS_ROLE, CC::URL_ACCESS_ADMIN, CC::URL_ACCESS_RESTRICTED ) as $access ) {
			$this->assertFalse( $config['url_access'][ $access ] );
		}

		// Privacy protections all on.
		$this->assertTrue( $config['privacy_protection']['block_pii'] );
		$this->assertTrue( $config['privacy_protection']['strip_pii_meta'] );
		$this->assertTrue( $config['privacy_protection']['anonymize_users'] );
	}

	public function test_apply_filters_overrides_rules_via_hook() {
		$override = array( array( 'name' => 'zz_custom_rule', 'syncable' => true ) );
		add_filter( self::FILTER_KEY_URL, static function () use ( $override ) {
			return $override;
		} );

		$this->assertSame( $override, Classification_Rules::apply_filters( 'url', array() ) );

		// Unfiltered rule types pass the input through unchanged.
		$untouched = array( 'keep' => 'me' );
		remove_all_filters( 'wptsall_classification_rules_untouched_type' );
		$this->assertSame( $untouched, Classification_Rules::apply_filters( 'untouched_type', $untouched ) );
	}
}
