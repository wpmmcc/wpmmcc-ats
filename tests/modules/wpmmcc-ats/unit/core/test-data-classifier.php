<?php
/**
 * Data Classifier Tests
 *
 * Tests for WPTSALL\Core\Data_Classifier class
 *
 * @package WPTSALL
 * @since 0.3.0
 */

use WPTSALL\Core\Data_Classifier;
use WPTSALL\Core\Data_Classification;
use WPTSALL\Core\Classification_Constants;

class Test_Data_Classifier extends WP_UnitTestCase {

	/**
	 * Classifier instance
	 *
	 * @var Data_Classifier
	 */
	private $classifier;

	/**
	 * Test post ID
	 *
	 * @var int
	 */
	private $test_post_id;

	/**
	 * Test user ID
	 *
	 * @var int
	 */
	private $test_user_id;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		$this->classifier = new Data_Classifier();

		// Create test post using wp_insert_post
		$this->test_post_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'Test Post for Classifier',
			'post_content' => 'Test content',
		) );

		// Create test user using wp_insert_user
		$existing_user = get_user_by( 'login', 'classifier_test_user' );
		if ( $existing_user ) {
			$this->test_user_id = $existing_user->ID;
		} else {
			$this->test_user_id = wp_insert_user( array(
				'user_login' => 'classifier_test_user',
				'user_email' => 'classifier@test.com',
				'user_pass'  => wp_generate_password(),
			) );
		}
	}

	/**
	 * Test classifier instantiation
	 */
	public function test_classifier_instantiation() {
		$this->assertInstanceOf( Data_Classifier::class, $this->classifier );
	}

	/**
	 * Test instantiation with custom config
	 */
	public function test_classifier_with_custom_config() {
		$custom_config = array(
			'meta_sync_mode' => 'blacklist',
			'data_types'     => array(
				'content' => false,
			),
		);

		$classifier = new Data_Classifier( $custom_config );
		$this->assertInstanceOf( Data_Classifier::class, $classifier );
	}

	// ==================== classify() Tests ====================

	/**
	 * Test classify returns Data_Classification object
	 */
	public function test_classify_returns_data_classification() {
		$post   = get_post( $this->test_post_id );
		$result = $this->classifier->classify( $post );

		$this->assertInstanceOf( Data_Classification::class, $result );
	}

	/**
	 * Test classify post with publish status
	 */
	public function test_classify_published_post() {
		$post   = get_post( $this->test_post_id );
		$result = $this->classifier->classify( $post );

		$this->assertTrue( $result->syncable );
		$this->assertEquals( 'post', $result->object_type );
		$this->assertEquals( Classification_Constants::DATA_TYPE_CONTENT, $result->data_type );
		$this->assertEquals( Classification_Constants::DATA_PRIVACY_PUBLIC, $result->privacy );
		$this->assertEquals( Classification_Constants::SYNC_ALWAYS, $result->sync_policy );
	}

	/**
	 * Test classify draft post is not syncable
	 */
	public function test_classify_draft_post_not_syncable() {
		$draft_post_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_status' => 'draft',
			'post_title'  => 'Draft Test',
		) );

		$post   = get_post( $draft_post_id );
		$result = $this->classifier->classify( $post );

		$this->assertFalse( $result->syncable );
		wp_delete_post( $draft_post_id, true );
	}

	/**
	 * Test classify page
	 */
	public function test_classify_page() {
		$page_id = wp_insert_post( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Test Page',
		) );

		$page   = get_post( $page_id );
		$result = $this->classifier->classify( $page );

		$this->assertTrue( $result->syncable );
		$this->assertEquals( 'page', $result->object_type );
		$this->assertEquals( Classification_Constants::DATA_TYPE_CONTENT, $result->data_type );
		wp_delete_post( $page_id, true );
	}

	/**
	 * Test classify user is never syncable
	 */
	public function test_classify_user_never_syncable() {
		$user   = get_user_by( 'id', $this->test_user_id );
		$result = $this->classifier->classify( $user );

		$this->assertFalse( $result->syncable );
		$this->assertEquals( 'user', $result->object_type );
		$this->assertEquals( Classification_Constants::DATA_PRIVACY_PII, $result->privacy );
		$this->assertEquals( Classification_Constants::SYNC_NEVER, $result->sync_policy );
	}

	/**
	 * Test classify comment
	 */
	public function test_classify_comment() {
		$comment_id = wp_insert_comment( array(
			'comment_post_ID' => $this->test_post_id,
			'comment_content' => 'Test comment',
			'comment_author'  => 'Test Author',
		) );

		$comment = get_comment( $comment_id );
		$result  = $this->classifier->classify( $comment );

		$this->assertEquals( 'comment', $result->object_type );
		$this->assertEquals( Classification_Constants::DATA_TYPE_COMMENT, $result->data_type );
		$this->assertEquals( Classification_Constants::SYNC_CONFIGURABLE, $result->sync_policy );
		wp_delete_comment( $comment_id, true );
	}

	/**
	 * Test classify attachment
	 */
	public function test_classify_attachment() {
		$attachment_id = wp_insert_post( array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'post_title'  => 'Test Attachment',
		) );

		$attachment = get_post( $attachment_id );
		$result     = $this->classifier->classify( $attachment );

		$this->assertEquals( 'attachment', $result->object_type );
		$this->assertEquals( Classification_Constants::DATA_TYPE_ATTACHMENT, $result->data_type );
		$this->assertEquals( Classification_Constants::SYNC_CONFIGURABLE, $result->sync_policy );
		wp_delete_post( $attachment_id, true );
	}

	/**
	 * Test classify with explicit object type
	 */
	public function test_classify_with_explicit_type() {
		$post   = get_post( $this->test_post_id );
		$result = $this->classifier->classify( $post, 'post' );

		$this->assertEquals( 'post', $result->object_type );
	}

	/**
	 * Test classify unknown type returns default values
	 */
	public function test_classify_unknown_type() {
		$result = $this->classifier->classify( new stdClass(), 'unknown_type' );

		$this->assertFalse( $result->syncable );
		$this->assertEquals( 'unknown_type', $result->object_type );
		$this->assertEquals( Classification_Constants::DATA_TYPE_CONTENT, $result->data_type );
		$this->assertEquals( Classification_Constants::DATA_PRIVACY_INTERNAL, $result->privacy );
		$this->assertEquals( Classification_Constants::SYNC_NEVER, $result->sync_policy );
	}

	// ==================== classify_meta_field() Tests ====================

	/**
	 * Test classify_meta_field returns correct structure
	 */
	public function test_classify_meta_field_structure() {
		$result = $this->classifier->classify_meta_field( 'test_field' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'meta_key', $result );
		$this->assertArrayHasKey( 'syncable', $result );
		$this->assertArrayHasKey( 'category', $result );
		$this->assertArrayHasKey( 'privacy', $result );
	}

	/**
	 * Test PII field is not syncable
	 */
	public function test_pii_field_not_syncable() {
		$pii_fields = array(
			'billing_email',
			'billing_address',
			'shipping_phone',
			'user_email',
			'user_phone',
			'payment_method',
			'ip_address',
			'customer_ip_address',
			'_customer_id',
			'_billing_city',
			'_shipping_country',
		);

		foreach ( $pii_fields as $field ) {
			$result = $this->classifier->classify_meta_field( $field );

			$this->assertFalse(
				$result['syncable'],
				"PII field '$field' should not be syncable"
			);
			$this->assertEquals( 'pii', $result['category'] );
			$this->assertEquals( Classification_Constants::DATA_PRIVACY_PII, $result['privacy'] );
		}
	}

	/**
	 * Test never sync field is not syncable
	 */
	public function test_never_sync_field_not_syncable() {
		$never_sync_fields = array(
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_old_date',
			'session_token',
			'_wp_session_data',
		);

		foreach ( $never_sync_fields as $field ) {
			$result = $this->classifier->classify_meta_field( $field );

			$this->assertFalse(
				$result['syncable'],
				"Never sync field '$field' should not be syncable"
			);
			$this->assertEquals( 'never_sync', $result['category'] );
		}
	}

	/**
	 * Test transient fields not syncable
	 */
	public function test_transient_fields_not_syncable() {
		$result = $this->classifier->classify_meta_field( '_transient_feed_123' );

		$this->assertFalse( $result['syncable'] );
		$this->assertEquals( 'never_sync', $result['category'] );
	}

	/**
	 * Test cache fields not syncable
	 */
	public function test_cache_fields_not_syncable() {
		$result = $this->classifier->classify_meta_field( 'object_cache' );

		$this->assertFalse( $result['syncable'] );
		$this->assertEquals( 'never_sync', $result['category'] );
	}

	/**
	 * Test oembed cache fields not syncable
	 */
	public function test_oembed_cache_not_syncable() {
		$result = $this->classifier->classify_meta_field( '_oembed_abc123' );

		$this->assertFalse( $result['syncable'] );
		$this->assertEquals( 'never_sync', $result['category'] );
	}

	/**
	 * Test configurable field in whitelist mode
	 */
	public function test_configurable_field_whitelist_mode() {
		$configurable_fields = array(
			'_thumbnail_id',
			'_yoast_wpseo_title',
			'rank_math_seo_score',
			'_wp_page_template',
		);

		foreach ( $configurable_fields as $field ) {
			$result = $this->classifier->classify_meta_field( $field );

			$this->assertEquals(
				'configurable',
				$result['category'],
				"Field '$field' should be configurable"
			);
		}
	}

	/**
	 * Test unknown field in whitelist mode is not syncable
	 */
	public function test_unknown_field_whitelist_mode() {
		$result = $this->classifier->classify_meta_field( 'completely_unknown_field' );

		$this->assertFalse( $result['syncable'] );
		$this->assertEquals( 'unknown', $result['category'] );
	}

	/**
	 * Test blacklist mode makes unknown fields syncable
	 */
	public function test_blacklist_mode_unknown_syncable() {
		$classifier = new Data_Classifier( array(
			'meta_sync_mode' => 'blacklist',
		) );

		$result = $classifier->classify_meta_field( 'completely_unknown_field' );

		$this->assertTrue( $result['syncable'] );
		$this->assertEquals( 'default', $result['category'] );
	}

	/**
	 * Test blacklist mode still blocks PII
	 */
	public function test_blacklist_mode_blocks_pii() {
		$classifier = new Data_Classifier( array(
			'meta_sync_mode' => 'blacklist',
		) );

		$result = $classifier->classify_meta_field( 'billing_email' );

		$this->assertFalse( $result['syncable'] );
		$this->assertEquals( 'pii', $result['category'] );
	}

	/**
	 * Test blacklist mode still blocks never_sync
	 */
	public function test_blacklist_mode_blocks_never_sync() {
		$classifier = new Data_Classifier( array(
			'meta_sync_mode' => 'blacklist',
		) );

		$result = $classifier->classify_meta_field( '_edit_lock' );

		$this->assertFalse( $result['syncable'] );
		$this->assertEquals( 'never_sync', $result['category'] );
	}

	// ==================== filter_meta_fields() Tests ====================

	/**
	 * Test filter_meta_fields returns filtered array
	 */
	public function test_filter_meta_fields_returns_array() {
		$meta_data = array(
			'_thumbnail_id' => 123,
			'_edit_lock'    => '1234567890:1',
			'custom_field'  => 'value',
		);

		$result = $this->classifier->filter_meta_fields( $meta_data );

		$this->assertIsArray( $result );
	}

	/**
	 * Test filter_meta_fields removes non-syncable fields
	 */
	public function test_filter_meta_fields_removes_blocked() {
		$meta_data = array(
			'_thumbnail_id'   => 123,
			'_edit_lock'      => '1234567890:1',
			'billing_email'   => 'test@example.com',
			'_transient_test' => 'cached',
		);

		$result = $this->classifier->filter_meta_fields( $meta_data );

		// Should NOT contain never_sync and PII fields
		$this->assertArrayNotHasKey( '_edit_lock', $result );
		$this->assertArrayNotHasKey( 'billing_email', $result );
		$this->assertArrayNotHasKey( '_transient_test', $result );
	}

	/**
	 * Test filter_meta_fields keeps configurable fields in whitelist mode
	 */
	public function test_filter_meta_fields_keeps_configurable() {
		$meta_data = array(
			'_thumbnail_id' => 123,
		);

		$result = $this->classifier->filter_meta_fields( $meta_data );

		$this->assertArrayHasKey( '_thumbnail_id', $result );
		$this->assertEquals( 123, $result['_thumbnail_id'] );
	}

	/**
	 * Test filter_meta_fields with empty array
	 */
	public function test_filter_meta_fields_empty_array() {
		$result = $this->classifier->filter_meta_fields( array() );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// ==================== get_statistics() Tests ====================

	/**
	 * Test get_statistics returns correct structure
	 */
	public function test_get_statistics_structure() {
		$posts = array(
			get_post( $this->test_post_id ),
		);

		$stats = $this->classifier->get_statistics( $posts );

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total', $stats );
		$this->assertArrayHasKey( 'syncable', $stats );
		$this->assertArrayHasKey( 'blocked', $stats );
		$this->assertArrayHasKey( 'by_type', $stats );
		$this->assertArrayHasKey( 'by_privacy', $stats );
		$this->assertArrayHasKey( 'by_policy', $stats );
	}

	/**
	 * Test get_statistics counts correctly
	 */
	public function test_get_statistics_counts() {
		// Create mix of objects
		$published_post_id = wp_insert_post( array(
			'post_status' => 'publish',
			'post_title'  => 'Stats Test Published',
		) );
		$draft_post_id = wp_insert_post( array(
			'post_status' => 'draft',
			'post_title'  => 'Stats Test Draft',
		) );

		$objects = array(
			get_post( $published_post_id ),
			get_post( $draft_post_id ),
			get_user_by( 'id', $this->test_user_id ),
		);

		$stats = $this->classifier->get_statistics( $objects );

		$this->assertEquals( 3, $stats['total'] );
		// Published post is syncable, draft and user are not
		$this->assertEquals( 1, $stats['syncable'] );
		$this->assertEquals( 2, $stats['blocked'] );

		wp_delete_post( $published_post_id, true );
		wp_delete_post( $draft_post_id, true );
	}

	/**
	 * Test get_statistics with empty array
	 */
	public function test_get_statistics_empty() {
		$stats = $this->classifier->get_statistics( array() );

		$this->assertEquals( 0, $stats['total'] );
		$this->assertEquals( 0, $stats['syncable'] );
		$this->assertEquals( 0, $stats['blocked'] );
	}

	/**
	 * Test get_statistics groups by type
	 */
	public function test_get_statistics_by_type() {
		$post1_id = wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'Stats Type 1' ) );
		$post2_id = wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'Stats Type 2' ) );

		$objects = array(
			get_post( $post1_id ),
			get_post( $post2_id ),
		);

		$stats = $this->classifier->get_statistics( $objects );

		$this->assertArrayHasKey( Classification_Constants::DATA_TYPE_CONTENT, $stats['by_type'] );
		$this->assertEquals( 2, $stats['by_type'][ Classification_Constants::DATA_TYPE_CONTENT ] );

		wp_delete_post( $post1_id, true );
		wp_delete_post( $post2_id, true );
	}

	// ==================== Data_Classification Object Tests ====================

	/**
	 * Test Data_Classification is_pii method
	 */
	public function test_classification_is_pii() {
		$user   = get_user_by( 'id', $this->test_user_id );
		$result = $this->classifier->classify( $user );

		$this->assertTrue( $result->is_pii() );
	}

	/**
	 * Test Data_Classification is_public method
	 */
	public function test_classification_is_public() {
		$post   = get_post( $this->test_post_id );
		$result = $this->classifier->classify( $post );

		$this->assertTrue( $result->is_public() );
	}

	/**
	 * Test Data_Classification is_always_sync method
	 */
	public function test_classification_is_always_sync() {
		$post   = get_post( $this->test_post_id );
		$result = $this->classifier->classify( $post );

		$this->assertTrue( $result->is_always_sync() );
	}

	/**
	 * Test Data_Classification is_never_sync method
	 */
	public function test_classification_is_never_sync() {
		$user   = get_user_by( 'id', $this->test_user_id );
		$result = $this->classifier->classify( $user );

		$this->assertTrue( $result->is_never_sync() );
	}

	/**
	 * Test Data_Classification to_array method
	 */
	public function test_classification_to_array() {
		$post   = get_post( $this->test_post_id );
		$result = $this->classifier->classify( $post );
		$array  = $result->to_array();

		$this->assertIsArray( $array );
		$this->assertArrayHasKey( 'object_type', $array );
		$this->assertArrayHasKey( 'data_type', $array );
		$this->assertArrayHasKey( 'privacy', $array );
		$this->assertArrayHasKey( 'sync_policy', $array );
		$this->assertArrayHasKey( 'syncable', $array );
	}

	/**
	 * Test Data_Classification to_json method
	 */
	public function test_classification_to_json() {
		$post   = get_post( $this->test_post_id );
		$result = $this->classifier->classify( $post );
		$json   = $result->to_json();

		$this->assertIsString( $json );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'object_type', $decoded );
	}

	// ==================== Edge Cases ====================

	/**
	 * Test wildcard pattern matching for meta fields
	 */
	public function test_wildcard_pattern_matching() {
		// Test pattern with wildcard at end
		$result1 = $this->classifier->classify_meta_field( '_yoast_wpseo_anything' );
		$this->assertEquals( 'configurable', $result1['category'] );

		// Test pattern with wildcard at start
		$result2 = $this->classifier->classify_meta_field( 'anything_cache' );
		$this->assertEquals( 'never_sync', $result2['category'] );
	}

	/**
	 * Test conditions_met is populated
	 */
	public function test_conditions_met_populated() {
		$post   = get_post( $this->test_post_id );
		$result = $this->classifier->classify( $post );

		$this->assertIsArray( $result->conditions_met );
		$this->assertArrayHasKey( 'status', $result->conditions_met );
		$this->assertTrue( $result->conditions_met['status'] );
	}

	/**
	 * Test term classification
	 */
	public function test_classify_term() {
		$term = wp_insert_term( 'Test Category', 'category' );
		if ( ! is_wp_error( $term ) ) {
			$term_obj = get_term( $term['term_id'], 'category' );
			$result   = $this->classifier->classify( $term_obj );

			$this->assertEquals( 'taxonomy', $result->object_type );
			$this->assertEquals( Classification_Constants::DATA_TYPE_TERM, $result->data_type );
			$this->assertTrue( $result->syncable );

			// Cleanup
			wp_delete_term( $term['term_id'], 'category' );
		}
	}

	/**
	 * Clean up test data
	 */
	public function tearDown(): void {
		if ( $this->test_post_id ) {
			wp_delete_post( $this->test_post_id, true );
		}

		if ( $this->test_user_id ) {
			wp_delete_user( $this->test_user_id );
		}

		parent::tearDown();
	}
}
