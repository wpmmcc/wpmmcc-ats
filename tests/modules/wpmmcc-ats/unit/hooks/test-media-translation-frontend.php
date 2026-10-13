<?php
/**
 * Media Translation Frontend tests (M-02, opus5)
 *
 * Renders a mapped attachment through wp_get_attachment_image() inside a
 * simulated zh_CN virtual-site request and asserts the REAL HTML:
 *  - the alt attribute is translated via the media_alt string row for the
 *    request language (the pre-fix code passed an undefined $lang and fell
 *    back to the default language);
 *  - the src is swapped to the target attachment URL.
 *
 * @package WPTSALL
 * @since 2.1.4
 */

use WPTSALL\Core\Language_Context;
use WPTSALL\Models\Services\Media_Mapping_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Strings\Services\String_Translation_Service;

class Test_Media_Translation_Frontend extends SimpleTestCase {

	/**
	 * Test relation IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_relation_ids = array();

	/**
	 * Test attachment IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_attachment_ids = array();

	/**
	 * media_mappings row IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_mapping_ids = array();

	/**
	 * wptsall_strings row IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_string_ids = array();

	public function setUp(): void {
		parent::setUp();
		wp_cache_flush();
	}

	public function tearDown(): void {
		Language_Context::reset();

		foreach ( $this->test_string_ids as $sid ) {
			String_Translation_Service::delete( (int) $sid );
		}
		$this->test_string_ids = array();

		foreach ( $this->test_mapping_ids as $mid ) {
			Media_Mapping_Service::delete_mapping( (int) $mid );
		}
		$this->test_mapping_ids = array();

		foreach ( $this->test_attachment_ids as $att_id ) {
			wp_delete_attachment( $att_id, true );
		}
		$this->test_attachment_ids = array();

		foreach ( $this->test_relation_ids as $rid ) {
			Site_Relation_Service::delete_relation( (int) $rid );
		}
		$this->test_relation_ids = array();

		parent::tearDown();
	}

	/**
	 * Helper: stage a real 1x1 PNG attachment with generated metadata.
	 *
	 * @param string $alt ALT text to store (source language).
	 * @return int Attachment ID.
	 */
	private function create_attachment( $alt ) {
		$png = base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg=='
		);
		$upload = wp_upload_bits( 'm02-' . uniqid() . '.png', null, $png );
		$this->assertTrue( empty( $upload['error'] ), 'staging PNG must succeed: ' . ( $upload['error'] ?? '' ) );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'M02 attachment ' . uniqid(),
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		$this->assertGreaterThan( 0, (int) $attachment_id, 'attachment must be created' );
		$attachment_id = (int) $attachment_id;
		$this->test_attachment_ids[] = $attachment_id;

		if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			if ( ! empty( $metadata ) && ! is_wp_error( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}
		}

		if ( '' !== (string) $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		return $attachment_id;
	}

	/**
	 * M-02: real wp_get_attachment_image() HTML must carry the request-language
	 * alt translation and the target attachment URL.
	 */
	public function test_attachment_image_html_has_translated_alt_for_request_language() {
		$source_alt = 'Source alt text ' . uniqid();
		$zh_alt    = '目标站点ALT译文' . uniqid();

		// Fixture: relation (virtual zh_CN target) + source/target attachments.
		$relation_result = Site_Relation_Service::create_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => 'en',
			'target_sites'   => array(
				array(
					'id'   => 'v_test_m02_' . uniqid(),
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );
		if ( empty( $relation_result['success'] ) || empty( $relation_result['relation_ids'] ) ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}
		$relation_id = (int) $relation_result['relation_ids'][0];
		$this->test_relation_ids[] = $relation_id;
		$relation = Site_Relation_Service::get_relation( $relation_id );
		$this->assertIsArray( $relation, 'relation row must load' );

		$source_id = $this->create_attachment( $source_alt );
		$target_id = $this->create_attachment( '' );

		// Fixture: media_mappings row through the product service.
		$mapping_id = Media_Mapping_Service::create_mapping( array(
			'source_media_id'  => $source_id,
			'relation_id'      => $relation_id,
			'source_site_id'   => get_current_blog_id(),
			'source_file_path' => get_attached_file( $source_id ),
			'source_file_url'  => (string) wp_get_attachment_url( $source_id ),
			'target_media_id'  => $target_id,
			'target_site_id'   => (string) ( $relation['target_site_id'] ?? '' ),
			'target_file_path' => get_attached_file( $target_id ),
			'target_file_url'  => (string) wp_get_attachment_url( $target_id ),
			'mapping_method'   => 'copy',
			'alt_translated'   => 1,
		) );
		$this->assertGreaterThan( 0, (int) $mapping_id, 'media mapping must be created' );
		$this->test_mapping_ids[] = (int) $mapping_id;

		// Fixture: media_alt string row. Register a decoy under the default
		// language — the pre-fix code resolved the undefined $lang to the
		// default language, so the decoy must NOT win when the request is zh_CN.
		$string_id = String_Translation_Service::register( 'media_alt', 'attachment_' . $source_id, $source_alt, 'en_US' );
		$this->assertGreaterThan( 0, (int) $string_id, 'media_alt string must register' );
		$this->test_string_ids[] = (int) $string_id;

		$default_lang = '';
		if ( class_exists( '\\WPTSALL\\Strings\\Services\\Language_Service' ) ) {
			$default = \WPTSALL\Strings\Services\Language_Service::get_default();
			$default_lang = (string) ( $default['code'] ?? '' );
		}
		$translations = array( 'zh_CN' => $zh_alt );
		$decoy        = '';
		if ( '' !== $default_lang && 'zh_CN' !== $default_lang ) {
			$decoy = 'Default-lang decoy ' . uniqid();
			$translations[ $default_lang ] = $decoy;
		}
		$this->assertTrue(
			String_Translation_Service::set_translations( (int) $string_id, $translations ),
			'translations must be stored'
		);

		// Act: simulate the zh_CN virtual-site request and render real HTML.
		Language_Context::set_language( 'zh_CN' );
		$html = wp_get_attachment_image( $source_id, 'full' );

		$this->assertNotEmpty( $html, 'wp_get_attachment_image() must render HTML for the mapped attachment' );
		$this->assertStringContainsString( $zh_alt, (string) $html, 'rendered HTML alt must use the request-language translation' );
		$this->assertStringNotContainsString( $source_alt, (string) $html, 'the source-language alt must not leak into the target HTML' );
		if ( '' !== $decoy ) {
			$this->assertStringNotContainsString( $decoy, (string) $html, 'the default-language decoy must not win over the request language (M-02 regression)' );
		}

		// The mapped src swap must also hold in the same HTML.
		$target_url = (string) wp_get_attachment_url( $target_id );
		if ( '' !== $target_url ) {
			$this->assertStringContainsString( $target_url, (string) $html, 'img src must point at the target attachment URL' );
		}
	}

	/**
	 * Control: without a request language the renderer must not touch alt
	 * (no mapping is resolved, source alt stays).
	 */
	public function test_attachment_image_alt_untouched_without_request_language() {
		Language_Context::reset();

		$source_alt = 'No-context alt ' . uniqid();
		$source_id  = $this->create_attachment( $source_alt );

		$html = wp_get_attachment_image( $source_id, 'full' );
		$this->assertNotEmpty( (string) $html, 'HTML must render without a language context' );
		$this->assertStringContainsString( $source_alt, (string) $html, 'alt must stay the source text with no request language' );
	}
}
