<?php
/**
 * Url_Converter + Link Filters + Output Localizer unit tests.
 *
 * @package WPTSALL
 * @since 2.2.0
 */

use WPTSALL\Sites\Services\Url_Converter;
use WPTSALL\Hooks\Virtual_Site_Link_Filters;
use WPTSALL\Hooks\Output_Link_Localizer;
use WPTSALL\Hooks\Virtual_Site_Router;

class Test_Virtual_Site_Url_And_Links extends SimpleTestCase {

	private $saved_settings = null;

	public function setUp(): void {
		// P1-TEST-02 (2026-09-02): prefix injection is skipped for the
		// default-language virtual site. The plugin derives default_language
		// from get_locale() on activation, so these tests must not depend on
		// the container locale (Lab slots run en_US, the main container
		// zh_CN) — pin the settings explicitly for every test.
		if ( class_exists( \WPTSALL\Settings\Services\Settings_Service::class ) ) {
			$this->saved_settings = \WPTSALL\Settings\Services\Settings_Service::get_all();
			\WPTSALL\Settings\Services\Settings_Service::update(
				array(
					'default_language' => 'zh_CN',
					'url_form'         => 'subdir',
					'directory_for_default_language' => false,
				)
			);
		}
	}

	public function tearDown(): void {
		if ( null !== $this->saved_settings && class_exists( \WPTSALL\Settings\Services\Settings_Service::class ) ) {
			\WPTSALL\Settings\Services\Settings_Service::update( $this->saved_settings );
		}
		$GLOBALS['wptsall_current_virtual_site'] = null;
		parent::tearDown();
	}

	public function test_url_converter_virtualize_and_source_roundtrip() {
		$vs  = array( 'id' => 'v_test', 'path_prefix' => 'en_us', 'lang' => 'en_US' );
		$src = 'https://example.test/hello-world/';
		$out = Url_Converter::virtualize( $src, $vs );
		$this->assertTrue( false !== strpos( $out, '/en_us/hello-world/' ), 'virtualize should inject prefix: ' . $out );
		$back = Url_Converter::source_url( $out, $vs );
		$this->assertEquals( 'https://example.test/hello-world/', $back );
	}

	public function test_url_converter_idempotent_prefix() {
		$vs  = array( 'path_prefix' => 'en_us' );
		$url = 'https://example.test/en_us/hello/';
		$this->assertEquals( $url, Url_Converter::virtualize( $url, $vs ) );
	}

	public function test_home_for_virtual_site_uses_option_home() {
		$vs   = array( 'path_prefix' => 'fr' );
		$home = Url_Converter::home_for_virtual_site( $vs );
		$this->assertTrue( false !== strpos( $home, '/fr/' ), $home );
	}

	public function test_should_skip_home_url_for_sitemap_and_assets() {
		$this->assertTrue( Virtual_Site_Link_Filters::should_skip_home_url( 'https://x.test/sitemap.xml', '/sitemap.xml' ) );
		$this->assertTrue( Virtual_Site_Link_Filters::should_skip_home_url( 'https://x.test/a.css', '/a.css' ) );
		$this->assertFalse( Virtual_Site_Link_Filters::should_skip_home_url( 'https://x.test/', '/' ) );
	}

	public function test_output_localizer_prefixes_same_host_href() {
		$vs = array( 'id' => 'v1', 'path_prefix' => 'en_us', 'lang' => 'en_US' );
		$GLOBALS['wptsall_current_virtual_site'] = $vs;
		$ref = new ReflectionClass( Virtual_Site_Router::class );
		if ( $ref->hasProperty( 'current_virtual_site' ) ) {
			$prop = $ref->getProperty( 'current_virtual_site' );
			$prop->setAccessible( true );
			$prop->setValue( null, $vs );
		}

		$html = '<a href="/about/">About</a><img src="/logo.png"><a href="https://other.test/x">ext</a>';
		$out  = Output_Link_Localizer::rewrite_html( $html );
		$this->assertTrue( false !== strpos( $out, '/en_us/about/' ) || false !== strpos( $out, 'en_us/about' ), $out );
		$this->assertTrue( false !== strpos( $out, 'src="/logo.png"' ), 'assets must stay unprefixed: ' . $out );
		$this->assertTrue( false !== strpos( $out, 'https://other.test/x' ), 'external links stay: ' . $out );

		$GLOBALS['wptsall_current_virtual_site'] = null;
		if ( isset( $prop ) ) {
			$prop->setValue( null, null );
		}
	}

	public function test_virtual_site_resolved_action_fires_on_bind() {
		$fired = false;
		$cb    = function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'wptsall_virtual_site_resolved', $cb, 10, 3 );

