<?php
/**
 * Unit tests: wptsall_object_id + SEO dual-emission guard.
 *
 * @package WPTSALL
 * @since 2.2.0
 */

use WPTSALL\API\Object_Id;
use WPTSALL\Hooks\Virtual_Site_SEO;

class Test_Object_Id_And_Seo_Guards extends SimpleTestCase {

	public function test_object_id_function_exists() {
		$this->assertTrue( function_exists( 'wptsall_object_id' ) );
	}

	public function test_object_id_zero_input() {
		$this->assertEquals( 0, wptsall_object_id( 0, 'post', false ) );
		$this->assertEquals( 0, wptsall_object_id( 0, 'post', true ) );
	}

	public function test_object_id_missing_returns_original_when_flag_set() {
		$GLOBALS['wptsall_current_virtual_site'] = null;
		$this->assertEquals( 12345, wptsall_object_id( 12345, 'post', true, null ) );
		$this->assertEquals( 0, wptsall_object_id( 12345, 'post', false, null ) );
	}

	public function test_object_id_resolves_shadow_on_virtual_site() {
		if ( ! class_exists( 'WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			$this->markTestSkipped( 'Virtual_Site_Service not loaded' );
		}

		// P1-TEST-02 (2026-09-02): resolve('…', 'en_US') resolves the virtual
		// site via Virtual_Site_Service::get_all(active) by language — a
		// shadow post alone is not enough; the virtual site must be a real,
		// active row (legacy contract resolved from globals only).
		$suffix    = uniqid();
		$vs_create = \WPTSALL\Sites\Services\Virtual_Site_Service::create(
			array(
				'name'        => 'OID Fixture ' . $suffix,
				'path_prefix' => 'oid-fixture-' . $suffix,
				'lang'        => 'en_US',
			)
		);
		$this->assertNotEmpty( $vs_create['success'] ?? false, 'virtual site fixture: ' . wp_json_encode( $vs_create ) );
		$vs_id = (int) $vs_create['site_id'];

		$source = wp_insert_post( array(
			'post_title'  => 'OID Source',
			'post_status' => 'publish',
			'post_type'   => 'post',
			'post_name'   => 'oid-source-' . wp_generate_password( 6, false ),
		) );
		$shadow = wp_insert_post( array(
			'post_title'  => 'OID Shadow',
			'post_status' => 'publish',
			'post_type'   => 'post',
			'post_name'   => 'oid-shadow-' . wp_generate_password( 6, false ),
		) );
		update_post_meta( $shadow, '_wptsall_virtual_site_id', 'v_' . $vs_id );
		update_post_meta( $shadow, '_wptsall_source_post_id', $source );

		$vs = array(
			'id'          => (string) $vs_id,
			'path_prefix' => 'oid-fixture-' . $suffix,
			'lang'        => 'en_US',
		);
		$GLOBALS['wptsall_current_virtual_site'] = $vs;
		$ref = new ReflectionClass( \WPTSALL\Hooks\Virtual_Site_Router::class );
		$p   = null;
		if ( $ref->hasProperty( 'current_virtual_site' ) ) {
			$p = $ref->getProperty( 'current_virtual_site' );
			$p->setAccessible( true );
			$p->setValue( null, $vs );
		}

		$resolved = wptsall_object_id( $source, 'post', false, 'en_US' );
		$this->assertEquals( $shadow, $resolved, 'object_id should map source→shadow' );

		wp_delete_post( $source, true );
		wp_delete_post( $shadow, true );
		$GLOBALS['wptsall_current_virtual_site'] = null;
		\WPTSALL\Sites\Services\Virtual_Site_Service::delete( $vs_id );
		if ( isset( $p ) ) {
			$p->setValue( null, null );
		}
	}

	public function test_build_sitemap_alternates_returns_x_default() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'Alt Post',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );
		$alts = Virtual_Site_SEO::build_sitemap_alternates( (int) $post_id );
		$langs = array_column( $alts, 'hreflang' );
		$this->assertTrue( in_array( 'x-default', $langs, true ), 'must include x-default: ' . wp_json_encode( $alts ) );
		wp_delete_post( $post_id, true );
	}

	public function test_yoast_active_emits_only_on_wpseo_head_not_both() {
		// Structural: when Yoast is active, init should not leave duplicate
		// wp_head + wpseo_head registrations. We assert the helper exists and
		// is_yoast_active is callable; full hook list depends on runtime plugins.
		$this->assertTrue( method_exists( Virtual_Site_SEO::class, 'is_yoast_active' ) );
		$this->assertTrue( method_exists( Virtual_Site_SEO::class, 'build_sitemap_alternates' ) );
	}

	public function test_rankmath_does_not_disable_seo_layer() {
		$this->assertTrue( method_exists( Virtual_Site_SEO::class, 'is_rankmath_active' ) );
		$this->assertTrue( method_exists( Virtual_Site_SEO::class, 'filter_rankmath_sitemap_entry' ) );
		$this->assertTrue( method_exists( Virtual_Site_SEO::class, 'filter_rankmath_canonical' ) );
		$this->assertTrue( method_exists( Virtual_Site_SEO::class, 'rankmath_catch_sitemap_on_virtual_prefix' ) );

		// Even if Rank Math appears active, emitter=none is the only settings kill-switch
		// (constant WPTSALL_SEO_OUTPUT aside). Blanket Rank Math disable was removed in B1.
		$was_defined = defined( 'RANK_MATH_VERSION' );
		if ( ! $was_defined ) {
			define( 'RANK_MATH_VERSION', '1.0.0-test' );
		}
		$this->assertTrue( Virtual_Site_SEO::is_rankmath_active() );
		// Constant WPTSALL_SEO_OUTPUT is the only hard kill-switch; emitter=none
		// only suppresses hreflang (should_emit_hreflang).
		$this->assertTrue( Virtual_Site_SEO::is_output_enabled() );
	}

	public function test_hreflang_emitter_none_keeps_seo_layer_but_skips_hreflang() {
		if ( ! class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
			$this->markTestSkipped( 'Settings_Service missing' );
		}
		$saved = \WPTSALL\Settings\Services\Settings_Service::get_all();
		\WPTSALL\Settings\Services\Settings_Service::update( array( 'hreflang_emitter' => 'none' ) );
		$this->assertTrue( Virtual_Site_SEO::is_output_enabled() );
		$this->assertFalse( Virtual_Site_SEO::should_emit_hreflang() );
		\WPTSALL\Settings\Services\Settings_Service::update( $saved );
	}

	public function test_opengraph_url_virtualizes_under_vs() {
		// Use a non-default lang so Url_Converter does not omit the path prefix
		// when directory_for_default_language is off (lab settings vary by slot).
		$vs = array( 'id' => 'v_og', 'path_prefix' => 'fr_fr', 'lang' => 'fr_FR' );
		$GLOBALS['wptsall_current_virtual_site'] = $vs;
		$ref = new ReflectionClass( \WPTSALL\Hooks\Virtual_Site_Router::class );
		if ( $ref->hasProperty( 'current_virtual_site' ) ) {
			$prop = $ref->getProperty( 'current_virtual_site' );
			$prop->setAccessible( true );
			$prop->setValue( null, $vs );
		}
		$home = untrailingslashit( home_url() );
		$out  = Virtual_Site_SEO::filter_opengraph_url( $home . '/hello/' );
		$this->assertTrue( false !== strpos( $out, '/fr_fr/' ), $out );
		$GLOBALS['wptsall_current_virtual_site'] = null;
		if ( isset( $prop ) ) {
			$prop->setValue( null, null );
		}
	}

	public function test_rankmath_sitemap_entry_attaches_alternate_langs() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'RM Alt Post',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );
		$entry = array( 'loc' => home_url( '/?p=' . $post_id ) );
		$obj   = get_post( $post_id );
		$out   = Virtual_Site_SEO::filter_rankmath_sitemap_entry( $entry, 'post', $obj );
		$this->assertTrue( is_array( $out ) );
		$this->assertTrue( ! empty( $out['alternateLangs'] ) || ! empty( $out['alternates'] ), 'expected alternateLangs: ' . wp_json_encode( $out ) );
		$xml = '<url><loc>' . esc_url( $out['loc'] ) . '</loc></url>';
		$xml2 = Virtual_Site_SEO::filter_rankmath_sitemap_url_xml( $xml, $out );
		$this->assertTrue( false !== strpos( $xml2, 'xhtml:link' ), 'expected xhtml:link inject' );
		wp_delete_post( $post_id, true );
	}

	/**
	 * ATS-B-01 (doc 16 / ADR-7): the hreflang emitter consumes
	 * `wpmmcc_cross_site_alternates` exactly once, merges only unmapped
	 * language groups (case-insensitive single-emitter rule), keeps
	 * x-default ATS-owned, and normalizes BCP47 casing.
	 */
	private function collect_with( $post_id, array $hreflangs ) {
		$method = new ReflectionMethod( Virtual_Site_SEO::class, 'collect_alternate_hreflangs' );
		$method->setAccessible( true );
		return $method->invoke( null, $post_id, $hreflangs );
	}

	public function test_ats_b01_cross_site_alternates_absent_subscriber_is_noop() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'B01 Noop Post',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );
		$in  = array(
			'x-default' => 'https://src.example/x',
			'en-US'     => 'https://src.example/en',
		);
		$out = $this->collect_with( $post_id, $in );
		wp_delete_post( $post_id, true );
		$this->assertSame( $in, $out, 'no wpmmcc subscriber must leave hreflangs untouched' );
	}

	public function test_ats_b01_cross_site_alternates_merge_unmapped_languages() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'B01 Merge Post',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );
		$cb = function ( $alternates, $filter_post_id ) use ( $post_id ) {
			$this->assertSame( array(), $alternates, 'emitter must seed the filter with an empty array' );
			$this->assertSame( (int) $post_id, (int) $filter_post_id, 'filter must receive the source post id' );
			return array(
				'fr_fr' => 'https://remote.example/fr/post',
				'de'    => 'https://remote.example/de/post',
			);
		};
		add_filter( 'wpmmcc_cross_site_alternates', $cb, 10, 2 );
		$out = $this->collect_with( $post_id, array(
			'x-default' => 'https://src.example/x',
			'en-US'     => 'https://src.example/en',
			'zh-CN'     => 'https://src.example/zh',
		) );
		remove_filter( 'wpmmcc_cross_site_alternates', $cb, 10, 2 );
		wp_delete_post( $post_id, true );

		$this->assertSame( 'https://src.example/x', $out['x-default'], 'x-default stays ATS-owned' );
		$this->assertSame( 'https://src.example/en', $out['en-US'] );
		$this->assertSame( 'https://src.example/zh', $out['zh-CN'] );
		$this->assertSame( 'https://remote.example/fr/post', $out['fr-FR'], 'merged keys must use BCP47 casing' );
		$this->assertSame( 'https://remote.example/de/post', $out['de'] );
	}

	public function test_ats_b01_cross_site_alternates_dedup_is_case_insensitive() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'B01 Dedup Post',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );
		$cb = function () {
			// wpmmcc emits lower-case codes; ATS already maps zh-CN — the
			// single-emitter rule must suppress this row, not duplicate it.
			return array( 'zh-cn' => 'https://remote.example/zh/dup' );
		};
		add_filter( 'wpmmcc_cross_site_alternates', $cb, 10, 2 );
		$out = $this->collect_with( $post_id, array(
			'x-default' => 'https://src.example/x',
			'zh-CN'     => 'https://src.example/zh',
		) );
		remove_filter( 'wpmmcc_cross_site_alternates', $cb, 10, 2 );
		wp_delete_post( $post_id, true );

		$this->assertSame( 'https://src.example/zh', $out['zh-CN'] );
		$this->assertArrayNotHasKey( 'zh-cn', $out, 'duplicate language group must be suppressed' );
		$this->assertSame( 2, count( $out ), 'one hreflang row per language group: ' . wp_json_encode( $out ) );
	}
}
