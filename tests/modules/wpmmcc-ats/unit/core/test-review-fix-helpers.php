<?php
/**
 * Unit tests for WordPress.org review-related helpers (1.9.0).
 *
 * @package WPTSALL
 */

class Test_Review_Fix_Helpers extends SimpleTestCase {

	public function test_path_helpers_exist_and_resolve() {
		$this->assertTrue( function_exists( 'wptsall_get_plugins_dir' ) );
		$this->assertTrue( function_exists( 'wptsall_get_content_dir' ) );
		$this->assertTrue( function_exists( 'wptsall_get_lang_dir' ) );
		$this->assertTrue( is_dir( wptsall_get_plugins_dir() ) );
		$this->assertTrue( is_dir( wptsall_get_content_dir() ) );
		$lang_dir = rtrim( wptsall_get_lang_dir(), '/' );
		$this->assertTrue( substr( $lang_dir, -10 ) === '/languages', 'lang dir must end with /languages' );
	}

	public function test_request_uri_helper_preserves_path_and_query() {
		$this->assertTrue( function_exists( 'wptsall_get_request_uri' ) );
		$_SERVER['REQUEST_URI'] = '/en_us/hello-world/?x=1';
		$uri                    = wptsall_get_request_uri();
		$this->assertStringContainsString( '/', $uri );
		$this->assertStringContainsString( 'x=1', $uri );
	}

	public function test_pro_helpers_always_true() {
		$this->assertTrue( function_exists( 'wptsall_is_pro_active' ) );
		$this->assertTrue( wptsall_is_pro_active() );
		if ( function_exists( 'wptsall_is_pro_enabled' ) ) {
			$this->assertTrue( wptsall_is_pro_enabled() );
		}
	}

	/**
	 * Route secrets are exactly 32 random bytes encoded as unpadded base64url.
	 * Keeping this contract explicit prevents weak/ambiguous values becoming
	 * active REST route prefixes.
	 */
	public function test_client_route_secret_has_canonical_shape() {
		$this->assertTrue( function_exists( 'wptsall_generate_client_route_secret' ) );
		$this->assertTrue( function_exists( 'wptsall_is_valid_client_route_secret' ) );
		$secret = wptsall_generate_client_route_secret();
		$this->assertEquals( 43, strlen( $secret ) );
		$this->assertTrue( (bool) preg_match( '/\\A[A-Za-z0-9_-]{43}\\z/', $secret ) );
		$this->assertTrue( wptsall_is_valid_client_route_secret( $secret ) );
	}

	public function test_client_route_secret_replaces_malformed_values() {
		$old = get_option( 'wptsall_client_route_secret', null );
		foreach ( array( '', str_repeat( 'a', 31 ), str_repeat( 'a', 32 ), str_repeat( 'a', 42 ), str_repeat( 'a', 44 ), str_repeat( '!', 43 ) ) as $malformed ) {
			update_option( 'wptsall_client_route_secret', $malformed, false );
			$secret = wptsall_get_client_route_secret();
			$this->assertTrue( wptsall_is_valid_client_route_secret( $secret ), 'Malformed route secret must be replaced.' );
			$this->assertEquals( $secret, get_option( 'wptsall_client_route_secret' ) );
		}
		if ( null === $old ) {
			delete_option( 'wptsall_client_route_secret' );
		} else {
			update_option( 'wptsall_client_route_secret', $old, false );
		}
	}

	public function test_route_secret_matching_rejects_noncanonical_candidate() {
		$old = get_option( 'wptsall_client_route_secret', null );
		$secret = wptsall_generate_client_route_secret();
		update_option( 'wptsall_client_route_secret', $secret, false );
		$this->assertTrue( wptsall_route_secret_matches( $secret ) );
		$this->assertFalse( wptsall_route_secret_matches( substr( $secret, 0, 42 ) ) );
		$this->assertFalse( wptsall_route_secret_matches( $secret . 'a' ) );
		$this->assertFalse( wptsall_route_secret_matches( str_repeat( '!', 43 ) ) );
		if ( null === $old ) {
			delete_option( 'wptsall_client_route_secret' );
		} else {
			update_option( 'wptsall_client_route_secret', $old, false );
		}
	}

