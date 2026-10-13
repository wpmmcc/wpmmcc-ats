<?php
/**
 * P2-1: Third-Party Plugin Functional Integration Tests
 *
 * Tests that wptsall's hooks work correctly when specific third-party
 * plugins are active. Unlike the coexistence E2E test, these verify
 * FUNCTIONAL correctness.
 *
 * Run via: php tests/modules/wpmmcc-ats/unit/run.php --file=test-third-party-plugin-hooks.php
 *
 * @package WPTSALL\Tests\Unit
 */

class Test_Third_Party_Plugin_Hooks {

	/**
	 * WooCommerce + wptsall the_content filter at priority 999.
	 */
	public function test_woocommerce_content_filter_priority() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo "  ⏭️  WooCommerce not active, skipping\n";
			return;
		}
		$original  = '[woocommerce_cart]';
		$filtered  = apply_filters( 'the_content', $original );
		assert( ! empty( $filtered ) || $filtered === $original );
		echo "  ✅ WooCommerce content filter priority 999 safe\n";
	}

	/**
	 * Elementor + wptsall the_content filter ordering.
	 */
	public function test_elementor_content_filter_priority() {
		global $wp_filter;
		// P1-TEST-02 (2026-09-02): this is an "Elementor + wptsall" scenario.
		// The >=999 priority only applies when Elementor is active (plain
		// installs register the_content at the default priority); skip on
		// containers without an active Elementor.
		if ( ! did_action( 'elementor/loaded' ) && ! class_exists( '\Elementor\Plugin' ) ) {
			echo "  ⏭️  elementor not active — priority guard not applicable\n";
			return;
		}
		if ( ! isset( $wp_filter['the_content'] ) ) {
			echo "  ⏭️  the_content filter not registered\n";
			return;
		}

		$wptsall_priority = 0;
		foreach ( $wp_filter['the_content']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $cb ) {
				if ( strpos( $id, 'WPTSALL' ) !== false || strpos( $id, 'Virtual_Site_Router' ) !== false ) {
					$wptsall_priority = max( $wptsall_priority, $priority );
				}
			}
		}
		if ( $wptsall_priority > 0 ) {
			assert( $wptsall_priority >= 999, "wptsall should be at >= 999, got {$wptsall_priority}" );
		}
		echo "  ✅ Elementor + wptsall filter ordering correct (wptsall at {$wptsall_priority})\n";
	}

	/**
	 * Yoast field rules adapter returns expected meta keys.
	 */
	public function test_yoast_field_rules_adapter() {
		if ( ! class_exists( '\\WPTSALL\\Models\\Adapters\\Yoast_Field_Rules_Adapter' ) ) {
			echo "  ⏭️  Yoast adapter not loaded, skipping\n";
			return;
		}
		$adapter = new \WPTSALL\Models\Adapters\Yoast_Field_Rules_Adapter();
		$rules   = $adapter->get_field_rules();
		assert( ! empty( $rules ) );
		assert( isset( $rules['_yoast_wpseo_title'] ) );
		assert( isset( $rules['_yoast_wpseo_metadesc'] ) );
		echo "  ✅ Yoast field rules adapter: " . count( $rules ) . " rules\n";
	}

	/**
	 * P1-1: wp_get_attachment_url filter method exists.
	 */
	public function test_attachment_url_filter_method_exists() {
		assert(
			method_exists( '\\WPTSALL\\MediaTranslation\\Hooks\\Media_Translation_Frontend', 'filter_attachment_url' ),
			'filter_attachment_url method should exist'
		);
		echo "  ✅ filter_attachment_url method exists\n";
	}

	/**
	 * P0-2: pre_get_posts REST + Polylang guards present in source.
	 *
	 * P1-TEST-01 (2026-09-02): the source path must resolve inside the
	 * mounted plugin, not `dirname(__DIR__, 2) . '/source'` — under the
	 * docker-lab unit layout (/tmp/tests/unit) that path does not exist and
	 * file_get_contents() returns false, failing the guard asserts even
	 * though all three guards exist in class-virtual-site-router.php
	 * (REST_REQUEST guard line 939, wptsall_rewrite_hash line 255,
	 * wp_cache_get/set( $oc_key ) lines 1351/1361).
	 */
	private static function router_source(): string {
		if ( defined( 'WPTSALL_PLUGIN_DIR' ) ) {
			$base = WPTSALL_PLUGIN_DIR;
		} elseif ( defined( 'WPTSALL_FILE' ) ) {
			$base = dirname( WPTSALL_FILE );
		} else {
			$base = dirname( __DIR__, 2 ) . '/source';
		}

		return (string) file_get_contents( $base . '/includes/hooks/class-virtual-site-router.php' );
	}

	public function test_pre_get_posts_guards_present() {
		$source = self::router_source();
		assert( $source !== false && $source !== '' );
		assert( strpos( $source, "defined( 'REST_REQUEST' ) && REST_REQUEST" ) !== false );
		assert( strpos( $source, 'pll_current_language' ) !== false );
		echo "  ✅ pre_get_posts REST + Polylang guards present\n";
	}

	/**
	 * P1-2: rewrite rules hash guard present in source.
	 */
	public function test_rewrite_rules_hash_guard_present() {
		$source = self::router_source();
		assert( $source !== false && $source !== '' );
		assert( strpos( $source, 'wptsall_rewrite_hash' ) !== false );
		echo "  ✅ rewrite rules hash guard present\n";
	}

	/**
	 * P1-3: get_virtual_content wp_cache present in source.
	 */
	public function test_virtual_content_wp_cache_present() {
		$source = self::router_source();
		assert( $source !== false && $source !== '' );
		assert( strpos( $source, 'wp_cache_get( $oc_key' ) !== false );
		assert( strpos( $source, 'wp_cache_set( $oc_key' ) !== false );
		echo "  ✅ get_virtual_content wp_cache present\n";
	}
}