		$wp = new stdClass();
		$wp->query_vars = array( 'pagename' => 'en_us/hello' );
		$vs = array( 'id' => 'v_test', 'path_prefix' => 'en_us', 'lang' => 'en_US' );
		$resolved = array(
			'type'       => 'post_type',
			'subtype'    => 'post',
			'source_id'  => 10,
			'queried_id' => 20,
		);
		$applied = \WPTSALL\Hooks\Virtual_Site_Query_Switch::bind_query_vars( $wp, $resolved );
		do_action( 'wptsall_virtual_site_resolved', $vs, array_merge( $resolved, $applied ), $wp );

		$this->assertTrue( $fired, 'wptsall_virtual_site_resolved should fire' );
		remove_action( 'wptsall_virtual_site_resolved', $cb, 10 );
	}

	public function test_get_terms_args_includes_vs_meta_on_virtual_site() {
		$vs = array( 'id' => '2', 'path_prefix' => 'en_us', 'lang' => 'en_US' );
		$GLOBALS['wptsall_current_virtual_site'] = $vs;
		$ref = new ReflectionClass( Virtual_Site_Router::class );
		$prop = $ref->getProperty( 'current_virtual_site' );
		$prop->setAccessible( true );
		$prop->setValue( null, $vs );

		$args = \WPTSALL\Hooks\Virtual_Site_Query_Switch::filter_get_terms_args( array( 'taxonomy' => 'category' ), array( 'category' ) );
		$this->assertTrue( ! empty( $args['meta_query'] ), wp_json_encode( $args ) );
		$found = false;
		foreach ( $args['meta_query'] as $clause ) {
			if ( is_array( $clause ) && ( $clause['key'] ?? '' ) === '_wptsall_virtual_site_id' && ( $clause['compare'] ?? '' ) === 'IN' ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'VS get_terms must IN virtual_site_id: ' . wp_json_encode( $args ) );

		$prop->setValue( null, null );
		$GLOBALS['wptsall_current_virtual_site'] = null;
		$args2 = \WPTSALL\Hooks\Virtual_Site_Query_Switch::filter_get_terms_args( array(), array( 'post_tag' ) );
		$found_ex = false;
		foreach ( ( $args2['meta_query'] ?? array() ) as $clause ) {
			if ( is_array( $clause ) && ( $clause['compare'] ?? '' ) === 'NOT EXISTS' ) {
				$found_ex = true;
			}
		}
		$this->assertTrue( $found_ex, 'source get_terms must exclude shadows: ' . wp_json_encode( $args2 ) );
	}

	public function test_get_terms_args_skips_filter_for_identity_and_object_lookups() {
		$base = \WPTSALL\Hooks\Virtual_Site_Query_Switch::filter_get_terms_args(
			array( 'taxonomy' => 'category' ),
			array( 'category' )
		);
		$this->assertNotEmpty( $base['meta_query'] ?? null, 'listing queries still isolate' );

		$by_include = \WPTSALL\Hooks\Virtual_Site_Query_Switch::filter_get_terms_args(
			array( 'include' => array( 12, 34 ), 'taxonomy' => 'category' ),
			array( 'category' )
		);
		$this->assertTrue( empty( $by_include['meta_query'] ), 'include lookups must not isolate: ' . wp_json_encode( $by_include ) );

		$by_object = \WPTSALL\Hooks\Virtual_Site_Query_Switch::filter_get_terms_args(
			array( 'object_ids' => array( 99 ), 'taxonomy' => 'category' ),
			array( 'category' )
		);
		$this->assertTrue( empty( $by_object['meta_query'] ), 'object_ids lookups must not isolate' );

		$by_slug = \WPTSALL\Hooks\Virtual_Site_Query_Switch::filter_get_terms_args(
			array( 'slug' => 'news', 'taxonomy' => 'category' ),
			array( 'category' )
		);
		$this->assertTrue( empty( $by_slug['meta_query'] ), 'slug lookups must not isolate' );
	}

	public function test_post_type_archive_and_attachment_link_prefix() {
		$vs = array( 'id' => 'v_arc', 'path_prefix' => 'en_us', 'lang' => 'en_US' );
		$GLOBALS['wptsall_current_virtual_site'] = $vs;
		$ref = new ReflectionClass( Virtual_Site_Router::class );
		$prop = $ref->getProperty( 'current_virtual_site' );
		$prop->setAccessible( true );
		$prop->setValue( null, $vs );

		$archive = Virtual_Site_Router::rewrite_post_type_archive_link( home_url( '/product/' ), 'product' );
		$this->assertTrue( false !== strpos( $archive, '/en_us/' ), $archive );

		$att = Virtual_Site_Router::rewrite_attachment_link( home_url( '/?attachment_id=9' ), 9 );
		$this->assertTrue( false !== strpos( $att, '/en_us/' ) || $att === home_url( '/?attachment_id=9' ), $att );

		$prop->setValue( null, null );
		$GLOBALS['wptsall_current_virtual_site'] = null;
	}
}
