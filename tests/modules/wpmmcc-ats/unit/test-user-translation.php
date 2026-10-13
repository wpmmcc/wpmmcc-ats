<?php
/**
 * User Translation Service Tests
 *
 * Tests for WPTSALL\UserTranslation\Services\User_Translation_Service:
 * - user mapping CRUD on wp_wptsall_user_mappings
 * - user profile field string registration (on_save_user)
 * - frontend field swap via the pre_user_* filters (filter_user_field)
 *
 * catalog: WP-CLASS-User_Translation_Service
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 1.3.0
 */

use WPTSALL\UserTranslation\Services\User_Translation_Service;
use WPTSALL\Strings\Services\String_Translation_Service;
use WPTSALL\Core\Language_Context;

class Test_User_Translation_Service extends SimpleTestCase {

	/**
	 * Source user IDs whose mapping rows must be cleaned up.
	 *
	 * @var int[]
	 */
	private $source_user_ids = array();

	/**
	 * String rows created during the test (ids in wp_wptsall_strings).
	 *
	 * @var int[]
	 */
	private $string_ids = array();

	public function setUp(): void {
		parent::setUp();
		if ( function_exists( 'wptsall_create_user_mappings_table' ) ) {
			wptsall_create_user_mappings_table();
		}
		if ( function_exists( 'wptsall_create_strings_table' ) ) {
			wptsall_create_strings_table();
		}
	}

