<?php
/**
 * Compatibility Notices Tests
 *
 * Tests for WPTSALL\Admin\Compatibility_Notices:
 * - init(): registers admin_notices hooks only inside admin context
 * - maybe_block_theme_notice(): capability-gated; with an admin + matching
 *   screen the output follows the block-theme state of the active theme
 *   (notice text when block theme, no output otherwise)
 * - maybe_artifact_drift_notice(): never fatals; with the current Lab
 *   artifact state it either stays silent (no/matching manifest) or emits
 *   the drift error notice
 *
 * catalog: WP-CLASS-Compatibility_Notices
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Admin\Compatibility_Notices;

class Test_Compatibility_Notices extends SimpleTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->remove_notice_hooks();
		wp_set_current_user( 0 );
		unset( $GLOBALS['current_screen'], $GLOBALS['screen'] );
	}

	public function tearDown(): void {
		$this->remove_notice_hooks();
		wp_set_current_user( 0 );
		unset( $GLOBALS['current_screen'], $GLOBALS['screen'] );
		parent::tearDown();
	}

	private function remove_notice_hooks() {
		remove_action( 'admin_notices', array( Compatibility_Notices::class,'maybe_block_theme_notice' ) );
		remove_action( 'network_admin_notices', array( Compatibility_Notices::class,'maybe_block_theme_notice' ) );
		remove_action( 'admin_notices', array( Compatibility_Notices::class,'maybe_artifact_drift_notice' ) );
	}

	/**
	 * Force is_admin() true with a screen whose id is in the notice allowlist.
	 */
	private function enter_admin_dashboard() {
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		wp_set_current_user( 1 ); // Lab user 1 is an administrator.
		set_current_screen( 'dashboard' );
	}

	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Admin\Compatibility_Notices' ) );
	}

	public function test_init_registers_hooks_only_in_admin() {
		// Frontend-like context (CLI default): init() must not register notices.
		$this->assertFalse( is_admin() );
		Compatibility_Notices::init();
		$this->assertFalse( has_action( 'admin_notices', array( Compatibility_Notices::class,'maybe_block_theme_notice' ) ) );
		$this->assertFalse( has_action( 'admin_notices', array( Compatibility_Notices::class,'maybe_artifact_drift_notice' ) ) );

		// Admin context: all three notices registered.
		$this->enter_admin_dashboard();
		try {
			$this->assertTrue( is_admin() );
			Compatibility_Notices::init();
			$this->assertEquals( 10, has_action( 'admin_notices', array( Compatibility_Notices::class,'maybe_block_theme_notice' ) ) );
			$this->assertEquals( 10, has_action( 'network_admin_notices', array( Compatibility_Notices::class,'maybe_block_theme_notice' ) ) );
			$this->assertEquals( 10, has_action( 'admin_notices', array( Compatibility_Notices::class,'maybe_artifact_drift_notice' ) ) );
		} finally {
			$this->remove_notice_hooks();
			unset( $GLOBALS['current_screen'], $GLOBALS['screen'] );
			wp_set_current_user( 0 );
		}
	}

	public function test_block_theme_notice_is_capability_gated() {
		// Non-admin user: no output regardless of theme.
		ob_start();
		Compatibility_Notices::maybe_block_theme_notice();
		$this->assertSame( '', ob_get_clean(), 'non-privileged users must never see the notice' );

		// Privileged user but no screen: no output either (no screen match).
		wp_set_current_user( 1 );
		ob_start();
		Compatibility_Notices::maybe_block_theme_notice();
		$this->assertSame( '', ob_get_clean(), 'without a matching screen the notice stays hidden' );
	}

	public function test_block_theme_notice_follows_active_theme_state() {
		$this->enter_admin_dashboard();
		try {
			$is_block = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

			ob_start();
			Compatibility_Notices::maybe_block_theme_notice();
			$out = ob_get_clean();

			if ( $is_block ) {
				$this->assertStringContainsString( 'notice notice-info', $out );
				$this->assertStringContainsString( 'Block theme detected', $out );
			} else {
				$this->assertSame( '', $out, 'classic theme on a wptsall screen must not emit the block-theme notice' );
			}
		} finally {
			unset( $GLOBALS['current_screen'], $GLOBALS['screen'] );
			wp_set_current_user( 0 );
		}
	}

	public function test_artifact_drift_notice_is_capability_gated_and_never_fatals() {
		// Non-admin: silent.
		ob_start();
		Compatibility_Notices::maybe_artifact_drift_notice();
		$this->assertSame( '', ob_get_clean() );

		// Admin: must complete without error; output depends on the mounted
		// artifact (no manifest / matching SHA -> silent; drifting SHA -> error).
		$this->enter_admin_dashboard();
		try {
			ob_start();
			Compatibility_Notices::maybe_artifact_drift_notice();
			$out = ob_get_clean();
			$this->assertIsString( $out );

			$manifest = defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH . 'build-manifest.json' : '';
			if ( $manifest && is_readable( $manifest ) ) {
				$data = json_decode( (string) file_get_contents( $manifest ), true );
				if ( is_array( $data ) && ! empty( $data['seo_sha256'] ) ) {
					$seo      = WPTSALL_PATH . 'includes/hooks/class-virtual-site-seo.php';
					$drifting = is_readable( $seo ) && ! hash_equals( (string) $data['seo_sha256'], (string) hash_file( 'sha256', $seo ) );
					if ( $drifting ) {
						$this->assertStringContainsString( 'notice notice-error', $out );
						$this->assertStringContainsString( 'build-manifest.json', $out );
					} else {
						$this->assertSame( '', $out );
					}
					return;
				}
			}
			// No manifest / no seo_sha256: silent.
			$this->assertSame( '', $out );
		} finally {
			unset( $GLOBALS['current_screen'], $GLOBALS['screen'] );
			wp_set_current_user( 0 );
		}
	}
}
