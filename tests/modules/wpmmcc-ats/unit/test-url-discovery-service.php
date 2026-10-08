<?php
/**
 * URL Discovery Service Tests
 *
 * L1/L2 for WPTSALL\ManualTranslation\Services\Url_Discovery_Service:
 * - preview(): pure prefix-swap matrix (dash source, underscore source,
 *   case-sensitive mismatch pin, full URL, unresolvable path)
 * - preview(): real resolution with '/%postname%/' permalinks — source slug
 *   resolves and the target path resolves via the slug fallback
 * - discover_bulk(): found (mapping created via Post_Mapping_Service and
 *   removable via delete_mappings_for_source), missing (target basename is
 *   an attachment whose 'inherit' status is not in the fallback status
 *   list), error (unresolvable source / URL without path), and skipped
 *   (empty URL produces no result row); the wptsall_url_discovery_bulk
 *   audit hook fires
 *
 * catalog: WP-CLASS-Url_Discovery_Service
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\ManualTranslation\Services\Url_Discovery_Service;
use WPTSALL\Models\Services\Post_Mapping_Service;

class Test_Url_Discovery_Service extends SimpleTestCase {

	/**
	 * @var string|null Original permalink structure (restored in tearDown).
	 */
	private $orig_permalink;

	/**
	 * @var array<int, int>
	 */
	private $post_ids = array();

	public function setUp(): void {
		parent::setUp();
		$this->orig_permalink = get_option( 'permalink_structure' );
	}

	public function tearDown(): void {
		foreach ( $this->post_ids as $post_id ) {
			Post_Mapping_Service::delete_mappings_for_source( $post_id, 'post', get_current_blog_id() );
			Post_Mapping_Service::delete_mappings_for_source( $post_id, 'attachment', get_current_blog_id() );
			wp_delete_post( $post_id, true );
		}
		$this->post_ids = array();

		if ( null !== $this->orig_permalink ) {
			self::set_permalink_structure( $this->orig_permalink );
		}
		parent::tearDown();
	}

	/**
	 * Switch permalink structure and flush rewrite rules in-process.
	 */
	private static function set_permalink_structure( string $structure ): void {
		global $wp_rewrite;
		update_option( 'permalink_structure', $structure );
		$wp_rewrite->init();
		$wp_rewrite->flush_rules();
	}

	private function create_post( string $post_type, string $title ): int {
		$post_id = wp_insert_post( array(
			'post_title'   => $title,
			'post_name'    => sanitize_title( $title ),
			'post_content' => 'x',
			'post_status'  => 'post' === $post_type ? 'publish' : 'inherit',
			'post_type'    => $post_type,
		), true );
		$this->assertTrue( ! is_wp_error( $post_id ), 'post insert failed' );
		$this->assertGreaterThan( 0, (int) $post_id );
		$this->post_ids[] = (int) $post_id;
		return (int) $post_id;
	}

	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\ManualTranslation\Services\Url_Discovery_Service' ) );
	}

	public function test_preview_prefix_swap_matrix() {
		// Dash variant of the source prefix.
		$r = Url_Discovery_Service::preview( '/zh-cn/products/foo/', 'zh_CN', 'en_US' );
		$this->assertSame( '/zh-cn/products/foo/', $r['source_path'] );
		$this->assertSame( '/en-us/products/foo/', $r['target_path'] );

		// Underscore variant matches the same prefix.
		$r = Url_Discovery_Service::preview( '/zh_cn/products/foo/', 'zh_CN', 'en_US' );
		$this->assertSame( '/en-us/products/foo/', $r['target_path'] );

		// Full URL: only the path is used.
		$r = Url_Discovery_Service::preview( 'https://example.com/zh-cn/products/foo/', 'zh_CN', 'en_US' );
		$this->assertSame( '/zh-cn/products/foo/', $r['source_path'] );
		$this->assertSame( '/en-us/products/foo/', $r['target_path'] );

		// Case-sensitive mismatch pin: 'ZH-CN' does not match 'zh-cn', so the
		// whole path is appended to the target prefix.
		$r = Url_Discovery_Service::preview( '/ZH-CN/products/foo/', 'zh_CN', 'en_US' );
		$this->assertSame( '/en-us/ZH-CN/products/foo/', $r['target_path'] );

		// Path without the source prefix: prefix is prepended verbatim.
		$r = Url_Discovery_Service::preview( '/products/foo/', 'zh_CN', 'en_US' );
		$this->assertSame( '/en-us/products/foo/', $r['target_path'] );
	}

	public function test_preview_resolves_real_posts() {
		self::set_permalink_structure( '/%postname%/' );
		$slug = 'zz-uds-' . strtolower( uniqid() );
		$post_id = $this->create_post( 'post', $slug );

		$r = Url_Discovery_Service::preview( '/' . $slug . '/', 'zh_CN', 'en_US' );

		$this->assertSame( $post_id, $r['source_post_id'], 'source path resolves via url_to_postid' );
		$this->assertSame( $post_id, $r['target_post_id'], 'target resolves via the slug fallback' );
		$this->assertTrue( $r['exists'] );
		$this->assertSame( $slug, $r['source_post_title'] );

		// Unresolvable path: ids stay 0 and exists is false.
		$r = Url_Discovery_Service::preview( '/no-such-' . strtolower( uniqid() ) . '/', 'zh_CN', 'en_US' );
		$this->assertSame( 0, $r['source_post_id'] );
		$this->assertSame( 0, $r['target_post_id'] );
		$this->assertFalse( $r['exists'] );
	}

	public function test_discover_bulk_full_status_matrix() {
		self::set_permalink_structure( '/%postname%/' );
		$tag      = strtolower( uniqid() );
		$slug     = 'zz-uds-' . $tag;
		$post_id  = $this->create_post( 'post', $slug );

		// Attachment under the post: its 'inherit' status is NOT in the slug
		// fallback status list, so the derived target path counts as missing.
		$att_id   = wp_insert_post( array(
			'post_title'  => 'zz-uds-att-' . $tag,
			'post_name'   => 'zz-uds-att-' . $tag,
			'post_status' => 'inherit',
			'post_type'   => 'attachment',
			'post_parent' => $post_id,
		), true );
		$this->assertTrue( ! is_wp_error( $att_id ), 'attachment insert failed' );
		$this->post_ids[] = (int) $att_id;

		$hook_urls = null;
		add_action( 'wptsall_url_discovery_bulk', static function ( $urls, $s, $t ) use ( &$hook_urls ) {
			$hook_urls = array( 'urls' => $urls, 'source' => $s, 'target' => $t );
		}, 10, 3 );

		$result = Url_Discovery_Service::discover_bulk( array(
			'/' . $slug . '/',                      // -> found (self slug fallback)
			'/' . $slug . '/zz-uds-att-' . $tag . '/', // -> missing (attachment target)
			'/unresolvable-' . $tag . '/',          // -> error (source not resolved)
			'https://example.com',                  // -> error (no path)
			'',                                     // -> skipped entirely
		), 'zh_CN', 'en_US' );

		remove_all_actions( 'wptsall_url_discovery_bulk' );

		$this->assertSame( 1, $result['found'], 'found count: ' . wp_json_encode( $result ) );
		$this->assertSame( 1, $result['missing'] );
		$this->assertSame( 2, $result['errors'] );
		// The empty URL is skipped: no result row for it.
		$this->assertCount( 4, $result['results'] );

		$statuses = array();
		foreach ( $result['results'] as $row ) {
			$statuses[ $row['url'] ] = $row['status'];
		}
		$this->assertSame( 'found', $statuses[ '/' . $slug . '/' ] );
		$this->assertSame( 'missing', $statuses[ '/' . $slug . '/zz-uds-att-' . $tag . '/' ] );
		$this->assertSame( 'error', $statuses[ '/unresolvable-' . $tag . '/' ] );
		$this->assertSame( 'error', $statuses[ 'https://example.com' ] );

		// The found row carries a real mapping id for the source post.
		$found_row = null;
		foreach ( $result['results'] as $row ) {
			if ( 'found' === $row['status'] ) {
				$found_row = $row;
			}
		}
		$this->assertNotNull( $found_row );
		$this->assertSame( $post_id, $found_row['source_post_id'] );
		$this->assertGreaterThan( 0, (int) $found_row['mapping_id'] );

		// Mapping is queryable and removable via the service API.
		$mappings = Post_Mapping_Service::get_all_mappings_for_source( $post_id, 'post', get_current_blog_id() );
		$this->assertNotEmpty( $mappings );
		$this->assertNotFalse( Post_Mapping_Service::delete_mappings_for_source( $post_id, 'post', get_current_blog_id() ) );

		// Audit hook fired with the original URL list.
		$this->assertIsArray( $hook_urls );
		$this->assertSame( 'zh_CN', $hook_urls['source'] );
		$this->assertSame( 'en_US', $hook_urls['target'] );
	}
}
