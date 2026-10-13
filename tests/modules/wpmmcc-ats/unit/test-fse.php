<?php
/**
 * FSE Content Adapter Tests
 *
 * Tests for WPTSALL\Fse\Fse_Content_Adapter and WPTSALL\Fse\Module:
 * managed post types, block string extraction, string registration on change
 * (mark_and_extract) and translation application (translate_content), plus
 * front-filter pass-through behaviour and module hook wiring.
 *
 * @package WPTSALL\Tests\Unit\Fse
 * @since 2.0.1
 */

use WPTSALL\Fse\Fse_Content_Adapter;
use WPTSALL\Fse\Module;
use WPTSALL\Strings\Services\String_Translation_Service;

class Test_Fse_Content_Adapter extends SimpleTestCase {

	/**
	 * String row ids created by this test (cleaned in tearDown).
	 *
	 * @var int[]
	 */
	private $created_ids = array();

	public function setUp(): void {
		parent::setUp();
		if ( function_exists( 'wptsall_create_strings_table' ) ) {
			wptsall_create_strings_table();
		}
	}

	public function tearDown(): void {
		foreach ( $this->created_ids as $id ) {
			String_Translation_Service::delete( (int) $id );
		}
		$this->created_ids = array();
		parent::tearDown();
	}

	/**
	 * Build a fake FSE post (no DB insert needed for adapter logic).
	 */
	private function make_fse_post( $post_type, $title, $content ) {
		return new WP_Post( (object) array(
			'ID'          => 987654000 + wp_rand( 1, 99999 ),
			'post_type'   => $post_type,
			'post_title'  => $title,
			'post_content' => $content,
		) );
	}

	/**
	 * Collect and later delete the string rows registered for a fake post.
	 */
	private function track_fse_rows( $fake_post ) {
		$rows = String_Translation_Service::list( array( 'context' => 'fse_' . $fake_post->post_type, 'limit' => 500 ) );
		foreach ( $rows as $r ) {
			$prefixes = array( 'title_' . (int) $fake_post->ID . '_', 'title_' . (int) $fake_post->ID, 'block_' . (int) $fake_post->ID . '_' );
			$key = (string) $r['string_key'];
			if ( 0 === strpos( $key, 'title_' . (int) $fake_post->ID )
				|| 0 === strpos( $key, 'block_' . (int) $fake_post->ID . '_' ) ) {
				$this->created_ids[] = (int) $r['id'];
			}
		}
	}

	// ==================== Managed types ====================

	public function test_managed_types_cover_fse_post_types() {
		$this->assertSame(
			array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' ),
			Fse_Content_Adapter::$managed_types
		);
	}

	public function test_filter_managed_types_merges_and_dedupes() {
		$merged = Fse_Content_Adapter::filter_managed_types( array( 'wp_template', 'custom_cpt' ) );
		$this->assertIsArray( $merged );
		$this->assertContains( 'wp_template', $merged );
		$this->assertContains( 'wp_template_part', $merged );
		$this->assertContains( 'wp_navigation', $merged );
		$this->assertContains( 'wp_global_styles', $merged );
		$this->assertContains( 'custom_cpt', $merged );
		// Deduplicated: wp_template appears once.
		$this->assertCount( 1, array_keys( $merged, 'wp_template' ) );

		// Non-array input tolerated.
		$this->assertContains( 'wp_template', Fse_Content_Adapter::filter_managed_types( 'wp_template' ) );
	}

	// ==================== extract_translatable_strings ====================

	public function test_extract_returns_empty_for_empty_content() {
		$this->assertSame( array(), Fse_Content_Adapter::extract_translatable_strings( '' ) );
	}

	public function test_extract_paragraph_text_leaf() {
		$content = "<!-- wp:paragraph -->\n<p>Hello FSE Unit</p>\n<!-- /wp:paragraph -->";
		$this->assertSame( array( 'Hello FSE Unit' ), Fse_Content_Adapter::extract_translatable_strings( $content ) );
	}

	public function test_extract_block_attributes() {
		$content = '<!-- wp:unitprobe/attr {"title":"My Attr Title","label":"My Attr Label","placeholder":"Type here"} /-->';
		$strings = Fse_Content_Adapter::extract_translatable_strings( $content );
		$this->assertIsArray( $strings );
		$this->assertContains( 'My Attr Title', $strings );
		$this->assertContains( 'My Attr Label', $strings );
		$this->assertContains( 'Type here', $strings );
	}

	public function test_extract_dedupes_repeated_text() {
		$block = "<!-- wp:paragraph -->\n<p>Same Text Twice</p>\n<!-- /wp:paragraph -->";
		$strings = Fse_Content_Adapter::extract_translatable_strings( $block . $block );
		$this->assertSame( array( 'Same Text Twice' ), $strings );
	}

	public function test_extract_filters_noise() {
		// Too-short leaves are dropped.
		$this->assertSame(
			array(),
			Fse_Content_Adapter::extract_translatable_strings( "<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->" )
		);
		// JSON-ish leaves (leading brace) are not human text.
		$this->assertSame(
			array(),
			Fse_Content_Adapter::extract_translatable_strings( "<p>{ \"layout\": \"wide\" }</p>" )
		);
	}

	public function test_extract_walks_nested_inner_blocks() {
		$content = "<!-- wp:group -->"
			. "<!-- wp:paragraph -->\n<p>Outer Wrapper</p>\n<!-- /wp:paragraph -->"
			. "<!-- wp:paragraph -->\n<p>Inner Deep Text</p>\n<!-- /wp:paragraph -->"
			. "<!-- /wp:group -->";
		$strings = Fse_Content_Adapter::extract_translatable_strings( $content );
		$this->assertContains( 'Outer Wrapper', $strings );
		$this->assertContains( 'Inner Deep Text', $strings );
	}

