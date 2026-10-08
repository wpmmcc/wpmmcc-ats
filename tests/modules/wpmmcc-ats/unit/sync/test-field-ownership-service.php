<?php
/**
 * Field ownership — manual meta claims (W2-1).
 *
 * @package WPTSALL
 */

use WPTSALL\Sites\Services\Translation_Identity;
use WPTSALL\Sync\Services\Field_Ownership_Service;

class Test_Field_Ownership_Service extends SimpleTestCase {

	/** @var int[] */
	private $post_ids = array();

	public function tearDown(): void {
		foreach ( $this->post_ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		$this->post_ids = array();
		parent::tearDown();
	}

	private function create_shadow_post(): int {
		$source_id = wp_insert_post(
			array(
				'post_title'  => 'Owner Source ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$target_id = wp_insert_post(
			array(
				'post_title'  => 'Owner Target ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->post_ids[] = $source_id;
		$this->post_ids[] = $target_id;

		Translation_Identity::write_meta( $target_id, '_wptsall_virtual_site_id', 'v_owner_test' );
		Translation_Identity::write_meta( $target_id, '_wptsall_source_post_id', $source_id );
		Translation_Identity::write_meta( $target_id, '_wptsall_source_blog_id', get_current_blog_id() );
		Translation_Identity::write_meta( $target_id, '_wptsall_relation_id', 42 );
		return (int) $target_id;
	}

	public function test_claim_manual_meta_path_is_recorded() {
		$target_id = $this->create_shadow_post();
		Field_Ownership_Service::claim_manual( 'post', $target_id, 'meta:_custom_seo_title', 'Hello' );

		$owners = Field_Ownership_Service::get_owners( 'post', $target_id );
		$this->assertIsArray( $owners );
		$this->assertArrayHasKey( 'meta:_custom_seo_title', $owners );
		$this->assertEquals( 'wp_manual', $owners['meta:_custom_seo_title']['value_origin'] ?? '' );
	}

	public function test_updated_post_meta_hook_claims_manual_on_shadow() {
		Field_Ownership_Service::init();
		$target_id = $this->create_shadow_post();

		// Use WP API so the hooked updated_post_meta fires (product path).
		update_post_meta( $target_id, '_custom_seo_title', 'Manual Value' );

		$owners = Field_Ownership_Service::get_owners( 'post', $target_id );
		$this->assertArrayHasKey( 'meta:_custom_seo_title', $owners, 'Meta edit on shadow must claim meta:*' );
		$this->assertEquals( 'wp_manual', $owners['meta:_custom_seo_title']['value_origin'] ?? '' );
	}
}
