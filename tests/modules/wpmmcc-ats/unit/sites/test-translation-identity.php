<?php
/**
 * Unit tests for Translation_Identity helpers (pure model matching via Relation_Resolver already covered).
 *
 * @package WPTSALL
 * @group sites
 * @group translation-identity
 */

use WPTSALL\Sites\Services\Translation_Identity;

class Test_Translation_Identity extends WP_UnitTestCase {

	public function test_class_exists() {
		$this->assertTrue( class_exists( Translation_Identity::class ) );
	}

	public function test_get_translations_empty_for_unknown_post() {
		$out = Translation_Identity::get_translations( 999999991 );
		$this->assertIsArray( $out );
		$this->assertArrayHasKey( '_current_lang', $out );
	}

	public function test_find_target_rejects_invalid_ids() {
		$this->assertNull( Translation_Identity::find_target( 0, 1 ) );
		$this->assertNull( Translation_Identity::find_target( 1, 0 ) );
	}

	public function test_raw_meta_bypasses_get_post_meta_filters() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		global $wpdb;
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => Translation_Identity::META_VIRTUAL_SITE_ID,
				'meta_value' => 'v_fr',
			),
			array( '%d', '%s', '%s' )
		);
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => Translation_Identity::META_SOURCE_POST_ID,
				'meta_value' => '42',
			),
			array( '%d', '%s', '%s' )
		);

		add_filter(
			'get_post_metadata',
			static function ( $check, $object_id, $meta_key ) use ( $post_id ) {
				if ( (int) $object_id === (int) $post_id && 0 === strpos( (string) $meta_key, '_wptsall_' ) ) {
					return ''; // Hostile CPT filter: pretend identity meta is empty.
				}
				return $check;
			},
			10,
			3
		);

		$this->assertSame( '', (string) get_post_meta( $post_id, Translation_Identity::META_VIRTUAL_SITE_ID, true ) );
		$this->assertSame( 'v_fr', (string) Translation_Identity::raw_meta( $post_id, Translation_Identity::META_VIRTUAL_SITE_ID ) );
		$this->assertTrue( Translation_Identity::is_shadow_post( $post_id ) );
		$this->assertTrue( Translation_Identity::has_identity_markers( $post_id ) );
	}

	public function test_is_shadow_post_requires_both_markers() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertFalse( Translation_Identity::is_shadow_post( $post_id ) );

		global $wpdb;
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => Translation_Identity::META_VIRTUAL_SITE_ID,
				'meta_value' => 'v_fr',
			),
			array( '%d', '%s', '%s' )
		);
		$this->assertFalse( Translation_Identity::is_shadow_post( $post_id ) );
		$this->assertTrue( Translation_Identity::has_identity_markers( $post_id ) );
	}

	public function test_write_meta_persists_when_update_post_meta_filtered() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		add_filter(
			'update_post_metadata',
			static function ( $check, $object_id, $meta_key ) use ( $post_id ) {
				if ( (int) $object_id === (int) $post_id && Translation_Identity::is_identity_meta_key( (string) $meta_key ) ) {
					return false; // Block WP API write.
				}
				return $check;
			},
			10,
			3
		);
		$this->assertTrue( Translation_Identity::write_meta( $post_id, Translation_Identity::META_RELATION_ID, 77 ) );
		$this->assertSame( 77, (int) Translation_Identity::raw_meta( $post_id, Translation_Identity::META_RELATION_ID ) );
	}
}