	// ==================== mark_and_extract ====================

	public function test_mark_and_extract_registers_strings_and_fires_action() {
		$post = $this->make_fse_post( 'wp_template', 'Unit FSE Header', "<!-- wp:paragraph -->\n<p>Hello FSE Unit</p>\n<!-- /wp:paragraph -->" );

		$fired = array();
		$cb = static function ( $fired_post, $fired_strings ) use ( &$fired ) {
			$fired[] = array( $fired_post, $fired_strings );
		};
		add_action( 'wptsall_fse_content_changed', $cb, 10, 2 );
		try {
			Fse_Content_Adapter::mark_and_extract( $post );
		} finally {
			remove_action( 'wptsall_fse_content_changed', $cb, 10, 2 );
		}

		// Action fired once with the post and the extracted leaves.
		$this->assertCount( 1, $fired );
		$this->assertSame( $post, $fired[0][0] );
		$this->assertSame( array( 'Hello FSE Unit' ), $fired[0][1] );

		// Strings registered under the fse_{post_type} context with stable keys.
		$this->assertSame(
			'Unit FSE Header',
			String_Translation_Service::translate( 'fse_wp_template', 'title_' . (int) $post->ID, '', 'xx_XX' )
		);
		$this->assertSame(
			'Hello FSE Unit',
			String_Translation_Service::translate( 'fse_wp_template', 'block_' . (int) $post->ID . '_0', '', 'xx_XX' )
		);

		$this->track_fse_rows( $post );
	}

	// ==================== translate_content ====================

	public function test_translate_content_applies_stored_translation() {
		$post = $this->make_fse_post( 'wp_template_part', 'Unit FSE Part', "<!-- wp:paragraph -->\n<p>Replace Me Please</p>\n<!-- /wp:paragraph -->" );
		Fse_Content_Adapter::mark_and_extract( $post );
		$this->track_fse_rows( $post );

		$row = null;
		foreach ( String_Translation_Service::list( array( 'context' => 'fse_wp_template_part', 'limit' => 500 ) ) as $r ) {
			if ( 'block_' . (int) $post->ID . '_0' === (string) $r['string_key'] ) {
				$row = $r;
				break;
			}
		}
		$this->assertNotNull( $row, 'block string row should be registered' );
		$this->assertTrue( String_Translation_Service::set_translations( (int) $row['id'], array( 'fr_FR' => 'Remplace Moi SVP' ) ) );

		$translated = Fse_Content_Adapter::translate_content(
			"<!-- wp:paragraph -->\n<p>Replace Me Please</p>\n<!-- /wp:paragraph -->",
			'fr_FR',
			(int) $post->ID,
			'wp_template_part'
		);
		$this->assertStringContainsString( 'Remplace Moi SVP', $translated );
		$this->assertStringNotContainsString( 'Replace Me Please', $translated );
	}

	public function test_translate_content_passthrough_without_lang_or_translation() {
		$post = $this->make_fse_post( 'wp_navigation', 'Unit FSE Nav', "<!-- wp:paragraph -->\n<p>Untouched Nav Text</p>\n<!-- /wp:paragraph -->" );
		Fse_Content_Adapter::mark_and_extract( $post );
		$this->track_fse_rows( $post );

		$original = "<!-- wp:paragraph -->\n<p>Untouched Nav Text</p>\n<!-- /wp:paragraph -->";

		// No target language -> content untouched.
		$this->assertSame( $original, Fse_Content_Adapter::translate_content( $original, '', (int) $post->ID, 'wp_navigation' ) );

		// Language with no stored translation -> source text returned -> no replacement.
		$this->assertSame( $original, Fse_Content_Adapter::translate_content( $original, 'de_DE', (int) $post->ID, 'wp_navigation' ) );
	}

	// ==================== Module front filters ====================

	public function test_module_filters_pass_through_non_fse_posts() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Regular Post Title' ) );

		// No global post set -> the_content filter is a no-op.
		unset( $GLOBALS['post'] );
		$this->assertSame( '<p>Regular content</p>', Module::filter_the_content( '<p>Regular content</p>' ) );

		// Non-managed post type -> title filter is a no-op.
		$this->assertSame( 'Regular Post Title', Module::filter_the_title( 'Regular Post Title', (int) $post_id ) );

		// No post at all -> title filter is a no-op.
		$this->assertSame( 'Fallback Title', Module::filter_the_title( 'Fallback Title', 0 ) );
	}

	public function test_module_and_adapter_hooks_registered() {
		// FSE adapter REST + insert hooks (registered by Module::init at plugin load).
		foreach ( Fse_Content_Adapter::$managed_types as $pt ) {
			$this->assertNotFalse(
				has_action( "rest_after_insert_{$pt}", array( 'WPTSALL\Fse\Fse_Content_Adapter', 'on_rest_after_insert' ) ),
				"rest_after_insert_{$pt} hook should be registered"
			);
		}
		$this->assertNotFalse( has_action( 'wp_after_insert_post', array( 'WPTSALL\Fse\Fse_Content_Adapter', 'on_after_insert' ) ) );

		// Front filters registered by the module bootstrap.
		$this->assertNotFalse( has_filter( 'the_content', array( 'WPTSALL\Fse\Module', 'filter_the_content' ) ) );
		$this->assertNotFalse( has_filter( 'the_title', array( 'WPTSALL\Fse\Module', 'filter_the_title' ) ) );
	}
}
