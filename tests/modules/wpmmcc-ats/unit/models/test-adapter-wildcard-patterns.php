<?php
/**
 * Plugin Field Rules Adapter Wildcard Pattern Tests
 *
 * Phase 2E step 1: validates that the Registry's match_meta_key() falls
 * back to wildcard pattern rules when no explicit rule covers the key.
 *
 * @package WPTSALL
 * @since 1.3.0
 */

if ( ! class_exists( 'WPTSALL\\Models\\Adapters\\Plugin_Field_Rules_Registry' ) ) {
    fwrite( STDERR, "Registry class not loadable\n" );
    return;
}

class Test_Adapter_Wildcard_Patterns extends WP_UnitTestCase {

    public function test_explicit_match_wins_over_pattern() {
        $rule = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::match_meta_key( '_author' );
        $this->assertNotNull( $rule );
        $this->assertSame( 'plugin', $rule['source'] ); // site-reviews adapter, explicit
    }

    public function test_pattern_matches_meta_key_in_family() {
        $rule = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::match_meta_key( '_yoast_wpseo_twitter_title' );
        $this->assertNotNull( $rule, 'Pattern should match a key in the _yoast_wpseo_twitter_* family' );
        $this->assertSame( 'plugin_pattern', $rule['source'] );
    }

    public function test_pattern_does_not_match_outside_family() {
        $rule = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::match_meta_key( '_yoast_wpseo_title' );
        // _yoast_wpseo_title has an explicit rule, so it matches via explicit (source=plugin), not pattern
        $this->assertNotNull( $rule );
        $this->assertSame( 'plugin', $rule['source'] );
    }

    public function test_no_match_returns_null() {
        $rule = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::match_meta_key( 'totally_unrelated_meta_key' );
        $this->assertNull( $rule );
    }

    public function test_get_all_field_patterns_includes_yoast() {
        $patterns = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::get_all_field_patterns();
        $this->assertArrayHasKey( '_yoast_wpseo_twitter_*', $patterns );
    }
}
