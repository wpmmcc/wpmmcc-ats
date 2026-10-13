<?php
/**
 * Sync_Access_Validator Unit Tests
 *
 * Tests for WPTSALL\Core\Sync_Access_Validator class.
 *
 * @package WPTSALL\Tests\Unit\Core
 * @since 0.5.0
 */

namespace WPTSALL\Tests\Unit\Core;

use WPTSALL\Core\Sync_Access_Validator;
use WPTSALL\Core\Classification_Constants;

/**
 * Test class for Sync_Access_Validator
 */
class Test_Sync_Access_Validator extends \SimpleTestCase {

	/**
	 * Test post IDs created during tests
	 *
	 * @var array
	 */
	private $test_post_ids = array();

	/**
	 * Tear down test environment
	 *
	 * @return void
	 */
	public function tearDown(): void {
		// Clean up test posts
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->test_post_ids = array();

		parent::tearDown();
	}

	/**
	 * Create a test post
	 *
	 * @param array $args Post arguments.
	 * @return int Post ID.
	 */
	private function create_test_post( $args = array() ) {
		$defaults = array(
			'post_title'   => 'Test Post ' . uniqid(),
			'post_content' => 'Test content',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		);

		$post_id               = wp_insert_post( array_merge( $defaults, $args ) );
		$this->test_post_ids[] = $post_id;

		return $post_id;
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	/**
	 * Test constructor with default config
	 */
	public function test_constructor_with_default_config() {
		$validator = new Sync_Access_Validator();

		$this->assertInstanceOf( Sync_Access_Validator::class, $validator );
	}

	/**
	 * Test constructor with custom config
	 */
	public function test_constructor_with_custom_config() {
		$config = array(
			'privacy_protection' => array( 'block_pii' => true ),
			'data_types'         => array( 'content' => true ),
		);

		$validator = new Sync_Access_Validator( $config );

		$this->assertInstanceOf( Sync_Access_Validator::class, $validator );
	}

	// =========================================================================
	// validate_url Tests
	// =========================================================================

	/**
	 * Test validate_url with public frontend URL
	 */
	public function test_validate_url_public_frontend() {
		$validator = new Sync_Access_Validator();

		$result = $validator->validate_url( '/sample-page/' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
		$this->assertArrayHasKey( 'classification', $result );
	}

	/**
	 * Test validate_url with admin URL
	 */
	public function test_validate_url_admin() {
		$validator = new Sync_Access_Validator();

		$result = $validator->validate_url( '/wp-admin/options.php' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
		// Admin URLs should typically be blocked
		$this->assertFalse( $result['allowed'] );
	}

	/**
	 * Test validate_url with REST API URL
	 */
	public function test_validate_url_rest_api() {
		$validator = new Sync_Access_Validator();

		$result = $validator->validate_url( '/wp-json/wp/v2/posts' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
	}

	/**
	 * Test validate_url with login URL
	 */
	public function test_validate_url_login() {
		$validator = new Sync_Access_Validator();

		$result = $validator->validate_url( '/wp-login.php' );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['allowed'] );
	}

	/**
	 * Test validate_url with full URL
	 */
	public function test_validate_url_full_url() {
		$validator = new Sync_Access_Validator();

		$result = $validator->validate_url( 'https://example.com/sample-page/' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
	}

	// =========================================================================
	// validate_data Tests
	// =========================================================================

	/**
	 * Test validate_data with WP_Post object
	 */
	public function test_validate_data_wp_post() {
		$validator = new Sync_Access_Validator();
		$post_id   = $this->create_test_post();
		$post      = get_post( $post_id );

		$result = $validator->validate_data( $post );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
		$this->assertArrayHasKey( 'classification', $result );
	}

	/**
	 * Test validate_data with draft post
	 */
	public function test_validate_data_draft_post() {
		$validator = new Sync_Access_Validator();
		$post_id   = $this->create_test_post( array( 'post_status' => 'draft' ) );
		$post      = get_post( $post_id );

		$result = $validator->validate_data( $post );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
	}

	/**
	 * Test validate_data with explicit object type
	 */
	public function test_validate_data_with_explicit_type() {
		$validator = new Sync_Access_Validator();
		$post_id   = $this->create_test_post();
		$post      = get_post( $post_id );

		$result = $validator->validate_data( $post, 'post' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
	}

	/**
	 * Test validate_data with page
	 */
	public function test_validate_data_page() {
		$validator = new Sync_Access_Validator();
		$post_id   = $this->create_test_post( array( 'post_type' => 'page' ) );
		$page      = get_post( $post_id );

		$result = $validator->validate_data( $page );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
	}

	// =========================================================================
	// validate_meta_field Tests
	// =========================================================================

	/**
	 * Test validate_meta_field with regular meta key
	 */
	public function test_validate_meta_field_regular() {
		$validator = new Sync_Access_Validator();

		$result = $validator->validate_meta_field( '_thumbnail_id' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
		$this->assertArrayHasKey( 'classification', $result );
	}

	/**
	 * Test validate_meta_field with PII field (email pattern)
	 */
	public function test_validate_meta_field_pii_email() {
		$validator = new Sync_Access_Validator();

		$result = $validator->validate_meta_field( 'user_email' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
		// PII fields should be blocked
		$this->assertFalse( $result['allowed'] );
	}

	/**
	 * Test validate_meta_field with edit_lock
	 */
	public function test_validate_meta_field_edit_lock() {
		$validator = new Sync_Access_Validator();

		$result = $validator->validate_meta_field( '_edit_lock' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
		// System fields should be blocked
		$this->assertFalse( $result['allowed'] );
	}

	/**
	 * Test validate_meta_field with custom meta
	 */
	public function test_validate_meta_field_custom() {
		$validator = new Sync_Access_Validator();

		$result = $validator->validate_meta_field( 'custom_field_value' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
	}

	// =========================================================================
	// filter_meta_data Tests
	// =========================================================================

	/**
	 * Test filter_meta_data removes blocked fields
	 */
	public function test_filter_meta_data_removes_blocked() {
		$validator = new Sync_Access_Validator();

		$meta_data = array(
			'_thumbnail_id' => 123,
			'_edit_lock'    => '1234567890:1',
			'custom_field'  => 'value',
		);

		$filtered = $validator->filter_meta_data( $meta_data );

		$this->assertIsArray( $filtered );
		// _edit_lock should be removed
		$this->assertArrayNotHasKey( '_edit_lock', $filtered );
	}

	/**
	 * Test filter_meta_data with object type
	 */
	public function test_filter_meta_data_with_object_type() {
		$validator = new Sync_Access_Validator();

		$meta_data = array(
			'_thumbnail_id' => 123,
			'custom_field'  => 'value',
		);

		$filtered = $validator->filter_meta_data( $meta_data, 'post' );

		$this->assertIsArray( $filtered );
	}

	/**
	 * Test filter_meta_data with empty array
	 */
	public function test_filter_meta_data_empty() {
		$validator = new Sync_Access_Validator();

		$filtered = $validator->filter_meta_data( array() );

		$this->assertIsArray( $filtered );
		$this->assertEmpty( $filtered );
	}

	// =========================================================================
	// validate (Comprehensive) Tests
	// =========================================================================

	/**
	 * Test validate with all parameters
	 */
	public function test_validate_comprehensive() {
		$validator = new Sync_Access_Validator();
		$post_id   = $this->create_test_post();
		$post      = get_post( $post_id );

		$result = $validator->validate(
			$post,
			'/sample-page/',
			array(
				'_thumbnail_id' => 123,
				'custom_field'  => 'value',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
		$this->assertArrayHasKey( 'url_check', $result );
		$this->assertArrayHasKey( 'data_check', $result );
		$this->assertArrayHasKey( 'meta_check', $result );
		$this->assertArrayHasKey( 'blocked_meta', $result );
	}

	/**
	 * Test validate without URL
	 */
	public function test_validate_without_url() {
		$validator = new Sync_Access_Validator();
		$post_id   = $this->create_test_post();
		$post      = get_post( $post_id );

		$result = $validator->validate( $post );

		$this->assertIsArray( $result );
		$this->assertNull( $result['url_check'] );
	}

	/**
	 * Test validate without meta data
	 */
	public function test_validate_without_meta() {
		$validator = new Sync_Access_Validator();
		$post_id   = $this->create_test_post();
		$post      = get_post( $post_id );

		$result = $validator->validate( $post, '/test/' );

		$this->assertIsArray( $result );
		$this->assertNull( $result['meta_check'] );
	}

	/**
	 * Test validate blocks admin URL
	 */
	public function test_validate_blocks_admin_url() {
		$validator = new Sync_Access_Validator();
		$post_id   = $this->create_test_post();
		$post      = get_post( $post_id );

		$result = $validator->validate( $post, '/wp-admin/post.php' );

		$this->assertFalse( $result['allowed'] );
		$this->assertNotNull( $result['reason'] );
	}

	/**
	 * Test validate with meta that contains blocked fields
	 */
	public function test_validate_meta_with_blocked_fields() {
		$validator = new Sync_Access_Validator();
		$post_id   = $this->create_test_post();
		$post      = get_post( $post_id );

		$result = $validator->validate(
			$post,
			null,
			array(
				'_edit_lock' => '12345:1',
				'_edit_last' => 1,
				'_custom'    => 'value',
			)
		);

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['blocked_meta'] );
	}

	// =========================================================================
	// validate_batch Tests
	// =========================================================================

	/**
	 * Test validate_batch with multiple objects
	 */
	public function test_validate_batch() {
		$validator = new Sync_Access_Validator();

		$post1 = get_post( $this->create_test_post() );
		$post2 = get_post( $this->create_test_post() );
		$post3 = get_post( $this->create_test_post( array( 'post_status' => 'draft' ) ) );

		$results = $validator->validate_batch( array( $post1, $post2, $post3 ) );

		$this->assertIsArray( $results );
		$this->assertArrayHasKey( 'total', $results );
		$this->assertArrayHasKey( 'allowed', $results );
		$this->assertArrayHasKey( 'blocked', $results );
		$this->assertEquals( 3, $results['total'] );
	}

	/**
	 * Test validate_batch with empty array
	 */
	public function test_validate_batch_empty() {
		$validator = new Sync_Access_Validator();

		$results = $validator->validate_batch( array() );

		$this->assertIsArray( $results );
		$this->assertEquals( 0, $results['total'] );
		$this->assertEmpty( $results['allowed'] );
		$this->assertEmpty( $results['blocked'] );
	}

	/**
	 * Test validate_batch preserves keys
	 */
	public function test_validate_batch_preserves_keys() {
		$validator = new Sync_Access_Validator();

		$post1 = get_post( $this->create_test_post() );
		$post2 = get_post( $this->create_test_post() );

		$results = $validator->validate_batch(
			array(
				'first'  => $post1,
				'second' => $post2,
			)
		);

		$this->assertIsArray( $results );
		// Keys should be preserved in allowed or blocked
		$all_keys = array_merge(
			array_keys( $results['allowed'] ),
			array_keys( $results['blocked'] )
		);
		$this->assertTrue( in_array( 'first', $all_keys, true ) || in_array( 'second', $all_keys, true ) );
	}

	// =========================================================================
	// get_validation_stats Tests
	// =========================================================================

	/**
	 * Test get_validation_stats
	 */
	public function test_get_validation_stats() {
		$validator = new Sync_Access_Validator();

		$post1 = get_post( $this->create_test_post() );
		$post2 = get_post( $this->create_test_post() );

		$stats = $validator->get_validation_stats( array( $post1, $post2 ) );

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total', $stats );
		$this->assertArrayHasKey( 'allowed', $stats );
		$this->assertArrayHasKey( 'blocked', $stats );
		$this->assertArrayHasKey( 'blocked_reasons', $stats );
		$this->assertEquals( 2, $stats['total'] );
	}

	/**
	 * Test get_validation_stats with empty array
	 */
	public function test_get_validation_stats_empty() {
		$validator = new Sync_Access_Validator();

		$stats = $validator->get_validation_stats( array() );

		$this->assertIsArray( $stats );
		$this->assertEquals( 0, $stats['total'] );
		$this->assertEquals( 0, $stats['allowed'] );
		$this->assertEquals( 0, $stats['blocked'] );
	}

	/**
	 * Test get_validation_stats groups blocked reasons
	 */
	public function test_get_validation_stats_groups_reasons() {
		$validator = new Sync_Access_Validator();

		// Create posts that will be blocked for the same reason
		$post1 = get_post( $this->create_test_post( array( 'post_status' => 'trash' ) ) );
		$post2 = get_post( $this->create_test_post( array( 'post_status' => 'trash' ) ) );

		$stats = $validator->get_validation_stats( array( $post1, $post2 ) );

		$this->assertIsArray( $stats );
		$this->assertIsArray( $stats['blocked_reasons'] );
	}

	// =========================================================================
	// is_data_type_allowed Tests
	// =========================================================================

	/**
	 * Test is_data_type_allowed blocks user type
	 */
	public function test_is_data_type_allowed_blocks_user() {
		$validator = new Sync_Access_Validator();

		$result = $validator->is_data_type_allowed( Classification_Constants::DATA_TYPE_USER );

		$this->assertFalse( $result );
	}

	/**
	 * Test is_data_type_allowed blocks order type
	 */
	public function test_is_data_type_allowed_blocks_order() {
		$validator = new Sync_Access_Validator();

		$result = $validator->is_data_type_allowed( Classification_Constants::DATA_TYPE_ORDER );

		$this->assertFalse( $result );
	}

	/**
	 * Test is_data_type_allowed with content type and config
	 */
	public function test_is_data_type_allowed_content_with_config() {
		$config = array(
			'data_types' => array(
				Classification_Constants::DATA_TYPE_CONTENT => true,
			),
		);

		$validator = new Sync_Access_Validator( $config );

		$result = $validator->is_data_type_allowed( Classification_Constants::DATA_TYPE_CONTENT );

		$this->assertTrue( $result );
	}

	/**
	 * Test is_data_type_allowed with disabled type
	 */
	public function test_is_data_type_allowed_disabled() {
		$config = array(
			'data_types' => array(
				Classification_Constants::DATA_TYPE_CONTENT => false,
			),
		);

		$validator = new Sync_Access_Validator( $config );

		$result = $validator->is_data_type_allowed( Classification_Constants::DATA_TYPE_CONTENT );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// is_url_access_allowed Tests
	// =========================================================================

	/**
	 * Test is_url_access_allowed with public access
	 */
	public function test_is_url_access_allowed_public() {
		$config = array(
			'url_access' => array(
				Classification_Constants::URL_ACCESS_PUBLIC => true,
			),
		);

		$validator = new Sync_Access_Validator( $config );

		$result = $validator->is_url_access_allowed( Classification_Constants::URL_ACCESS_PUBLIC );

		$this->assertTrue( $result );
	}

	/**
	 * Test is_url_access_allowed with admin access blocked
	 */
	public function test_is_url_access_allowed_admin_blocked() {
		$config = array(
			'url_access' => array(
				Classification_Constants::URL_ACCESS_ADMIN => false,
			),
		);

		$validator = new Sync_Access_Validator( $config );

		$result = $validator->is_url_access_allowed( Classification_Constants::URL_ACCESS_ADMIN );

		$this->assertFalse( $result );
	}

	/**
	 * Test is_url_access_allowed with unspecified access
	 */
	public function test_is_url_access_allowed_unspecified() {
		$validator = new Sync_Access_Validator( array() );

		$result = $validator->is_url_access_allowed( 'unknown_access' );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// generate_report Tests
	// =========================================================================

	/**
	 * Test generate_report with allowed result
	 */
	public function test_generate_report_allowed() {
		$validator = new Sync_Access_Validator();

		$validation_result = array(
			'allowed'      => true,
			'reason'       => null,
			'url_check'    => array( 'syncable' => true ),
			'data_check'   => array( 'syncable' => true ),
			'meta_check'   => null,
			'blocked_meta' => array(),
		);

		$report = $validator->generate_report( $validation_result );

		$this->assertIsString( $report );
		$this->assertStringContainsString( 'wptsall-validation-report', $report );
		$this->assertStringContainsString( 'success', $report );
	}

	/**
	 * Test generate_report with blocked result
	 */
	public function test_generate_report_blocked() {
		$validator = new Sync_Access_Validator();

		$validation_result = array(
			'allowed'      => false,
			'reason'       => 'URL 不可同步',
			'url_check'    => array( 'syncable' => false ),
			'data_check'   => null,
			'meta_check'   => null,
			'blocked_meta' => array(),
		);

		$report = $validator->generate_report( $validation_result );

		$this->assertIsString( $report );
		$this->assertStringContainsString( 'error', $report );
		$this->assertStringContainsString( 'URL 不可同步', $report );
	}

	/**
	 * Test generate_report with meta check
	 */
	public function test_generate_report_with_meta() {
		$validator = new Sync_Access_Validator();

		$validation_result = array(
			'allowed'      => true,
			'reason'       => null,
			'url_check'    => null,
			'data_check'   => array( 'syncable' => true ),
			'meta_check'   => array(
				'total'   => 5,
				'allowed' => 3,
				'blocked' => 2,
			),
			'blocked_meta' => array( '_edit_lock' => 'system field' ),
		);

		$report = $validator->generate_report( $validation_result );

		$this->assertIsString( $report );
		$this->assertStringContainsString( '5', $report );
		$this->assertStringContainsString( '3', $report );
		$this->assertStringContainsString( '2', $report );
	}

	// =========================================================================
	// Edge Cases
	// =========================================================================

	/**
	 * Test validate with null object
	 */
	public function test_validate_with_stdclass() {
		$validator = new Sync_Access_Validator();

		$object       = new \stdClass();
		$object->ID   = 999;
		$object->type = 'unknown';

		$result = $validator->validate( $object );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['allowed'] );
	}

	/**
	 * Test validate_url with empty string
	 */
	public function test_validate_url_empty_string() {
		$validator = new Sync_Access_Validator();

		$result = $validator->validate_url( '' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
	}

	/**
	 * Test filter_meta_data preserves allowed fields
	 */
	public function test_filter_meta_data_preserves_allowed() {
		$config = array(
			'meta_sync_mode' => 'blacklist',
		);

		$validator = new Sync_Access_Validator( $config );

		$meta_data = array(
			'allowed_field_1' => 'value1',
			'allowed_field_2' => 'value2',
		);

		$filtered = $validator->filter_meta_data( $meta_data );

		$this->assertIsArray( $filtered );
	}
}
