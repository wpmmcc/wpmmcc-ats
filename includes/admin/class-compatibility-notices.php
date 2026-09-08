<?php
/**
 * Classic-theme / FSE boundary notices.
 *
 * @package WPTSALL\Admin
 * @since 2.0.1
 */

namespace WPTSALL\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility_Notices class.
 */
class Compatibility_Notices {

	/**
	 * @return void
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_notices', array( __CLASS__, 'maybe_block_theme_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'maybe_block_theme_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_artifact_drift_notice' ) );
	}

	/**
	 * @return void
	 */
	public static function maybe_block_theme_notice() {
		if ( ! wptsall_user_can_manage_translations() ) {
			return;
		}
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$show   = false;
		if ( $screen ) {
			$id = (string) $screen->id;
			if ( false !== strpos( $id, 'wptsall' ) || false !== strpos( $id, 'wpmmcc' )
				|| in_array( $id, array( 'themes', 'plugins', 'dashboard', 'dashboard-network' ), true ) ) {
				$show = true;
			}
		}
		if ( ! $show ) {
			return;
		}

		echo '<div class="notice notice-info"><p>';
		echo esc_html__(
			'WPTSALL: Block theme detected. Templates, template parts, Navigation, and global styles are included in scan/progress and the FSE adapter (Layer B strings + needs_resync). Keep block JSON structure intact when reviewing translations; font family/face remain excluded.',
			'wpmmcc-ats'
		);
		echo '</p></div>';
	}

	/**
	 * Warn when lab package SHA diverges from product source manifest.
	 *
	 * @return void
	 */
	public static function maybe_artifact_drift_notice() {
		if ( ! wptsall_user_can_manage_translations() ) {
			return;
		}
		$manifest = defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH . 'build-manifest.json' : '';
		if ( ! $manifest || ! is_readable( $manifest ) ) {
			return;
		}
		$raw = file_get_contents( $manifest );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['seo_sha256'] ) ) {
			return;
		}
		$seo = WPTSALL_PATH . 'includes/hooks/class-virtual-site-seo.php';
		if ( ! is_readable( $seo ) ) {
			return;
		}
		$actual = hash_file( 'sha256', $seo );
		if ( hash_equals( (string) $data['seo_sha256'], (string) $actual ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'WPTSALL: build-manifest.json SHA does not match class-virtual-site-seo.php. Re-run tests/docker-lab/scripts/sync-plugin-from-source.sh (or rebuild the artifact) before trusting Lab results.',
			'wpmmcc-ats'
		);
		echo '</p></div>';
	}
}