	public function test_job_snapshot_rejects_missing_source_object() {
		if ( ! class_exists( '\\WPTSALL\\Core\\Job_Snapshot' ) ) {
			$this->assertTrue( false, 'Job_Snapshot must be loaded for snapshot safety tests.' );
		}
		$missing_id = 2147483000;
		$result = \WPTSALL\Core\Job_Snapshot::assert_fresh(
			'post_type',
			$missing_id,
			str_repeat( 'a', 64 ),
			''
		);
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'source_object_not_found', $result->get_error_code() );
	}

	public function test_cron_trigger_rejects_non_allowlisted_hooks() {
		if ( ! function_exists( 'wptsall_trigger_cron_job' ) ) {
			$cron = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : '' ) . 'includes/tasks/automation-cron.php';
			if ( $cron && file_exists( $cron ) ) {
				require_once $cron;
			}
		}
		$this->assertTrue( function_exists( 'wptsall_trigger_cron_job' ) );
		$this->assertFalse( wptsall_trigger_cron_job( 'wp_foreign_hook_name' ) );
		$this->assertFalse( wptsall_trigger_cron_job( '' ) );
		$this->assertTrue( function_exists( 'wptsall_get_allowed_cron_job_hooks' ) );
		$allowed = wptsall_get_allowed_cron_job_hooks();
		$this->assertTrue( is_array( $allowed ) );
		$this->assertNotEmpty( $allowed );
		foreach ( $allowed as $hook ) {
			$this->assertStringStartsWith( 'wptsall_', $hook );
		}
	}

	public function test_no_unprefixed_buddypress_cpt_registration() {
		$hooks = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wpmmcc-ats/' ) . 'includes/hooks/hooks.php';
		$this->assertTrue( file_exists( $hooks ), 'hooks.php must exist' );
		$src = file_get_contents( $hooks ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertFalse(
			(bool) preg_match( "/register_post_type\s*\(\s*['\"]buddypress['\"]/", $src ),
			'Must not register unprefixed buddypress CPT'
		);
		$this->assertTrue( function_exists( 'wptsall_register_support_post_types' ) );
	}

	public function test_site_verify_permission_callback_is_not_return_true() {
		$file = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wpmmcc-ats/' ) . 'includes/tasks/api/class-site-rest-controller.php';
		$this->assertTrue( file_exists( $file ) );
		$src = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringNotContainsString(
			"'permission_callback' => '__return_true'",
			$src
		);
		$this->assertStringContainsString( 'check_verify_permission', $src );
	}

	public function test_remote_langpack_download_removed() {
		$file = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wpmmcc-ats/' ) . 'includes/bootstrap.php';
		$this->assertTrue( file_exists( $file ) );
		$src = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		// Remote language-pack download/AJAX handlers must not exist (wp.org review).
		$this->assertStringNotContainsString( 'wp_ajax_wptsall_langpack_list', $src );
		$this->assertStringNotContainsString( 'wp_ajax_wptsall_langpack_install', $src );

		$service_file = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wpmmcc-ats/' ) . 'includes/core/class-langpack-service.php';
		$this->assertTrue( file_exists( $service_file ) );
		$service_src = file_get_contents( $service_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringNotContainsString( 'wp_remote_get', $service_src );
		$this->assertStringNotContainsString( 'wpmm.cc', $service_src );
	}

	public function test_hreflang_emitter_form_value_matches_settings_schema() {
		// P1-TEST-01 (2026-09-02): the hreflang emitter select moved from the
		// settings page to the SEO Compatibility page (settings page only
		// shows a pointer link). Assert against the live location.
		$file = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wpmmcc-ats/' ) . 'includes/settings/admin/class-seo-settings-page.php';
		$this->assertTrue( file_exists( $file ) );
		$src = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( 'value="wpmmcc-ats"', $src );
		$this->assertStringNotContainsString( 'value="wptsall"', $src );
		$this->assertTrue( class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) );
		$all = \WPTSALL\Settings\Services\Settings_Service::get_all();
		$this->assertContains( $all['hreflang_emitter'], array( 'wpmmcc-ats', 'yoast', 'none' ) );
	}

	public function test_hreflang_legacy_wptsall_normalizes_to_wpmmcc_ats() {
		$this->assertTrue( class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) );
		update_option(
			\WPTSALL\Settings\Services\Settings_Service::OPTION_KEY,
			array( 'hreflang_emitter' => 'wptsall' )
		);
		$all = \WPTSALL\Settings\Services\Settings_Service::get_all();
		$this->assertEquals( 'wpmmcc-ats', $all['hreflang_emitter'] );
		delete_option( \WPTSALL\Settings\Services\Settings_Service::OPTION_KEY );
	}

	public function test_quick_edit_checks_edit_post_capability() {
		$file = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wpmmcc-ats/' ) . 'includes/manual-translation/hooks/class-language-meta-saver.php';
		$this->assertTrue( file_exists( $file ) );
		$src = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$pid )", $src );
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$post_id )", $src );
	}

	public function test_seo_respects_yoast_emitter_deferral() {
		$this->assertTrue( class_exists( '\\WPTSALL\\Hooks\\Virtual_Site_SEO' ) );
		$this->assertTrue( method_exists( '\\WPTSALL\\Hooks\\Virtual_Site_SEO', 'should_emit_hreflang' ) );
		$this->assertTrue( method_exists( '\\WPTSALL\\Hooks\\Virtual_Site_SEO', 'get_hreflang_emitter' ) );
		$src_file = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wpmmcc-ats/' ) . 'includes/hooks/class-virtual-site-seo.php';
		$src      = file_get_contents( $src_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( "should_emit_hreflang", $src );
		$this->assertStringContainsString( "'yoast' === \$emitter", $src );
	}

	public function test_core_widget_option_allowlist_is_tight() {
		$this->assertTrue( function_exists( 'wptsall_is_core_widget_option' ) );
		$this->assertTrue( wptsall_is_core_widget_option( 'widget_text' ) );
		$this->assertTrue( wptsall_is_core_widget_option( 'widget_block' ) );
		$this->assertFalse( wptsall_is_core_widget_option( 'widget_unknown_third_party' ) );
		$this->assertTrue( function_exists( 'wptsall_is_syncable_option' ) );
		$this->assertTrue( wptsall_is_syncable_option( 'blogname' ) );
		$this->assertTrue( wptsall_is_syncable_option( 'widget_text' ) );
		$this->assertFalse( wptsall_is_syncable_option( 'widget_unknown_third_party' ) );
	}

	public function test_zh_cn_po_has_no_stale_trialware_msgids() {
		$po = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wpmmcc-ats/' ) . 'languages/wpmmcc-ats-zh_CN.po';
		$this->assertTrue( file_exists( $po ), 'zh_CN.po must exist' );
		$src = file_get_contents( $po ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		foreach ( array( 'License Key', 'Bind to unlock the Tasks module (Pro)', 'Auto Translate (Pro)', 'Pro Available' ) as $bad ) {
			$this->assertStringNotContainsString( 'msgid "' . $bad . '"', $src, "stale trialware msgid still present: {$bad}" );
		}
		$this->assertStringContainsString( 'support/plugin/wpmmcc-ats', $src, 'Bugs-To must point at wpmmcc-ats slug' );
		$this->assertStringNotContainsString( 'support/plugin/source', $src );
	}

	public function test_dead_pro_license_ui_removed() {
		$tasks = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wpmmcc-ats/' ) . 'includes/tasks/admin/class-tasks-page.php';
		$css   = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wpmmcc-ats/' ) . 'assets/css/translation-editor.css';
		$menu  = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wpmmcc-ats/' ) . 'includes/admin/menu.php';
		$this->assertStringNotContainsString( 'has_local_license', file_get_contents( $tasks ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringNotContainsString( 'wptsall-pro-hint', file_get_contents( $css ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringNotContainsString( 'licensed users', file_get_contents( $menu ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
