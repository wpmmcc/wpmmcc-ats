<?php
/**
 * Virtual Site Service Tests
 *
 * Tests for WPTSALL\Sites\Services\Virtual_Site_Service class
 *
 * @package WPTSALL
 * @since 0.5.0
 */

use WPTSALL\Sites\Services\Virtual_Site_Service;

class Test_Virtual_Site_Service extends WP_UnitTestCase {

	/**
	 * Test site IDs for cleanup
	 *
	 * @var array
	 */
	protected $test_site_ids = array();

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure virtual_sites table exists
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_virtual_sites';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_name varchar(255) NOT NULL,
			site_tagline varchar(255) DEFAULT '',
			site_path varchar(100) NOT NULL,
			site_language varchar(20) NOT NULL DEFAULT 'en_US',
			site_logo varchar(500) DEFAULT '',
			enable_blog_sync tinyint(1) DEFAULT 0,
			source_blog_id bigint(20) unsigned DEFAULT 0,
			permalink_structure varchar(255) DEFAULT '',
			category_base varchar(100) DEFAULT '',
			tag_base varchar(100) DEFAULT '',
			status varchar(20) DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY site_path (site_path),
			KEY status (status),
			KEY site_language (site_language)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Clean up test data
	 */
	public function tearDown(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_virtual_sites';

		// Delete tracked test sites
		if ( ! empty( $this->test_site_ids ) ) {
			$ids_placeholder = implode( ',', array_map( 'intval', $this->test_site_ids ) );
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids_placeholder})" );
		}

		// Clean up test sites by path pattern
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE site_path LIKE %s', $table, 'test-%' ) );

		parent::tearDown();
	}

	/**
	 * Helper: Create a test virtual site
	 *
	 * @param array $overrides Override default values.
	 * @return array Result from create.
	 */
	private function create_test_site( $overrides = array() ) {
		$defaults = array(
			'name'        => 'Test Site ' . uniqid(),
			'path_prefix' => 'test-' . uniqid(),
			'lang'        => 'en_US',
			'subtitle'    => 'Test Subtitle',
		);

		$data = array_merge( $defaults, $overrides );
		$result = Virtual_Site_Service::create( $data );

		// Track created sites for cleanup
		if ( $result['success'] && ! empty( $result['site_id'] ) ) {
			$this->test_site_ids[] = $result['site_id'];
		}

		return $result;
	}

	/**
	 * Test Virtual_Site_Service class exists
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Sites\Services\Virtual_Site_Service' ) );
	}

	/**
	 * Test create virtual site
	 */
	public function test_create_site() {
		$result = $this->create_test_site( array(
			'name'        => 'Test Create Site',
			'path_prefix' => 'test-create',
			'lang'        => 'ja',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'site_id', $result );
		$this->assertGreaterThan( 0, $result['site_id'] );

		// Verify site was created
		$site = Virtual_Site_Service::get( $result['site_id'] );
		$this->assertNotNull( $site );
		$this->assertEquals( 'Test Create Site', $site['name'] );
		$this->assertEquals( 'test-create', $site['path_prefix'] );
		$this->assertEquals( 'ja', $site['lang'] );
	}

	/**
	 * Test create site validates required fields
	 */
	public function test_create_site_validates_required() {
		// Missing name
		$result = Virtual_Site_Service::create( array(
			'path_prefix' => 'test-missing-name',
			'lang'        => 'en_US',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );

		// Missing path_prefix
		$result = Virtual_Site_Service::create( array(
			'name' => 'Test Missing Path',
			'lang' => 'en_US',
		) );

		$this->assertFalse( $result['success'] );

		// Missing lang
		$result = Virtual_Site_Service::create( array(
			'name'        => 'Test Missing Lang',
			'path_prefix' => 'test-missing-lang',
		) );

		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test create site prevents duplicate path_prefix
	 */
	public function test_create_site_prevents_duplicate_path() {
		$result1 = $this->create_test_site( array(
			'path_prefix' => 'test-duplicate-path',
		) );
		$this->assertTrue( $result1['success'] );

		// Try to create another with same path
		$result2 = Virtual_Site_Service::create( array(
			'name'        => 'Another Site',
			'path_prefix' => 'test-duplicate-path',
			'lang'        => 'en_US',
		) );

		$this->assertFalse( $result2['success'] );
		$this->assertNotEmpty( $result2['errors'] );
	}

	/**
	 * Test get virtual site
	 */
	public function test_get_site() {
		$result = $this->create_test_site( array(
			'name'        => 'Test Get Site',
			'path_prefix' => 'test-get',
			'subtitle'    => 'My Subtitle',
		) );
		$this->assertTrue( $result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );

		$this->assertNotNull( $site );
		$this->assertIsArray( $site );
		$this->assertArrayHasKey( 'id', $site );
		$this->assertArrayHasKey( 'name', $site );
		$this->assertArrayHasKey( 'subtitle', $site );
		$this->assertArrayHasKey( 'path_prefix', $site );
		$this->assertArrayHasKey( 'lang', $site );
		$this->assertArrayHasKey( 'status', $site );
		$this->assertEquals( 'Test Get Site', $site['name'] );
		$this->assertEquals( 'My Subtitle', $site['subtitle'] );
	}

	/**
	 * Test get non-existent site returns null
	 */
	public function test_get_nonexistent_site() {
		$site = Virtual_Site_Service::get( 999999 );
		$this->assertNull( $site );
	}

	/**
	 * Test get_all returns array
	 */
	public function test_get_all_returns_array() {
		$sites = Virtual_Site_Service::get_all();
		$this->assertIsArray( $sites );
	}

	/**
	 * Test get_all with status filter
	 */
	public function test_get_all_filter_status() {
		$result1 = $this->create_test_site( array(
			'path_prefix' => 'test-active-1',
		) );
		$this->assertTrue( $result1['success'] );

		// Update to inactive
		Virtual_Site_Service::update( $result1['site_id'], array(
			'status' => 'inactive',
		) );

		$result2 = $this->create_test_site( array(
			'path_prefix' => 'test-active-2',
		) );
		$this->assertTrue( $result2['success'] );

		$active_sites = Virtual_Site_Service::get_all( array( 'status' => 'active' ) );

		foreach ( $active_sites as $site ) {
			$this->assertEquals( 'active', $site['status'] );
		}
	}

	/**
	 * Test get_all with language filter
	 */
	public function test_get_all_filter_lang() {
		$result = $this->create_test_site( array(
			'path_prefix' => 'test-lang-ja',
			'lang'        => 'ja',
		) );
		$this->assertTrue( $result['success'] );

		$ja_sites = Virtual_Site_Service::get_all( array( 'lang' => 'ja' ) );

		foreach ( $ja_sites as $site ) {
			$this->assertEquals( 'ja', $site['lang'] );
		}
	}

	/**
	 * Test get_all with search
	 */
	public function test_get_all_search() {
		$result = $this->create_test_site( array(
			'name'        => 'Unique Searchable Name XYZ',
			'path_prefix' => 'test-search-xyz',
		) );
		$this->assertTrue( $result['success'] );

		$found = Virtual_Site_Service::get_all( array( 'search' => 'Unique Searchable' ) );

		$this->assertNotEmpty( $found );
		$found_names = array_column( $found, 'name' );
		$this->assertContains( 'Unique Searchable Name XYZ', $found_names );
	}

	/**
	 * Test get_all with limit
	 */
	public function test_get_all_limit() {
		// Create multiple sites
		$this->create_test_site( array( 'path_prefix' => 'test-limit-1' ) );
		$this->create_test_site( array( 'path_prefix' => 'test-limit-2' ) );
		$this->create_test_site( array( 'path_prefix' => 'test-limit-3' ) );

		$limited = Virtual_Site_Service::get_all( array( 'limit' => 2 ) );

		$this->assertCount( 2, $limited );
	}

	/**
	 * Test update virtual site
	 */
	public function test_update_site() {
		$result = $this->create_test_site( array(
			'name'        => 'Original Name',
			'path_prefix' => 'test-update',
		) );
		$this->assertTrue( $result['success'] );

		$update_result = Virtual_Site_Service::update( $result['site_id'], array(
			'name'     => 'Updated Name',
			'subtitle' => 'New Subtitle',
		) );

		$this->assertTrue( $update_result['success'] );

		// Verify update
		$site = Virtual_Site_Service::get( $result['site_id'] );
		$this->assertEquals( 'Updated Name', $site['name'] );
		$this->assertEquals( 'New Subtitle', $site['subtitle'] );
	}

	/**
	 * Test update non-existent site fails
	 */
	public function test_update_nonexistent_site() {
		$result = Virtual_Site_Service::update( 999999, array(
			'name' => 'New Name',
		) );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test update site status
	 */
	public function test_update_site_status() {
		$result = $this->create_test_site( array(
			'path_prefix' => 'test-status',
		) );
		$this->assertTrue( $result['success'] );

		$update_result = Virtual_Site_Service::update( $result['site_id'], array(
			'status' => 'inactive',
		) );

		$this->assertTrue( $update_result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );
		$this->assertEquals( 'inactive', $site['status'] );
	}

	/**
	 * Test update site validates status values
	 */
	public function test_update_site_invalid_status_ignored() {
		$result = $this->create_test_site( array(
			'path_prefix' => 'test-invalid-status',
		) );
		$this->assertTrue( $result['success'] );

		$update_result = Virtual_Site_Service::update( $result['site_id'], array(
			'status' => 'invalid_status',
		) );

		$this->assertTrue( $update_result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );
		$this->assertEquals( 'active', $site['status'] ); // Should remain unchanged
	}

	/**
	 * Test delete virtual site
	 */
	public function test_delete_site() {
		$result = $this->create_test_site( array(
			'path_prefix' => 'test-delete',
		) );
		$this->assertTrue( $result['success'] );
		$site_id = $result['site_id'];

		$delete_result = Virtual_Site_Service::delete( $site_id );

		$this->assertTrue( $delete_result['success'] );

		// Verify deletion
		$site = Virtual_Site_Service::get( $site_id );
		$this->assertNull( $site );

		// Remove from tracking since deleted
		$this->test_site_ids = array_diff( $this->test_site_ids, array( $site_id ) );
	}

	/**
	 * Test delete non-existent site fails
	 */
	public function test_delete_nonexistent_site() {
		$result = Virtual_Site_Service::delete( 999999 );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test check_url_conflict
	 */
	public function test_check_url_conflict() {
		$result = $this->create_test_site( array(
			'path_prefix' => 'test-conflict',
		) );
		$this->assertTrue( $result['success'] );

		$conflict = Virtual_Site_Service::check_url_conflict( 'test-conflict' );

		$this->assertTrue( $conflict['has_conflict'] );
		$this->assertNotEmpty( $conflict['conflicts'] );
		$this->assertEquals( 'virtual_site', $conflict['conflicts'][0]['type'] );
	}

	/**
	 * Test check_url_conflict with exclude_id
	 */
	public function test_check_url_conflict_exclude_id() {
		$result = $this->create_test_site( array(
			'path_prefix' => 'test-exclude-conflict',
		) );
		$this->assertTrue( $result['success'] );

		// Check conflict excluding the created site
		$conflict = Virtual_Site_Service::check_url_conflict( 'test-exclude-conflict', $result['site_id'] );

		$this->assertFalse( $conflict['has_conflict'] );
	}

	/**
	 * Test check_url_conflict no conflict
	 */
	public function test_check_url_conflict_none() {
		$conflict = Virtual_Site_Service::check_url_conflict( 'unique-path-' . uniqid() );

		$this->assertFalse( $conflict['has_conflict'] );
		$this->assertEmpty( $conflict['conflicts'] );
	}

	/**
	 * Test get_available_languages
	 */
	public function test_get_available_languages() {
		$languages = Virtual_Site_Service::get_available_languages();

		$this->assertIsArray( $languages );
		$this->assertNotEmpty( $languages );
		$this->assertArrayHasKey( 'en_US', $languages );
		$this->assertArrayHasKey( 'zh_CN', $languages );
		$this->assertArrayHasKey( 'ja', $languages );
		$this->assertArrayHasKey( 'fr_FR', $languages );
	}

	/**
	 * Test site has timestamps
	 */
	public function test_site_has_timestamps() {
		$result = $this->create_test_site( array(
			'path_prefix' => 'test-timestamps',
		) );
		$this->assertTrue( $result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );

		$this->assertArrayHasKey( 'created_at', $site );
		$this->assertArrayHasKey( 'updated_at', $site );
		$this->assertNotEmpty( $site['created_at'] );
		$this->assertNotEmpty( $site['updated_at'] );
	}

	/**
	 * Test normalized site data structure
	 */
	public function test_normalized_data_structure() {
		$result = $this->create_test_site( array(
			'name'                => 'Structure Test',
			'path_prefix'         => 'test-structure',
			'subtitle'            => 'Test Subtitle',
			'lang'                => 'en_US',
			'logo_url'            => 'https://example.com/logo.png',
			'enable_blog_sync'    => 1,
			'blog_source_site'    => 1,
			'permalink_structure' => '/%postname%/',
			'category_base'       => 'topics',
			'tag_base'            => 'labels',
		) );
		$this->assertTrue( $result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );

		// Verify all normalized fields exist
		$expected_fields = array(
			'id',
			'name',
			'subtitle',
			'path_prefix',
			'lang',
			'logo_url',
			'enable_blog_sync',
			'blog_source_site',
			'permalink_structure',
			'category_base',
			'tag_base',
			'status',
			'created_at',
			'updated_at',
		);

		foreach ( $expected_fields as $field ) {
			$this->assertArrayHasKey( $field, $site, "Site should have '{$field}' field" );
		}
	}

	/**
	 * Test update path_prefix
	 */
	public function test_update_path_prefix() {
		$result = $this->create_test_site( array(
			'path_prefix' => 'test-original-path',
		) );
		$this->assertTrue( $result['success'] );

		$update_result = Virtual_Site_Service::update( $result['site_id'], array(
			'path_prefix' => 'test-new-path',
		) );

		$this->assertTrue( $update_result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );
		$this->assertEquals( 'test-new-path', $site['path_prefix'] );
	}

	/**
	 * Test update logo_url
	 */
	public function test_update_logo_url() {
		$result = $this->create_test_site( array(
			'path_prefix' => 'test-logo',
		) );
		$this->assertTrue( $result['success'] );

		$update_result = Virtual_Site_Service::update( $result['site_id'], array(
			'logo_url' => 'https://example.com/new-logo.png',
		) );

		$this->assertTrue( $update_result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );
		$this->assertEquals( 'https://example.com/new-logo.png', $site['logo_url'] );
	}

	/**
	 * Test update enable_blog_sync
	 */
	public function test_update_enable_blog_sync() {
		$result = $this->create_test_site( array(
			'path_prefix' => 'test-blog-sync',
		) );
		$this->assertTrue( $result['success'] );

		$update_result = Virtual_Site_Service::update( $result['site_id'], array(
			'enable_blog_sync' => 1,
		) );

		$this->assertTrue( $update_result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );
		$this->assertEquals( 1, $site['enable_blog_sync'] );
	}

	/**
	 * Test get_all with ordering
	 */
	public function test_get_all_ordering() {
		$sites = Virtual_Site_Service::get_all( array(
			'orderby' => 'created_at',
			'order'   => 'ASC',
		) );

		$this->assertIsArray( $sites );
	}

	/**
	 * Test create site sanitizes path_prefix
	 */
	public function test_create_sanitizes_path_prefix() {
		$result = $this->create_test_site( array(
			'name'        => 'Test Sanitize',
			'path_prefix' => 'Test Path With Spaces',
			'lang'        => 'en_US',
		) );

		$this->assertTrue( $result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );
		// sanitize_title converts to lowercase and replaces spaces with dashes
		$this->assertStringNotContainsString( ' ', $site['path_prefix'] );
	}

	/**
	 * Test default status is active
	 */
	public function test_default_status_is_active() {
		$result = $this->create_test_site( array(
			'path_prefix' => 'test-default-status',
		) );
		$this->assertTrue( $result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );
		$this->assertEquals( 'active', $site['status'] );
	}

	/**
	 * Test create site with permalink settings (v0.6.0)
	 */
	public function test_create_site_with_permalink_settings() {
		$result = $this->create_test_site( array(
			'name'                => 'Permalink Test Site',
			'path_prefix'         => 'test-permalink',
			'lang'                => 'en_US',
			'permalink_structure' => '/%year%/%monthnum%/%postname%/',
			'category_base'       => 'topics',
			'tag_base'            => 'labels',
		) );

		$this->assertTrue( $result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );
		$this->assertEquals( '/%year%/%monthnum%/%postname%/', $site['permalink_structure'] );
		$this->assertEquals( 'topics', $site['category_base'] );
		$this->assertEquals( 'labels', $site['tag_base'] );
	}

	/**
	 * Test update permalink_structure
	 */
	public function test_update_permalink_structure() {
		$result = $this->create_test_site( array(
			'path_prefix'         => 'test-update-permalink',
			'permalink_structure' => '/%postname%/',
		) );
		$this->assertTrue( $result['success'] );

		$update_result = Virtual_Site_Service::update( $result['site_id'], array(
			'permalink_structure' => '/%year%/%monthnum%/%day%/%postname%/',
		) );

		$this->assertTrue( $update_result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );
		$this->assertEquals( '/%year%/%monthnum%/%day%/%postname%/', $site['permalink_structure'] );
	}

	/**
	 * Test update category_base
	 */
	public function test_update_category_base() {
		$result = $this->create_test_site( array(
			'path_prefix'   => 'test-update-cat-base',
			'category_base' => 'category',
		) );
		$this->assertTrue( $result['success'] );

		$update_result = Virtual_Site_Service::update( $result['site_id'], array(
			'category_base' => 'topics',
		) );

		$this->assertTrue( $update_result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );
		$this->assertEquals( 'topics', $site['category_base'] );
	}

	/**
	 * Test update tag_base
	 */
	public function test_update_tag_base() {
		$result = $this->create_test_site( array(
			'path_prefix' => 'test-update-tag-base',
			'tag_base'    => 'tag',
		) );
		$this->assertTrue( $result['success'] );

		$update_result = Virtual_Site_Service::update( $result['site_id'], array(
			'tag_base' => 'labels',
		) );

		$this->assertTrue( $update_result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );
		$this->assertEquals( 'labels', $site['tag_base'] );
	}

	/**
	 * Test default permalink settings are empty (inherit from source)
	 */
	public function test_default_permalink_settings_empty() {
		$result = $this->create_test_site( array(
			'path_prefix' => 'test-default-permalink',
		) );
		$this->assertTrue( $result['success'] );

		$site = Virtual_Site_Service::get( $result['site_id'] );

		// Default values should be empty (inherit from source site)
		$this->assertEquals( '', $site['permalink_structure'] );
		$this->assertEquals( '', $site['category_base'] );
		$this->assertEquals( '', $site['tag_base'] );
	}

	// ========================================
	// Bulk Operations Tests (v0.9.0)
	// ========================================

	/**
	 * Test bulk_create with valid data
	 */
	public function test_bulk_create_success() {
		$sites_data = array(
			array(
				'name'        => 'Bulk Site 1',
				'path_prefix' => 'test-bulk-1-' . uniqid(),
				'lang'        => 'en_US',
			),
			array(
				'name'        => 'Bulk Site 2',
				'path_prefix' => 'test-bulk-2-' . uniqid(),
				'lang'        => 'zh_CN',
			),
			array(
				'name'        => 'Bulk Site 3',
				'path_prefix' => 'test-bulk-3-' . uniqid(),
				'lang'        => 'ja',
			),
		);

		$result = Virtual_Site_Service::bulk_create( $sites_data );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 3, $result['created'] );
		$this->assertEmpty( $result['errors'] );

		// Track for cleanup
		foreach ( $result['created'] as $created ) {
			$this->test_site_ids[] = $created['site_id'];
		}

		// Verify sites were actually created
		foreach ( $result['created'] as $created ) {
			$site = Virtual_Site_Service::get( $created['site_id'] );
			$this->assertNotNull( $site );
		}
	}

	/**
	 * Test bulk_create with empty array
	 */
	public function test_bulk_create_empty_array() {
		$result = Virtual_Site_Service::bulk_create( array() );

		$this->assertFalse( $result['success'] );
		$this->assertEmpty( $result['created'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test bulk_create with partial failures
	 */
	public function test_bulk_create_partial_failure() {
		$path_prefix = 'test-bulk-dup-' . uniqid();

		// Create first site
		$this->create_test_site( array( 'path_prefix' => $path_prefix ) );

		$sites_data = array(
			array(
				'name'        => 'Valid Site',
				'path_prefix' => 'test-bulk-valid-' . uniqid(),
				'lang'        => 'en_US',
			),
			array(
				'name'        => 'Duplicate Path Site',
				'path_prefix' => $path_prefix, // This will fail (duplicate)
				'lang'        => 'zh_CN',
			),
		);

		$result = Virtual_Site_Service::bulk_create( $sites_data );

		$this->assertFalse( $result['success'] );
		$this->assertCount( 1, $result['created'] );
		$this->assertCount( 1, $result['errors'] );

		// Track for cleanup
		foreach ( $result['created'] as $created ) {
			$this->test_site_ids[] = $created['site_id'];
		}
	}

	/**
	 * Test bulk_create with missing required fields
	 */
	public function test_bulk_create_missing_required_fields() {
		$sites_data = array(
			array(
				'name' => 'Missing Path Site',
				// missing path_prefix and lang
			),
		);

		$result = Virtual_Site_Service::bulk_create( $sites_data );

		$this->assertFalse( $result['success'] );
		$this->assertEmpty( $result['created'] );
		$this->assertCount( 1, $result['errors'] );
	}

	/**
	 * Test bulk_delete with valid IDs
	 */
	public function test_bulk_delete_success() {
		// Create test sites
		$site1 = $this->create_test_site( array( 'path_prefix' => 'test-bulk-del-1-' . uniqid() ) );
		$site2 = $this->create_test_site( array( 'path_prefix' => 'test-bulk-del-2-' . uniqid() ) );
		$site3 = $this->create_test_site( array( 'path_prefix' => 'test-bulk-del-3-' . uniqid() ) );

		$site_ids = array(
			$site1['site_id'],
			$site2['site_id'],
			$site3['site_id'],
		);

		$result = Virtual_Site_Service::bulk_delete( $site_ids );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 3, $result['deleted'] );
		$this->assertEmpty( $result['errors'] );

		// Verify sites were actually deleted
		foreach ( $site_ids as $site_id ) {
			$site = Virtual_Site_Service::get( $site_id );
			$this->assertNull( $site );
		}

		// Remove from cleanup since already deleted
		$this->test_site_ids = array_diff( $this->test_site_ids, $site_ids );
	}

	/**
	 * Test bulk_delete with empty array
	 */
	public function test_bulk_delete_empty_array() {
		$result = Virtual_Site_Service::bulk_delete( array() );

		$this->assertFalse( $result['success'] );
		$this->assertEmpty( $result['deleted'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test bulk_delete with non-existent IDs
	 */
	public function test_bulk_delete_non_existent_ids() {
		$result = Virtual_Site_Service::bulk_delete( array( 99999, 99998, 99997 ) );

		$this->assertFalse( $result['success'] );
		$this->assertEmpty( $result['deleted'] );
		$this->assertCount( 3, $result['errors'] );
	}

	/**
	 * Test bulk_delete with mixed valid and invalid IDs
	 */
	public function test_bulk_delete_partial_failure() {
		$site = $this->create_test_site( array( 'path_prefix' => 'test-bulk-del-mix-' . uniqid() ) );

		$result = Virtual_Site_Service::bulk_delete( array( $site['site_id'], 99999 ) );

		$this->assertFalse( $result['success'] );
		$this->assertCount( 1, $result['deleted'] );
		$this->assertCount( 1, $result['errors'] );

		// Remove from cleanup since already deleted
		$this->test_site_ids = array_diff( $this->test_site_ids, array( $site['site_id'] ) );
	}

	/**
	 * Test bulk_update with valid data
	 */
	public function test_bulk_update_success() {
		$site1 = $this->create_test_site( array( 'path_prefix' => 'test-bulk-upd-1-' . uniqid() ) );
		$site2 = $this->create_test_site( array( 'path_prefix' => 'test-bulk-upd-2-' . uniqid() ) );

		$updates = array(
			array(
				'site_id'  => $site1['site_id'],
				'name'     => 'Updated Name 1',
				'subtitle' => 'Updated Subtitle 1',
			),
			array(
				'site_id' => $site2['site_id'],
				'name'    => 'Updated Name 2',
				'lang'    => 'zh_CN',
			),
		);

		$result = Virtual_Site_Service::bulk_update( $updates );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['updated'] );
		$this->assertEmpty( $result['errors'] );

		// Verify updates were applied
		$updated_site1 = Virtual_Site_Service::get( $site1['site_id'] );
		$this->assertEquals( 'Updated Name 1', $updated_site1['name'] );
		$this->assertEquals( 'Updated Subtitle 1', $updated_site1['subtitle'] );

		$updated_site2 = Virtual_Site_Service::get( $site2['site_id'] );
		$this->assertEquals( 'Updated Name 2', $updated_site2['name'] );
		$this->assertEquals( 'zh_CN', $updated_site2['lang'] );
	}

	/**
	 * Test bulk_update with empty array
	 */
	public function test_bulk_update_empty_array() {
		$result = Virtual_Site_Service::bulk_update( array() );

		$this->assertFalse( $result['success'] );
		$this->assertEmpty( $result['updated'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test bulk_update with missing site_id
	 */
	public function test_bulk_update_missing_site_id() {
		$updates = array(
			array(
				'name' => 'Test Name',
				// missing site_id
			),
		);

		$result = Virtual_Site_Service::bulk_update( $updates );

		$this->assertFalse( $result['success'] );
		$this->assertEmpty( $result['updated'] );
		$this->assertCount( 1, $result['errors'] );
	}

	/**
	 * Test bulk_update with non-existent site_id
	 */
	public function test_bulk_update_non_existent_site() {
		$updates = array(
			array(
				'site_id' => 99999,
				'name'    => 'Test Name',
			),
		);

		$result = Virtual_Site_Service::bulk_update( $updates );

		$this->assertFalse( $result['success'] );
		$this->assertEmpty( $result['updated'] );
		$this->assertCount( 1, $result['errors'] );
	}

	/**
	 * Test bulk_update with no update fields
	 */
	public function test_bulk_update_no_fields() {
		$site = $this->create_test_site( array( 'path_prefix' => 'test-bulk-upd-empty-' . uniqid() ) );

		$updates = array(
			array(
				'site_id' => $site['site_id'],
				// no fields to update
			),
		);

		$result = Virtual_Site_Service::bulk_update( $updates );

		$this->assertFalse( $result['success'] );
		$this->assertEmpty( $result['updated'] );
		$this->assertCount( 1, $result['errors'] );
	}

	/**
	 * Test bulk_update with partial failures
	 */
	public function test_bulk_update_partial_failure() {
		$site = $this->create_test_site( array( 'path_prefix' => 'test-bulk-upd-mix-' . uniqid() ) );

		$updates = array(
			array(
				'site_id' => $site['site_id'],
				'name'    => 'Valid Update',
			),
			array(
				'site_id' => 99999,
				'name'    => 'Invalid Site',
			),
		);

		$result = Virtual_Site_Service::bulk_update( $updates );

		$this->assertFalse( $result['success'] );
		$this->assertCount( 1, $result['updated'] );
		$this->assertCount( 1, $result['errors'] );

		// Verify valid update was applied
		$updated_site = Virtual_Site_Service::get( $site['site_id'] );
		$this->assertEquals( 'Valid Update', $updated_site['name'] );
	}

	/**
	 * Test bulk_update status change
	 */
	public function test_bulk_update_status_change() {
		$site1 = $this->create_test_site( array( 'path_prefix' => 'test-bulk-status-1-' . uniqid() ) );
		$site2 = $this->create_test_site( array( 'path_prefix' => 'test-bulk-status-2-' . uniqid() ) );

		$updates = array(
			array(
				'site_id' => $site1['site_id'],
				'status'  => 'inactive',
			),
			array(
				'site_id' => $site2['site_id'],
				'status'  => 'inactive',
			),
		);

		$result = Virtual_Site_Service::bulk_update( $updates );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['updated'] );

		// Verify status changes
		$updated1 = Virtual_Site_Service::get( $site1['site_id'] );
		$updated2 = Virtual_Site_Service::get( $site2['site_id'] );

		$this->assertEquals( 'inactive', $updated1['status'] );
		$this->assertEquals( 'inactive', $updated2['status'] );
	}
}
