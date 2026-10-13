<?php
/**
 * Unit tests: B4 prefer_sitemap_hreflang + B5 media library columns.
 *
 * @package WPTSALL
 * @since 2.2.0
 */

use WPTSALL\Hooks\Virtual_Site_SEO;
use WPTSALL\MediaTranslation\Admin\Media_Library_Columns;
use WPTSALL\Settings\Services\Settings_Service;

class Test_Seo_Prefer_Sitemap_And_Media_Columns extends SimpleTestCase {

	public function test_prefer_sitemap_helpers_exist() {
		$this->assertTrue( method_exists( Virtual_Site_SEO::class, 'prefer_sitemap_hreflang' ) );
		$this->assertTrue( method_exists( Virtual_Site_SEO::class, 'sitemap_plugin_owns_alternates' ) );
	}

	public function test_settings_default_prefer_sitemap() {
		$defaults = Settings_Service::defaults();
		$this->assertTrue( ! empty( $defaults['prefer_sitemap_hreflang'] ) );
	}

	public function test_should_emit_respects_prefer_sitemap_when_yoast_defined() {
		$prev = Settings_Service::get_all();
		Settings_Service::update(
			array(
				'hreflang_emitter'       => 'wpmmcc-ats',
				'prefer_sitemap_hreflang'=> 1,
			)
		);

		$yoast_was = defined( 'WPSEO_VERSION' );
		if ( ! $yoast_was && ! defined( 'WPSEO_VERSION' ) ) {
			// Lab usually has Yoast; if not, define a stub constant for this assertion.
			define( 'WPSEO_VERSION', '99.0-test' );
		}

		if ( Virtual_Site_SEO::is_yoast_active() || Virtual_Site_SEO::is_rankmath_active() ) {
			$this->assertFalse(
				Virtual_Site_SEO::should_emit_hreflang(),
				'DisableHeadLangs: head hreflang off when sitemap SEO plugin active'
			);
		}

		// Force head via filter escape hatch.
		add_filter( 'wptsall_force_head_hreflang', '__return_true' );
		$this->assertTrue( Virtual_Site_SEO::should_emit_hreflang() );
		remove_filter( 'wptsall_force_head_hreflang', '__return_true' );

		// Restore prefer off for isolation of other suites if needed.
		Settings_Service::update(
			array(
				'prefer_sitemap_hreflang' => isset( $prev['prefer_sitemap_hreflang'] ) ? (int) $prev['prefer_sitemap_hreflang'] : 1,
				'hreflang_emitter'        => $prev['hreflang_emitter'] ?? 'wpmmcc-ats',
			)
		);
	}

	public function test_prefer_sitemap_off_allows_head() {
		Settings_Service::update(
			array(
				'hreflang_emitter'        => 'wpmmcc-ats',
				'prefer_sitemap_hreflang' => 0,
			)
		);
		$this->assertTrue( Virtual_Site_SEO::prefer_sitemap_hreflang() === false );
		$this->assertTrue( Virtual_Site_SEO::should_emit_hreflang() );
		Settings_Service::update( array( 'prefer_sitemap_hreflang' => 1 ) );
	}

	public function test_media_library_columns_registered_keys() {
		$cols = Media_Library_Columns::add_columns( array( 'title' => 'File' ) );
		$this->assertTrue( isset( $cols['wptsall_site'] ) );
		$this->assertTrue( isset( $cols['wptsall_map'] ) );
	}

	public function test_media_lookup_mappings_empty_for_unknown() {
		$rows = Media_Library_Columns::lookup_mappings( 999999991 );
		$this->assertTrue( is_array( $rows ) );
	}
}
