<?php
/**
 * Settings Service Tests
 *
 * Tests for WPTSALL\Settings\Services\Settings_Service:
 * - defaults / get_all merging with the wptsall_settings option
 * - single-key get with fallback
 * - update(): partial updates, unknown-key whitelist, boolean and enum
 *   sanitization, persistence to the option
 * - wptsall_settings_updated action
 *
 * catalog: WP-CLASS-Settings_Service
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 1.2.0
 */

use WPTSALL\Settings\Services\Settings_Service;

class Test_Settings_Service extends SimpleTestCase {

	const OPTION_KEY = 'wptsall_settings';

	/**
	 * Original option value (null = option absent).
	 *
	 * @var mixed
	 */
	private $original_option;

	public function setUp(): void {
		parent::setUp();
		// Preserve any pre-existing settings on the test site.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_KEY ),
			ARRAY_A
		);
		$this->original_option = $row ? array( $row['option_value'], $row['autoload'] ) : null;
		delete_option( self::OPTION_KEY );
	}

	public function tearDown(): void {
		// Restore the original option exactly.
		if ( is_array( $this->original_option ) ) {
			update_option( self::OPTION_KEY, maybe_unserialize( $this->original_option[0] ), 'yes' === $this->original_option[1] );
		} else {
			delete_option( self::OPTION_KEY );
		}
		parent::tearDown();
	}

	public function test_service_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Settings\Services\Settings_Service' ) );
		$this->assertEquals( 'wptsall_settings', Settings_Service::OPTION_KEY );
	}

	public function test_defaults_cover_expected_schema() {
		$defaults = Settings_Service::defaults();

		$this->assertIsArray( $defaults );
		$this->assertEquals( 'subdir', $defaults['url_form'] );
		$this->assertEquals( 'zh_CN', $defaults['default_language'] );
		$this->assertEquals( 'copy', $defaults['media_handling'] );
		$this->assertEquals( 'new_only', $defaults['sync_mode'] );
		$this->assertEquals( 7, $defaults['log_retention_days'] );
		$this->assertTrue( $defaults['client_api_enabled'] );
		$this->assertFalse( $defaults['detect_browser'] );
		$this->assertFalse( $defaults['debug_mode'] );
		$this->assertEquals( 'wpmmcc-ats', $defaults['hreflang_emitter'] );
	}

	public function test_get_all_equals_defaults_when_option_absent() {
		$this->assertEquals( Settings_Service::defaults(), Settings_Service::get_all() );
	}

	public function test_get_all_treats_non_array_option_as_empty() {
		update_option( self::OPTION_KEY, 'not-an-array' );
		$this->assertEquals( Settings_Service::defaults(), Settings_Service::get_all() );
	}

	public function test_get_single_key_with_and_without_fallback() {
		$this->assertEquals( 'subdir', Settings_Service::get( 'url_form' ) );
		$this->assertEquals( 7, Settings_Service::get( 'log_retention_days' ) );
		// Unknown key returns the caller-supplied fallback (null by default).
		$this->assertNull( Settings_Service::get( 'no_such_key' ) );
		$this->assertEquals( 'fallback', Settings_Service::get( 'no_such_key', 'fallback' ) );
	}

	public function test_update_persists_partial_and_merges_with_defaults() {
		$updated = Settings_Service::update( array(
			'log_retention_days' => 30,
			'url_form'            => 'subdomain',
		) );

		$this->assertIsArray( $updated );
		$this->assertEquals( 30, $updated['log_retention_days'] );
		$this->assertEquals( 'subdomain', $updated['url_form'] );
		// Untouched keys still resolve to defaults.
		$this->assertEquals( 'copy', $updated['media_handling'] );
		$this->assertEquals( 'zh_CN', $updated['default_language'] );

		// Persisted: a fresh read from the option agrees.
		$stored = get_option( self::OPTION_KEY );
		$this->assertIsArray( $stored );
		$this->assertEquals( 30, $stored['log_retention_days'] );
		$this->assertEquals( 'subdomain', $stored['url_form'] );
	}

	public function test_update_ignores_unknown_keys() {
		Settings_Service::update( array(
			'evil_key'            => 'x',
			'another_unknown_key' => 'y',
			'log_retention_days'  => 3,
		) );

		$stored = get_option( self::OPTION_KEY );
		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( 'evil_key', $stored );
		$this->assertArrayNotHasKey( 'another_unknown_key', $stored );

		$all = Settings_Service::get_all();
		$this->assertArrayNotHasKey( 'evil_key', $all );
		$this->assertEquals( 3, $all['log_retention_days'] );
	}

	public function test_update_sanitizes_enum_values() {
		Settings_Service::update( array(
			'url_form'           => 'weird://form',
			'media_handling'     => 'teleport',
			'sync_mode'          => 'everything',
			'hreflang_emitter'   => 'some_random_theme',
		) );

		$all = Settings_Service::get_all();
		$this->assertEquals( 'subdir', $all['url_form'], 'invalid url_form falls back to subdir' );
		$this->assertEquals( 'copy', $all['media_handling'], 'invalid media_handling falls back to copy' );
		$this->assertEquals( 'new_only', $all['sync_mode'], 'invalid sync_mode falls back to new_only' );
		$this->assertEquals( 'wpmmcc-ats', $all['hreflang_emitter'], 'invalid hreflang_emitter falls back to wpmmcc-ats' );
	}

	public function test_get_all_normalizes_legacy_hreflang_emitter_slug() {
		update_option( self::OPTION_KEY, array( 'hreflang_emitter' => 'wptsall' ) );
		$this->assertEquals( 'wpmmcc-ats', Settings_Service::get_all()['hreflang_emitter'], 'legacy "wptsall" slug must normalize to "wpmmcc-ats"' );

		Settings_Service::update( array( 'hreflang_emitter' => 'wptsall' ) );
		$this->assertEquals( 'wpmmcc-ats', Settings_Service::get_all()['hreflang_emitter'] );
	}

	public function test_update_coerces_booleans_and_normalizes_reading() {
		Settings_Service::update( array(
			'debug_mode'         => 'yes',
			'client_api_enabled' => 0,
			'permalink_fallback' => '1',
		) );

		$all = Settings_Service::get_all();
		$this->assertTrue( $all['debug_mode'] );
		$this->assertFalse( $all['client_api_enabled'] );
		$this->assertTrue( $all['permalink_fallback'] );

		// Raw storage uses 1/0 integers.
		$stored = get_option( self::OPTION_KEY );
		$this->assertEquals( 1, $stored['debug_mode'] );
		$this->assertEquals( 0, $stored['client_api_enabled'] );
	}

	public function test_update_sanitizes_log_retention_days() {
		Settings_Service::update( array( 'log_retention_days' => -5 ) );
		$this->assertEquals( 0, Settings_Service::get( 'log_retention_days' ), 'negative retention clamps to 0' );

		Settings_Service::update( array( 'log_retention_days' => 'not-a-number' ) );
		$this->assertEquals( 0, Settings_Service::get( 'log_retention_days' ), 'non-numeric retention casts to 0' );

		Settings_Service::update( array( 'log_retention_days' => '42' ) );
		$this->assertEquals( 42, Settings_Service::get( 'log_retention_days' ) );
	}

	public function test_update_with_non_array_returns_current_settings() {
		Settings_Service::update( array( 'log_retention_days' => 14 ) );
		$result = Settings_Service::update( 'garbage' );
		$this->assertEquals( Settings_Service::get_all(), $result );
		$this->assertEquals( 14, $result['log_retention_days'], 'non-array update must not change stored settings' );
	}

	public function test_update_fires_settings_updated_action_with_partial() {
		$received = null;
		$partial  = null;
		$catch = function ( $next, $raw_partial ) use ( &$received, &$partial ) {
			$received = $next;
			$partial  = $raw_partial;
		};
		add_action( 'wptsall_settings_updated', $catch, 10, 2 );

		Settings_Service::update( array( 'sync_mode' => 'manual' ) );

		remove_action( 'wptsall_settings_updated', $catch, 10 );

		$this->assertIsArray( $received );
		$this->assertArrayHasKey( 'sync_mode', $received );
		$this->assertEquals( array( 'sync_mode' => 'manual' ), $partial, 'action receives the original partial as second arg' );
	}

	/**
	 * Uninstall data-retention policy (BUG-LC-02 remediation).
	 */
	public function test_delete_data_on_uninstall_defaults_to_false() {
		$this->assertArrayHasKey( 'delete_data_on_uninstall', Settings_Service::defaults() );
		$this->assertFalse( Settings_Service::defaults()['delete_data_on_uninstall'] );
		$this->assertFalse( Settings_Service::get( 'delete_data_on_uninstall' ), 'full data deletion on uninstall must be opt-in' );
	}

	public function test_delete_data_on_uninstall_roundtrips_as_boolean() {
		$updated = Settings_Service::update( array( 'delete_data_on_uninstall' => '1' ) );
		$this->assertTrue( $updated['delete_data_on_uninstall'] );

		$stored = get_option( self::OPTION_KEY );
		$this->assertEquals( 1, $stored['delete_data_on_uninstall'], 'raw storage uses 1/0 integers like the other booleans' );

		Settings_Service::update( array( 'delete_data_on_uninstall' => false ) );
		$this->assertFalse( Settings_Service::get( 'delete_data_on_uninstall' ) );
	}

	public function test_delete_data_on_uninstall_absent_stored_value_reads_false() {
		// Sites that saved settings before this key existed must read false,
		// never garbage or null.
		update_option( self::OPTION_KEY, array( 'url_form' => 'subdir' ) );
		$this->assertFalse( Settings_Service::get( 'delete_data_on_uninstall' ) );
	}
}
