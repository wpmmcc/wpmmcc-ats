<?php
/**
 * Unit tests for the manual multilingual language catalog.
 *
 * @package WPTSALL
 */

class Test_Language_Service_Schema extends SimpleTestCase {

	public function test_language_context_normalizes_wp_locale_case() {
		$this->assertTrue( class_exists( '\\WPTSALL\\Core\\Language_Context' ) );
		$this->assertEquals( 'fr_FR', \WPTSALL\Core\Language_Context::normalize_lang( 'fr_FR' ) );
		$this->assertEquals( 'fr_FR', \WPTSALL\Core\Language_Context::normalize_lang( 'fr-fr' ) );
		$this->assertEquals( 'zh_CN', \WPTSALL\Core\Language_Context::normalize_lang( 'ZH_cn' ) );
		$this->assertEquals( 'en', \WPTSALL\Core\Language_Context::normalize_lang( 'en' ) );
	}

	public function test_languages_table_exists_and_default_language_is_seeded() {
		global $wpdb;

		$schema = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wpmmcc-ats/' ) . 'includes/languages/database/schema-languages.php';
		$this->assertTrue( file_exists( $schema ), 'languages schema must be present' );
		require_once $schema;
		$this->assertTrue( function_exists( 'wptsall_create_languages_table' ), 'language table creator must be loaded' );
		wptsall_create_languages_table();

		$table = wptsall_table( 'languages' );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertEquals( $table, $exists, 'wp_wptsall_languages table must exist after migration/bootstrap' );

		foreach ( array( 'code', 'slug', 'name', 'locale', 'is_default', 'status', 'created_at', 'updated_at' ) as $column ) {
			$col = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ), ARRAY_A );
			$this->assertNotEmpty( $col, "languages table must contain {$column}" );
		}

		$this->assertTrue( class_exists( '\\WPTSALL\\Languages\\Services\\Language_Service' ) );
		$default = \WPTSALL\Languages\Services\Language_Service::get_default();
		$this->assertIsArray( $default, 'a default language row must be seeded for manual multilingual setup' );
		$this->assertNotEmpty( $default['code'] ?? '', 'default language code must be non-empty' );
		$this->assertNotEmpty( $default['name'] ?? '', 'default language name must be non-empty' );
	}

	public function test_language_service_upsert_updates_existing_code_and_sets_default_atomically() {
		global $wpdb;
		$table = wptsall_table( 'languages' );
		$svc   = '\\WPTSALL\\Languages\\Services\\Language_Service';
		$this->assertTrue( class_exists( $svc ) );

		$old_default = call_user_func( array( $svc, 'get_default' ) );
		$old_default_id = (int) ( $old_default['id'] ?? 0 );
		$code = 'zz_ZZ';
		$slug = 'zz-zz-manual-gate';

		$wpdb->delete( $table, array( 'code' => $code ), array( '%s' ) );

		$id = call_user_func( array( $svc, 'upsert' ), array(
			'code'        => $code,
			'slug'        => $slug,
			'name'        => 'Manual Gate Language',
			'native_name' => 'Manual Gate Language',
			'locale'      => $code,
			'sort_order'  => 999,
			'status'      => 'active',
		) );

		try {
			$this->assertGreaterThan( 0, (int) $id, 'upsert must insert a valid row with NOT NULL timestamps' );
			$row = call_user_func( array( $svc, 'get_by_code' ), $code );
			$this->assertEquals( (int) $id, (int) ( $row['id'] ?? 0 ) );
			$this->assertNotEmpty( $row['created_at'] ?? '', 'created_at must be populated' );
			$this->assertNotEmpty( $row['updated_at'] ?? '', 'updated_at must be populated' );

			$again = call_user_func( array( $svc, 'upsert' ), array(
				'code'        => $code,
				'slug'        => $slug,
				'name'        => 'Manual Gate Language Updated',
				'native_name' => 'Manual Gate Language Updated',
				'locale'      => $code,
				'sort_order'  => 998,
				'status'      => 'active',
			) );
			$this->assertEquals( (int) $id, (int) $again, 'upsert must update an existing language by code instead of failing on duplicate keys' );
			$row = call_user_func( array( $svc, 'get_by_code' ), $code );
			$this->assertEquals( 'Manual Gate Language Updated', $row['name'] ?? '' );

			$this->assertTrue( call_user_func( array( $svc, 'set_default' ), (int) $id ), 'set_default must succeed for an existing row' );
			$default = call_user_func( array( $svc, 'get_default' ) );
			$this->assertEquals( (int) $id, (int) ( $default['id'] ?? 0 ), 'new default must be visible through get_default' );

			$defaults_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_default = 1', $table ) );
			$this->assertEquals( 1, $defaults_count, 'only one language row may be marked default' );
		} finally {
			if ( $old_default_id > 0 ) {
				call_user_func( array( $svc, 'set_default' ), $old_default_id );
			}
			$wpdb->delete( $table, array( 'code' => $code ), array( '%s' ) );
			if ( $old_default_id <= 0 && function_exists( 'wptsall_seed_default_languages' ) ) {
				wptsall_seed_default_languages();
			}
		}
	}
}