	public function tearDown(): void {
		global $wpdb;

		// Remove user mapping rows created by this test.
		if ( ! empty( $this->source_user_ids ) ) {
			$table = $wpdb->prefix . 'wptsall_user_mappings';
			$ids   = implode( ',', array_map( 'intval', array_unique( $this->source_user_ids ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$table} WHERE source_user_id IN ({$ids})" );
		}

		// Remove registered string rows.
		if ( ! empty( $this->string_ids ) ) {
			$table = wptsall_table( 'strings' );
			$ids   = implode( ',', array_map( 'intval', array_unique( $this->string_ids ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" );
		}

		// Neutralize request language context between tests.
		if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
			Language_Context::reset();
		}
		unset( $GLOBALS['wptsall_current_virtual_site'] );

		parent::tearDown();
	}

	/**
	 * Helper: record a mapping and register its source user for cleanup.
	 */
	private function record_mapping( $source_id, $target_id, $lang, $type = 'manual' ) {
		$this->source_user_ids[] = (int) $source_id;
		return User_Translation_Service::record( $source_id, $target_id, $lang, $type );
	}

	public function test_service_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\UserTranslation\Services\User_Translation_Service' ) );
		$this->assertTrue( method_exists( 'WPTSALL\UserTranslation\Services\User_Translation_Service', 'record' ) );
		$this->assertTrue( method_exists( 'WPTSALL\UserTranslation\Services\User_Translation_Service', 'get_target_user_id' ) );
		$this->assertTrue( method_exists( 'WPTSALL\UserTranslation\Services\User_Translation_Service', 'filter_user_field' ) );
	}

	public function test_record_and_get_target_user_id_roundtrip() {
		$source_id = self::factory()->user->create();
		$target_id = self::factory()->user->create();

		$ok = $this->record_mapping( $source_id, $target_id, 'en_US' );
		$this->assertTrue( $ok, 'record() should persist a new mapping' );

		$this->assertEquals( $target_id, User_Translation_Service::get_target_user_id( $source_id, 'en_US' ) );
		// Different language has no mapping.
		$this->assertEquals( 0, User_Translation_Service::get_target_user_id( $source_id, 'fr_FR' ) );
		// Invalid inputs fall back to 0.
		$this->assertEquals( 0, User_Translation_Service::get_target_user_id( 0, 'en_US' ) );
		$this->assertEquals( 0, User_Translation_Service::get_target_user_id( $source_id, '' ) );
		$this->assertEquals( 0, User_Translation_Service::get_target_user_id( -5, 'en_US' ) );
	}

	public function test_record_updates_existing_mapping_for_same_lang() {
		$source_id = self::factory()->user->create();
		$target_a  = self::factory()->user->create();
		$target_b  = self::factory()->user->create();

		$this->assertTrue( $this->record_mapping( $source_id, $target_a, 'de_DE' ) );
		$this->assertTrue( $this->record_mapping( $source_id, $target_b, 'de_DE' ), 'record() should update an existing mapping, not duplicate it' );

		$this->assertEquals( $target_b, User_Translation_Service::get_target_user_id( $source_id, 'de_DE' ) );

		// Still exactly one row for this source + lang.
		$rows = User_Translation_Service::list_mappings( array(
			'source_id'   => $source_id,
			'target_lang' => 'de_DE',
		) );
		$this->assertCount( 1, $rows );
	}

	public function test_record_rejects_invalid_input() {
		$this->assertFalse( User_Translation_Service::record( 0, 1, 'en_US' ) );
		$this->assertFalse( User_Translation_Service::record( 1, 0, 'en_US' ) );
		$this->assertFalse( User_Translation_Service::record( 1, 1, '' ) );
		$this->assertFalse( User_Translation_Service::record( -1, 1, 'en_US' ) );
	}

	public function test_list_mappings_filters_by_source_and_lang() {
		$src_a = self::factory()->user->create();
		$src_b = self::factory()->user->create();
		$tgt   = self::factory()->user->create();

		$this->record_mapping( $src_a, $tgt, 'en_US' );
		$this->record_mapping( $src_a, $tgt + 1, 'fr_FR' );
		$this->record_mapping( $src_b, $tgt + 2, 'en_US' );

		$rows = User_Translation_Service::list_mappings( array( 'source_id' => $src_a ) );
		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertEquals( $src_a, (int) $row['source_user_id'] );
		}

		$only_en = User_Translation_Service::list_mappings( array(
			'source_id'   => $src_a,
			'target_lang' => 'en_US',
		) );
		$this->assertCount( 1, $only_en );
		$this->assertEquals( 'en_US', $only_en[0]['target_site_id'] );
		$this->assertEquals( 'manual', $only_en[0]['mapping_type'] );

		// No filter matches everything (within the limit), including other rows.
		$all = User_Translation_Service::list_mappings();
		$this->assertGreaterThanOrEqual( 3, count( $all ) );
	}

	public function test_delete_removes_mapping() {
		$source_id = self::factory()->user->create();
		$target_id = self::factory()->user->create();
		$this->record_mapping( $source_id, $target_id, 'ja_JP' );

		$rows = User_Translation_Service::list_mappings( array(
			'source_id'   => $source_id,
			'target_lang' => 'ja_JP',
		) );
		$this->assertCount( 1, $rows );
		$row_id = (int) $rows[0]['id'];

		$this->assertTrue( User_Translation_Service::delete( $row_id ) );
		$this->assertEquals( 0, User_Translation_Service::get_target_user_id( $source_id, 'ja_JP' ) );
		// Deleting again finds no row: $wpdb->delete returns 0 → false.
		$this->assertFalse( User_Translation_Service::delete( $row_id ) );
	}

	public function test_counts_reports_totals_sources_and_langs() {
		$baseline = User_Translation_Service::counts();
		$this->assertIsArray( $baseline );
		$this->assertArrayHasKey( 'total', $baseline );
		$this->assertArrayHasKey( 'sources', $baseline );
		$this->assertArrayHasKey( 'langs', $baseline );

		$source_id = self::factory()->user->create();
		$this->record_mapping( $source_id, self::factory()->user->create(), 'xx_XX' );
		$this->record_mapping( $source_id, self::factory()->user->create(), 'yy_YY' );

		$after = User_Translation_Service::counts();
		$this->assertEquals( $baseline['total'] + 2, $after['total'], 'counts(total) should reflect the two new rows' );
		$this->assertEquals( $baseline['sources'] + 1, $after['sources'], 'both rows share one source user' );
		$this->assertEquals( $baseline['langs'] + 2, $after['langs'], 'the two rows use two distinct languages' );
	}

	public function test_on_save_user_registers_profile_fields_as_strings() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'first_name', 'John' );
		update_user_meta( $user_id, 'last_name', 'Doe' );
		update_user_meta( $user_id, 'description', 'Bio text' );

		User_Translation_Service::on_save_user( $user_id );

		$expected = array(
			'user_' . $user_id . '_first_name' => 'John',
			'user_' . $user_id . '_last_name'  => 'Doe',
			'user_' . $user_id . '_description' => 'Bio text',
		);
		$found = array();
		foreach ( String_Translation_Service::list( array( 'context' => 'user', 'limit' => 500 ) ) as $row ) {
			if ( isset( $expected[ $row['string_key'] ] ) ) {
				$found[ $row['string_key'] ] = $row['source_text'];
				$this->string_ids[] = (int) $row['id'];
			}
		}

		foreach ( $expected as $key => $text ) {
			$this->assertArrayHasKey( $key, $found, "on_save_user should register {$key}" );
			$this->assertEquals( $text, $found[ $key ] );
		}
	}

