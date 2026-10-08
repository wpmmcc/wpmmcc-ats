<?php
/**
 * Unit tests for Relation_Resolver and Virtual_Permalink.
 *
 * @package WPTSALL
 */

use WPTSALL\Sites\Services\Relation_Resolver;
use WPTSALL\Sites\Services\Virtual_Permalink;

/**
 * @group sites
 * @group relation-resolver
 */
class Test_Relation_Resolver_And_Permalink extends WP_UnitTestCase {

	public function test_model_covers_exact_post_type() {
		$model = array(
			'post_types'  => wp_json_encode( array( array( 'name' => 'product' ), array( 'name' => 'product_variation' ) ) ),
			'plugin_slug' => 'woocommerce',
		);
		$this->assertTrue( Relation_Resolver::model_covers_post_type( $model, 'product' ) );
		$this->assertFalse( Relation_Resolver::model_covers_post_type( $model, 'post' ) );
	}

	public function test_model_covers_core_only_via_wordpress_blog_slug() {
		$woo = array(
			'post_types'  => wp_json_encode( array( array( 'name' => 'product' ) ) ),
			'plugin_slug' => 'woocommerce',
		);
		$blog = array(
			'post_types'  => wp_json_encode( array() ),
			'plugin_slug' => 'wordpress-blog',
		);

		// Regression: post/page must NOT match every model (old short-circuit).
		$this->assertFalse( Relation_Resolver::model_covers_post_type( $woo, 'post' ) );
		$this->assertFalse( Relation_Resolver::model_covers_post_type( $woo, 'page' ) );
		$this->assertTrue( Relation_Resolver::model_covers_post_type( $blog, 'post' ) );
		$this->assertTrue( Relation_Resolver::model_covers_post_type( $blog, 'page' ) );
		$this->assertFalse( Relation_Resolver::model_covers_post_type( $blog, 'product' ) );
	}

	public function test_virtual_permalink_uses_get_permalink_shape_for_posts() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Permalink Shape',
				'post_name'   => 'permalink-shape',
				'post_status' => 'publish',
			)
		);
		$expected = get_permalink( $post_id );
		$got      = Virtual_Permalink::for_post( $post_id, null );
		$this->assertNotEmpty( $got );
		$this->assertSame( $expected, $got );
	}

	public function test_virtual_permalink_virtualizes_with_path_prefix_context() {
		// Hermetic permalink shape: this suite must not depend on the host
		// site's permalink_structure. Fresh installs (module-ci runner,
		// wp core install defaults) use plain permalinks (?p=123), where a
		// generated permalink correctly carries no post slug; the lab slots
		// are pre-set to /%postname%/ by ensure-slot-wordpress.sh, which is
		// why this only failed on the 2026-09-09 module-ci run. Pin pretty
		// permalinks for this case and restore afterwards.
		global $wp_rewrite;
		$orig_structure = $wp_rewrite->permalink_structure;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'VS Prefix',
				'post_name'   => 'vs-prefix-post',
				'post_status' => 'publish',
			)
		);
		// Non-default lang so Url_Converter does not omit the path prefix when
		// directory_for_default_language is off (lab slot settings vary).
		$vs  = array(
			'id'          => 'v_test',
			'path_prefix' => 'en-test',
			'lang'        => 'fr_FR',
		);
		$url = Virtual_Permalink::for_post( $post_id, $vs );
		$this->assertNotEmpty( $url );
		$this->assertStringContainsString( '/en-test/', $url );
		$this->assertStringContainsString( 'vs-prefix-post', $url );

		$wp_rewrite->set_permalink_structure( $orig_structure );
	}
}
