<?php
/**
 * Manual Content Service: Gutenberg Block Comment Semantics (Phase 4; plan
 * §7 TEST-CONTENT-SEMANTICS-001, SEM-BLOCK-COMMENTS row).
 *
 * Gap-analysis SEM-03 ("部分成立"): the FSE adapter path already uses
 * parse_blocks() with tests; the UNPROVEN half was the ordinary post/page
 * writeback boundary — Manual_Content_Service::sanitize_editor_payload_value
 * sends post_content through wp_kses_post(), which is exactly where block
 * comments could be mangled.
 *
 * Live probes on WP 6.7 (2026-09-08) fix the expectations:
 *  - VALID block markers (simple, JSON attrs, nested group, wp:html) survive
 *    wp_kses_post byte-identically → hard asserts.
 *  - DAMAGED input is deterministically normalized, never silently dropped
 *    or emptied: an unclosed inner block gets its closing marker auto-added;
 *    an unterminated comment opener gets HTML-escaped → pinned exact output.
 *  - DB round trip: the sanitized markup stores byte-identically through
 *    wp_update_post for an unfiltered_html user.
 *
 * catalog: WP-CLASS-Manual_Content_Service
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Sites\Services\Manual_Content_Service;

class Test_Manual_Content_Block_Comments extends WP_UnitTestCase {

	/**
	 * @var array<int, int> Posts created by this run.
	 */
	private $post_ids = array();

	public function tearDown(): void {
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->post_ids = array();
		parent::tearDown();
	}

	private function sanitize_content( string $raw ): string {
		$value = Manual_Content_Service::sanitize_editor_payload_value( 'post_content', $raw );
		$this->assertIsString( $value, 'post_content sanitize must stay a string' );
		return $value;
	}

	public function test_valid_block_comments_survive_byte_identically() {
		// Simple paragraph pair.
		$markup = '<!-- wp:paragraph --><p>Hero block</p><!-- /wp:paragraph -->';
		$this->assertSame( $markup, $this->sanitize_content( $markup ) );

		// Marker with JSON attributes (byte-identical incl. braces/quotes).
		$markup = '<!-- wp:group {"align":"wide","layout":{"type":"flex"}} --><!-- wp:paragraph -->Text<!-- /wp:paragraph --><!-- /wp:group -->';
		$this->assertSame( $markup, $this->sanitize_content( $markup ) );

		// Raw html block with inner markup.
		$markup = '<!-- wp:html --><div class="x"><span>keep</span></div><!-- /wp:html -->';
		$this->assertSame( $markup, $this->sanitize_content( $markup ) );
	}

	public function test_damaged_inner_block_is_deterministically_normalized() {
		// Missing closing marker: wp_kses_post auto-adds it (WP comment
		// balancing) instead of dropping or corrupting content. Pinned exact
		// output so any WP-core behavior change surfaces here.
		$raw     = 'a<!-- wp:paragraph --><p>Text';
		$outcome = $this->sanitize_content( $raw );
		$this->assertSame(
			'a<!-- wp:paragraph --><p>Text<!-- /wp:paragraph -->',
			$outcome,
			'unclosed inner block must be auto-closed deterministically, never silently dropped'
		);
	}

	public function test_unterminated_comment_opener_is_escaped_not_dropped() {
		// Unterminated "<!--" opener: the opener is HTML-escaped (fail-soft);
		// no PHP error, no silent data loss beyond the deterministic escape.
		$raw = 'b<!-- wp:broken';
		$this->assertSame(
			'b&lt;!-- wp:broken',
			$this->sanitize_content( $raw ),
			'unterminated comment opener must be escaped deterministically, never silently dropped'
		);
	}

	public function test_block_markup_round_trip_through_wp_update_post() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$markup = '<!-- wp:paragraph {"align":"center"} --><p>Round trip</p><!-- /wp:paragraph -->';
		$sanitized = $this->sanitize_content( $markup );

		$post_id = wp_insert_post( array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Block round trip ' . uniqid(),
			'post_content' => $sanitized,
		) );
		$this->assertFalse( is_wp_error( $post_id ), 'insert failed: ' . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : '' ) );
		$this->assertGreaterThan( 0, (int) $post_id );
		$this->post_ids[] = (int) $post_id;

		$stored = get_post( $post_id )->post_content;
		$this->assertSame( $sanitized, $stored, 'block comments must store byte-identically' );

		// Update path too (translation overwrites existing content).
		$updated = wp_update_post( array(
			'ID'          => $post_id,
			'post_content' => $sanitized,
		), true );
		$this->assertFalse( is_wp_error( $updated ), 'update failed: ' . ( is_wp_error( $updated ) ? $updated->get_error_message() : '' ) );
		$this->assertSame( $sanitized, get_post( $post_id )->post_content );
	}
}