	public function test_filter_user_field_swaps_translated_value_via_pre_user_filter() {
		$user_id = self::factory()->user->create();

		$string_id = String_Translation_Service::register( 'user', 'user_' . $user_id . '_first_name', 'John' );
		$this->assertNotFalse( $string_id );
		$this->string_ids[] = (int) $string_id;
		$this->assertTrue( String_Translation_Service::set_translations( $string_id, array( 'en_US' => 'Johann' ) ) );

		// Wire the filter with 2 accepted args so the callback receives the
		// user id (the service callback's full public signature).
		add_filter( 'pre_user_first_name', array( 'WPTSALL\UserTranslation\Services\User_Translation_Service', 'filter_user_field' ), 10, 2 );
		Language_Context::set_language( 'en_US' );

		$this->assertEquals( 'Johann', apply_filters( 'pre_user_first_name', 'John', $user_id ) );

		// Source-language request keeps the original value.
		Language_Context::reset();
		$this->assertEquals( 'John', apply_filters( 'pre_user_first_name', 'John', $user_id ) );

		remove_filter( 'pre_user_first_name', array( 'WPTSALL\UserTranslation\Services\User_Translation_Service', 'filter_user_field' ), 10 );
	}

	/**
	 * Correct expectation: after the module's init(), pushing a value through
	 * the pre_user_first_name filter should swap in the translation that
	 * on_save_user registered for that user.
	 *
	 * FINDING: init() registers the pre_user_* filters with accepted_args=1,
	 * so filter_user_field always receives $user_id = 0 and builds the key
	 * "user_0_<field>", which never matches the "user_<id>_<field>" keys
	 * registered by on_save_user. The frontend swap is therefore dead for
	 * real users. Source code is read-only for tests, so this case is skipped
	 * with the finding recorded instead.
	 */
	public function test_init_wired_filter_swaps_registered_user_field() {
		$user_id = self::factory()->user->create();

		$string_id = String_Translation_Service::register( 'user', 'user_' . $user_id . '_first_name', 'John' );
		$this->assertNotFalse( $string_id );
		$this->string_ids[] = (int) $string_id;
		$this->assertTrue( String_Translation_Service::set_translations( $string_id, array( 'en_US' => 'Johann' ) ) );

		User_Translation_Service::init();
		Language_Context::set_language( 'en_US' );
		$swapped = apply_filters( 'pre_user_first_name', 'John', $user_id );
		$this->assertEquals( 'Johann', $swapped );
	}

	public function test_filter_user_field_returns_original_when_no_translation() {
		$user_id = self::factory()->user->create();
		User_Translation_Service::init();
		Language_Context::set_language( 'en_US' );

		// No registered string for this user: fallback passes through.
		$this->assertEquals( 'Untouched', apply_filters( 'pre_user_description', 'Untouched', $user_id ) );
	}

	public function test_filter_user_field_empty_value_is_not_translated() {
		$user_id = self::factory()->user->create();
		User_Translation_Service::init();
		Language_Context::set_language( 'en_US' );

		$this->assertSame( '', apply_filters( 'pre_user_last_name', '', $user_id ) );
	}

	public function test_init_registers_filters_for_all_supported_fields() {
		User_Translation_Service::init();
		foreach ( array( 'pre_user_display_name', 'pre_user_first_name', 'pre_user_last_name', 'pre_user_description' ) as $tag ) {
			$this->assertNotFalse(
				has_filter( $tag, array( 'WPTSALL\UserTranslation\Services\User_Translation_Service', 'filter_user_field' ) ),
				"init() should hook {$tag}"
			);
		}
	}
}
