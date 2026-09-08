<?php
/**
 * Settings Admin Page
 *
 * Top-level submenu for editing WPTSALL general settings. Replaces the
 * scattered "Features" tab in Tasks with a proper Polylang-style settings
 * form.
 *
 * @package WPTSALL
 * @since 1.2.0
 */

namespace WPTSALL\Settings\Admin;

use WPTSALL\Settings\Services\Settings_Service;
use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Admin\Admin_Page_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_Page {

	const PAGE_SLUG = 'wptsall-settings';
	const CAP       = 'manage_wptsall_settings';

	public static function init() {
		add_action( 'admin_post_wptsall_settings_save', array( __CLASS__, 'handle_save' ) );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Settings', 'wpmmcc-ats' ),
			__( 'Settings', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$settings = Settings_Service::get_all();
		$languages = Language_Service::get_all( array( 'status' => 'all' ) );

		Admin_Page_Helper::render_header(
			__( 'WPTSALL Settings', 'wpmmcc-ats' ),
			__( 'General plugin behavior: URL form, default language, media, sync, debug, SEO emitter.', 'wpmmcc-ats' )
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wptsall_save_settings' ); ?>
			<input type="hidden" name="action" value="wptsall_settings_save">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="url_form"><?php esc_html_e( 'URL form', 'wpmmcc-ats' ); ?></label></th>
					<td>
						<select name="url_form" id="url_form">
							<option value="subdir" <?php selected( $settings['url_form'], 'subdir' ); ?>><?php esc_html_e( 'Subdir (e.g. /en_us/) — Directory mode', 'wpmmcc-ats' ); ?></option>
							<option value="subdomain" <?php selected( $settings['url_form'], 'subdomain' ); ?>><?php esc_html_e( 'Subdomain (e.g. en.example.com)', 'wpmmcc-ats' ); ?></option>
							<option value="param" <?php selected( $settings['url_form'], 'param' ); ?>><?php esc_html_e( 'Query parameter (?lang=en_US)', 'wpmmcc-ats' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Parameter mode detects ?lang= / ?wptsall_lang= matching a virtual-site language or path prefix. Subdomain needs DNS/host mapping.', 'wpmmcc-ats' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Directory for default language', 'wpmmcc-ats' ); ?></th>
					<td>
						<label><input type="checkbox" name="directory_for_default_language" value="1" <?php checked( ! empty( $settings['directory_for_default_language'] ) ); ?>>
						<?php esc_html_e( 'Keep path prefix on the default-language virtual site (WPML directory_for_default_language). Off = default-lang home stays without prefix when converting URLs.', 'wpmmcc-ats' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="default_language"><?php esc_html_e( 'Default language', 'wpmmcc-ats' ); ?></label></th>
					<td>
						<select name="default_language" id="default_language">
							<?php foreach ( $languages as $lang ) : ?>
								<option value="<?php echo esc_attr( $lang['code'] ); ?>" <?php selected( $settings['default_language'], $lang['code'] ); ?>>
									<?php echo esc_html( $lang['code'] ); ?> — <?php echo esc_html( $lang['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Used as the x-default hreflang target and for untranslated content fallback.', 'wpmmcc-ats' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Browser detection', 'wpmmcc-ats' ); ?></th>
					<td>
						<label><input type="checkbox" name="detect_browser" value="1" <?php checked( $settings['detect_browser'] ); ?>>
						<?php esc_html_e( 'Auto-redirect first-time visitors based on Accept-Language header', 'wpmmcc-ats' ); ?></label>
						<p class="description"><?php esc_html_e( 'Recommended OFF to avoid SEO duplicate-content issues.', 'wpmmcc-ats' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="media_handling"><?php esc_html_e( 'Media handling', 'wpmmcc-ats' ); ?></label></th>
					<td>
						<select name="media_handling" id="media_handling">
							<option value="copy" <?php selected( $settings['media_handling'], 'copy' ); ?>><?php esc_html_e( 'Copy media to each language site (recommended)', 'wpmmcc-ats' ); ?></option>
							<option value="reference" <?php selected( $settings['media_handling'], 'reference' ); ?>><?php esc_html_e( 'Reference source media (saves disk, breaks CDN)', 'wpmmcc-ats' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sync_mode"><?php esc_html_e( 'Sync mode', 'wpmmcc-ats' ); ?></label></th>
					<td>
						<select name="sync_mode" id="sync_mode">
							<option value="new_only" <?php selected( $settings['sync_mode'], 'new_only' ); ?>><?php esc_html_e( 'New content only', 'wpmmcc-ats' ); ?></option>
							<option value="full" <?php selected( $settings['sync_mode'], 'full' ); ?>><?php esc_html_e( 'Two-way sync', 'wpmmcc-ats' ); ?></option>
							<option value="manual" <?php selected( $settings['sync_mode'], 'manual' ); ?>><?php esc_html_e( 'Manual (no automatic sync)', 'wpmmcc-ats' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Permalink fallback', 'wpmmcc-ats' ); ?></th>
					<td>
						<label><input type="checkbox" name="permalink_fallback" value="1" <?php checked( $settings['permalink_fallback'] ); ?>>
						<?php esc_html_e( '301 redirect translated post 404s to their virtual-site URL (P0-3)', 'wpmmcc-ats' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'SEO', 'wpmmcc-ats' ); ?></th>
					<td>
						<p class="description">
							<?php
							echo wp_kses_post(
								sprintf(
									/* translators: %s: admin URL */
									__( 'hreflang emitter and sitemap options moved to <a href="%s">SEO Compatibility</a>.', 'wpmmcc-ats' ),
									esc_url( admin_url( 'admin.php?page=wptsall-seo' ) )
								)
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Client API', 'wpmmcc-ats' ); ?></th>
					<td>
						<label><input type="checkbox" name="client_api_enabled" value="1" <?php checked( $settings['client_api_enabled'] ); ?>>
						<?php esc_html_e( 'Enable /wp-json/wptsall/v2/* client REST endpoints (route_secret + token auth)', 'wpmmcc-ats' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Debug mode', 'wpmmcc-ats' ); ?></th>
					<td>
						<label><input type="checkbox" name="debug_mode" value="1" <?php checked( $settings['debug_mode'] ); ?>>
						<?php esc_html_e( 'Verbose logging to wptsall_log_info() — also enables wp_footer marker', 'wpmmcc-ats' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="log_retention_days"><?php esc_html_e( 'Log retention (days)', 'wpmmcc-ats' ); ?></label></th>
					<td>
						<input type="number" min="0" max="365" name="log_retention_days" id="log_retention_days" value="<?php echo (int) $settings['log_retention_days']; ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Old log entries (wptsall_task_logs) are purged after this many days. 0 = keep forever.', 'wpmmcc-ats' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'wpmmcc-ats' ); ?></button>
			</p>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Theme & Plugin Locale Overrides (1.3.0)', 'wpmmcc-ats' ); ?></h2>
		<p><?php esc_html_e( 'For each target language, optionally override the WP locale string used when loading .mo files from wp-content/languages/. Default is to use the language code as the locale.', 'wpmmcc-ats' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wptsall_locale_save' ); ?>
			<input type="hidden" name="action" value="wptsall_locale_save">
			<table class="form-table">
				<tr>
					<th><label for="override_lang"><?php esc_html_e( 'Target language', 'wpmmcc-ats' ); ?></label></th>
					<td><select name="override_lang" id="override_lang">
						<?php foreach ( $languages as $lang ) : ?>
							<option value="<?php echo esc_attr( $lang['code'] ); ?>"><?php echo esc_html( $lang['code'] ); ?></option>
						<?php endforeach; ?>
					</select></td>
				</tr>
				<tr>
					<th><label for="override_locale"><?php esc_html_e( 'WP locale', 'wpmmcc-ats' ); ?></label></th>
					<td><input name="override_locale" id="override_locale" type="text" placeholder="en_US / de_DE / fr_FR" class="regular-text"></td>
				</tr>
			</table>
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Save override', 'wpmmcc-ats' ); ?></button>
		</form>
		<?php
		$overrides = \WPTSALL\ThemeLocalization\Theme_Localization::get_overrides();
		if ( ! empty( $overrides ) ) : ?>
			<h3><?php esc_html_e( 'Current overrides', 'wpmmcc-ats' ); ?></h3>
			<table class="wp-list-table widefat">
				<thead><tr><th><?php esc_html_e( 'Target lang', 'wpmmcc-ats' ); ?></th><th><?php esc_html_e( 'WP locale', 'wpmmcc-ats' ); ?></th><th><?php esc_html_e( 'Action', 'wpmmcc-ats' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $overrides as $lang => $loc ) : ?>
					<tr>
						<td><code><?php echo esc_html( $lang ); ?></code></td>
						<td><code><?php echo esc_html( $loc ); ?></code></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( 'wptsall_locale_clear' ); ?>
								<input type="hidden" name="action" value="wptsall_locale_clear">
								<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>">
								<button class="button button-small" type="submit"><?php esc_html_e( 'Remove', 'wpmmcc-ats' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<hr>
		<h2><?php esc_html_e( 'Current effective settings (read-only)', 'wpmmcc-ats' ); ?></h2>
		<pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;max-width:600px;"><?php echo esc_html( (string) wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
		<?php
		Admin_Page_Helper::render_footer();
	}

	public static function handle_locale_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_locale_save' );
		$lang = sanitize_text_field( wp_unslash( $_POST['override_lang'] ?? '' ) );
		$loc  = sanitize_text_field( wp_unslash( $_POST['override_locale'] ?? '' ) );
		if ( '' !== $lang ) {
			\WPTSALL\ThemeLocalization\Theme_Localization::set_override( $lang, $loc );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_locale_clear() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_locale_clear' );
		\WPTSALL\ThemeLocalization\Theme_Localization::clear_override( sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) ) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_save_settings' );
		$partial = array(
			'url_form'           => sanitize_key( wp_unslash( $_POST['url_form'] ?? 'subdir' ) ),
			'default_language'   => sanitize_text_field( wp_unslash( $_POST['default_language'] ?? 'zh_CN' ) ),
			'detect_browser'     => ! empty( $_POST['detect_browser'] ),
			'media_handling'     => sanitize_key( wp_unslash( $_POST['media_handling'] ?? 'copy' ) ),
			'sync_mode'          => sanitize_key( wp_unslash( $_POST['sync_mode'] ?? 'new_only' ) ),
			'permalink_fallback' => ! empty( $_POST['permalink_fallback'] ),
			'directory_for_default_language' => ! empty( $_POST['directory_for_default_language'] ),
			'client_api_enabled' => ! empty( $_POST['client_api_enabled'] ),
			'debug_mode'         => ! empty( $_POST['debug_mode'] ),
			'log_retention_days' => (int) ( isset( $_POST['log_retention_days'] ) ? sanitize_text_field( wp_unslash( $_POST['log_retention_days'] ) ) : 7 ),
		);
		Settings_Service::update( $partial );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
