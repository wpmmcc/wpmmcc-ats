<?php
/**
 * SEO Compatibility settings page (hreflang / sitemap / DisableHeadLangs).
 *
 * @package WPTSALL\Settings\Admin
 * @since 2.2.0
 */

namespace WPTSALL\Settings\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Settings\Services\Settings_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seo_Settings_Page class.
 */
class Seo_Settings_Page {

	const PAGE_SLUG = 'wptsall-seo';
	const CAP       = 'manage_wptsall_settings';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_wptsall_seo_settings_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice_static_front_shadow_missing' ) );
	}

	/**
	 * Warn when the static front page has no shadow on an active virtual site.
	 *
	 * @return void
	 */
	public static function maybe_notice_static_front_shadow_missing() {
		if ( ! function_exists( 'wptsall_user_can_manage_translations' ) || ! wptsall_user_can_manage_translations() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( false === strpos( (string) $screen->id, 'wptsall' ) && 'options-reading' !== $screen->id ) ) {
			return;
		}
		$front = (int) get_option( 'page_on_front' );
		if ( $front <= 0 || 'page' !== get_option( 'show_on_front' ) ) {
			return;
		}
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' )
			|| ! class_exists( '\\WPTSALL\\Hooks\\Virtual_Site_Query_Switch' ) ) {
			return;
		}
		$sites   = \WPTSALL\Sites\Services\Virtual_Site_Service::get_all( array( 'status' => 'active' ) );
		$missing = array();
		foreach ( (array) $sites as $site ) {
			if ( ! is_array( $site ) || empty( $site['path_prefix'] ) ) {
				continue;
			}
			$shadow = \WPTSALL\Hooks\Virtual_Site_Query_Switch::find_shadow_post_id( $front, $site, 'page' );
			if ( $shadow <= 0 ) {
				$missing[] = (string) ( $site['path_prefix'] ?? $site['id'] ?? '' );
			}
		}
		if ( empty( $missing ) ) {
			return;
		}
		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: comma-separated virtual path prefixes */
				__( 'Static front page has no shadow translation for virtual site(s): %s. Map/translate that page or visitors under those prefixes may see the source front page.', 'wpmmcc-ats' ),
				implode( ', ', array_slice( $missing, 0, 8 ) )
			)
		);
		echo '</p></div>';
	}

	/**
	 * @return void
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'SEO Compatibility', 'wpmmcc-ats' ),
			__( 'SEO', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$settings = Settings_Service::get_all();
		Admin_Page_Helper::render_header(
			__( 'SEO Compatibility', 'wpmmcc-ats' ),
			__( 'hreflang emitter, sitemap alternates, and DisableHeadLangs-style coordination with Yoast / Rank Math.', 'wpmmcc-ats' )
		);
		$has_seo_plugin = (
			( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend' ) )
			|| ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) )
			|| ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\\Plugin' ) )
			|| ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) )
		);
		if ( ! $has_seo_plugin ) {
			echo '<div class="notice notice-info"><p>';
			echo esc_html__( 'No Yoast / Rank Math / AIOSEO / SEOPress detected. Core WordPress sitemaps will drop shadow posts and rewrite locs under a virtual prefix, but will not emit xhtml:link alternates. For production multilanguage sitemaps, install one of those SEO plugins (or accept head hreflang only).', 'wpmmcc-ats' );
			echo '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wptsall_save_seo_settings' ); ?>
			<input type="hidden" name="action" value="wptsall_seo_settings_save">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="hreflang_emitter"><?php esc_html_e( 'hreflang emitter', 'wpmmcc-ats' ); ?></label></th>
					<td>
						<select name="hreflang_emitter" id="hreflang_emitter">
							<option value="wpmmcc-ats" <?php selected( $settings['hreflang_emitter'], 'wpmmcc-ats' ); ?>><?php esc_html_e( 'WPTSALL (recommended)', 'wpmmcc-ats' ); ?></option>
							<option value="yoast" <?php selected( $settings['hreflang_emitter'], 'yoast' ); ?>><?php esc_html_e( 'Defer to Yoast (if active)', 'wpmmcc-ats' ); ?></option>
							<option value="none" <?php selected( $settings['hreflang_emitter'], 'none' ); ?>><?php esc_html_e( 'Disable hreflang only (keep canonical / sitemap / OG)', 'wpmmcc-ats' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Prefer sitemap hreflang', 'wpmmcc-ats' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="prefer_sitemap_hreflang" value="1" <?php checked( ! empty( $settings['prefer_sitemap_hreflang'] ) ); ?>>
							<?php esc_html_e( 'When Yoast / Rank Math is active, skip head hreflang (WPML DisableHeadLangs). Sitemap alternates keep working.', 'wpmmcc-ats' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Detected SEO plugins', 'wpmmcc-ats' ); ?></th>
					<td>
						<ul style="margin:0;">
							<li>Yoast: <?php echo defined( 'WPSEO_VERSION' ) ? esc_html( 'WPSEO_VERSION ' . WPSEO_VERSION ) : esc_html__( 'not active', 'wpmmcc-ats' ); ?></li>
							<li>Rank Math: <?php echo defined( 'RANK_MATH_VERSION' ) ? esc_html( 'RANK_MATH_VERSION ' . RANK_MATH_VERSION ) : esc_html__( 'not active', 'wpmmcc-ats' ); ?></li>
						</ul>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save SEO settings', 'wpmmcc-ats' ) ); ?>
		</form>
		<p class="description">
			<?php esc_html_e( 'URL form and default-language directory remain under Settings. Escape hatch filter: wptsall_force_head_hreflang.', 'wpmmcc-ats' ); ?>
		</p>
		<?php
	}

	/**
	 * @return void
	 */
	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_save_seo_settings' );
		Settings_Service::update(
			array(
				'hreflang_emitter'         => sanitize_key( wp_unslash( $_POST['hreflang_emitter'] ?? 'wpmmcc-ats' ) ),
				'prefer_sitemap_hreflang'  => ! empty( $_POST['prefer_sitemap_hreflang'] ),
			)
		);
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
